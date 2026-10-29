<?php
/**
 * Tests for the odds converter.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Odds;

use InvalidArgumentException;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Odds\OddsFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the conversion maths against known bookmaker prices.
 */
final class OddsConverterTest extends TestCase {

	/**
	 * Known-good prices: fraction, decimal, American.
	 *
	 * @return array<string,array{0:int,1:int,2:float,3:int}>
	 */
	public static function known_prices(): array {
		return array(
			'evens' => array( 1, 1, 2.0, 100 ),
			'5/2'   => array( 5, 2, 3.5, 250 ),
			'9/2'   => array( 9, 2, 5.5, 450 ),
			'1/2'   => array( 1, 2, 1.5, -200 ),
			'4/5'   => array( 4, 5, 1.8, -125 ),
			'2/5'   => array( 2, 5, 1.4, -250 ),
			'1/3'   => array( 1, 3, 1.3333333333, -300 ),
			'10/11' => array( 10, 11, 1.9090909091, -110 ),
			'100/1' => array( 100, 1, 101.0, 10000 ),
		);
	}

	/**
	 * Fractional converts to the expected decimal.
	 *
	 * @param int   $numerator   Numerator.
	 * @param int   $denominator Denominator.
	 * @param float $decimal     Expected decimal.
	 */
	#[DataProvider( 'known_prices' )]
	public function test_fractional_to_decimal( int $numerator, int $denominator, float $decimal ): void {
		$this->assertEqualsWithDelta(
			$decimal,
			OddsConverter::fractional_to_decimal( $numerator, $denominator ),
			0.0000001
		);
	}

	/**
	 * Decimal converts back to the expected fraction.
	 *
	 * @param int   $numerator   Expected numerator.
	 * @param int   $denominator Expected denominator.
	 * @param float $decimal     Decimal input.
	 */
	#[DataProvider( 'known_prices' )]
	public function test_decimal_to_fractional( int $numerator, int $denominator, float $decimal ): void {
		$this->assertSame(
			array( $numerator, $denominator ),
			OddsConverter::decimal_to_fractional( $decimal )
		);
	}

	/**
	 * Decimal converts to the expected American price.
	 *
	 * @param int   $numerator   Unused.
	 * @param int   $denominator Unused.
	 * @param float $decimal     Decimal input.
	 * @param int   $american    Expected American price.
	 */
	#[DataProvider( 'known_prices' )]
	public function test_decimal_to_american( int $numerator, int $denominator, float $decimal, int $american ): void {
		$this->assertSame( $american, OddsConverter::decimal_to_american( $decimal ) );
	}

	/**
	 * American converts back to the expected decimal.
	 *
	 * @param int   $numerator   Unused.
	 * @param int   $denominator Unused.
	 * @param float $decimal     Expected decimal.
	 * @param int   $american    American input.
	 */
	#[DataProvider( 'known_prices' )]
	public function test_american_to_decimal( int $numerator, int $denominator, float $decimal, int $american ): void {
		$this->assertEqualsWithDelta(
			$decimal,
			OddsConverter::american_to_decimal( $american ),
			0.0001
		);
	}

	/**
	 * Decimal survives a round trip through fractional.
	 *
	 * @param int   $numerator   Numerator.
	 * @param int   $denominator Denominator.
	 * @param float $decimal     Decimal input.
	 */
	#[DataProvider( 'known_prices' )]
	public function test_fractional_round_trip( int $numerator, int $denominator, float $decimal ): void {
		list( $round_numerator, $round_denominator ) = OddsConverter::decimal_to_fractional( $decimal );

		$this->assertEqualsWithDelta(
			$decimal,
			OddsConverter::fractional_to_decimal( $round_numerator, $round_denominator ),
			0.0000001
		);
	}

	/**
	 * The simplest equivalent fraction is preferred over a scaled one.
	 */
	public function test_fraction_is_reduced(): void {
		$this->assertSame( array( 1, 2 ), OddsConverter::decimal_to_fractional( 1.5 ) );
		$this->assertSame( array( 1, 1 ), OddsConverter::decimal_to_fractional( 2.0 ) );
	}

	/**
	 * Off-ladder decimals snap to the nearest bookmaker fraction.
	 *
	 * @dataProvider ladder_cases
	 *
	 * @param float  $decimal  Decimal price.
	 * @param string $expected Expected fraction.
	 */
	public function test_fraction_snaps_to_ladder( float $decimal, string $expected ): void {
		$this->assertSame( $expected, OddsConverter::format( $decimal, OddsFormat::Fractional ) );
	}

	/**
	 * Decimal price => ladder fraction.
	 *
	 * @return array<string,array{0:float,1:string}>
	 */
	public static function ladder_cases(): array {
		return array(
			'2.04'   => array( 2.04, '21/20' ),
			'3.47'   => array( 3.47, '5/2' ),
			'3.41'   => array( 3.41, '5/2' ),
			'1.67'   => array( 1.67, '4/6' ),
			'2.50'   => array( 2.5, '6/4' ),
			'3.10'   => array( 3.1, '85/40' ),
			'1.01'   => array( 1.01, '1/100' ),
			'1000.0' => array( 1000.0, '999/1' ),
		);
	}

	/**
	 * Display formatting matches the conventions of each format.
	 */
	public function test_format(): void {
		$this->assertSame( '3.50', OddsConverter::format( 3.5, OddsFormat::Decimal ) );
		$this->assertSame( '5/2', OddsConverter::format( 3.5, OddsFormat::Fractional ) );
		$this->assertSame( '+250', OddsConverter::format( 3.5, OddsFormat::American ) );
		$this->assertSame( '-200', OddsConverter::format( 1.5, OddsFormat::American ) );
	}

	/**
	 * A decimal price of exactly 1 pays nothing and is rejected.
	 */
	public function test_decimal_of_one_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		OddsConverter::decimal_to_american( 1.0 );
	}

	/**
	 * A zero denominator is rejected rather than dividing by zero.
	 */
	public function test_zero_denominator_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		OddsConverter::fractional_to_decimal( 5, 0 );
	}

	/**
	 * American prices inside -100..100 do not exist and are rejected.
	 */
	public function test_american_inside_dead_zone_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		OddsConverter::american_to_decimal( 50 );
	}

	/**
	 * The plausibility guard brackets the range providers may emit.
	 */
	public function test_plausibility_range(): void {
		$this->assertFalse( OddsConverter::is_plausible( 1.0 ) );
		$this->assertTrue( OddsConverter::is_plausible( 1.01 ) );
		$this->assertTrue( OddsConverter::is_plausible( 1000.0 ) );
		$this->assertFalse( OddsConverter::is_plausible( 1000.01 ) );
	}

	/**
	 * Implied probability is the reciprocal of the decimal price.
	 */
	public function test_implied_probability(): void {
		$this->assertEqualsWithDelta( 0.5, OddsConverter::implied_probability( 2.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.25, OddsConverter::implied_probability( 4.0 ), 0.0001 );
	}
}
