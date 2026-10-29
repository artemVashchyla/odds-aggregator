<?php
/**
 * Tests for the outbound HTTP wrapper.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Http;

use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\HttpException;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers the three things the wrapper exists to centralise: throttling, retries
 * and turning a failed response into an exception.
 *
 * The WordPress transport is stubbed, so a test queues the responses it wants
 * and then counts the attempts the client actually made. Retry delays are
 * configured to zero to keep the suite fast.
 */
final class HttpClientTest extends TestCase {

	/**
	 * Empties the transport stub and the limiter's backing store.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_http']       = array(
			'responses' => array(),
			'requests'  => array(),
		);
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_filters']    = array();
		$GLOBALS['wpdb']->rows                      = array();
	}

	/**
	 * Clears the User-Agent filter a test may have installed.
	 */
	protected function tearDown(): void {
		$GLOBALS['odds_aggregator_test_filters'] = array();
	}

	/**
	 * A client that retries without waiting.
	 *
	 * @param int $attempts Attempts allowed, including the first.
	 * @return HttpClient
	 */
	private function client( int $attempts = 3 ): HttpClient {
		return new HttpClient( new RateLimiter( 600 ), new RetryPolicy( $attempts, 0, 0 ) );
	}

	/**
	 * Queues one transport response.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $body    Response body.
	 * @param array<string,string> $headers Response headers.
	 * @return void
	 */
	private function queue( int $status, string $body = '', array $headers = array() ): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	/**
	 * URLs the transport was asked for.
	 *
	 * @return string[]
	 */
	private function requests(): array {
		return $GLOBALS['odds_aggregator_test_http']['requests'];
	}

	/**
	 * A successful GET returns the body and makes exactly one request.
	 */
	public function test_successful_get_returns_the_body(): void {
		$this->queue( 200, '<html>odds</html>' );

		$this->assertSame( '<html>odds</html>', $this->client()->get( 'https://example.test/page', 'test' ) );
		$this->assertCount( 1, $this->requests() );
	}

	/**
	 * Any 2xx counts as success.
	 */
	public function test_any_2xx_is_a_success(): void {
		$this->queue( 204, '' );

		$this->assertSame( '', $this->client()->get( 'https://example.test/page', 'test' ) );
	}

	/**
	 * JSON is decoded for the caller.
	 */
	public function test_json_is_decoded(): void {
		$this->queue( 200, '{"a":1,"b":[2,3]}' );

		$this->assertSame(
			array(
				'a' => 1,
				'b' => array( 2, 3 ),
			),
			$this->client()->get_json( 'https://example.test/api', 'test' )
		);
	}

	/**
	 * A body that is not JSON is a failure, not an empty result.
	 */
	public function test_a_non_json_body_throws(): void {
		$this->queue( 200, '<html>maintenance</html>' );

		$this->expectException( HttpException::class );
		$this->expectExceptionMessage( 'did not return JSON' );

		$this->client()->get_json( 'https://example.test/api', 'test' );
	}

	/**
	 * Response headers come back lowercased and flattened, which is what the
	 * metered-feed caller reads its allowance from.
	 */
	public function test_response_headers_are_normalised(): void {
		$this->queue(
			200,
			'[]',
			array(
				'X-Requests-Remaining' => '499',
				'X-Requests-Used'      => array( '1', '2' ),
			)
		);

		$meta = $this->client()->get_json_meta( 'https://example.test/api', 'test' );

		$this->assertSame( '499', $meta['headers']['x-requests-remaining'] );
		$this->assertSame( '1', $meta['headers']['x-requests-used'] );
	}

	/**
	 * A transient failure is retried and a later success is returned.
	 */
	public function test_a_transient_failure_is_retried(): void {
		$this->queue( 503 );
		$this->queue( 200, 'recovered' );

		$this->assertSame( 'recovered', $this->client()->get( 'https://example.test/page', 'test' ) );
		$this->assertCount( 2, $this->requests() );
	}

	/**
	 * Once the attempt budget is spent the failure surfaces, carrying the status.
	 */
	public function test_exhausted_retries_throw_with_the_last_status(): void {
		$this->queue( 503 );
		$this->queue( 503 );

		try {
			$this->client( 2 )->get( 'https://example.test/page', 'test' );

			$this->fail( 'Expected an HttpException.' );
		} catch ( HttpException $exception ) {
			$this->assertSame( 503, $exception->getCode() );
			$this->assertStringContainsString( '2 attempt(s)', $exception->getMessage() );
			$this->assertCount( 2, $this->requests() );
		}
	}

	/**
	 * A permanent answer is not retried, so no allowance is wasted on it.
	 */
	public function test_a_permanent_failure_is_not_retried(): void {
		$this->queue( 404 );

		try {
			$this->client( 3 )->get( 'https://example.test/page', 'test' );

			$this->fail( 'Expected an HttpException.' );
		} catch ( HttpException $exception ) {
			$this->assertSame( 404, $exception->getCode() );
			$this->assertCount( 1, $this->requests() );
		}
	}

	/**
	 * The failure message must not leak the query string, which is where an API
	 * key lives.
	 */
	public function test_failure_message_hides_the_query_string(): void {
		$this->queue( 401 );

		try {
			$this->client( 1 )->get( 'https://example.test/api?apiKey=super-secret', 'test' );

			$this->fail( 'Expected an HttpException.' );
		} catch ( HttpException $exception ) {
			$this->assertStringNotContainsString( 'super-secret', $exception->getMessage() );
			$this->assertStringContainsString( 'https://example.test/api', $exception->getMessage() );
		}
	}

	/**
	 * The same applies to a body that was not JSON.
	 */
	public function test_json_failure_message_hides_the_query_string(): void {
		$this->queue( 200, 'nope' );

		try {
			$this->client( 1 )->get_json( 'https://example.test/api?apiKey=super-secret', 'test' );

			$this->fail( 'Expected an HttpException.' );
		} catch ( HttpException $exception ) {
			$this->assertStringNotContainsString( 'super-secret', $exception->getMessage() );
		}
	}

	/**
	 * An empty bucket refuses the request outright, without touching the network.
	 */
	public function test_an_empty_bucket_refuses_the_request(): void {
		$client = new HttpClient( new RateLimiter( 1 ), new RetryPolicy( 1, 0, 0 ) );

		$this->queue( 200, 'first' );
		$this->queue( 200, 'second' );

		$this->assertSame( 'first', $client->get( 'https://example.test/page', 'test' ) );

		try {
			$client->get( 'https://example.test/page', 'test' );

			$this->fail( 'Expected an HttpException.' );
		} catch ( HttpException $exception ) {
			$this->assertSame( 429, $exception->getCode() );
			$this->assertStringContainsString( 'Rate limit reached for "test"', $exception->getMessage() );
			$this->assertCount( 1, $this->requests(), 'A throttled request must not reach the transport.' );
		}
	}

	/**
	 * Each bucket is throttled on its own.
	 */
	public function test_buckets_are_throttled_separately(): void {
		$client = new HttpClient( new RateLimiter( 1 ), new RetryPolicy( 1, 0, 0 ) );

		$this->queue( 200, 'a' );
		$this->queue( 200, 'b' );

		$this->assertSame( 'a', $client->get( 'https://example.test/a', 'oddschecker' ) );
		$this->assertSame( 'b', $client->get( 'https://example.test/b', 'oddsapi' ) );
	}

	/**
	 * The plugin identifies itself unless the caller supplies its own agent.
	 */
	public function test_default_user_agent_is_filterable(): void {
		$GLOBALS['odds_aggregator_test_filters']['odds_aggregator_user_agent'] = static fn(): string => 'Custom/1.0';

		$this->queue( 200, 'ok' );

		$this->assertSame( 'ok', $this->client()->get( 'https://example.test/page', 'test' ) );
	}

	/**
	 * The requested URL reaches the transport unchanged.
	 */
	public function test_the_url_is_passed_through_verbatim(): void {
		$this->queue( 200, 'ok' );

		$this->client()->get( 'https://example.test/page?a=1&b=2', 'test' );

		$this->assertSame( array( 'https://example.test/page?a=1&b=2' ), $this->requests() );
	}
}
