<?php
/**
 * Outbound HTTP.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Http;

/**
 * Wrapper around the WordPress HTTP API.
 *
 * Centralises the three things every outbound call needs and that are easy to
 * forget per call site: a rate limit, a retry policy, and turning WordPress's
 * WP_Error return convention into an exception.
 */
final class HttpClient {

	/**
	 * Constructor.
	 *
	 * @param RateLimiter $rate_limiter Throttle.
	 * @param RetryPolicy $retry_policy Backoff rules.
	 * @param int         $timeout      Per-request timeout in seconds.
	 */
	public function __construct(
		private readonly RateLimiter $rate_limiter,
		private readonly RetryPolicy $retry_policy,
		private readonly int $timeout = 10
	) {}

	/**
	 * Performs a GET and returns the response body.
	 *
	 * @param string               $url     Absolute URL.
	 * @param string               $bucket  Rate-limit bucket, normally the provider id.
	 * @param array<string,string> $headers Extra request headers.
	 * @return string
	 * @throws HttpException When throttled, or when every attempt fails.
	 */
	public function get( string $url, string $bucket, array $headers = array() ): string {
		return (string) $this->fetch( $url, $bucket, $headers )['body'];
	}

	/**
	 * Performs a GET and returns the body together with the response headers.
	 *
	 * Metered feeds report their remaining allowance in a header, which is
	 * worth showing an administrator, so one caller needs more than the body.
	 *
	 * @param string               $url     Absolute URL.
	 * @param string               $bucket  Rate-limit bucket, normally the provider id.
	 * @param array<string,string> $headers Extra request headers.
	 * @return array{body:string,headers:array<string,string>}
	 * @throws HttpException When throttled, or when every attempt fails.
	 */
	private function fetch( string $url, string $bucket, array $headers = array() ): array {
		if ( ! $this->rate_limiter->consume( $bucket ) ) {
			throw new HttpException(
				sprintf(
					'Rate limit reached for "%s"; retry in %ds.',
					esc_html( $bucket ),
					(int) $this->rate_limiter->retry_after( $bucket )
				),
				429
			);
		}

		// A caller may supply its own User-Agent (a scraper needs a browser's);
		// otherwise the plugin identifies itself.
		$agent = (string) ( $headers['User-Agent'] ?? $this->user_agent() );
		unset( $headers['User-Agent'] );

		$args = array(
			'timeout'     => $this->timeout,
			'redirection' => 3,
			'user-agent'  => $agent,
			'headers'     => array_merge(
				array(
					'Accept'          => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-GB,en;q=0.9',
				),
				$headers
			),
		);

		$attempt        = 0;
		$last_status    = 0;
		$last_message   = 'Request was never attempted.';
		$total_attempts = $this->retry_policy->max_attempts();

		while ( $attempt < $total_attempts ) {
			++$attempt;

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_status  = 0;
				$last_message = $response->get_error_message();
			} else {
				$last_status = (int) wp_remote_retrieve_response_code( $response );

				if ( $last_status >= 200 && $last_status < 300 ) {
					return array(
						'body'    => (string) wp_remote_retrieve_body( $response ),
						'headers' => $this->response_headers( $response ),
					);
				}

				$last_message = sprintf( 'Upstream responded %d.', $last_status );
			}

			if ( ! $this->retry_policy->should_retry( $attempt, $last_status ) ) {
				break;
			}

			$retry_after = is_wp_error( $response )
				? null
				: $this->parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );

			usleep( $this->retry_policy->delay_microseconds( $attempt, $retry_after ) );
		}

		throw new HttpException(
			sprintf( 'GET %s failed after %d attempt(s): %s', esc_html( $this->without_query( $url ) ), (int) $attempt, esc_html( $last_message ) ),
			(int) $last_status
		);
	}

	/**
	 * Performs a GET and decodes a JSON response.
	 *
	 * @param string               $url     Absolute URL.
	 * @param string               $bucket  Rate-limit bucket.
	 * @param array<string,string> $headers Extra request headers.
	 * @return array<mixed>
	 * @throws HttpException When the request fails or the body is not a JSON array.
	 */
	public function get_json( string $url, string $bucket, array $headers = array() ): array {
		return $this->get_json_meta( $url, $bucket, $headers )['data'];
	}

	/**
	 * Performs a GET, decodes JSON, and keeps the response headers.
	 *
	 * @param string               $url     Absolute URL.
	 * @param string               $bucket  Rate-limit bucket.
	 * @param array<string,string> $headers Extra request headers.
	 * @return array{data:array<mixed>,headers:array<string,string>}
	 * @throws HttpException When the request fails or the body is not a JSON array.
	 */
	public function get_json_meta( string $url, string $bucket, array $headers = array() ): array {
		$response = $this->fetch( $url, $bucket, array_merge( array( 'Accept' => 'application/json' ), $headers ) );
		$decoded  = json_decode( (string) $response['body'], true );

		if ( ! is_array( $decoded ) ) {
			throw new HttpException( sprintf( 'GET %s did not return JSON.', esc_html( $this->without_query( $url ) ) ) );
		}

		return array(
			'data'    => $decoded,
			'headers' => $response['headers'],
		);
	}

	/**
	 * Response headers as a lowercase-keyed map of single values.
	 *
	 * WordPress hands back a case-insensitive dictionary object rather than an
	 * array, and a repeated header arrives as a list, so both are flattened.
	 *
	 * @param mixed $response Transport response.
	 * @return array<string,string>
	 */
	private function response_headers( $response ): array {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$flat = array();

		foreach ( (array) $headers as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value )
				? (string) reset( $value )
				: (string) $value;
		}

		return $flat;
	}

	/**
	 * URL without its query string, safe to put in messages.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private function without_query( string $url ): string {
		return explode( '?', $url, 2 )[0];
	}

	/**
	 * Default User-Agent: identifies the plugin, used unless a caller overrides it.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		$agent = sprintf(
			'OddsAggregator/%s (+%s)',
			defined( 'ODDS_AGGREGATOR_VERSION' ) ? ODDS_AGGREGATOR_VERSION : '1.0.0',
			home_url( '/' )
		);

		/**
		 * Filters the outbound User-Agent header.
		 *
		 * @param string $agent User-Agent value.
		 */
		return (string) apply_filters( 'odds_aggregator_user_agent', $agent );
	}

	/**
	 * Reads a Retry-After header, which may be seconds or an HTTP date.
	 *
	 * @param mixed $header Raw header value.
	 * @return int|null Seconds to wait, or null when absent or unparseable.
	 */
	private function parse_retry_after( $header ): ?int {
		if ( ! is_string( $header ) || '' === $header ) {
			return null;
		}

		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}

		$timestamp = strtotime( $header );

		if ( false === $timestamp ) {
			return null;
		}

		return max( 0, $timestamp - time() );
	}
}
