<?php
/**
 * Tests for the Oddschecker page parser.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use OddsAggregator\Providers\OddscheckerParser;
use OddsAggregator\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * Runs the parser against a saved Oddschecker page, so the parsing logic is
 * verified without touching the network.
 */
final class OddscheckerParserTest extends TestCase {

	/**
	 * Saved listing page.
	 *
	 * @return string
	 */
	private function fixture(): string {
		return (string) file_get_contents( ODDS_AGGREGATOR_FIXTURES . 'oddschecker-premier-league.html' );
	}

	/**
	 * Every match on the page is found, with teams, league and kickoff.
	 */
	public function test_reads_matches(): void {
		$matches = ( new OddscheckerParser() )->matches( $this->fixture() );

		$this->assertCount( 2, $matches );
		$this->assertSame( '102012695', $matches[0]['id'] );
		$this->assertSame( 'Chelsea FC', $matches[0]['home'] );
		$this->assertSame( 'Manchester United', $matches[0]['away'] );
		$this->assertSame( 'English Premier League', $matches[0]['league'] );
		$this->assertSame( '2026-10-31T12:30:00Z', $matches[0]['start'] );
		$this->assertFalse( $matches[0]['in_running'] );
	}

	/**
	 * Prices are indexed by bet type, outcome and bookmaker code.
	 */
	public function test_reads_prices(): void {
		$match = ( new OddscheckerParser() )->matches( $this->fixture() )[0];

		$this->assertSame( 1.833, $match['markets'][1]['HOME']['J3'] );
		$this->assertSame( 3.95, $match['markets'][1]['DRAW']['U6'] );
		$this->assertSame( 4.1, $match['markets'][1]['AWAY']['U2'] );
	}

	/**
	 * A suspended price is never returned.
	 */
	public function test_skips_suspended_prices(): void {
		$match = ( new OddscheckerParser() )->matches( $this->fixture() )[0];

		// The fixture carries an extra SUSPENDED 9.99 from U1; the active 1.83 must win.
		$this->assertSame( 1.83, $match['markets'][1]['HOME']['U1'] );
	}

	/**
	 * The bookmaker directory maps codes to brands.
	 */
	public function test_reads_bookmaker_directory(): void {
		$books = ( new OddscheckerParser() )->bookmakers( $this->fixture() );

		$this->assertSame( 'FanDuel', $books['U1'] );
		$this->assertSame( 'Bet365', $books['J3'] );
	}

	/**
	 * A page without the data block - e.g. a Cloudflare challenge - is an error.
	 */
	public function test_page_without_data_throws(): void {
		$this->expectException( ProviderException::class );

		( new OddscheckerParser() )->matches( '<html><title>Just a moment...</title></html>' );
	}
}
