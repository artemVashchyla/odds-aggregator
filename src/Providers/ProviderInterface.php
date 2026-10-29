<?php
/**
 * Contract for odds sources.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\OddsLine;

/**
 * Every odds source implements this, so the rest of the plugin never needs to
 * know whether data came from a scrape, an API or a fixture file.
 */
interface ProviderInterface {

	/**
	 * Machine id, used in settings and cache keys.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human readable label for the admin dropdown.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the provider is configured well enough to be used.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Events the provider can currently quote.
	 *
	 * @return Event[]
	 * @throws ProviderException When the source cannot be read.
	 */
	public function fetch_events(): array;

	/**
	 * Prices for one event across the requested markets.
	 *
	 * @param Event    $event   Event to price.
	 * @param Market[] $markets Markets to include.
	 * @return OddsLine[]
	 * @throws ProviderException When the source cannot be read.
	 */
	public function fetch_odds( Event $event, array $markets ): array;
}
