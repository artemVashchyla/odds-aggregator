<?php
/**
 * Tests for the settings screen's source status.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Admin;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Admin\SourceStatus;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * SourceStatus over the sample provider and a failing scraper.
 */
final class SourceStatusTest extends TestCase {

	/**
	 * Builds the status for a provider.
	 *
	 * @param string $provider Provider id.
	 * @return SourceStatus
	 */
	private function source_status( string $provider ): SourceStatus {
		$GLOBALS['odds_aggregator_test_options']    = array( Settings::OPTION => array( 'provider' => $provider ) );
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_cron']       = array();
		$GLOBALS['wpdb']->rows                      = array();
		$GLOBALS['odds_aggregator_test_http']       = array(
			'responses' => array(),
			'requests'  => array(),
		);

		$settings = new Settings();
		$markets  = new MarketRegistry();
		$factory  = new ProviderFactory( $settings, new HttpClient( new RateLimiter(), new RetryPolicy( 1 ) ) );

		return new SourceStatus( $settings, $factory, new OddsRepository( $factory, new OddsCache(), $markets ), $markets );
	}

	/**
	 * Sample data is healthy and quotes every enabled market.
	 */
	public function test_sample_data_is_healthy(): void {
		$status = $this->source_status( 'mock' );

		$this->assertTrue( $status->is_healthy() );
		$this->assertNull( $status->error() );
		$this->assertCount( 10, $status->enabled_quoted_bookmakers() );
		$this->assertSame( array(), $status->unquoted_markets() );
	}

	/**
	 * A scraper that cannot read its page reports the failure and its gaps.
	 */
	public function test_failing_scraper_reports_error_and_coverage(): void {
		$status = $this->source_status( 'oddschecker' );

		$this->assertFalse( $status->is_healthy() );
		$this->assertSame( array(), $status->events() );
		$this->assertSame( 'oddschecker', $status->error()['provider'] ?? null );
		$this->assertCount( 8, $status->enabled_quoted_bookmakers() );
		$this->assertSame( array( 'ou25', 'btts' ), $status->unquoted_markets() );
	}
}
