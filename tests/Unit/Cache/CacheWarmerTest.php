<?php
/**
 * Tests for the cron cache warmer.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Cache;

use DateTimeImmutable;
use OddsAggregator\Admin\Settings;
use OddsAggregator\Cache\CacheWarmer;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\AbstractProvider;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * CacheWarmer::run() over a provider with more events than the old batch size.
 */
final class CacheWarmerTest extends TestCase {

	/**
	 * Events the stub provider lists.
	 *
	 * @var int
	 */
	public const EVENT_COUNT = 8;

	/**
	 * Registers a provider with EVENT_COUNT events and selects it.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']    = array( Settings::OPTION => array( 'provider' => 'many' ) );
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_cron']       = array();
		$GLOBALS['wpdb']->rows                      = array();
		$GLOBALS['odds_aggregator_test_filters']    = array(
			'odds_aggregator_providers' => static fn( array $builders ): array => $builders + array(
				'many' => static fn() => new class() extends AbstractProvider {
					/**
					 * Machine id.
					 *
					 * @return string
					 */
					public function id(): string {
						return 'many';
					}

					/**
					 * Label.
					 *
					 * @return string
					 */
					public function label(): string {
						return 'Many';
					}

					/**
					 * Events.
					 *
					 * @return Event[]
					 */
					public function fetch_events(): array {
						return array_map(
							static fn( int $i ): Event => new Event( 'e' . $i, 'football', 'L', 'H' . $i, 'A' . $i, new DateTimeImmutable() ),
							range( 1, CacheWarmerTest::EVENT_COUNT )
						);
					}

					/**
					 * Odds.
					 *
					 * @param Event $event   Event.
					 * @param array $markets Markets.
					 * @return OddsLine[]
					 */
					public function fetch_odds( Event $event, array $markets ): array {
						return array( new OddsLine( $event->id(), '1x2', 'bet365', array( 'home' => $this->make_odds( 2.0 ) ) ) );
					}
				},
			),
		);
	}

	/**
	 * Clears the registered filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['odds_aggregator_test_filters'] = array();
	}

	/**
	 * One run caches odds for every listed event.
	 */
	public function test_run_warms_every_event(): void {
		$settings   = new Settings();
		$repository = new OddsRepository(
			new ProviderFactory( $settings, new HttpClient( new RateLimiter(), new RetryPolicy() ) ),
			new OddsCache(),
			new MarketRegistry()
		);

		( new CacheWarmer( $repository, $settings ) )->run();

		$markets = $settings->markets();
		sort( $markets );

		foreach ( range( 1, self::EVENT_COUNT ) as $i ) {
			$this->assertNotFalse(
				get_transient( 'oa_1_' . OddsCache::key( 'odds', 'many', 'e' . $i, implode( ',', $markets ) ) ),
				'e' . $i
			);
		}
	}
}
