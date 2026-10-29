<?php
/**
 * Supported odds display formats.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Odds;

/**
 * The three formats odds can be displayed in.
 *
 * Decimal is the canonical internal representation; the other two are produced
 * at the display edge by {@see OddsConverter}.
 */
enum OddsFormat: string {

	case Decimal    = 'decimal';
	case Fractional = 'fractional';
	case American   = 'american';

	/**
	 * Translated label for use in the admin and the block UI.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Decimal    => __( 'Decimal', 'odds-aggregator' ),
			self::Fractional => __( 'Fractional', 'odds-aggregator' ),
			self::American   => __( 'American', 'odds-aggregator' ),
		};
	}

	/**
	 * Resolves a stored string to a format, falling back to decimal.
	 *
	 * @param string|null $value Raw value from settings or a block attribute.
	 * @return self
	 */
	public static function from_string_or_default( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Decimal;
	}
}
