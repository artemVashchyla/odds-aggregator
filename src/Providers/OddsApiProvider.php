<?php
/**
 * The Odds API client.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\HttpException;

/**
 * Licensed alternative to scraping, using the-odds-api.com v4.
 *
 * One GET of the odds endpoint returns every upcoming match for the sport with
 * each bookmaker's prices, so events and odds come from the same payload: it is
 * fetched once, kept for the configured number of seconds, and every event is
 * priced from that copy instead of one request per event.
 *
 * That matters more here than it does for a scraper, because the feed is
 * metered. A request costs "markets x regions" credits and the free plan allows
 * 500 a month, so the payload TTL - not the cron cadence - is what governs
 * spend. At the default three hours, two markets in one region cost roughly 480
 * credits a month.
 *
 * Coverage note: BTTS is not offered by this endpoint at all, so that market
 * returns no rows and the block leaves its table out.
 */
final class OddsApiProvider extends AbstractProvider {

	/**
	 * API root.
	 *
	 * @var string
	 */
	private const BASE_URL = 'https://api.the-odds-api.com/v4';

	/**
	 * Sport key queried.
	 *
	 * @var string
	 */
	private const SPORT = 'soccer_epl';

	/**
	 * Seconds a failure is remembered before another request is attempted.
	 *
	 * @var int
	 */
	private const FAILURE_TTL = 300;

	/**
	 * Transient holding what the feed last reported about the allowance.
	 *
	 * Public because the settings screen reads it to show the remaining credit.
	 *
	 * @var string
	 */
	public const ALLOWANCE_KEY = 'odds_aggregator_oapi_allowance';

	/**
	 * Seconds the reported allowance is kept.
	 *
	 * Generous: it is a display value, and a stale figure with its own
	 * timestamp beats showing nothing at all.
	 *
	 * @var int
	 */
	private const ALLOWANCE_TTL = 604800;

	/**
	 * Internal market key => upstream market key.
	 *
	 * @var array<string,string>
	 */
	private const MARKET_MAP = array(
		'1x2'  => 'h2h',
		'ou25' => 'totals',
	);

	/**
	 * Payloads for this PHP request, keyed by cache key.
	 *
	 * Static because the factory builds a new provider for every call; the
	 * events lookup and every odds lookup in one page view share one fetch.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private static array $payloads = array();

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http            Transport.
	 * @param string     $api_key         API key from settings.
	 * @param string     $regions         Comma-separated bookmaker regions.
	 * @param string[]   $enabled_markets Internal market keys enabled site-wide.
	 * @param int        $ttl             Seconds a fetched payload is reused.
	 */
	public function __construct(
		private readonly HttpClient $http,
		private readonly string $api_key,
		private readonly string $regions = 'us',
		private readonly array $enabled_markets = array( '1x2', 'ou25' ),
		private readonly int $ttl = 10800
	) {}

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'oddsapi';
	}

	/**
	 * Markets the bulk odds endpoint returns.
	 *
	 * @return string[]
	 */
	public function supported_markets(): array {
		return array_keys( self::MARKET_MAP );
	}

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'The Odds API', 'odds-aggregator' );
	}

	/**
	 * Unusable without a key.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== trim( $this->api_key );
	}

	/**
	 * Events currently quoted upstream.
	 *
	 * @return Event[]
	 * @throws ProviderException When the API cannot be read.
	 */
	public function fetch_events(): array {
		$events = array();

		foreach ( $this->payload() as $row ) {
			$event = $this->to_event( $row );

			if ( $event instanceof Event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Prices for one event, read from the shared payload.
	 *
	 * @param Event    $event   Event to price.
	 * @param Market[] $markets Markets to include.
	 * @return OddsLine[]
	 * @throws ProviderException When the API cannot be read.
	 */
	public function fetch_odds( Event $event, array $markets ): array {
		$wanted = array();

		foreach ( $markets as $market ) {
			if ( isset( self::MARKET_MAP[ $market->key() ] ) ) {
				$wanted[ self::MARKET_MAP[ $market->key() ] ] = $market->key();
			}
		}

		if ( array() === $wanted ) {
			return array();
		}

		$row = $this->find_row( $event->id() );

		// The match has started or dropped out of the payload: nothing to price.
		if ( null === $row ) {
			return array();
		}

		$lines = array();

		foreach ( (array) ( $row['bookmakers'] ?? array() ) as $book ) {
			if ( ! is_array( $book ) ) {
				continue;
			}

			$bookmaker_id = $this->normalise_bookmaker( (string) ( $book['title'] ?? $book['key'] ?? '' ) );

			if ( null === $bookmaker_id ) {
				continue;
			}

			foreach ( (array) ( $book['markets'] ?? array() ) as $upstream_market ) {
				if ( ! is_array( $upstream_market ) ) {
					continue;
				}

				$market_key = $wanted[ (string) ( $upstream_market['key'] ?? '' ) ] ?? null;

				if ( null === $market_key ) {
					continue;
				}

				$prices = $this->to_prices(
					$market_key,
					(array) ( $upstream_market['outcomes'] ?? array() ),
					$row
				);

				if ( array() !== $prices ) {
					$lines[] = new OddsLine( $event->id(), $market_key, $bookmaker_id, $prices );
				}
			}
		}

		return $lines;
	}

	/**
	 * Converts an upstream outcome list into internal prices.
	 *
	 * @param string              $market_key Internal market key.
	 * @param array<int,mixed>    $outcomes   Upstream outcomes.
	 * @param array<string,mixed> $row        Upstream event row, for team names.
	 * @return array<string,Odds>
	 */
	private function to_prices( string $market_key, array $outcomes, array $row ): array {
		$prices = array();

		foreach ( $outcomes as $outcome ) {
			if ( ! is_array( $outcome ) ) {
				continue;
			}

			$key = $this->outcome_key( $market_key, (string) ( $outcome['name'] ?? '' ), $row );

			if ( null === $key ) {
				continue;
			}

			// Over/Under is only our market when the line is actually 2.5.
			if ( 'ou25' === $market_key && abs( (float) ( $outcome['point'] ?? 0 ) - 2.5 ) > 0.01 ) {
				continue;
			}

			$odds = $this->make_odds( $outcome['price'] ?? null );

			if ( null !== $odds ) {
				$prices[ $key ] = $odds;
			}
		}

		return $prices;
	}

	/**
	 * Maps an upstream outcome name onto an internal outcome key.
	 *
	 * @param string              $market_key Internal market key.
	 * @param string              $name       Upstream outcome name.
	 * @param array<string,mixed> $row        Upstream event row, for team names.
	 * @return string|null
	 */
	private function outcome_key( string $market_key, string $name, array $row ): ?string {
		if ( 'ou25' === $market_key ) {
			return match ( strtolower( $name ) ) {
				'over'  => 'over',
				'under' => 'under',
				default => null,
			};
		}

		if ( '1x2' !== $market_key ) {
			return null;
		}

		if ( 'draw' === strtolower( $name ) ) {
			return 'draw';
		}

		if ( ( $row['home_team'] ?? null ) === $name ) {
			return 'home';
		}

		if ( ( $row['away_team'] ?? null ) === $name ) {
			return 'away';
		}

		return null;
	}

	/**
	 * Converts an upstream event row into an Event.
	 *
	 * @param mixed $row Upstream row.
	 * @return Event|null
	 */
	private function to_event( mixed $row ): ?Event {
		if ( ! is_array( $row ) || ! isset( $row['id'], $row['home_team'], $row['away_team'] ) ) {
			return null;
		}

		try {
			$kickoff = new DateTimeImmutable(
				(string) ( $row['commence_time'] ?? 'now' ),
				new DateTimeZone( 'UTC' )
			);
		} catch ( Exception $exception ) {
			$kickoff = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}

		return new Event(
			(string) $row['id'],
			'football',
			(string) ( $row['sport_title'] ?? 'Football' ),
			(string) $row['home_team'],
			(string) $row['away_team'],
			$kickoff
		);
	}

	/**
	 * One event row by upstream id.
	 *
	 * @param string $id Upstream event id.
	 * @return array<string,mixed>|null
	 * @throws ProviderException When the API cannot be read.
	 */
	private function find_row( string $id ): ?array {
		foreach ( $this->payload() as $row ) {
			if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The odds payload, fetched at most once per configured TTL.
	 *
	 * @return array<int,mixed>
	 * @throws ProviderException When the API cannot be read.
	 */
	private function payload(): array {
		$key = $this->cache_key();

		if ( isset( self::$payloads[ $key ] ) ) {
			return self::$payloads[ $key ];
		}

		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			self::$payloads[ $key ] = $cached;

			return $cached;
		}

		/*
		 * A revoked key or a rejected request must not be retried on every cron
		 * tick for the rest of the month. The marker lives in a key space keyed
		 * by the API key, so pasting a new key clears it at once and a settings
		 * probe still gets a real answer.
		 */
		$failure = get_transient( $key . '_fail' );

		if ( is_string( $failure ) && '' !== $failure ) {
			throw new ProviderException( wp_kses( $failure, array() ) );
		}

		try {
			$payload = $this->fetch_payload();
		} catch ( ProviderException $exception ) {
			set_transient( $key . '_fail', $exception->getMessage(), self::FAILURE_TTL );

			throw $exception;
		}

		/**
		 * Filters how long a The Odds API payload is reused, in seconds.
		 *
		 * Defaults to the interval set on the Performance tab. One fetch costs
		 * one credit per market per region.
		 *
		 * @param int $ttl Seconds.
		 */
		set_transient( $key, $payload, (int) apply_filters( 'odds_aggregator_oddsapi_ttl', $this->ttl ) );
		self::$payloads[ $key ] = $payload;

		return $payload;
	}

	/**
	 * Calls the odds endpoint once.
	 *
	 * @return array<int,mixed>
	 * @throws ProviderException When the key is missing or the call fails.
	 */
	private function fetch_payload(): array {
		if ( ! $this->is_available() ) {
			throw new ProviderException( 'The Odds API needs an API key; set one in the plugin settings.' );
		}

		// add_query_arg() does not encode values, so each one is encoded here,
		// exactly once.
		$url = add_query_arg(
			array(
				'apiKey'     => rawurlencode( $this->api_key ),
				'regions'    => rawurlencode( $this->regions ),
				'markets'    => rawurlencode( implode( ',', $this->upstream_markets() ) ),
				'oddsFormat' => 'decimal',
			),
			sprintf( '%s/sports/%s/odds', self::BASE_URL, $this->sport() )
		);

		try {
			$response = $this->http->get_json_meta( $url, $this->id() );
		} catch ( HttpException $exception ) {
			throw new ProviderException(
				sprintf( 'The Odds API request failed: %s', wp_kses( $exception->getMessage(), array() ) ),
				(int) $exception->getCode(),
				$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- previous exception, not output.
			);
		}

		$this->remember_allowance( $response['headers'] );

		return $response['data'];
	}

	/**
	 * Records the allowance the feed reports alongside every response.
	 *
	 * On a metered plan this is the number an administrator actually wants to
	 * see, and it costs nothing extra to capture.
	 *
	 * @param array<string,string> $headers Response headers.
	 * @return void
	 */
	private function remember_allowance( array $headers ): void {
		$remaining = (string) ( $headers['x-requests-remaining'] ?? '' );

		if ( '' === $remaining ) {
			return;
		}

		set_transient(
			self::ALLOWANCE_KEY,
			array(
				'remaining' => (int) $remaining,
				'used'      => (int) ( $headers['x-requests-used'] ?? 0 ),
				'last'      => (int) ( $headers['x-requests-last'] ?? 0 ),
				'checked'   => time(),
			),
			self::ALLOWANCE_TTL
		);
	}

	/**
	 * Cache key for the current configuration.
	 *
	 * The API key is part of it so that a newly entered key cannot be validated
	 * against a payload the previous key paid for.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		$parts = array(
			$this->sport(),
			$this->regions,
			implode( ',', $this->upstream_markets() ),
			hash( 'sha256', $this->api_key ),
		);

		return 'odds_aggregator_oapi_' . md5( implode( '|', $parts ) );
	}

	/**
	 * Upstream market keys to request.
	 *
	 * Derived from the markets enabled site-wide rather than from the markets a
	 * single caller asked for: one market set means one cache key, and therefore
	 * one paid request serving every caller.
	 *
	 * @return string[]
	 */
	private function upstream_markets(): array {
		$markets = array();

		foreach ( $this->enabled_markets as $key ) {
			if ( isset( self::MARKET_MAP[ (string) $key ] ) ) {
				$markets[] = self::MARKET_MAP[ (string) $key ];
			}
		}

		$markets = array_unique( $markets );
		sort( $markets );

		// Every enabled market may be one this feed does not carry, BTTS being
		// the obvious case. Ask for the cheapest market anyway, or there would
		// be no events to choose from at all.
		return array() === $markets ? array( 'h2h' ) : array_values( $markets );
	}

	/**
	 * Sport key queried upstream.
	 *
	 * @return string
	 */
	private function sport(): string {
		/**
		 * Filters the sport queried at The Odds API.
		 *
		 * Any sport key the feed supports works, e.g. "soccer_uefa_champs_league".
		 *
		 * @param string $sport Upstream sport key.
		 */
		return (string) apply_filters( 'odds_aggregator_oddsapi_sport', self::SPORT );
	}
}
