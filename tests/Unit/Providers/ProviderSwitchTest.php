<?php
/**
 * Tests for blocks surviving a change of data source.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use DateTimeImmutable;
use DateTimeZone;
use OddsAggregator\Admin\Settings;
use OddsAggregator\Blocks\OddsTableRenderer;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * A block stores a provider-specific event id. These tests switch the data
 * source underneath it and check the block still resolves its event.
 */
final class ProviderSwitchTest extends TestCase {

	/**
	 * Oddschecker id of Chelsea v Man Utd in the fixture.
	 *
	 * @var string
	 */
	private const OC_EVENT = '102012695';

	/**
	 * Resets the in-memory WordPress state.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']    = array();
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_cron']       = array();
		$GLOBALS['wpdb']->rows                      = array();
		$GLOBALS['odds_aggregator_test_filters']    = array();
		$GLOBALS['odds_aggregator_test_http']       = array(
			'responses' => array(),
			'requests'  => array(),
		);
	}

	/**
	 * Selects a provider, keeping everything else stored.
	 *
	 * @param string $provider Provider id.
	 */
	private function use_provider( string $provider ): void {
		$GLOBALS['odds_aggregator_test_options'][ Settings::OPTION ] = array( 'provider' => $provider );
	}

	/**
	 * Queues the saved Oddschecker page as the next HTTP response.
	 */
	private function queue_oddschecker_page(): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => (string) file_get_contents( ODDS_AGGREGATOR_FIXTURES . 'oddschecker-premier-league.html' ),
		);
	}

	/**
	 * A repository over the currently selected provider.
	 *
	 * @return OddsRepository
	 */
	private function repository(): OddsRepository {
		return new OddsRepository(
			new ProviderFactory( new Settings(), new HttpClient( new RateLimiter(), new RetryPolicy( 1 ) ) ),
			new OddsCache(),
			new MarketRegistry()
		);
	}

	/**
	 * A block chosen under a live source renders sample odds under sample data.
	 */
	public function test_sample_data_adopts_an_event_from_a_live_source(): void {
		$this->use_provider( 'oddschecker' );
		$this->queue_oddschecker_page();
		$this->repository()->events();

		$this->use_provider( 'mock' );
		$settings = new Settings();
		$markets  = new MarketRegistry();
		$renderer = new OddsTableRenderer( $this->repository(), $settings, new BookmakerRegistry(), $markets );
		$html     = $renderer->render( array( 'eventId' => self::OC_EVENT ) );

		$this->assertStringContainsString( 'Chelsea FC v Manchester United', $html );
		$this->assertStringContainsString( 'data-decimal="', $html );
		$this->assertStringContainsString( 'Sample odds for preview only', $html );
		$this->assertStringNotContainsString( 'temporarily unavailable', $html );
	}

	/**
	 * Between two live sources the same fixture is found under its new id.
	 */
	public function test_same_fixture_is_matched_under_another_live_source(): void {
		$GLOBALS['odds_aggregator_test_options'][ OddsRepository::EVENT_INDEX_OPTION ] = array(
			'oddsapi-hash-1' => array(
				'id'      => 'oddsapi-hash-1',
				'sport'   => 'football',
				'league'  => 'EPL',
				'home'    => 'Chelsea',
				'away'    => 'Man Utd',
				'kickoff' => '2026-10-31T12:30:00+00:00',
			),
		);

		$this->use_provider( 'oddschecker' );
		$this->queue_oddschecker_page();

		$event = $this->repository()->find_event( 'oddsapi-hash-1' );

		$this->assertInstanceOf( Event::class, $event );
		$this->assertSame( self::OC_EVENT, $event->id() );
	}

	/**
	 * An id nobody has ever listed still resolves to nothing.
	 */
	public function test_unknown_id_stays_unresolved(): void {
		$this->use_provider( 'mock' );

		$this->assertNull( $this->repository()->find_event( 'never-seen' ) );
	}

	/**
	 * Team names are compared loosely, kick-off strictly.
	 */
	public function test_fixture_matching_rules(): void {
		$utc     = new DateTimeZone( 'UTC' );
		$kickoff = new DateTimeImmutable( '2026-10-31 12:30', $utc );
		$event   = new Event( 'a', 'football', 'EPL', 'Chelsea FC', 'Manchester United', $kickoff );

		$this->assertTrue( $event->is_same_fixture( new Event( 'b', 'football', 'EPL', 'Chelsea', 'Man Utd', $kickoff ) ) );
		$this->assertTrue( $event->is_same_fixture( new Event( 'c', 'football', 'EPL', 'chelsea', 'Manchester United FC', $kickoff->modify( '+1 hour' ) ) ) );
		$this->assertFalse( $event->is_same_fixture( new Event( 'd', 'football', 'EPL', 'Chelsea', 'Manchester City', $kickoff ) ) );
		$this->assertFalse( $event->is_same_fixture( new Event( 'e', 'football', 'EPL', 'Chelsea', 'Man Utd', $kickoff->modify( '+7 days' ) ) ) );

		$bournemouth = new Event( 'f', 'football', 'EPL', 'AFC Bournemouth', 'Leeds United', $kickoff );

		$this->assertTrue( $bournemouth->is_same_fixture( new Event( 'g', 'football', 'EPL', 'Bournemouth', 'Leeds', $kickoff ) ) );
	}
}
