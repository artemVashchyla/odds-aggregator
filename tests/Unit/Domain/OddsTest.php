<?php
/**
 * Tests for the price value object.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Domain;

use InvalidArgumentException;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Odds\OddsConverter;
use PHPUnit\Framework\TestCase;

/**
 * Covers the plausibility guard, comparison and the cache round trip.
 *
 * The guard is what keeps a mis-parsed page from reaching the table: a scraper
 * that reads a shirt number as a price must fail at construction rather than
 * quote 7.0 to a visitor.
 */
final class OddsTest extends TestCase {

	/**
	 * The accessors report what was passed in.
	 */
	public function test_accessors_report_the_construction_values(): void {
		$odds = new Odds( 2.5, 1_700_000_000, 'oddschecker' );

		$this->assertSame( 2.5, $odds->decimal() );
		$this->assertSame( 1_700_000_000, $odds->fetched_at() );
		$this->assertSame( 'oddschecker', $odds->source() );
	}

	/**
	 * The source is optional, because the sample provider has none to give.
	 */
	public function test_the_source_is_optional(): void {
		$this->assertSame( '', ( new Odds( 2.5, 0 ) )->source() );
	}

	/**
	 * Prices at the edges of the plausible range are accepted.
	 */
	public function test_the_range_boundaries_are_accepted(): void {
		$this->assertSame( OddsConverter::MIN_DECIMAL, ( new Odds( OddsConverter::MIN_DECIMAL, 0 ) )->decimal() );
		$this->assertSame( OddsConverter::MAX_DECIMAL, ( new Odds( OddsConverter::MAX_DECIMAL, 0 ) )->decimal() );
	}

	/**
	 * A price outside the range is refused outright.
	 *
	 * @param float $decimal Implausible decimal price.
	 * @dataProvider provide_implausible_prices
	 */
	public function test_an_implausible_price_is_refused( float $decimal ): void {
		$this->expectException( InvalidArgumentException::class );

		new Odds( $decimal, 0 );
	}

	/**
	 * Values a broken parser produces.
	 *
	 * @return array<string,array{0:float}>
	 */
	public static function provide_implausible_prices(): array {
		return array(
			'zero'              => array( 0.0 ),
			'negative'          => array( -2.5 ),
			'evens misread'     => array( 1.0 ),
			'just below floor'  => array( 1.009 ),
			'just above cap'    => array( 1000.01 ),
			'shirt number mess' => array( 99_999.0 ),
		);
	}

	/**
	 * The longer price is the better one for the punter.
	 */
	public function test_a_longer_price_beats_a_shorter_one(): void {
		$long  = new Odds( 3.5, 0 );
		$short = new Odds( 2.0, 0 );

		$this->assertTrue( $long->beats( $short ) );
		$this->assertFalse( $short->beats( $long ) );
	}

	/**
	 * Equal prices do not beat each other, so the first bookmaker quoted keeps
	 * the "best price" marker rather than it flickering between ties.
	 */
	public function test_equal_prices_do_not_beat_each_other(): void {
		$first  = new Odds( 2.0, 0 );
		$second = new Odds( 2.0, 0 );

		$this->assertFalse( $first->beats( $second ) );
		$this->assertFalse( $second->beats( $first ) );
	}

	/**
	 * A price survives the trip through the cache's primitive form.
	 */
	public function test_the_array_form_round_trips(): void {
		$original = new Odds( 2.75, 1_700_000_000, 'oddsapi' );
		$restored = Odds::from_array( $original->to_array() );

		$this->assertInstanceOf( Odds::class, $restored );
		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	/**
	 * The array form uses the keys the REST clients read.
	 */
	public function test_the_array_form_keys_are_stable(): void {
		$this->assertSame(
			array( 'decimal', 'fetchedAt', 'source' ),
			array_keys( ( new Odds( 2.0, 0 ) )->to_array() )
		);
	}

	/**
	 * Missing fields default rather than failing, so a payload written by an
	 * older version still reads.
	 */
	public function test_missing_fields_default(): void {
		$odds = Odds::from_array( array( 'decimal' => 2.0 ) );

		$this->assertInstanceOf( Odds::class, $odds );
		$this->assertSame( 0, $odds->fetched_at() );
		$this->assertSame( '', $odds->source() );
	}

	/**
	 * A stored value that is no longer plausible is dropped on read instead of
	 * throwing, because a cache read must not take a page down.
	 *
	 * @param array<string,mixed> $data Stored price.
	 * @dataProvider provide_unreadable_stored_prices
	 */
	public function test_an_unreadable_stored_price_is_dropped( array $data ): void {
		$this->assertNull( Odds::from_array( $data ) );
	}

	/**
	 * Stored shapes that cannot become a price.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function provide_unreadable_stored_prices(): array {
		return array(
			'empty'       => array( array() ),
			'zero'        => array( array( 'decimal' => 0 ) ),
			'negative'    => array( array( 'decimal' => -1 ) ),
			'above cap'   => array( array( 'decimal' => 5000 ) ),
			'not numeric' => array( array( 'decimal' => 'evens' ) ),
			'null'        => array( array( 'decimal' => null ) ),
		);
	}

	/**
	 * A numeric string reads back as a price, which is how JSON from a feed
	 * sometimes arrives.
	 */
	public function test_a_numeric_string_is_accepted(): void {
		$odds = Odds::from_array(
			array(
				'decimal'   => '2.50',
				'fetchedAt' => '1700000000',
			)
		);

		$this->assertInstanceOf( Odds::class, $odds );
		$this->assertSame( 2.5, $odds->decimal() );
		$this->assertSame( 1_700_000_000, $odds->fetched_at() );
	}
}
