<?php
/**
 * Retry and backoff rules.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Http;

/**
 * Exponential backoff with jitter.
 *
 * Jitter matters here: without it, every site running this plugin that gets
 * rate-limited at the same moment retries at the same moment, and the upstream
 * sees a synchronised thundering herd.
 */
final class RetryPolicy {

	/**
	 * Statuses worth retrying; everything else is a permanent failure.
	 *
	 * @var int[]
	 */
	private const RETRYABLE = array( 408, 429, 500, 502, 503, 504 );

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts  Total attempts, including the first.
	 * @param int $base_delay_ms Delay before the second attempt.
	 * @param int $max_delay_ms  Ceiling for any single wait.
	 */
	public function __construct(
		private readonly int $max_attempts = 3,
		private readonly int $base_delay_ms = 300,
		private readonly int $max_delay_ms = 5000
	) {}

	/**
	 * Total attempts allowed.
	 *
	 * @return int
	 */
	public function max_attempts(): int {
		return max( 1, $this->max_attempts );
	}

	/**
	 * Whether another attempt should be made.
	 *
	 * @param int $attempt Attempts made so far, 1-based.
	 * @param int $status  HTTP status, or 0 for a transport failure.
	 * @return bool
	 */
	public function should_retry( int $attempt, int $status ): bool {
		if ( $attempt >= $this->max_attempts() ) {
			return false;
		}

		// A transport failure (no response at all) is always worth one more go.
		return 0 === $status || in_array( $status, self::RETRYABLE, true );
	}

	/**
	 * Microseconds to sleep before the next attempt.
	 *
	 * @param int      $attempt     Attempts made so far, 1-based.
	 * @param int|null $retry_after Upstream Retry-After value in seconds, if sent.
	 * @return int
	 */
	public function delay_microseconds( int $attempt, ?int $retry_after = null ): int {
		// An explicit Retry-After is an instruction, not a suggestion.
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( $retry_after * 1000, $this->max_delay_ms ) * 1000;
		}

		$exponential = $this->base_delay_ms * ( 2 ** max( 0, $attempt - 1 ) );
		$capped      = min( $exponential, $this->max_delay_ms );

		// Full jitter: pick uniformly from [0, capped].
		return wp_rand( 0, (int) $capped ) * 1000;
	}
}
