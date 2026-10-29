<?php
/**
 * Tests for the cached event read path.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Odds;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * OddsRepository::events() and refresh_events().
 */
final class OddsRepositoryTest extends TestCase {

	/**
	 * Resets the in-memory WordPress state.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']    = array( Settings::OPTION => array( 'provider' => 'mock' ) );
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_cron']       = array();
		$GLOBALS['wpdb']->rows                      = array();
	}

	/**
	 * Builds a repository over the sample provider.
	 *
	 * @return OddsRepository
	 */
	private function repository(): OddsRepository {
		return new OddsRepository(
			new ProviderFactory( new Settings(), new HttpClient( new RateLimiter(), new RetryPolicy() ) ),
			new OddsCache(),
			new MarketRegistry()
		);
	}

	/**
	 * Stores an event list that is past its fresh window.
	 */
	private function seed_stale_events(): void {
		set_transient(
			'oa_1_' . OddsCache::key( 'events', 'mock' ),
			array(
				'data'      => array(
					array(
						'id'      => 'cached-event',
						'league'  => 'Cached League',
						'home'    => 'Home',
						'away'    => 'Away',
						'kickoff' => '2030-01-01T15:00:00+00:00',
					),
				),
				'stored_at' => time() - 3600,
			)
		);
	}

	/**
	 * A stale list is served as is and a background refresh is queued.
	 */
	public function test_stale_events_are_served_without_fetching(): void {
		$this->seed_stale_events();

		$events = $this->repository()->events();

		$this->assertSame( array( 'cached-event' ), array_map( static fn( $event ) => $event->id(), $events ) );
		$this->assertSame( OddsRepository::REFRESH_EVENTS_HOOK, $GLOBALS['odds_aggregator_test_cron'][0]['hook'] ?? null );
	}

	/**
	 * Only one background refresh is queued for repeated stale reads.
	 */
	public function test_stale_reads_queue_one_refresh(): void {
		$this->seed_stale_events();
		$repository = $this->repository();

		$repository->events();
		$repository->events();

		$this->assertCount( 1, $GLOBALS['odds_aggregator_test_cron'] );
	}

	/**
	 * A cold cache is filled from the provider.
	 */
	public function test_cold_cache_fetches_from_provider(): void {
		$events = $this->repository()->events();

		$this->assertNotEmpty( $events );
		$this->assertNotFalse( get_transient( 'oa_1_' . OddsCache::key( 'events', 'mock' ) ) );
		$this->assertSame( array(), $GLOBALS['odds_aggregator_test_cron'] );
	}

	/**
	 * Refreshing replaces a stale list with a fresh fetch.
	 */
	public function test_refresh_replaces_stale_events(): void {
		$this->seed_stale_events();

		$ids = array_map( static fn( $event ) => $event->id(), $this->repository()->refresh_events() );

		$this->assertNotContains( 'cached-event', $ids );
		$this->assertContains( 'eng-ars-che', $ids );
	}
}
