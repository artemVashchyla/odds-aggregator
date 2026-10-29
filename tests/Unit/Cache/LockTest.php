<?php
/**
 * Tests for the options-table lock.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Cache;

use OddsAggregator\Cache\Lock;
use OddsAggregator\Http\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Lock and its use by RateLimiter.
 */
final class LockTest extends TestCase {

	/**
	 * Empties the fake options table.
	 */
	protected function setUp(): void {
		$GLOBALS['wpdb']->rows                      = array();
		$GLOBALS['odds_aggregator_test_transients'] = array();
	}

	/**
	 * A held lock cannot be taken again until released.
	 */
	public function test_lock_is_exclusive_until_released(): void {
		$first  = new Lock();
		$second = new Lock();

		$this->assertTrue( $first->acquire( 'job', 30 ) );
		$this->assertFalse( $second->acquire( 'job', 30 ) );

		$first->release( 'job' );

		$this->assertTrue( $second->acquire( 'job', 30 ) );
	}

	/**
	 * An expired lock is taken over.
	 */
	public function test_expired_lock_is_reclaimed(): void {
		$lock = new Lock();

		$this->assertTrue( $lock->acquire( 'job', 30 ) );

		$GLOBALS['wpdb']->rows = array_map( static fn(): string => (string) ( time() - 1 ), $GLOBALS['wpdb']->rows );

		$this->assertTrue( $lock->acquire( 'job', 30 ) );
	}

	/**
	 * Locks with different names do not block each other.
	 */
	public function test_names_are_independent(): void {
		$lock = new Lock();

		$this->assertTrue( $lock->acquire( 'a', 30 ) );
		$this->assertTrue( $lock->acquire( 'b', 30 ) );
	}

	/**
	 * A bucket another process is updating is treated as throttled.
	 */
	public function test_rate_limiter_refuses_while_bucket_is_locked(): void {
		$limiter = new RateLimiter( 20 );

		$this->assertTrue( $limiter->consume( 'provider' ) );

		( new Lock() )->acquire( 'odds_aggregator_rl_provider', 5 );

		$this->assertFalse( $limiter->consume( 'provider' ) );
	}
}
