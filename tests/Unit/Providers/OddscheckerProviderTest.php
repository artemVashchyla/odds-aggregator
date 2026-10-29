<?php
/**
 * Tests for the Oddschecker provider.
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
use OddsAggregator\Providers\OddscheckerParser;
use OddsAggregator\Providers\OddscheckerProvider;
use OddsAggregator\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping from parsed page data to events and odds rows.
 *
 * The parsed page is seeded into the provider's page cache, exactly as a
 * previous request would have left it, so no HTTP request is made.
 */
final class OddscheckerProviderTest extends TestCase {

	/**
	 * Builds a provider whose page cache holds the saved fixture.
	 *
	 * @return OddscheckerProvider
	 */
	private function provider(): OddscheckerProvider {
		$html    = (string) file_get_contents( ODDS_AGGREGATOR_FIXTURES . 'oddschecker-premier-league.html' );
		$matches = ( new OddscheckerParser() )->matches( $html );
		$key     = 'odds_aggregator_oc_' . md5( 'https://www.oddschecker.com/us/soccer/premier-league' );

		$GLOBALS['odds_aggregator_test_transients'][ $key ] = $matches;

		return new OddscheckerProvider( new HttpClient( new RateLimiter(), new RetryPolicy() ) );
	}

	/**
	 * Matches become events with UTC kickoffs.
	 */
	public function test_events(): void {
		$events = $this->provider()->fetch_events();

		$this->assertCount( 2, $events );
		$this->assertSame( '102012695', $events[0]->id() );
		$this->assertSame( 'Chelsea FC v Manchester United', $events[0]->title() );
		$this->assertSame( '2026-10-31 12:30', $events[0]->kickoff()->format( 'Y-m-d H:i' ) );
	}

	/**
	 * 1X2 prices are mapped onto internal bookmaker ids and outcome keys.
	 */
	public function test_match_result_odds(): void {
		$provider = $this->provider();
		$event    = $provider->fetch_events()[0];
		$market   = ( new MarketRegistry() )->get( '1x2' );
		$lines    = $provider->fetch_odds( $event, array( $market ) );
		$by_book  = array();

		foreach ( $lines as $line ) {
			$this->assertInstanceOf( OddsLine::class, $line );
			$by_book[ $line->bookmaker_id() ] = $line;
		}

		$this->assertEqualsCanonicalizing(
			array( 'bet365', 'fanduel', 'draftkings', 'betmgm', 'caesars', 'fanatics', 'betrivers', 'borgata' ),
			array_keys( $by_book )
		);

		$this->assertSame( 1.833, $by_book['bet365']->price( 'home' )->decimal() );
		$this->assertSame( 3.8, $by_book['bet365']->price( 'draw' )->decimal() );
		$this->assertSame( 4.0, $by_book['bet365']->price( 'away' )->decimal() );
		$this->assertSame( 'oddschecker', $by_book['bet365']->price( 'home' )->source() );
	}

	/**
	 * Markets the listing page does not carry produce no rows.
	 */
	public function test_unsupported_market_is_empty(): void {
		$provider = $this->provider();
		$event    = $provider->fetch_events()[0];
		$market   = ( new MarketRegistry() )->get( 'btts' );

		$this->assertSame( array(), $provider->fetch_odds( $event, array( $market ) ) );
	}

	/**
	 * An event that is no longer on the page has nothing to price.
	 */
	public function test_unknown_event_is_empty(): void {
		$provider = $this->provider();
		$market   = ( new MarketRegistry() )->get( '1x2' );
		$gone     = new Event( '1', 'football', 'x', 'A', 'B', new \DateTimeImmutable() );

		$this->assertSame( array(), $provider->fetch_odds( $gone, array( $market ) ) );
	}

	/**
	 * A 403 from Cloudflare is reported as a block, not as a bare status code.
	 */
	public function test_forbidden_is_explained(): void {
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_http']       = array(
			'responses' => array(
				array(
					'response' => array( 'code' => 403 ),
					'headers'  => array(),
					'body'     => '<html><title>Just a moment...</title></html>',
				),
			),
			'requests'  => array(),
		);

		$provider = new OddscheckerProvider( new HttpClient( new RateLimiter(), new RetryPolicy( 1 ) ) );

		try {
			$provider->fetch_events();
			$this->fail( 'A 403 must throw.' );
		} catch ( ProviderException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
			$this->assertStringContainsString( 'HTTP 403', $exception->getMessage() );
			$this->assertStringContainsString( 'Cloudflare', $exception->getMessage() );
		}
	}
}
