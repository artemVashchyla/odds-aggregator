<?php
/**
 * Provider-level failure.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use RuntimeException;

/**
 * Thrown when a provider cannot produce usable data.
 */
final class ProviderException extends RuntimeException {}
