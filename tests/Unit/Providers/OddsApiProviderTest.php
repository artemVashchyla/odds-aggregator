<?php
/**
 * Tests for The Odds API provider.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Providers\OddsApiProvider;
use OddsAggregator\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping from the upstream payload to events and odds rows, and
 * the request economy that makes a metered feed affordable.
 *
 * The fixture is a real response for the us region, trimmed to two matches. It
 * is normally seeded into the provider's payload cache, exactly as a previous
 * request would have left it, so no HTTP happens. Tests that care about the
 * number of requests queue a canned response instead and count the calls.
 *
 * Every test uses its own API key or region, because the provider's per-request
 * memo is static and keyed by configuration: distinct keys keep tests from
 * shadowing one another.
 */
final class OddsApiProviderTest extends TestCase {

	/**
	 * Clears the in-memory transients and the transport stub.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_http']       = array(
			'responses' => array(),
			'requests'  => array(),
		);
	}

	/**
	 * Saved upstream response.
	 *
	 * @return string
	 */
	private function fixture(): string {
		return (string) file_get_contents( ODDS_AGGREGATOR_FIXTURES . 'oddsapi-soccer-epl.json' );
	}

	/**
	 * Transport over the stubbed WordPress HTTP functions.
	 *
	 * @return HttpClient
	 */
	private function http(): HttpClient {
		return new HttpClient( new RateLimiter(), new RetryPolicy() );
	}

	/**
	 * Mirrors the provider's own cache key, so a test can seed it.
	 *
	 * @param string $api_key API key.
	 * @param string $regions Regions.
	 * @param string $markets Upstream market list.
	 * @return string
	 */
	private function cache_key( string $api_key, string $regions = 'us', string $markets = 'h2h,totals' ): string {
		$parts = array( 'soccer_epl', $regions, $markets, hash( 'sha256', $api_key ) );

		return 'odds_aggregator_oapi_' . md5( implode( '|', $parts ) );
	}

	/**
	 * A provider whose payload cache already holds the fixture.
	 *
	 * @param string $api_key API key, unique per test.
	 * @return OddsApiProvider
	 */
	private function seeded( string $api_key ): OddsApiProvider {
		$GLOBALS['odds_aggregator_test_transients'][ $this->cache_key( $api_key ) ] = json_decode( $this->fixture(), true );

		return new OddsApiProvider( $this->http(), $api_key );
	}

	/**
	 * Queues one successful upstream response.
	 */
	private function queue_fixture(): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $this->fixture(),
		);
	}

	/**
	 * Upstream requests made so far.
	 *
	 * @return int
	 */
	private function calls(): int {
		return count( $GLOBALS['odds_aggregator_test_http']['requests'] );
	}

	/**
	 * The most recently requested URL.
	 *
	 * @return string
	 */
	private function last_url(): string {
		$urls = $GLOBALS['odds_aggregator_test_http']['requests'];

		return array() === $urls ? '' : (string) end( $urls );
	}

	/**
	 * Rows indexed by bookmaker id.
	 *
	 * @param OddsLine[] $lines Price rows.
	 * @return array<string,OddsLine>
	 */
	private function by_bookmaker( array $lines ): array {
		$rows = array();

		foreach ( $lines as $line ) {
			$this->assertInstanceOf( OddsLine::class, $line );
			$rows[ $line->bookmaker_id() ] = $line;
		}

		return $rows;
	}

	/**
	 * Upstream rows become events with UTC kickoffs.
	 */
	public function test_events_map_from_the_payload(): void {
		$events = $this->seeded( 'k-events' )->fetch_events();

		$this->assertCount( 2, $events );
		$this->assertSame( '6601f69175a02f03fb7065aacb538e01', $events[0]->id() );
		$this->assertSame( 'Chelsea v Manchester United', $events[0]->title() );
		$this->assertSame( '2026-10-31 12:30', $events[0]->kickoff()->format( 'Y-m-d H:i' ) );
	}

	/**
	 * Match Result prices map onto internal bookmaker ids and outcome keys.
	 */
	public function test_match_result_prices_map_onto_bookmaker_ids(): void {
		$provider = $this->seeded( 'k-1x2' );
		$event    = $provider->fetch_events()[0];
		$market   = ( new MarketRegistry() )->get( '1x2' );
		$rows     = $this->by_bookmaker( $provider->fetch_odds( $event, array( $market ) ) );

		$this->assertEqualsCanonicalizing(
			array(
				'fanduel',
				'draftkings',
				'betmgm',
				'betrivers',
				'betonlineag',
				'betus',
				'bovada',
				'lowvigag',
				'mybookieag',
			),
			array_keys( $rows )
		);

		$this->assertSame( 1.83, $rows['betmgm']->price( 'home' )->decimal() );
		$this->assertSame( 3.9, $rows['betmgm']->price( 'draw' )->decimal() );
		$this->assertSame( 4.0, $rows['betmgm']->price( 'away' )->decimal() );
		$this->assertSame( 'oddsapi', $rows['betmgm']->price( 'home' )->source() );
	}

	/**
	 * Over/Under is our market only when the line is 2.5.
	 *
	 * Several books in the fixture quote the same market at 3.0; those rows must
	 * not be presented as Over/Under 2.5 prices.
	 */
	public function test_over_under_keeps_only_the_2_5_line(): void {
		$provider = $this->seeded( 'k-ou25' );
		$event    = $provider->fetch_events()[0];
		$market   = ( new MarketRegistry() )->get( 'ou25' );
		$rows     = $this->by_bookmaker( $provider->fetch_odds( $event, array( $market ) ) );

		$this->assertEqualsCanonicalizing(
			array( 'betmgm', 'betrivers', 'mybookieag' ),
			array_keys( $rows )
		);

		$this->assertSame( 1.54, $rows['betmgm']->price( 'over' )->decimal() );
		$this->assertSame( 2.3, $rows['betmgm']->price( 'under' )->decimal() );
	}

	/**
	 * An event that is not in the payload has nothing to price.
	 */
	public function test_unknown_event_has_nothing_to_price(): void {
		$provider = $this->seeded( 'k-missing' );
		$market   = ( new MarketRegistry() )->get( '1x2' );
		$gone     = new Event( 'nope', 'football', 'EPL', 'A', 'B', new \DateTimeImmutable() );

		$this->assertSame( array(), $provider->fetch_odds( $gone, array( $market ) ) );
	}

	/**
	 * This endpoint does not carry Both Teams to Score at all.
	 */
	public function test_btts_produces_no_rows(): void {
		$provider = $this->seeded( 'k-btts' );
		$event    = $provider->fetch_events()[0];
		$market   = ( new MarketRegistry() )->get( 'btts' );

		$this->assertSame( array(), $provider->fetch_odds( $event, array( $market ) ) );
	}

	/**
	 * One upstream request serves the event list and every event's prices.
	 *
	 * This is the regression guard: the provider used to request the whole sport
	 * and discard all but one event, so listing two events and pricing both cost
	 * three metered requests instead of one.
	 */
	public function test_one_request_serves_events_and_every_event(): void {
		$this->queue_fixture();

		$provider = new OddsApiProvider( $this->http(), 'k-batch' );
		$market   = ( new MarketRegistry() )->get( '1x2' );
		$events   = $provider->fetch_events();

		$this->assertCount( 2, $events );

		foreach ( $events as $event ) {
			$this->assertNotSame( array(), $provider->fetch_odds( $event, array( $market ) ) );
		}

		$this->assertSame( 1, $this->calls() );
	}

	/**
	 * Only the markets enabled site-wide are paid for.
	 *
	 * A request costs one credit per market, so asking for Over/Under when it is
	 * switched off would double the bill for data nothing would display.
	 */
	public function test_requested_markets_follow_the_enabled_markets(): void {
		$this->queue_fixture();

		$provider = new OddsApiProvider( $this->http(), 'k-one-market', 'us', array( '1x2' ) );
		$provider->fetch_events();

		$this->assertStringContainsString( 'markets=h2h&', $this->last_url() );
		$this->assertStringNotContainsString( 'totals', $this->last_url() );
	}

	/**
	 * A comma separated list is encoded once, not twice.
	 *
	 * The add_query_arg() function leaves values as given, so the provider
	 * encodes them itself; a second pass would send a literal "%252C" upstream.
	 */
	public function test_market_list_is_encoded_once(): void {
		$this->queue_fixture();

		$provider = new OddsApiProvider( $this->http(), 'k-encoding' );
		$provider->fetch_events();

		$this->assertStringContainsString( 'markets=h2h%2Ctotals', $this->last_url() );
		$this->assertStringNotContainsString( '%252C', $this->last_url() );
	}

	/**
	 * Enabling only markets this feed lacks still lists events.
	 *
	 * Without a fallback the market list would be empty, the request would carry
	 * no markets, and the editor would offer nothing to choose from.
	 */
	public function test_markets_the_feed_lacks_still_list_events(): void {
		$this->queue_fixture();

		$provider = new OddsApiProvider( $this->http(), 'k-only-btts', 'us', array( 'btts' ) );

		$this->assertCount( 2, $provider->fetch_events() );
		$this->assertStringContainsString( 'markets=h2h&', $this->last_url() );
	}

	/**
	 * A newly entered key is never validated against the old key's payload.
	 *
	 * The settings screen probes a key by fetching events; if a cached payload
	 * satisfied that, any typo would be reported as a working key.
	 */
	public function test_a_new_key_is_not_served_the_old_payload(): void {
		$GLOBALS['odds_aggregator_test_transients'][ $this->cache_key( 'k-old' ) ] = json_decode( $this->fixture(), true );
		$this->queue_fixture();

		$provider = new OddsApiProvider( $this->http(), 'k-new' );
		$provider->fetch_events();

		$this->assertSame( 1, $this->calls() );
	}

	/**
	 * Changing region changes what is quoted, so it cannot reuse the payload.
	 */
	public function test_changing_region_refetches(): void {
		$GLOBALS['odds_aggregator_test_transients'][ $this->cache_key( 'k-region', 'us' ) ] = json_decode( $this->fixture(), true );
		$this->queue_fixture();

		$provider = new OddsApiProvider( $this->http(), 'k-region', 'uk' );
		$provider->fetch_events();

		$this->assertSame( 1, $this->calls() );
	}

	/**
	 * Without a key there is nothing to request.
	 */
	public function test_missing_key_throws(): void {
		$provider = new OddsApiProvider( $this->http(), '' );

		$this->assertFalse( $provider->is_available() );
		$this->expectException( ProviderException::class );

		$provider->fetch_events();
	}

	/**
	 * A bookmaker outside the catalogue is dropped rather than displayed.
	 */
	public function test_an_unknown_bookmaker_is_dropped(): void {
		$rows = array(
			array(
				'id'            => 'evt',
				'sport_title'   => 'EPL',
				'home_team'     => 'A',
				'away_team'     => 'B',
				'commence_time' => '2026-11-01T15:00:00Z',
				'bookmakers'    => array(
					array(
						'title'   => 'Pinnacle',
						'markets' => array(
							array(
								'key'      => 'h2h',
								'outcomes' => array(
									array(
										'name'  => 'A',
										'price' => 2.0,
									),
									array(
										'name'  => 'Draw',
										'price' => 3.0,
									),
									array(
										'name'  => 'B',
										'price' => 4.0,
									),
								),
							),
						),
					),
				),
			),
		);

		$GLOBALS['odds_aggregator_test_transients'][ $this->cache_key( 'k-unknown-book' ) ] = $rows;

		$provider = new OddsApiProvider( $this->http(), 'k-unknown-book' );
		$event    = $provider->fetch_events()[0];
		$market   = ( new MarketRegistry() )->get( '1x2' );

		$this->assertSame( array(), $provider->fetch_odds( $event, array( $market ) ) );
	}

	/**
	 * A rejected request is remembered briefly instead of retried at once.
	 *
	 * A revoked key would otherwise be retried on every cron tick, spending the
	 * month's credits on responses that cannot succeed.
	 */
	public function test_a_rejected_request_is_not_retried_immediately(): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => 401 ),
			'headers'  => array(),
			'body'     => '',
		);

		$provider = new OddsApiProvider( $this->http(), 'k-rejected' );

		try {
			$provider->fetch_events();
			$this->fail( 'Expected a ProviderException.' );
		} catch ( ProviderException $exception ) {
			$this->assertSame( 1, $this->calls() );
		}

		try {
			$provider->fetch_events();
			$this->fail( 'Expected a ProviderException.' );
		} catch ( ProviderException $exception ) {
			$this->assertSame( 1, $this->calls() );
		}
	}

	/**
	 * A failed request does not put the API key into the error message.
	 */
	public function test_error_message_does_not_contain_api_key(): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => 401 ),
			'headers'  => array(),
			'body'     => '',
		);

		try {
			( new OddsApiProvider( $this->http(), 'k-secret-123' ) )->fetch_events();
			$this->fail( 'Expected a ProviderException.' );
		} catch ( ProviderException $exception ) {
			$this->assertStringNotContainsString( 'k-secret-123', $exception->getMessage() );
			$this->assertStringContainsString( 'api.the-odds-api.com', $exception->getMessage() );
		}
	}

	/**
	 * The allowance the feed reports is kept for the settings screen.
	 */
	public function test_reported_allowance_is_remembered(): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(
				'x-requests-remaining' => '420',
				'x-requests-used'      => '80',
				'x-requests-last'      => '2',
			),
			'body'     => $this->fixture(),
		);

		( new OddsApiProvider( $this->http(), 'k-allowance' ) )->fetch_events();

		$allowance = $GLOBALS['odds_aggregator_test_transients'][ OddsApiProvider::ALLOWANCE_KEY ] ?? null;

		$this->assertIsArray( $allowance );
		$this->assertSame( 420, $allowance['remaining'] );
		$this->assertSame( 80, $allowance['used'] );
	}
}
