<?php
/**
 * Atomic named locks.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Cache;

/**
 * Named lock that is atomic across processes.
 */
final class Lock {

	/**
	 * Option name prefix and object cache group.
	 *
	 * @var string
	 */
	private const PREFIX = 'odds_aggregator_lock_';

	/**
	 * Takes the lock unless another process holds an unexpired one.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before an unreleased lock expires.
	 * @return bool True when the lock was acquired.
	 */
	public function acquire( string $name, int $ttl ): bool {
		$option = $this->option( $name );

		if ( $this->use_object_cache() ) {
			return (bool) wp_cache_add( $option, 1, self::PREFIX, $ttl );
		}

		if ( $this->insert( $option, $ttl ) ) {
			return true;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expires = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		if ( '' === $expires || (int) $expires > time() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $expires )
		);

		return $this->insert( $option, $ttl );
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public function release( string $name ): void {
		$option = $this->option( $name );

		if ( $this->use_object_cache() ) {
			wp_cache_delete( $option, self::PREFIX );

			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	}

	/**
	 * Inserts the lock row if it does not exist.
	 *
	 * @param string $option Option name.
	 * @param int    $ttl    Seconds until expiry.
	 * @return bool True when this call created the row.
	 */
	private function insert( string $option, int $ttl ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				(string) ( time() + $ttl )
			)
		);

		return 1 === (int) $inserted;
	}

	/**
	 * Option name for a lock, within the 191-character limit.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private function option( string $name ): string {
		return self::PREFIX . md5( $name );
	}

	/**
	 * Whether a persistent object cache is available.
	 *
	 * @return bool
	 */
	private function use_object_cache(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}
}
