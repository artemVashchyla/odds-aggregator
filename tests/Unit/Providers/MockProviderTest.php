<?php
/**
 * Tests for the fixture provider.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Providers\MockProvider;
use PHPUnit\Framework\TestCase;

/**
 * The default provider has to be dependable: the block, the admin preview and
 * the whole offline experience rest on it.
 */
final class MockProviderTest extends TestCase {

	/**
	 * It offers events to price.
	 */
	public function test_offers_events(): void {
		$events = ( new MockProvider() )->fetch_events();

		$this->assertNotEmpty( $events );
		$this->assertSame( 'football', $events[0]->sport() );
		$this->assertNotSame( '', $events[0]->title() );
	}

	/**
	 * It quotes every enabled market across all ten bookmakers.
	 */
	public function test_quotes_all_markets_and_bookmakers(): void {
		$provider = new MockProvider();
		$event    = $provider->fetch_events()[0];
		$markets  = array_values( ( new MarketRegistry() )->all() );
		$lines    = $provider->fetch_odds( $event, $markets );

		$this->assertCount( 30, $lines, 'Expected 10 bookmakers across 3 markets.' );

		foreach ( $lines as $line ) {
			$this->assertInstanceOf( OddsLine::class, $line );
			$this->assertSame( $event->id(), $line->event_id() );
			$this->assertNotEmpty( $line->prices() );
		}
	}

	/**
	 * Every quoted price is inside the plausible range.
	 */
	public function test_prices_are_plausible(): void {
		$provider = new MockProvider();
		$event    = $provider->fetch_events()[0];
		$markets  = array_values( ( new MarketRegistry() )->all() );

		foreach ( $provider->fetch_odds( $event, $markets ) as $line ) {
			foreach ( $line->prices() as $price ) {
				$this->assertInstanceOf( Odds::class, $price );
				$this->assertTrue( OddsConverter::is_plausible( $price->decimal() ) );
			}
		}
	}

	/**
	 * Repeated calls return identical prices, so the best-price highlight and
	 * the cache do not flicker between requests.
	 */
	public function test_prices_are_deterministic(): void {
		$provider = new MockProvider();
		$event    = $provider->fetch_events()[0];
		$markets  = array( ( new MarketRegistry() )->get( '1x2' ) );

		$first  = $provider->fetch_odds( $event, $markets );
		$second = $provider->fetch_odds( $event, $markets );

		$this->assertSame(
			array_map(
				static fn( OddsLine $line ): array => array_map(
					static fn( Odds $odds ): float => $odds->decimal(),
					$line->prices()
				),
				$first
			),
			array_map(
				static fn( OddsLine $line ): array => array_map(
					static fn( Odds $odds ): float => $odds->decimal(),
					$line->prices()
				),
				$second
			)
		);
	}

	/**
	 * Bookmakers do not all quote the same number, so comparison is meaningful.
	 */
	public function test_bookmakers_differ(): void {
		$provider = new MockProvider();
		$event    = $provider->fetch_events()[0];
		$markets  = array( ( new MarketRegistry() )->get( '1x2' ) );

		$home = array();

		foreach ( $provider->fetch_odds( $event, $markets ) as $line ) {
			$home[] = $line->price( 'home' )->decimal();
		}

		$this->assertGreaterThan( 1, count( array_unique( $home ) ) );
	}
}
