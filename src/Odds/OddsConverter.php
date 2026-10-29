<?php
/**
 * Conversion between fractional, decimal and American odds.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Odds;

use InvalidArgumentException;

/**
 * Pure, stateless odds maths.
 *
 * Decimal is treated as the canonical form everywhere in the plugin: providers
 * normalise to decimal on the way in, and this class converts to the display
 * format on the way out. That keeps a single source of truth and avoids the
 * rounding drift you get from chaining conversions.
 */
final class OddsConverter {

	/**
	 * Lowest decimal price considered a plausible quote.
	 *
	 * @var float
	 */
	public const MIN_DECIMAL = 1.01;

	/**
	 * Highest decimal price considered a plausible quote.
	 *
	 * @var float
	 */
	public const MAX_DECIMAL = 1000.0;

	/**
	 * Standard bookmaker fractional ladder, ascending.
	 *
	 * @var string
	 */
	public const FRACTION_LADDER = '1/100 1/66 1/50 1/40 1/33 1/25 1/20 1/16 1/14 1/12 1/11 1/10 1/9 1/8 2/15 1/7 2/13 1/6 2/11 1/5 2/9 1/4 2/7 3/10 1/3 4/11 2/5 4/9 1/2 8/15 4/7 8/13 4/6 8/11 4/5 5/6 10/11 20/21 1/1 21/20 11/10 6/5 5/4 11/8 6/4 13/8 7/4 15/8 2/1 85/40 9/4 5/2 11/4 3/1 10/3 7/2 4/1 9/2 5/1 11/2 6/1 13/2 7/1 15/2 8/1 17/2 9/1 10/1 11/1 12/1 14/1 16/1 18/1 20/1 22/1 25/1 28/1 33/1 40/1 50/1 66/1 80/1 100/1 125/1 150/1 200/1 250/1 300/1 400/1 500/1 750/1 999/1';

	/**
	 * Not instantiable: every method is static.
	 */
	private function __construct() {}

	/**
	 * Converts fractional odds to decimal.
	 *
	 * @param int $numerator   Fraction numerator, e.g. 5 in 5/2.
	 * @param int $denominator Fraction denominator, e.g. 2 in 5/2.
	 * @return float
	 * @throws InvalidArgumentException When either part is not positive.
	 */
	public static function fractional_to_decimal( int $numerator, int $denominator ): float {
		if ( $numerator <= 0 || $denominator <= 0 ) {
			throw new InvalidArgumentException(
				'Fractional odds require a positive numerator and denominator.'
			);
		}

		return ( $numerator / $denominator ) + 1.0;
	}

	/**
	 * Converts decimal odds to the closest price on the bookmaker ladder.
	 *
	 * @param float $decimal Decimal odds, greater than 1.
	 * @return array{0:int,1:int} Numerator and denominator as bookmakers write them.
	 * @throws InvalidArgumentException When the decimal price is not valid.
	 */
	public static function decimal_to_fractional( float $decimal ): array {
		self::assert_decimal( $decimal );

		$best       = array( 1, 1 );
		$best_error = PHP_FLOAT_MAX;

		foreach ( explode( ' ', self::FRACTION_LADDER ) as $step ) {
			list( $numerator, $denominator ) = array_map( 'intval', explode( '/', $step ) );

			$error = abs( $decimal - self::fractional_to_decimal( $numerator, $denominator ) );

			if ( $error < $best_error - 1.0e-9 ) {
				$best_error = $error;
				$best       = array( $numerator, $denominator );
			}
		}

		return $best;
	}

	/**
	 * Converts decimal odds to American (moneyline) odds.
	 *
	 * @param float $decimal Decimal odds, greater than 1.
	 * @return int Positive for underdogs, negative for favourites.
	 * @throws InvalidArgumentException When the decimal price is not valid.
	 */
	public static function decimal_to_american( float $decimal ): int {
		self::assert_decimal( $decimal );

		if ( $decimal >= 2.0 ) {
			return (int) round( ( $decimal - 1.0 ) * 100.0 );
		}

		return (int) round( -100.0 / ( $decimal - 1.0 ) );
	}

	/**
	 * Converts American (moneyline) odds to decimal.
	 *
	 * @param int $american Moneyline price, at or beyond +/-100.
	 * @return float
	 * @throws InvalidArgumentException When the price falls inside -100..100.
	 */
	public static function american_to_decimal( int $american ): float {
		if ( $american >= 100 ) {
			return ( $american / 100.0 ) + 1.0;
		}

		if ( $american <= -100 ) {
			return ( 100.0 / abs( $american ) ) + 1.0;
		}

		throw new InvalidArgumentException(
			'American odds must be >= 100 or <= -100; -100..100 is not a real price.'
		);
	}

	/**
	 * Converts fractional odds straight to American.
	 *
	 * @param int $numerator   Fraction numerator.
	 * @param int $denominator Fraction denominator.
	 * @return int
	 */
	public static function fractional_to_american( int $numerator, int $denominator ): int {
		return self::decimal_to_american( self::fractional_to_decimal( $numerator, $denominator ) );
	}

	/**
	 * Converts American odds straight to a fraction.
	 *
	 * @param int $american Moneyline price.
	 * @return array{0:int,1:int}
	 */
	public static function american_to_fractional( int $american ): array {
		return self::decimal_to_fractional( self::american_to_decimal( $american ) );
	}

	/**
	 * Renders a decimal price in the requested display format.
	 *
	 * @param float      $decimal Decimal odds.
	 * @param OddsFormat $format  Target format.
	 * @return string
	 */
	public static function format( float $decimal, OddsFormat $format ): string {
		switch ( $format ) {
			case OddsFormat::Fractional:
				list( $numerator, $denominator ) = self::decimal_to_fractional( $decimal );

				return $numerator . '/' . $denominator;

			case OddsFormat::American:
				$american = self::decimal_to_american( $decimal );

				return ( $american > 0 ? '+' : '' ) . $american;

			case OddsFormat::Decimal:
			default:
				self::assert_decimal( $decimal );

				return number_format( $decimal, 2, '.', '' );
		}
	}

	/**
	 * Whether a decimal price is inside the plausible range.
	 *
	 * Used by providers to reject parse errors rather than display nonsense.
	 *
	 * @param float $decimal Decimal odds.
	 * @return bool
	 */
	public static function is_plausible( float $decimal ): bool {
		return $decimal >= self::MIN_DECIMAL && $decimal <= self::MAX_DECIMAL;
	}

	/**
	 * Implied probability of a decimal price, as a fraction of 1.
	 *
	 * @param float $decimal Decimal odds.
	 * @return float
	 */
	public static function implied_probability( float $decimal ): float {
		self::assert_decimal( $decimal );

		return 1.0 / $decimal;
	}

	/**
	 * Guards against prices that cannot be converted.
	 *
	 * @param float $decimal Decimal odds.
	 * @return void
	 * @throws InvalidArgumentException When the price is not greater than 1.
	 */
	private static function assert_decimal( float $decimal ): void {
		if ( ! is_finite( $decimal ) || $decimal <= 1.0 ) {
			throw new InvalidArgumentException(
				'Decimal odds must be a finite number greater than 1.'
			);
		}
	}
}
