<?php
/**
 * Catalogue of supported markets.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Registry of the markets the plugin can display.
 *
 * Markets differ in outcome count on purpose: 1X2 has three, Over/Under and
 * BTTS have two. Everything downstream iterates outcomes rather than assuming
 * a shape, which is what makes adding a new market a data-only change.
 */
final class MarketRegistry {

	/**
	 * Resolved markets, keyed by market key.
	 *
	 * @var array<string,Market>|null
	 */
	private ?array $markets = null;

	/**
	 * All supported markets, keyed by key.
	 *
	 * @return array<string,Market>
	 */
	public function all(): array {
		if ( null !== $this->markets ) {
			return $this->markets;
		}

		$markets = array(
			'1x2'  => new Market(
				'1x2',
				__( 'Match Result (1X2)', 'odds-aggregator' ),
				array(
					new Outcome( 'home', __( 'Home', 'odds-aggregator' ) ),
					new Outcome( 'draw', __( 'Draw', 'odds-aggregator' ) ),
					new Outcome( 'away', __( 'Away', 'odds-aggregator' ) ),
				)
			),
			'ou25' => new Market(
				'ou25',
				__( 'Over/Under 2.5 Goals', 'odds-aggregator' ),
				array(
					new Outcome( 'over', __( 'Over 2.5', 'odds-aggregator' ) ),
					new Outcome( 'under', __( 'Under 2.5', 'odds-aggregator' ) ),
				)
			),
			'btts' => new Market(
				'btts',
				__( 'Both Teams to Score', 'odds-aggregator' ),
				array(
					new Outcome( 'yes', __( 'Yes', 'odds-aggregator' ) ),
					new Outcome( 'no', __( 'No', 'odds-aggregator' ) ),
				)
			),
		);

		/**
		 * Filters the market catalogue.
		 *
		 * @param array<string,Market> $markets Markets keyed by key.
		 */
		$filtered = apply_filters( 'odds_aggregator_markets', $markets );

		$this->markets = array_filter(
			is_array( $filtered ) ? $filtered : $markets,
			static fn( $market ): bool => $market instanceof Market
		);

		return $this->markets;
	}

	/**
	 * One market by key, or null when unknown.
	 *
	 * @param string $key Market key.
	 * @return Market|null
	 */
	public function get( string $key ): ?Market {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Subset of the catalogue, in the order requested.
	 *
	 * @param string[] $keys Market keys.
	 * @return array<string,Market>
	 */
	public function only( array $keys ): array {
		$subset = array();

		foreach ( $keys as $key ) {
			$market = $this->get( (string) $key );

			if ( $market instanceof Market ) {
				$subset[ $market->key() ] = $market;
			}
		}

		return $subset;
	}

	/**
	 * All supported market keys.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->all() );
	}
}
