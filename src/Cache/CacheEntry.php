<?php
/**
 * A cached payload with its age.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Cache;

/**
 * Immutable cache envelope.
 *
 * The age is carried alongside the payload so callers can distinguish "fresh"
 * from "stale but serviceable" and decide whether to trigger a refresh.
 */
final class CacheEntry {

	/**
	 * Constructor.
	 *
	 * @param mixed $data      Cached payload.
	 * @param int   $stored_at Unix timestamp the payload was written.
	 * @param int   $fresh_ttl Seconds the payload counts as fresh.
	 */
	public function __construct(
		private readonly mixed $data,
		private readonly int $stored_at,
		private readonly int $fresh_ttl
	) {}

	/**
	 * Cached payload.
	 *
	 * @return mixed
	 */
	public function data(): mixed {
		return $this->data;
	}

	/**
	 * Unix timestamp the payload was written.
	 *
	 * @return int
	 */
	public function stored_at(): int {
		return $this->stored_at;
	}

	/**
	 * Age in seconds.
	 *
	 * @return int
	 */
	public function age(): int {
		return max( 0, time() - $this->stored_at );
	}

	/**
	 * Whether the payload is within its fresh window.
	 *
	 * @return bool
	 */
	public function is_fresh(): bool {
		return $this->age() < $this->fresh_ttl;
	}
}
