<?php
/**
 * A single price.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

use InvalidArgumentException;
use OddsAggregator\Odds\OddsConverter;

/**
 * Immutable price for one outcome at one bookmaker.
 *
 * Always stored as decimal. The fetch timestamp travels with the price so the
 * front end can show how fresh it is rather than presenting stale data as live.
 */
final class Odds {

	/**
	 * Constructor.
	 *
	 * @param float  $decimal    Decimal price.
	 * @param int    $fetched_at Unix timestamp the price was retrieved.
	 * @param string $source     Provider id the price came from.
	 * @throws InvalidArgumentException When the price is outside the plausible range.
	 */
	public function __construct(
		private readonly float $decimal,
		private readonly int $fetched_at,
		private readonly string $source = ''
	) {
		if ( ! OddsConverter::is_plausible( $decimal ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Implausible decimal price: %s', esc_html( (string) $decimal ) )
			);
		}
	}

	/**
	 * Decimal price.
	 *
	 * @return float
	 */
	public function decimal(): float {
		return $this->decimal;
	}

	/**
	 * Unix timestamp the price was retrieved.
	 *
	 * @return int
	 */
	public function fetched_at(): int {
		return $this->fetched_at;
	}

	/**
	 * Provider id the price came from.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Whether this price is better than another for the punter.
	 *
	 * @param Odds $other Price to compare against.
	 * @return bool
	 */
	public function beats( Odds $other ): bool {
		return $this->decimal > $other->decimal;
	}

	/**
	 * Rebuilds a price from its array form.
	 *
	 * @param array<string,mixed> $data Array produced by {@see Odds::to_array()}.
	 * @return self|null Null when the stored value is no longer plausible.
	 */
	public static function from_array( array $data ): ?self {
		$decimal = (float) ( $data['decimal'] ?? 0 );

		if ( ! OddsConverter::is_plausible( $decimal ) ) {
			return null;
		}

		return new self( $decimal, (int) ( $data['fetchedAt'] ?? 0 ), (string) ( $data['source'] ?? '' ) );
	}

	/**
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'decimal'   => $this->decimal,
			'fetchedAt' => $this->fetched_at,
			'source'    => $this->source,
		);
	}
}
