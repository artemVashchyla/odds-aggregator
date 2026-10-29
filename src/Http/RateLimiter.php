<?php
/**
 * Outbound request throttling.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Http;

use OddsAggregator\Cache\Lock;

/**
 * Token bucket, persisted so the limit holds across PHP requests.
 *
 * Scraping a third-party site from a busy WordPress install is the quickest
 * way to get an IP banned. A bucket lets short bursts through (useful when the
 * cron warmer refreshes several events at once) while capping the sustained
 * rate.
 */
final class RateLimiter {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'odds_aggregator_rl_';

	/**
	 * Constructor.
	 *
	 * @param int  $requests_per_minute Sustained ceiling.
	 * @param int  $burst               Bucket capacity; defaults to the per-minute rate.
	 * @param Lock $lock                Lock serialising bucket updates.
	 */
	public function __construct(
		private readonly int $requests_per_minute = 20,
		private readonly int $burst = 0,
		private readonly Lock $lock = new Lock()
	) {}

	/**
	 * Consumes a token, returning false when the bucket is empty or busy.
	 *
	 * @param string $bucket Bucket name, normally the provider id.
	 * @return bool
	 */
	public function consume( string $bucket ): bool {
		$lock = self::PREFIX . $bucket;

		if ( ! $this->lock->acquire( $lock, 5 ) ) {
			return false;
		}

		try {
			return $this->take_token( $bucket );
		} finally {
			$this->lock->release( $lock );
		}
	}

	/**
	 * Refills the bucket and takes one token from it.
	 *
	 * @param string $bucket Bucket name.
	 * @return bool
	 */
	private function take_token( string $bucket ): bool {
		$capacity = $this->capacity();
		$rate     = $this->rate();
		$key      = self::PREFIX . $bucket;
		$now      = time();

		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['tokens'], $state['updated'] ) ) {
			$state = array(
				'tokens'  => (float) $capacity,
				'updated' => $now,
			);
		}

		// Refill for the time elapsed since the last request.
		$elapsed          = max( 0, $now - (int) $state['updated'] );
		$state['tokens']  = min( (float) $capacity, (float) $state['tokens'] + ( $elapsed * $rate ) );
		$state['updated'] = $now;

		if ( $state['tokens'] < 1.0 ) {
			$this->persist( $key, $state );

			return false;
		}

		--$state['tokens'];
		$this->persist( $key, $state );

		return true;
	}

	/**
	 * Seconds until another token becomes available.
	 *
	 * @param string $bucket Bucket name.
	 * @return int
	 */
	public function retry_after( string $bucket ): int {
		$state = get_transient( self::PREFIX . $bucket );

		if ( ! is_array( $state ) || ! isset( $state['tokens'] ) ) {
			return 0;
		}

		$deficit = 1.0 - (float) $state['tokens'];

		if ( $deficit <= 0 ) {
			return 0;
		}

		return (int) ceil( $deficit / $this->rate() );
	}

	/**
	 * Bucket capacity.
	 *
	 * @return int
	 */
	private function capacity(): int {
		return max( 1, $this->burst > 0 ? $this->burst : $this->requests_per_minute );
	}

	/**
	 * Refill rate in tokens per second.
	 *
	 * Floored at one request per minute: settings clamp the value, but the class
	 * is constructed directly too, and a rate of zero would both freeze the
	 * bucket for good and divide by zero in {@see RateLimiter::retry_after()}.
	 *
	 * @return float
	 */
	private function rate(): float {
		return max( 1, $this->requests_per_minute ) / 60.0;
	}

	/**
	 * Writes bucket state back, with a TTL comfortably past a full refill.
	 *
	 * @param string              $key   Transient key.
	 * @param array<string,mixed> $state Bucket state.
	 * @return void
	 */
	private function persist( string $key, array $state ): void {
		set_transient( $key, $state, 300 );
	}
}
