<?php
/**
 * Prices offered by one bookmaker on one market.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Immutable row of the comparison table: event x market x bookmaker.
 */
final class OddsLine {

	/**
	 * Constructor.
	 *
	 * @param string             $event_id     Event id.
	 * @param string             $market_key   Market key.
	 * @param string             $bookmaker_id Bookmaker id.
	 * @param array<string,Odds> $prices       Outcome key => price.
	 */
	public function __construct(
		private readonly string $event_id,
		private readonly string $market_key,
		private readonly string $bookmaker_id,
		private readonly array $prices
	) {}

	/**
	 * Event id.
	 *
	 * @return string
	 */
	public function event_id(): string {
		return $this->event_id;
	}

	/**
	 * Market key.
	 *
	 * @return string
	 */
	public function market_key(): string {
		return $this->market_key;
	}

	/**
	 * Bookmaker id.
	 *
	 * @return string
	 */
	public function bookmaker_id(): string {
		return $this->bookmaker_id;
	}

	/**
	 * All prices, keyed by outcome.
	 *
	 * @return array<string,Odds>
	 */
	public function prices(): array {
		return $this->prices;
	}

	/**
	 * Price for one outcome, or null when the bookmaker does not quote it.
	 *
	 * @param string $outcome_key Outcome key.
	 * @return Odds|null
	 */
	public function price( string $outcome_key ): ?Odds {
		return $this->prices[ $outcome_key ] ?? null;
	}

	/**
	 * Most recent fetch timestamp across the row, or 0 when empty.
	 *
	 * @return int
	 */
	public function fetched_at(): int {
		$timestamps = array_map(
			static fn( Odds $odds ): int => $odds->fetched_at(),
			array_values( $this->prices )
		);

		return array() === $timestamps ? 0 : max( $timestamps );
	}

	/**
	 * Rebuilds a row from its array form.
	 *
	 * Used when reading back from the cache, where only primitives are stored
	 * so that a class change cannot leave unreadable objects behind.
	 *
	 * @param array<string,mixed> $data Array produced by {@see OddsLine::to_array()}.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$prices = array();

		foreach ( (array) ( $data['prices'] ?? array() ) as $outcome_key => $price ) {
			if ( ! is_array( $price ) ) {
				continue;
			}

			$odds = Odds::from_array( $price );

			if ( $odds instanceof Odds ) {
				$prices[ (string) $outcome_key ] = $odds;
			}
		}

		return new self(
			(string) ( $data['eventId'] ?? '' ),
			(string) ( $data['marketKey'] ?? '' ),
			(string) ( $data['bookmaker'] ?? '' ),
			$prices
		);
	}

	/**
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'eventId'   => $this->event_id,
			'marketKey' => $this->market_key,
			'bookmaker' => $this->bookmaker_id,
			'prices'    => array_map(
				static fn( Odds $odds ): array => $odds->to_array(),
				$this->prices
			),
			'fetchedAt' => $this->fetched_at(),
		);
	}
}
