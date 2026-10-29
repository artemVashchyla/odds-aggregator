<?php
/**
 * Tests for the markets each provider declares.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Providers\MockProvider;
use OddsAggregator\Providers\OddsApiProvider;
use OddsAggregator\Providers\OddscheckerProvider;
use PHPUnit\Framework\TestCase;

/**
 * AbstractProvider::supported_markets() and its overrides.
 */
final class SupportedMarketsTest extends TestCase {

	/**
	 * Shared transport.
	 *
	 * @return HttpClient
	 */
	private function http(): HttpClient {
		return new HttpClient( new RateLimiter(), new RetryPolicy() );
	}

	/**
	 * The scraper's listing page carries Match Result only.
	 */
	public function test_oddschecker_quotes_match_result_only(): void {
		$this->assertSame( array( '1x2' ), ( new OddscheckerProvider( $this->http() ) )->supported_markets() );
	}

	/**
	 * The Odds API bulk endpoint has no BTTS market.
	 */
	public function test_odds_api_quotes_match_result_and_totals(): void {
		$this->assertSame( array( '1x2', 'ou25' ), ( new OddsApiProvider( $this->http(), 'key' ) )->supported_markets() );
	}

	/**
	 * Sample data covers every market.
	 */
	public function test_sample_data_quotes_every_market(): void {
		$this->assertNull( ( new MockProvider() )->supported_markets() );
	}

	/**
	 * Sample data quotes the ten UK bookmakers.
	 */
	public function test_sample_data_bookmakers(): void {
		$registry = new BookmakerRegistry();
		$quoted   = ( new MockProvider() )->supported_bookmakers();

		$this->assertCount( 10, $quoted );

		foreach ( $quoted as $id ) {
			$this->assertSame( 'uk', $registry->get( $id )?->region(), $id );
		}
	}

	/**
	 * The scraper quotes the US sportsbooks plus bet365, once each.
	 */
	public function test_oddschecker_bookmakers(): void {
		$this->assertEqualsCanonicalizing(
			array( 'bet365', 'fanduel', 'draftkings', 'betmgm', 'caesars', 'fanatics', 'borgata', 'betrivers' ),
			( new OddscheckerProvider( $this->http() ) )->supported_bookmakers()
		);
	}

	/**
	 * The Odds API's bookmakers depend on the region, so they are not declared.
	 */
	public function test_odds_api_bookmakers_are_unknown(): void {
		$this->assertNull( ( new OddsApiProvider( $this->http(), 'key' ) )->supported_bookmakers() );
	}

	/**
	 * Every default bookmaker has a region and a default URL.
	 */
	public function test_registry_regions_and_default_urls(): void {
		$registry = new BookmakerRegistry( array( 'bet365' => 'https://example.test/aff' ) );

		foreach ( $registry->all() as $bookmaker ) {
			$this->assertContains( $bookmaker->region(), array( 'uk', 'us' ), $bookmaker->id() );
			$this->assertStringStartsWith( 'https://', $registry->default_url( $bookmaker->id() ) );
		}

		$this->assertSame( 'https://example.test/aff', $registry->get( 'bet365' )?->url() );
		$this->assertSame( 'https://www.bet365.com', $registry->default_url( 'bet365' ) );
		$this->assertSame( 'uk', $registry->get( 'bet365' )?->with_url( 'https://x.test' )->region() );
	}
}
