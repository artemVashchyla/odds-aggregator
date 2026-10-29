<?php
/**
 * Cached access to odds data.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Odds;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OddsAggregator\Cache\CacheEntry;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Providers\AbstractProvider;
use OddsAggregator\Providers\ProviderException;
use OddsAggregator\Providers\ProviderFactory;
use OddsAggregator\Providers\ProviderInterface;

/**
 * The only thing the front end, the block and the REST API read odds through.
 *
 * Implements the read path the performance requirement needs:
 *
 * - fresh cache hit  -> return immediately, no network
 * - stale cache hit  -> return immediately, queue a background refresh
 * - miss             -> fetch once (behind a lock) and store
 *
 * The practical effect is that rendering a page never waits on a scrape, and a
 * burst of traffic against an expired key produces one upstream request rather
 * than one per visitor.
 */
final class OddsRepository {

	/**
	 * Cron hook used to refresh a single event out of band.
	 *
	 * @var string
	 */
	public const REFRESH_HOOK = 'odds_aggregator_refresh_event';

	/**
	 * Cron hook used to refresh the event list out of band.
	 *
	 * @var string
	 */
	public const REFRESH_EVENTS_HOOK = 'odds_aggregator_refresh_events';

	/**
	 * Transient holding the most recent provider failure.
	 *
	 * @var string
	 */
	public const ERROR_TRANSIENT = 'odds_aggregator_provider_error';

	/**
	 * Option recording every event any provider has listed, keyed by id.
	 *
	 * A block stores only an event id, and ids are provider-specific. This
	 * index is how a block chosen under one data source is still understood
	 * after switching to another. It is deliberately not part of the cache,
	 * so a cache flush on a provider change does not erase it.
	 *
	 * @var string
	 */
	public const EVENT_INDEX_OPTION = 'odds_aggregator_event_index';

	/**
	 * Most events kept in the index; the latest kick-offs win.
	 *
	 * @var int
	 */
	private const EVENT_INDEX_LIMIT = 300;

	/**
	 * Constructor.
	 *
	 * @param ProviderFactory $factory  Provider construction.
	 * @param OddsCache       $cache    Cache layer.
	 * @param MarketRegistry  $markets  Market catalogue.
	 */
	public function __construct(
		private readonly ProviderFactory $factory,
		private readonly OddsCache $cache,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Events available from the active provider.
	 *
	 * @return Event[]
	 */
	public function events(): array {
		$provider = $this->factory->create_or_fallback();
		$key      = $this->events_key( $provider );
		$entry    = $this->cache->get( $key );

		if ( $entry instanceof CacheEntry ) {
			if ( ! $entry->is_fresh() ) {
				$this->queue_events_refresh();
			}

			return $this->hydrate_events( (array) $entry->data() );
		}

		if ( ! $this->cache->acquire_lock( $key ) ) {
			return array();
		}

		try {
			return $this->fetch_and_store_events( $provider ) ?? array();
		} finally {
			$this->cache->release_lock( $key );
		}
	}

	/**
	 * Fetches the event list and writes it to the cache.
	 *
	 * @return Event[]
	 */
	public function refresh_events(): array {
		$provider = $this->factory->create_or_fallback();
		$events   = $this->fetch_and_store_events( $provider );

		if ( null !== $events ) {
			return $events;
		}

		$entry = $this->cache->get( $this->events_key( $provider ) );

		return $entry instanceof CacheEntry ? $this->hydrate_events( (array) $entry->data() ) : array();
	}

	/**
	 * Performs the upstream event fetch and stores the result.
	 *
	 * @param ProviderInterface $provider Active provider.
	 * @return Event[]|null Null when the provider failed.
	 */
	private function fetch_and_store_events( ProviderInterface $provider ): ?array {
		try {
			$events = $provider->fetch_events();
		} catch ( ProviderException $exception ) {
			$this->log( $exception->getMessage() );

			return null;
		}

		$this->cache->set(
			$this->events_key( $provider ),
			array_map( static fn( Event $event ): array => $event->to_array(), $events )
		);

		$this->remember_events( $events );
		$this->clear_error();

		return $events;
	}

	/**
	 * Queues a background refresh of the event list.
	 *
	 * @return void
	 */
	private function queue_events_refresh(): void {
		if ( ! $this->cache->acquire_lock( 'refresh_events', 120 ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::REFRESH_EVENTS_HOOK ) ) {
			wp_schedule_single_event( time(), self::REFRESH_EVENTS_HOOK );
		}
	}

	/**
	 * Cache key for the event list.
	 *
	 * @param ProviderInterface $provider Active provider.
	 * @return string
	 */
	private function events_key( ProviderInterface $provider ): string {
		return OddsCache::key( 'events', $provider->id() );
	}

	/**
	 * One event by id, or null when the active source cannot supply it.
	 *
	 * Ids differ between providers, so an id this source does not list is
	 * looked up in the event index and resolved in two further steps:
	 *
	 * 1. the same fixture (teams and kick-off) under this source's own id;
	 * 2. the provider adopting the recorded event - only sample data does,
	 *    because only sample data can price a match it does not list.
	 *
	 * @param string $event_id Event id, possibly from another provider.
	 * @return Event|null
	 */
	public function find_event( string $event_id ): ?Event {
		$events = $this->events();

		foreach ( $events as $event ) {
			if ( $event->id() === $event_id ) {
				return $event;
			}
		}

		$known = $this->indexed_event( $event_id );

		if ( null === $known ) {
			return null;
		}

		foreach ( $events as $event ) {
			if ( $known->is_same_fixture( $event ) ) {
				return $event;
			}
		}

		$provider = $this->factory->create_or_fallback();

		return $provider instanceof AbstractProvider ? $provider->adopt_event( $known ) : null;
	}

	/**
	 * Records listed events in the cross-provider index.
	 *
	 * @param Event[] $events Events just fetched.
	 * @return void
	 */
	private function remember_events( array $events ): void {
		$stored = get_option( self::EVENT_INDEX_OPTION, array() );
		$index  = is_array( $stored ) ? $stored : array();

		foreach ( $events as $event ) {
			$index[ $event->id() ] = $event->to_array();
		}

		if ( count( $index ) > self::EVENT_INDEX_LIMIT ) {
			uasort(
				$index,
				static fn( $a, $b ): int => strcmp( (string) ( $b['kickoff'] ?? '' ), (string) ( $a['kickoff'] ?? '' ) )
			);

			$index = array_slice( $index, 0, self::EVENT_INDEX_LIMIT, true );
		}

		// Unchanged lists are the common case; skip the write.
		if ( $index !== $stored ) {
			update_option( self::EVENT_INDEX_OPTION, $index, false );
		}
	}

	/**
	 * An event from the index, as it was listed by whichever source listed it.
	 *
	 * @param string $event_id Event id.
	 * @return Event|null
	 */
	private function indexed_event( string $event_id ): ?Event {
		$index = get_option( self::EVENT_INDEX_OPTION, array() );
		$row   = is_array( $index ) ? ( $index[ $event_id ] ?? null ) : null;

		return is_array( $row ) ? ( $this->hydrate_events( array( $row ) )[0] ?? null ) : null;
	}

	/**
	 * Prices for an event, served from cache wherever possible.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return OddsLine[]
	 */
	public function odds( string $event_id, array $market_keys ): array {
		$provider = $this->factory->create_or_fallback();
		$key      = $this->odds_key( $provider->id(), $event_id, $market_keys );
		$entry    = $this->cache->get( $key );

		if ( $entry instanceof CacheEntry ) {
			if ( ! $entry->is_fresh() ) {
				$this->queue_refresh( $event_id, $market_keys );
			}

			return $this->hydrate_lines( (array) $entry->data() );
		}

		// Cold cache: one process fetches, the rest render empty and pick it up next time.
		if ( ! $this->cache->acquire_lock( $key ) ) {
			return array();
		}

		try {
			return $this->fetch_and_store( $event_id, $market_keys );
		} finally {
			$this->cache->release_lock( $key );
		}
	}

	/**
	 * Fetches fresh prices and writes them to the cache.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return OddsLine[]
	 */
	public function refresh( string $event_id, array $market_keys ): array {
		return $this->fetch_and_store( $event_id, $market_keys );
	}

	/**
	 * Performs the upstream fetch, stores the result and notifies listeners.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return OddsLine[]
	 */
	private function fetch_and_store( string $event_id, array $market_keys ): array {
		$provider = $this->factory->create_or_fallback();
		$event    = $this->find_event( $event_id );

		if ( ! $event instanceof Event ) {
			return array();
		}

		$markets = array_values( $this->markets->only( $market_keys ) );

		if ( array() === $markets ) {
			return array();
		}

		try {
			$lines = $provider->fetch_odds( $event, $markets );
		} catch ( ProviderException $exception ) {
			$this->log( $exception->getMessage() );

			return array();
		}

		$this->cache->set(
			$this->odds_key( $provider->id(), $event_id, $market_keys ),
			array_map( static fn( OddsLine $line ): array => $line->to_array(), $lines )
		);

		$this->clear_error();

		/**
		 * Fires after odds for an event have been refreshed from a provider.
		 *
		 * Listen on this to push updates elsewhere, write a price history, or
		 * send alerts.
		 *
		 * @param OddsLine[] $lines       Freshly fetched rows.
		 * @param string     $event_id    Event the rows belong to.
		 * @param string     $provider_id Provider that produced them.
		 */
		do_action( 'odds_aggregator_odds_updated', $lines, $event_id, $provider->id() );

		return $lines;
	}

	/**
	 * Queues an out-of-band refresh, at most one in flight per key.
	 *
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys to include.
	 * @return void
	 */
	private function queue_refresh( string $event_id, array $market_keys ): void {
		$lock = 'refresh_' . OddsCache::key( $event_id, implode( ',', $market_keys ) );

		if ( ! $this->cache->acquire_lock( $lock, 120 ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::REFRESH_HOOK, array( $event_id, $market_keys ) ) ) {
			wp_schedule_single_event( time(), self::REFRESH_HOOK, array( $event_id, $market_keys ) );
		}
	}

	/**
	 * Cache key for a set of prices.
	 *
	 * Market keys are sorted so that the same request in a different order is
	 * one cache entry, not two.
	 *
	 * @param string   $provider_id Provider id.
	 * @param string   $event_id    Event id.
	 * @param string[] $market_keys Market keys.
	 * @return string
	 */
	private function odds_key( string $provider_id, string $event_id, array $market_keys ): string {
		$markets = array_map( 'strval', $market_keys );
		sort( $markets );

		return OddsCache::key( 'odds', $provider_id, $event_id, implode( ',', $markets ) );
	}

	/**
	 * Rebuilds events from their cached array form.
	 *
	 * @param array<int,mixed> $rows Cached rows.
	 * @return Event[]
	 */
	private function hydrate_events( array $rows ): array {
		$events = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}

			try {
				$kickoff = new DateTimeImmutable( (string) ( $row['kickoff'] ?? 'now' ), new DateTimeZone( 'UTC' ) );
			} catch ( Exception $exception ) {
				$kickoff = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			}

			$events[] = new Event(
				(string) $row['id'],
				(string) ( $row['sport'] ?? 'football' ),
				(string) ( $row['league'] ?? '' ),
				(string) ( $row['home'] ?? '' ),
				(string) ( $row['away'] ?? '' ),
				$kickoff
			);
		}

		return $events;
	}

	/**
	 * Rebuilds price rows from their cached array form.
	 *
	 * @param array<int,mixed> $rows Cached rows.
	 * @return OddsLine[]
	 */
	private function hydrate_lines( array $rows ): array {
		$lines = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$lines[] = OddsLine::from_array( $row );
			}
		}

		return $lines;
	}

	/**
	 * Most recent provider failure, or null when the last fetch succeeded.
	 *
	 * Surfaced in the admin and the block editor so a source that is silently
	 * returning nothing - a blocked scraper, a missing API key - explains
	 * itself instead of leaving an empty screen.
	 *
	 * @return array{provider:string,message:string,time:int}|null
	 */
	public static function last_error(): ?array {
		$error = get_transient( self::ERROR_TRANSIENT );

		if ( ! is_array( $error ) || ! isset( $error['message'] ) ) {
			return null;
		}

		return array(
			'provider' => (string) ( $error['provider'] ?? '' ),
			'message'  => (string) $error['message'],
			'time'     => (int) ( $error['time'] ?? 0 ),
		);
	}

	/**
	 * Records a provider failure for display, and logs it when debugging.
	 *
	 * @param string $message Failure description.
	 * @return void
	 */
	private function log( string $message ): void {
		set_transient(
			self::ERROR_TRANSIENT,
			array(
				'provider' => $this->factory->create_or_fallback()->id(),
				'message'  => $message,
				'time'     => time(),
			),
			HOUR_IN_SECONDS
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[odds-aggregator] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Forgets a recorded failure after a successful fetch.
	 *
	 * @return void
	 */
	private function clear_error(): void {
		delete_transient( self::ERROR_TRANSIENT );
	}
}
