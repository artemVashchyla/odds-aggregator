<?php
/**
 * Tests for the odds cache.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Cache;

use OddsAggregator\Cache\CacheEntry;
use OddsAggregator\Cache\OddsCache;
use PHPUnit\Framework\TestCase;

/**
 * Covers reads, writes, version-based invalidation and the refresh lock.
 *
 * No persistent object cache exists under PHPUnit, so the transient backend is
 * what runs here; the stubs keep it in memory. Ageing an entry means rewriting
 * the timestamp the cache stored, which is cheaper than waiting.
 */
final class OddsCacheTest extends TestCase {

	/**
	 * Option the cache namespaces its keys with.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'odds_aggregator_cache_version';

	/**
	 * Empties both backends and the fake options table behind the lock.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_options']    = array();
		$GLOBALS['wpdb']->rows                      = array();
	}

	/**
	 * Rewrites the stored timestamp of every entry, as if time had passed.
	 *
	 * @param int $seconds Seconds to age the entries by.
	 * @return void
	 */
	private function age_entries( int $seconds ): void {
		foreach ( $GLOBALS['odds_aggregator_test_transients'] as $key => $value ) {
			if ( is_array( $value ) && isset( $value['stored_at'] ) ) {
				$value['stored_at'] -= $seconds;

				$GLOBALS['odds_aggregator_test_transients'][ $key ] = $value;
			}
		}
	}

	/**
	 * A written payload reads back fresh and unchanged.
	 */
	public function test_a_written_payload_reads_back_fresh(): void {
		$cache = new OddsCache( 60, 600 );

		$cache->set( 'odds:chelsea', array( 'home' => 2.1 ) );

		$entry = $cache->get( 'odds:chelsea' );

		$this->assertInstanceOf( CacheEntry::class, $entry );
		$this->assertSame( array( 'home' => 2.1 ), $entry->data() );
		$this->assertTrue( $entry->is_fresh() );
	}

	/**
	 * An unknown key is a miss.
	 */
	public function test_an_unknown_key_misses(): void {
		$this->assertNull( ( new OddsCache() )->get( 'nothing-here' ) );
	}

	/**
	 * Past the fresh window the payload is still served, but marked stale, which
	 * is what triggers a background refresh instead of a blocking fetch.
	 */
	public function test_a_stale_payload_is_still_served(): void {
		$cache = new OddsCache( 60, 600 );

		$cache->set( 'odds:chelsea', 'prices' );
		$this->age_entries( 120 );

		$entry = $cache->get( 'odds:chelsea' );

		$this->assertInstanceOf( CacheEntry::class, $entry );
		$this->assertSame( 'prices', $entry->data() );
		$this->assertFalse( $entry->is_fresh() );
		$this->assertSame( 120, $entry->age() );
	}

	/**
	 * A falsy payload is a hit, not a miss: the transient backend reports
	 * absence as false, so the envelope has to be distinguished from it.
	 */
	public function test_a_falsy_payload_is_not_mistaken_for_a_miss(): void {
		$cache = new OddsCache();

		$cache->set( 'empty', array() );

		$entry = $cache->get( 'empty' );

		$this->assertInstanceOf( CacheEntry::class, $entry );
		$this->assertSame( array(), $entry->data() );
	}

	/**
	 * A stored value that is not an envelope is treated as a miss.
	 */
	public function test_a_foreign_stored_value_is_a_miss(): void {
		$cache = new OddsCache();

		$cache->set( 'odds:chelsea', 'prices' );

		foreach ( array_keys( $GLOBALS['odds_aggregator_test_transients'] ) as $key ) {
			$GLOBALS['odds_aggregator_test_transients'][ $key ] = 'left over from an older format';
		}

		$this->assertNull( $cache->get( 'odds:chelsea' ) );
	}

	/**
	 * Deleting removes one key and leaves the others alone.
	 */
	public function test_delete_removes_one_entry(): void {
		$cache = new OddsCache();

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );
		$cache->delete( 'a' );

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertSame( 2, $cache->get( 'b' )?->data() );
	}

	/**
	 * A flush orphans every existing key at once by moving the namespace on.
	 */
	public function test_flush_invalidates_everything(): void {
		$cache = new OddsCache();

		$cache->set( 'a', 1 );
		$cache->set( 'b', 2 );

		$before = $GLOBALS['odds_aggregator_test_options'][ self::VERSION_OPTION ] ?? null;

		$cache->flush();

		$this->assertNull( $cache->get( 'a' ) );
		$this->assertNull( $cache->get( 'b' ) );
		$this->assertNotSame( $before, $GLOBALS['odds_aggregator_test_options'][ self::VERSION_OPTION ] );
	}

	/**
	 * A flush does not delete the stored rows, so a later write cannot collide
	 * with an orphan; the old rows simply expire.
	 */
	public function test_flush_leaves_the_old_rows_to_expire(): void {
		$cache = new OddsCache();

		$cache->set( 'a', 1 );
		$cache->flush();
		$cache->set( 'a', 2 );

		$this->assertSame( 2, $cache->get( 'a' )?->data() );
		$this->assertCount( 2, $GLOBALS['odds_aggregator_test_transients'] );
	}

	/**
	 * The configured fresh window is reported, because callers use it as the
	 * Cache-Control max-age on the front end.
	 */
	public function test_fresh_ttl_is_reported(): void {
		$this->assertSame( 45, ( new OddsCache( 45, 600 ) )->fresh_ttl() );
	}

	/**
	 * Keys are deterministic, order-sensitive and collision-free across parts.
	 */
	public function test_keys_are_deterministic(): void {
		$this->assertSame( OddsCache::key( 'odds', 'chelsea', '1x2' ), OddsCache::key( 'odds', 'chelsea', '1x2' ) );
		$this->assertNotSame( OddsCache::key( 'odds', 'chelsea' ), OddsCache::key( 'chelsea', 'odds' ) );
		$this->assertNotSame( OddsCache::key( 'a', 'b' ), OddsCache::key( 'ab' ) );
	}

	/**
	 * Only one caller may refresh a key at a time, so a cache expiry under
	 * traffic sends one request upstream rather than one per visitor.
	 */
	public function test_the_refresh_lock_is_exclusive(): void {
		$cache = new OddsCache();
		$other = new OddsCache();

		$this->assertTrue( $cache->acquire_lock( 'odds:chelsea', 30 ) );
		$this->assertFalse( $other->acquire_lock( 'odds:chelsea', 30 ) );

		$cache->release_lock( 'odds:chelsea' );

		$this->assertTrue( $other->acquire_lock( 'odds:chelsea', 30 ) );
	}

	/**
	 * Different keys are refreshed independently.
	 */
	public function test_refresh_locks_are_per_key(): void {
		$cache = new OddsCache();

		$this->assertTrue( $cache->acquire_lock( 'odds:chelsea', 30 ) );
		$this->assertTrue( $cache->acquire_lock( 'odds:arsenal', 30 ) );
	}

	/**
	 * A lock lives in the versioned namespace too, so a flush cannot leave a
	 * stuck lock blocking the new namespace.
	 */
	public function test_a_flush_frees_the_refresh_lock(): void {
		$cache = new OddsCache();

		$this->assertTrue( $cache->acquire_lock( 'odds:chelsea', 30 ) );

		$cache->flush();

		$this->assertTrue( $cache->acquire_lock( 'odds:chelsea', 30 ) );
	}
}
