<?php
/**
 * Live contract test against The Odds API.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Integration;

use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Providers\OddsApiProvider;
use OddsAggregator\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * Checks the assumptions the provider makes about the real upstream payload.
 *
 * The unit tests pin the mapping against a recorded response, which cannot tell
 * us when the feed changes shape - a renamed field or a dropped market would
 * pass there and empty every table in production. This suite answers that one
 * question, and nothing else.
 *
 * It is opt-in. The feed is metered, so the suite is skipped unless
 * ODDS_AGGREGATOR_API_KEY is set:
 *
 *     ODDS_AGGREGATOR_API_KEY=... composer test:integration
 *
 * The whole class costs two credits: one bulk fetch, reused across the
 * assertions through the provider's own per-request memo, plus a rejected
 * request that is not billed. An empty payload is a skip, not a failure,
 * because the league it queries has an off-season.
 */
final class OddsApiLiveTest extends TestCase {

	/**
	 * API key under test.
	 *
	 * @var string
	 */
	private string $api_key = '';

	/**
	 * Skips the suite unless a key is configured.
	 */
	protected function setUp(): void {
		$key = (string) getenv( 'ODDS_AGGREGATOR_API_KEY' );

		if ( '' === trim( $key ) ) {
			$this->markTestSkipped( 'Set ODDS_AGGREGATOR_API_KEY to run the live The Odds API tests.' );
		}

		$this->api_key = trim( $key );

		$GLOBALS['odds_aggregator_test_filters'] = array();
	}

	/**
	 * Provider wired to the live transport.
	 *
	 * @param string $api_key API key to use.
	 * @return OddsApiProvider
	 */
	private function provider( string $api_key ): OddsApiProvider {
		return new OddsApiProvider(
			new HttpClient( new RateLimiter( 60 ), new RetryPolicy( 2, 500, 2000 ), 20 ),
			$api_key
		);
	}

	/**
	 * The payload still maps onto events, and one fetch serves them all.
	 *
	 * @return Event[]
	 */
	public function test_events_are_fetched_in_a_single_request(): array {
		$GLOBALS['odds_aggregator_live_requests'] = array();

		$provider = $this->provider( $this->api_key );
		$events   = $provider->fetch_events();

		$this->assertCount( 1, $GLOBALS['odds_aggregator_live_requests'], 'The bulk endpoint must be called once.' );
		$this->assertStringContainsString( 'api.the-odds-api.com/v4/sports/soccer_epl/odds', $GLOBALS['odds_aggregator_live_requests'][0] );
		$this->assertContainsOnlyInstancesOf( Event::class, $events );

		if ( array() === $events ) {
			$this->markTestSkipped( 'The feed returned no upcoming matches; the league is probably between seasons.' );
		}

		foreach ( $events as $event ) {
			$this->assertNotSame( '', $event->id() );
			$this->assertNotSame( '', $event->home(), $event->id() );
			$this->assertNotSame( '', $event->away(), $event->id() );
			$this->assertSame( 'football', $event->sport() );
			$this->assertGreaterThan( 0, $event->kickoff()->getTimestamp(), $event->id() );
		}

		return $events;
	}

	/**
	 * Prices still map onto the internal model, and no second request is paid
	 * for to get them.
	 *
	 * @param Event[] $events Events from the same payload.
	 * @depends test_events_are_fetched_in_a_single_request
	 */
	public function test_odds_map_onto_the_internal_model( array $events ): void {
		$GLOBALS['odds_aggregator_live_requests'] = array();

		$provider   = $this->provider( $this->api_key );
		$markets    = ( new MarketRegistry() )->only( array( '1x2', 'ou25' ) );
		$bookmakers = ( new BookmakerRegistry() )->ids();
		$lines      = array();

		foreach ( $events as $event ) {
			$lines = array_merge( $lines, $provider->fetch_odds( $event, $markets ) );
		}

		$this->assertSame( array(), $GLOBALS['odds_aggregator_live_requests'], 'Pricing must reuse the payload already fetched.' );
		$this->assertContainsOnlyInstancesOf( OddsLine::class, $lines );
		$this->assertNotSame( array(), $lines, 'The feed quoted no prices at all for any upcoming match.' );

		$seen_markets = array();

		foreach ( $lines as $line ) {
			$this->assertContains( $line->market_key(), array( '1x2', 'ou25' ) );
			$this->assertNotSame( array(), $line->prices(), $line->bookmaker_id() );
			$this->assertContainsOnlyInstancesOf( Odds::class, $line->prices() );

			$seen_markets[ $line->market_key() ] = true;

			foreach ( $line->prices() as $outcome_key => $odds ) {
				$this->assertContains(
					$outcome_key,
					'1x2' === $line->market_key() ? array( 'home', 'draw', 'away' ) : array( 'over', 'under' ),
					sprintf( '%s quoted an unknown outcome.', $line->bookmaker_id() )
				);
				$this->assertTrue(
					OddsConverter::is_plausible( $odds->decimal() ),
					sprintf( '%s quoted %s on %s.', $line->bookmaker_id(), (string) $odds->decimal(), $outcome_key )
				);
			}

			// The provider resolves upstream names through its alias map; every id
			// it produces must exist in the catalogue, or the table has no column
			// to put the price in.
			$this->assertContains(
				$line->bookmaker_id(),
				$bookmakers,
				sprintf( 'The alias map resolved "%s", which is not in bookmakers.json.', $line->bookmaker_id() )
			);
		}

		$this->assertArrayHasKey( '1x2', $seen_markets, 'The head-to-head market returned no rows.' );
	}

	/**
	 * The three-way market is complete at a bookmaker that quotes it, which is
	 * what the "best price" column compares.
	 *
	 * @param Event[] $events Events from the same payload.
	 * @depends test_events_are_fetched_in_a_single_request
	 */
	public function test_the_three_way_market_is_quoted_in_full( array $events ): void {
		$provider = $this->provider( $this->api_key );
		$markets  = ( new MarketRegistry() )->only( array( '1x2' ) );

		foreach ( $events as $event ) {
			foreach ( $provider->fetch_odds( $event, $markets ) as $line ) {
				if ( 3 === count( $line->prices() ) ) {
					$this->assertSame( array( 'home', 'draw', 'away' ), array_keys( $line->prices() ) );

					return;
				}
			}
		}

		$this->fail( 'No bookmaker quoted all three outcomes of the head-to-head market.' );
	}

	/**
	 * A market the endpoint does not carry returns nothing rather than throwing,
	 * which is what lets the block leave that table out.
	 *
	 * @param Event[] $events Events from the same payload.
	 * @depends test_events_are_fetched_in_a_single_request
	 */
	public function test_an_unsupported_market_returns_no_rows( array $events ): void {
		$provider = $this->provider( $this->api_key );

		$this->assertNotContains( 'btts', $provider->supported_markets() );
		$this->assertSame(
			array(),
			$provider->fetch_odds( $events[0], ( new MarketRegistry() )->only( array( 'btts' ) ) )
		);
	}

	/**
	 * The allowance header is still sent and still recorded, because it is the
	 * only number that tells an administrator what the plugin is spending.
	 *
	 * @depends test_events_are_fetched_in_a_single_request
	 */
	public function test_the_reported_allowance_is_recorded(): void {
		$allowance = get_transient( OddsApiProvider::ALLOWANCE_KEY );

		$this->assertIsArray( $allowance, 'The feed stopped sending x-requests-remaining.' );
		$this->assertArrayHasKey( 'remaining', $allowance );
		$this->assertArrayHasKey( 'used', $allowance );
		$this->assertGreaterThan( 0, $allowance['used'] );
		$this->assertGreaterThanOrEqual( 0, $allowance['remaining'] );
	}

	/**
	 * A rejected key surfaces as a provider failure with a readable message, so
	 * the settings screen can say what is wrong instead of showing no events.
	 */
	public function test_a_rejected_key_is_reported(): void {
		$provider = $this->provider( 'definitely-not-a-valid-key' );

		$this->expectException( ProviderException::class );
		$this->expectExceptionMessage( 'The Odds API request failed' );

		$provider->fetch_events();
	}

	/**
	 * A provider with no key never reaches the network at all.
	 */
	public function test_a_missing_key_costs_nothing(): void {
		$GLOBALS['odds_aggregator_live_requests'] = array();

		$provider = $this->provider( '' );

		$this->assertFalse( $provider->is_available() );

		try {
			$provider->fetch_events();

			$this->fail( 'Expected a ProviderException.' );
		} catch ( ProviderException $exception ) {
			$this->assertStringContainsString( 'needs an API key', $exception->getMessage() );
			$this->assertSame( array(), $GLOBALS['odds_aggregator_live_requests'] );
		}
	}
}
