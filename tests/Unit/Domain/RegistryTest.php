<?php
/**
 * Tests for the bookmaker and market catalogues.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Domain;

use OddsAggregator\Domain\Bookmaker;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\MarketRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Covers catalogue lookups and the administrator URL overlay.
 */
final class RegistryTest extends TestCase {

	/**
	 * The default catalogue carries ten UK and seven US bookmakers.
	 */
	public function test_ships_uk_and_us_bookmakers(): void {
		$registry = new BookmakerRegistry();

		$this->assertCount( 22, $registry->all() );
		$this->assertNotNull( $registry->get( 'williamhill' ) );
		$this->assertNotNull( $registry->get( 'fanduel' ) );
	}

	/**
	 * Every default bookmaker has a homepage link out of the box.
	 */
	public function test_defaults_have_urls(): void {
		foreach ( ( new BookmakerRegistry() )->all() as $bookmaker ) {
			$this->assertStringStartsWith( 'https://', $bookmaker->url(), $bookmaker->id() );
		}
	}

	/**
	 * Administrator URLs replace the defaults; an empty one does not.
	 */
	public function test_url_override_is_applied(): void {
		$overrides = array(
			'bet365' => 'https://example.test/go/bet365',
			'coral'  => '',
		);
		$registry  = new BookmakerRegistry( $overrides );
		$bookmaker = $registry->get( 'bet365' );

		$this->assertInstanceOf( Bookmaker::class, $bookmaker );
		$this->assertSame( 'https://example.test/go/bet365', $bookmaker->url() );
		$this->assertSame( 'https://www.coral.co.uk', $registry->get( 'coral' )->url() );
	}

	/**
	 * A bookmaker is immutable; changing the URL returns a copy.
	 */
	public function test_with_url_does_not_mutate(): void {
		$original = new Bookmaker( 'coral', 'Coral' );
		$copy     = $original->with_url( 'https://example.test' );

		$this->assertSame( '', $original->url() );
		$this->assertSame( 'https://example.test', $copy->url() );
		$this->assertSame( 'coral', $copy->id() );
	}

	/**
	 * Subsetting preserves the requested order and silently drops unknown ids.
	 */
	public function test_only_preserves_order_and_skips_unknown(): void {
		$registry = new BookmakerRegistry();
		$subset   = $registry->only( array( 'coral', 'bet365', 'does-not-exist' ) );

		$this->assertSame( array( 'coral', 'bet365' ), array_keys( $subset ) );
	}

	/**
	 * The three required markets are present with the right outcome shapes.
	 */
	public function test_markets_have_expected_outcomes(): void {
		$registry = new MarketRegistry();

		$this->assertSame( array( '1x2', 'ou25', 'btts' ), $registry->keys() );
		$this->assertSame( array( 'home', 'draw', 'away' ), $registry->get( '1x2' )->outcome_keys() );
		$this->assertSame( array( 'over', 'under' ), $registry->get( 'ou25' )->outcome_keys() );
		$this->assertSame( array( 'yes', 'no' ), $registry->get( 'btts' )->outcome_keys() );
	}

	/**
	 * Markets vary in outcome count, which is what keeps the model generic.
	 */
	public function test_markets_are_not_all_three_way(): void {
		$registry = new MarketRegistry();
		$counts   = array_map(
			static fn( $market ): int => count( $market->outcomes() ),
			$registry->all()
		);

		$this->assertSame( array( 3, 2, 2 ), array_values( $counts ) );
	}

	/**
	 * An unknown key yields null rather than an error.
	 */
	public function test_unknown_lookups_return_null(): void {
		$this->assertNull( ( new MarketRegistry() )->get( 'handicap' ) );
		$this->assertNull( ( new BookmakerRegistry() )->get( 'nope' ) );
	}
}
