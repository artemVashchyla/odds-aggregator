<?php
/**
 * A single selectable result within a market.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Immutable outcome, e.g. "home" in 1X2 or "over" in Over/Under 2.5.
 */
final class Outcome {

	/**
	 * Constructor.
	 *
	 * @param string $key   Machine key, unique within its market.
	 * @param string $label Human readable label.
	 */
	public function __construct(
		private readonly string $key,
		private readonly string $label
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
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'key'   => $this->key,
			'label' => $this->label,
		);
	}
}
