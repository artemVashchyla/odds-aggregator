<?php
/**
 * A betting market.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Immutable market definition.
 *
 * A market is just a key plus an ordered list of outcomes, which is what makes
 * the model generic: 1X2 has three outcomes, Over/Under and BTTS have two, and
 * nothing downstream assumes a particular count.
 */
final class Market {

	/**
	 * Constructor.
	 *
	 * @param string    $key      Machine key, e.g. "1x2".
	 * @param string    $label    Human readable label.
	 * @param Outcome[] $outcomes Ordered outcomes.
	 */
	public function __construct(
		private readonly string $key,
		private readonly string $label,
		private readonly array $outcomes
	) {}

	/**
	 * Machine key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Ordered outcomes.
	 *
	 * @return Outcome[]
	 */
	public function outcomes(): array {
		return $this->outcomes;
	}

	/**
	 * Outcome keys, in display order.
	 *
	 * @return string[]
	 */
	public function outcome_keys(): array {
		return array_map(
			static fn( Outcome $outcome ): string => $outcome->key(),
			$this->outcomes
		);
	}

	/**
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'key'      => $this->key,
			'label'    => $this->label,
			'outcomes' => array_map(
				static fn( Outcome $outcome ): array => $outcome->to_array(),
				$this->outcomes
			),
		);
	}
}
