<?php
/**
 * Tests for the outbound token bucket.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Http;

use OddsAggregator\Http\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Covers bucket capacity, refill and the retry hint.
 *
 * The bucket is persisted in a transient, which the stubs keep in memory, so a
 * test can age the stored state instead of waiting for the clock. Rates are kept
 * low enough that a token cannot refill mid-test.
 *
 * {@see \OddsAggregator\Tests\Unit\Cache\LockTest} covers what happens while
 * another process holds the bucket lock.
 */
final class RateLimiterTest extends TestCase {

	/**
	 * Transient key the limiter persists a bucket under.
	 *
	 * @var string
	 */
	private const KEY = 'odds_aggregator_rl_provider';

	/**
	 * Empties the bucket store and the fake options table behind the lock.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['wpdb']->rows                      = array();
	}

	/**
	 * Moves the stored bucket back in time, as if that many seconds had passed.
	 *
	 * @param int $seconds Seconds to age the state by.
	 * @return void
	 */
	private function age_bucket( int $seconds ): void {
		$state = $GLOBALS['odds_aggregator_test_transients'][ self::KEY ];

		$state['updated'] -= $seconds;

		$GLOBALS['odds_aggregator_test_transients'][ self::KEY ] = $state;
	}

	/**
	 * A fresh bucket is full, so a burst up to capacity is let through.
	 */
	public function test_a_full_bucket_allows_a_burst(): void {
		$limiter = new RateLimiter( 3 );

		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertFalse( $limiter->consume( 'provider' ), 'The fourth request should exhaust a bucket of three.' );
	}

	/**
	 * An explicit burst sets the capacity independently of the sustained rate.
	 */
	public function test_burst_sets_the_capacity(): void {
		$limiter = new RateLimiter( 60, 2 );

		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertFalse( $limiter->consume( 'provider' ) );
	}

	/**
	 * Elapsed time puts tokens back, one per 1/rate seconds.
	 */
	public function test_tokens_refill_over_time(): void {
		$limiter = new RateLimiter( 6 );

		for ( $i = 0; $i < 6; $i++ ) {
			$limiter->consume( 'provider' );
		}

		$this->assertFalse( $limiter->consume( 'provider' ) );

		// Six per minute is one every ten seconds.
		$this->age_bucket( 10 );

		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertFalse( $limiter->consume( 'provider' ) );
	}

	/**
	 * Refilling never overshoots the capacity, however long the bucket idled.
	 */
	public function test_refill_does_not_exceed_capacity(): void {
		$limiter = new RateLimiter( 2 );

		$this->assertTrue( $limiter->consume( 'provider' ) );

		$this->age_bucket( 86400 );

		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertFalse( $limiter->consume( 'provider' ) );
	}

	/**
	 * Buckets are independent, so one provider cannot starve another.
	 */
	public function test_buckets_are_independent(): void {
		$limiter = new RateLimiter( 1 );

		$this->assertTrue( $limiter->consume( 'oddschecker' ) );
		$this->assertFalse( $limiter->consume( 'oddschecker' ) );
		$this->assertTrue( $limiter->consume( 'oddsapi' ) );
	}

	/**
	 * An untouched bucket needs no wait.
	 */
	public function test_retry_after_is_zero_before_any_request(): void {
		$this->assertSame( 0, ( new RateLimiter( 6 ) )->retry_after( 'provider' ) );
	}

	/**
	 * A bucket with tokens left needs no wait either.
	 */
	public function test_retry_after_is_zero_while_tokens_remain(): void {
		$limiter = new RateLimiter( 6 );

		$limiter->consume( 'provider' );

		$this->assertSame( 0, $limiter->retry_after( 'provider' ) );
	}

	/**
	 * An exhausted bucket reports how long until the next token.
	 */
	public function test_retry_after_reports_the_wait(): void {
		$limiter = new RateLimiter( 6 );

		for ( $i = 0; $i < 6; $i++ ) {
			$limiter->consume( 'provider' );
		}

		$this->assertFalse( $limiter->consume( 'provider' ) );

		// Six per minute: a whole token is ten seconds away.
		$this->assertSame( 10, $limiter->retry_after( 'provider' ) );
	}

	/**
	 * Corrupt bucket state is replaced by a full bucket rather than trusted.
	 */
	public function test_unreadable_state_is_discarded(): void {
		$GLOBALS['odds_aggregator_test_transients'][ self::KEY ] = 'not an array';

		$limiter = new RateLimiter( 2 );

		$this->assertSame( 0, $limiter->retry_after( 'provider' ) );
		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertFalse( $limiter->consume( 'provider' ) );
	}

	/**
	 * A nonsensical rate still yields a working bucket instead of a division by
	 * zero on the retry hint.
	 */
	public function test_a_zero_rate_is_floored(): void {
		$limiter = new RateLimiter( 0 );

		$this->assertTrue( $limiter->consume( 'provider' ) );
		$this->assertFalse( $limiter->consume( 'provider' ) );
		$this->assertSame( 60, $limiter->retry_after( 'provider' ) );
	}
}
