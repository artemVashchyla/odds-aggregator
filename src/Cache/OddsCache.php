<?php
/**
 * Caching for fetched odds.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Cache;

/**
 * Two-tier cache with stale-while-revalidate semantics.
 *
 * Within the fresh window a read is a straight hit. Past it the payload is
 * still returned - stale odds beat a blank table and beat blocking the page on
 * a scrape - while a background refresh is triggered. Only past the stale
 * window does a read miss entirely.
 *
 * Storage prefers a persistent object cache and falls back to transients.
 * Invalidation is version-based: bumping a stored counter orphans every old
 * key at once, which avoids having to track and delete keys individually.
 */
final class OddsCache {

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private const GROUP = 'odds_aggregator';

	/**
	 * Option holding the cache version.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'odds_aggregator_cache_version';

	/**
	 * Whether a persistent object cache backs this instance.
	 *
	 * @var bool
	 */
	private readonly bool $use_object_cache;

	/**
	 * Constructor.
	 *
	 * @param int  $fresh_ttl Seconds a payload counts as fresh.
	 * @param int  $stale_ttl Seconds a stale payload may still be served.
	 * @param Lock $lock      Lock backend.
	 */
	public function __construct(
		private readonly int $fresh_ttl = 60,
		private readonly int $stale_ttl = 600,
		private readonly Lock $lock = new Lock()
	) {
		$this->use_object_cache = function_exists( 'wp_using_ext_object_cache' )
			&& wp_using_ext_object_cache();
	}

	/**
	 * Reads an entry, including stale ones.
	 *
	 * @param string $key Logical cache key.
	 * @return CacheEntry|null
	 */
	public function get( string $key ): ?CacheEntry {
		$raw = $this->read( $this->qualify( $key ) );

		if ( ! is_array( $raw ) || ! array_key_exists( 'data', $raw ) ) {
			return null;
		}

		return new CacheEntry( $raw['data'], (int) ( $raw['stored_at'] ?? 0 ), $this->fresh_ttl );
	}

	/**
	 * Writes an entry.
	 *
	 * @param string $key  Logical cache key.
	 * @param mixed  $data Payload.
	 * @return void
	 */
	public function set( string $key, mixed $data ): void {
		$this->write(
			$this->qualify( $key ),
			array(
				'data'      => $data,
				'stored_at' => time(),
			),
			$this->stale_ttl
		);
	}

	/**
	 * Removes a single entry.
	 *
	 * @param string $key Logical cache key.
	 * @return void
	 */
	public function delete( string $key ): void {
		$qualified = $this->qualify( $key );

		if ( $this->use_object_cache ) {
			wp_cache_delete( $qualified, self::GROUP );

			return;
		}

		delete_transient( $qualified );
	}

	/**
	 * Invalidates everything by moving to a new key namespace.
	 *
	 * @return void
	 */
	public function flush(): void {
		update_option( self::VERSION_OPTION, (string) time(), false );
	}

	/**
	 * Seconds a payload counts as fresh.
	 *
	 * @return int
	 */
	public function fresh_ttl(): int {
		return $this->fresh_ttl;
	}

	/**
	 * Takes a short-lived lock, so only one process refreshes a given key.
	 *
	 * Without this, a cache expiry under traffic sends every concurrent
	 * request upstream at once.
	 *
	 * @param string $key Logical cache key.
	 * @param int    $ttl Lock lifetime in seconds.
	 * @return bool True when the lock was acquired.
	 */
	public function acquire_lock( string $key, int $ttl = 30 ): bool {
		return $this->lock->acquire( $this->qualify( 'lock_' . $key ), $ttl );
	}

	/**
	 * Releases a lock taken by {@see OddsCache::acquire_lock()}.
	 *
	 * @param string $key Logical cache key.
	 * @return void
	 */
	public function release_lock( string $key ): void {
		$this->lock->release( $this->qualify( 'lock_' . $key ) );
	}

	/**
	 * Builds a stable key from its parts.
	 *
	 * @param string ...$parts Key parts.
	 * @return string
	 */
	public static function key( string ...$parts ): string {
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Namespaces a key with the current cache version.
	 *
	 * @param string $key Logical cache key.
	 * @return string
	 */
	private function qualify( string $key ): string {
		$version = (string) get_option( self::VERSION_OPTION, '1' );

		// Transient keys are capped at 172 characters; this stays far inside that.
		return 'oa_' . $version . '_' . $key;
	}

	/**
	 * Backend read.
	 *
	 * @param string $key Qualified key.
	 * @return mixed
	 */
	private function read( string $key ): mixed {
		if ( $this->use_object_cache ) {
			$value = wp_cache_get( $key, self::GROUP );

			return false === $value ? null : $value;
		}

		$value = get_transient( $key );

		return false === $value ? null : $value;
	}

	/**
	 * Backend write.
	 *
	 * @param string $key   Qualified key.
	 * @param mixed  $value Payload envelope.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	private function write( string $key, mixed $value, int $ttl ): void {
		if ( $this->use_object_cache ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );

			return;
		}

		set_transient( $key, $value, $ttl );
	}
}
