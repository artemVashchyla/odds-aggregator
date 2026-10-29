<?php
/**
 * Real transport for the integration suite.
 *
 * The unit bootstrap stubs wp_remote_get() with an in-memory queue. This file
 * defines the same function over cURL first, so that when the bootstrap runs its
 * function_exists() guard the stub is skipped and the integration tests talk to
 * the real upstream. Everything else - the retrieve helpers, the transients, the
 * option store - stays stubbed, because the point is to exercise the plugin's
 * own code against a live feed, not to run WordPress.
 *
 * Transport failures are reported as an HTTP status of 0, which is exactly how
 * HttpClient already treats "no response at all", so is_wp_error() can keep
 * returning false.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

/**
 * Requests made through the live transport, for tests that count them.
 *
 * @var array<int,string>
 */
$GLOBALS['odds_aggregator_live_requests'] = array();

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Performs a real GET.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Request arguments, as the WordPress HTTP API accepts them.
	 * @return array<string,mixed>
	 */
	function wp_remote_get( string $url, array $args = array() ): array {
		$GLOBALS['odds_aggregator_live_requests'][] = $url;

		$headers = array();

		foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}

		$response_headers = array();
		$handle           = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- deliberate: this file replaces the WordPress HTTP API.

		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => (int) ( $args['redirection'] ?? 3 ),
				CURLOPT_TIMEOUT        => (int) ( $args['timeout'] ?? 10 ),
				CURLOPT_USERAGENT      => (string) ( $args['user-agent'] ?? 'OddsAggregator-IntegrationTests/1.0' ),
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_HEADERFUNCTION => static function ( $unused, string $line ) use ( &$response_headers ): int {
					$parts = explode( ':', $line, 2 );

					if ( 2 === count( $parts ) ) {
						$response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}

					return strlen( $line );
				},
			)
		);

		$body   = curl_exec( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$error  = curl_error( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error

		curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		return array(
			'response' => array( 'code' => false === $body ? 0 : $status ),
			'headers'  => $response_headers,
			'body'     => false === $body ? $error : (string) $body,
		);
	}
}
