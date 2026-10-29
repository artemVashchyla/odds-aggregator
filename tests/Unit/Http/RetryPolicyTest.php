<?php
/**
 * Tests for the retry and backoff rules.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Http;

use OddsAggregator\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers which failures are worth another attempt and how long the wait is.
 *
 * The delay is deliberately random, so it is asserted as a range rather than a
 * value: the contract is "never longer than the cap, never negative".
 */
final class RetryPolicyTest extends TestCase {

	/**
	 * Attempt counts below one would mean never making the request at all.
	 */
	public function test_at_least_one_attempt_is_always_allowed(): void {
		$this->assertSame( 1, ( new RetryPolicy( 0 ) )->max_attempts() );
		$this->assertSame( 1, ( new RetryPolicy( -5 ) )->max_attempts() );
		$this->assertSame( 4, ( new RetryPolicy( 4 ) )->max_attempts() );
	}

	/**
	 * Transient upstream failures are retried.
	 *
	 * @param int $status Retryable HTTP status.
	 * @dataProvider provide_retryable_statuses
	 */
	public function test_transient_failures_are_retried( int $status ): void {
		$this->assertTrue( ( new RetryPolicy( 3 ) )->should_retry( 1, $status ) );
	}

	/**
	 * Statuses the policy treats as transient.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function provide_retryable_statuses(): array {
		return array(
			'request timeout'     => array( 408 ),
			'too many requests'   => array( 429 ),
			'internal error'      => array( 500 ),
			'bad gateway'         => array( 502 ),
			'service unavailable' => array( 503 ),
			'gateway timeout'     => array( 504 ),
			'no response at all'  => array( 0 ),
		);
	}

	/**
	 * A permanent answer is not worth repeating.
	 *
	 * @param int $status Non-retryable HTTP status.
	 * @dataProvider provide_permanent_statuses
	 */
	public function test_permanent_failures_are_not_retried( int $status ): void {
		$this->assertFalse( ( new RetryPolicy( 3 ) )->should_retry( 1, $status ) );
	}

	/**
	 * Statuses the policy treats as final.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function provide_permanent_statuses(): array {
		return array(
			'bad request'  => array( 400 ),
			'unauthorised' => array( 401 ),
			'forbidden'    => array( 403 ),
			'not found'    => array( 404 ),
			'gone'         => array( 410 ),
			'teapot'       => array( 418 ),
		);
	}

	/**
	 * The budget is respected even for a retryable status.
	 */
	public function test_retries_stop_at_the_attempt_budget(): void {
		$policy = new RetryPolicy( 3 );

		$this->assertTrue( $policy->should_retry( 1, 503 ) );
		$this->assertTrue( $policy->should_retry( 2, 503 ) );
		$this->assertFalse( $policy->should_retry( 3, 503 ) );
		$this->assertFalse( $policy->should_retry( 4, 503 ) );
	}

	/**
	 * An upstream Retry-After is obeyed rather than jittered away.
	 */
	public function test_retry_after_is_obeyed(): void {
		$policy = new RetryPolicy( 3, 300, 5000 );

		$this->assertSame( 2_000_000, $policy->delay_microseconds( 1, 2 ) );
	}

	/**
	 * Even an obeyed Retry-After cannot stall the request past the cap.
	 */
	public function test_retry_after_is_capped(): void {
		$policy = new RetryPolicy( 3, 300, 5000 );

		$this->assertSame( 5_000_000, $policy->delay_microseconds( 1, 600 ) );
	}

	/**
	 * A zero or absent Retry-After falls through to the backoff.
	 */
	public function test_unusable_retry_after_falls_back_to_backoff(): void {
		$policy = new RetryPolicy( 3, 100, 5000 );

		foreach ( array( null, 0, -1 ) as $retry_after ) {
			$delay = $policy->delay_microseconds( 1, $retry_after );

			$this->assertGreaterThanOrEqual( 0, $delay );
			$this->assertLessThanOrEqual( 100_000, $delay );
		}
	}

	/**
	 * Backoff is jittered, so the ceiling doubles per attempt while the floor
	 * stays at zero.
	 */
	public function test_backoff_ceiling_doubles_per_attempt(): void {
		$policy = new RetryPolicy( 5, 100, 5000 );

		$ceilings = array(
			1 => 100_000,
			2 => 200_000,
			3 => 400_000,
		);

		foreach ( $ceilings as $attempt => $ceiling ) {
			for ( $i = 0; $i < 25; $i++ ) {
				$delay = $policy->delay_microseconds( $attempt );

				$this->assertGreaterThanOrEqual( 0, $delay );
				$this->assertLessThanOrEqual( $ceiling, $delay );
			}
		}
	}

	/**
	 * The exponential growth stops at the configured ceiling.
	 */
	public function test_backoff_is_capped(): void {
		$policy = new RetryPolicy( 20, 100, 500 );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertLessThanOrEqual( 500_000, $policy->delay_microseconds( 15 ) );
		}
	}

	/**
	 * Jitter is real: repeated calls do not all return the same wait.
	 */
	public function test_backoff_is_jittered(): void {
		$policy = new RetryPolicy( 3, 1000, 5000 );
		$delays = array();

		for ( $i = 0; $i < 40; $i++ ) {
			$delays[] = $policy->delay_microseconds( 2 );
		}

		$this->assertGreaterThan( 1, count( array_unique( $delays ) ) );
	}
}
