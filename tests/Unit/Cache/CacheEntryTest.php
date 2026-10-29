<?php
/**
 * Tests for the cache envelope.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Cache;

use OddsAggregator\Cache\CacheEntry;
use PHPUnit\Framework\TestCase;

/**
 * Covers the age arithmetic the stale-while-revalidate decision rests on.
 */
final class CacheEntryTest extends TestCase {

	/**
	 * The payload is handed back exactly as stored, whatever its type.
	 *
	 * @param mixed $payload Stored payload.
	 * @dataProvider provide_payloads
	 */
	public function test_payload_survives_the_round_trip( mixed $payload ): void {
		$this->assertSame( $payload, ( new CacheEntry( $payload, time(), 60 ) )->data() );
	}

	/**
	 * Payload shapes the cache is asked to carry.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function provide_payloads(): array {
		return array(
			'list'   => array( array( 1, 2, 3 ) ),
			'map'    => array( array( 'a' => 'b' ) ),
			'string' => array( 'odds' ),
			'empty'  => array( array() ),
			'null'   => array( null ),
			'false'  => array( false ),
		);
	}

	/**
	 * A just-written entry is fresh and has no age.
	 */
	public function test_a_new_entry_is_fresh(): void {
		$now   = time();
		$entry = new CacheEntry( 'odds', $now, 60 );

		$this->assertSame( $now, $entry->stored_at() );
		$this->assertSame( 0, $entry->age() );
		$this->assertTrue( $entry->is_fresh() );
	}

	/**
	 * Freshness ends exactly at the TTL, not after it.
	 */
	public function test_freshness_ends_at_the_ttl(): void {
		$this->assertTrue( ( new CacheEntry( 'odds', time() - 59, 60 ) )->is_fresh() );
		$this->assertFalse( ( new CacheEntry( 'odds', time() - 60, 60 ) )->is_fresh() );
		$this->assertFalse( ( new CacheEntry( 'odds', time() - 600, 60 ) )->is_fresh() );
	}

	/**
	 * A zero TTL means nothing is ever fresh, which is how caching is disabled.
	 */
	public function test_a_zero_ttl_is_never_fresh(): void {
		$this->assertFalse( ( new CacheEntry( 'odds', time(), 0 ) )->is_fresh() );
	}

	/**
	 * Age reports the elapsed seconds.
	 */
	public function test_age_counts_elapsed_seconds(): void {
		$this->assertSame( 120, ( new CacheEntry( 'odds', time() - 120, 60 ) )->age() );
	}

	/**
	 * A timestamp in the future, from a clock change or a bad write, reads as
	 * age zero rather than a negative age.
	 */
	public function test_a_future_timestamp_does_not_go_negative(): void {
		$entry = new CacheEntry( 'odds', time() + 500, 60 );

		$this->assertSame( 0, $entry->age() );
		$this->assertTrue( $entry->is_fresh() );
	}

	/**
	 * A missing timestamp, as an older cache format would leave behind, reads as
	 * ancient and therefore stale.
	 */
	public function test_a_missing_timestamp_reads_as_stale(): void {
		$this->assertFalse( ( new CacheEntry( 'odds', 0, 60 ) )->is_fresh() );
	}
}
