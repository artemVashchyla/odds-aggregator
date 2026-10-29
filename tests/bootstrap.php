<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader and defines the few WordPress functions the
 * pure-PHP classes touch, so unit tests run without a WordPress installation.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ODDS_AGGREGATOR_URL' ) ) {
	define( 'ODDS_AGGREGATOR_URL', 'https://example.test/wp-content/plugins/odds-aggregator/' );
}

if ( ! defined( 'ODDS_AGGREGATOR_PATH' ) ) {
	define( 'ODDS_AGGREGATOR_PATH', dirname( __DIR__ ) . '/' );
}

/**
 * Recorded upstream responses, addressed from a constant rather than relative
 * to the test file, so a test can be moved between directories freely.
 */
if ( ! defined( 'ODDS_AGGREGATOR_FIXTURES' ) ) {
	define( 'ODDS_AGGREGATOR_FIXTURES', __DIR__ . '/fixtures/' );
}

$GLOBALS['odds_aggregator_test_filters'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub: applies the callback a test registered for the hook, if any.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Extra arguments.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		$callback = $GLOBALS['odds_aggregator_test_filters'][ $hook_name ] ?? null;

		return null === $callback ? $value : $callback( $value, ...$args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub: no-op.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub: returns the string unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value returned when unset.
	 * @return mixed
	 */
	function get_option( string $name, $fallback = false ) {
		return $GLOBALS['odds_aggregator_test_options'][ $name ] ?? $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Ignored.
	 * @return bool
	 */
	function update_option( string $name, $value, bool $autoload = true ): bool {
		$GLOBALS['odds_aggregator_test_options'][ $name ] = $value;

		return true;
	}
}

$GLOBALS['odds_aggregator_test_options'] = array();

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub: lowercases and strips anything outside a-z0-9_-.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: strips tags and trims.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function sanitize_text_field( string $value ): string {
		return trim( wp_strip_all_tags_stub( $value ) );
	}

	/**
	 * Helper for the sanitize_text_field stub.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function wp_strip_all_tags_stub( string $value ): string {
		return (string) preg_replace( '/<[^>]*>/', '', $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub: accepts only http and https URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}

$GLOBALS['odds_aggregator_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name Transient name.
	 * @return mixed False when unset.
	 */
	function get_transient( string $name ) {
		return $GLOBALS['odds_aggregator_test_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub backed by an in-memory store; expiry is ignored.
	 *
	 * @param string $name       Transient name.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Ignored.
	 * @return bool
	 */
	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$GLOBALS['odds_aggregator_test_transients'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub backed by an in-memory store.
	 *
	 * @param string $name Transient name.
	 * @return bool
	 */
	function delete_transient( string $name ): bool {
		unset( $GLOBALS['odds_aggregator_test_transients'][ $name ] );

		return true;
	}
}

$GLOBALS['odds_aggregator_test_cron'] = array();

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Stub backed by an in-memory queue.
	 *
	 * @param string       $hook Hook name.
	 * @param array<mixed> $args Arguments.
	 * @return int|false
	 */
	function wp_next_scheduled( string $hook, array $args = array() ) {
		foreach ( $GLOBALS['odds_aggregator_test_cron'] as $job ) {
			if ( $job['hook'] === $hook && $job['args'] === $args ) {
				return $job['timestamp'];
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Stub backed by an in-memory queue.
	 *
	 * @param int          $timestamp Run time.
	 * @param string       $hook      Hook name.
	 * @param array<mixed> $args      Arguments.
	 * @return bool
	 */
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['odds_aggregator_test_cron'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);

		return true;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub: HTML-escapes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Stub: HTML-escapes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub: HTML-escapes.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub: HTML-escapes the untranslated string.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Stub: HTML-escapes the untranslated string.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'wp_kses_data' ) ) {
	/**
	 * Stub: returns the markup unchanged.
	 *
	 * @param string $data Markup.
	 * @return string
	 */
	function wp_kses_data( string $data ): string {
		return $data;
	}
}

if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	/**
	 * Stub: renders the class attribute only.
	 *
	 * @param array<string,string> $extra Extra attributes.
	 * @return string
	 */
	function get_block_wrapper_attributes( array $extra = array() ): string {
		return 'class="' . esc_attr( $extra['class'] ?? '' ) . '"';
	}
}

$GLOBALS['odds_aggregator_test_caps'] = array();

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub: nobody is logged in until a test grants a capability.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return in_array( $capability, (array) $GLOBALS['odds_aggregator_test_caps'], true );
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	/**
	 * Stub: difference in seconds.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return string
	 */
	function human_time_diff( int $from, int $to = 0 ): string {
		return abs( $to - $from ) . ' secs';
	}
}

$GLOBALS['odds_aggregator_test_http'] = array(
	'responses' => array(),
	'requests'  => array(),
);

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Stub: records the URL and returns the next queued response.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Request arguments.
	 * @return array<string,mixed>
	 */
	function wp_remote_get( string $url, array $args = array() ): array {
		$GLOBALS['odds_aggregator_test_http']['requests'][] = $url;

		return array_shift( $GLOBALS['odds_aggregator_test_http']['responses'] ) ?? array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '[]',
		);
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub: stubbed responses are never errors.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stub.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Stub.
	 *
	 * @param array<string,mixed> $response Response.
	 * @param string              $header   Header name.
	 * @return string
	 */
	function wp_remote_retrieve_header( array $response, string $header ): string {
		return (string) ( $response['headers'][ $header ] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/**
	 * Stub.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return array<string,string>
	 */
	function wp_remote_retrieve_headers( array $response ): array {
		return (array) ( $response['headers'] ?? array() );
	}
}

$GLOBALS['odds_aggregator_test_settings_errors'] = array();

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Stub: collects notices so a test can read them back.
	 *
	 * @param string $setting Option name.
	 * @param string $code    Notice code.
	 * @param string $message Notice text.
	 * @param string $type    Notice type.
	 */
	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['odds_aggregator_test_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Stub.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	function wp_rand( int $min = 0, int $max = 0 ): int {
		return random_int( $min, max( $min, $max ) );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub: appends already-encoded arguments.
	 *
	 * @param array<string,string|string[]> $args Arguments.
	 * @param string                        $url  URL.
	 * @return string
	 */
	function add_query_arg( array $args, string $url ): string {
		$pairs = array();

		foreach ( $args as $key => $value ) {
			foreach ( is_array( $value ) ? $value : array( $value ) as $index => $item ) {
				$pairs[] = ( is_array( $value ) ? $key . '[' . $index . ']' : $key ) . '=' . $item;
			}
		}

		return $url . '?' . implode( '&', $pairs );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Stub: formats in UTC.
	 *
	 * @param string $format    Date format.
	 * @param int    $timestamp Unix timestamp.
	 * @return string
	 */
	function wp_date( string $format, int $timestamp ): string {
		return gmdate( $format, $timestamp );
	}
}

/**
 * In-memory stand-in for the three options-table queries Lock runs.
 */
final class Odds_Aggregator_Test_Wpdb {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * Rows, option name => value.
	 *
	 * @var array<string,string>
	 */
	public array $rows = array();

	/**
	 * Keeps the query and its arguments together for query() and get_var().
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		return (string) wp_json_encode( array( $query, $args ) );
	}

	/**
	 * Runs an INSERT IGNORE or DELETE.
	 *
	 * @param string $prepared Prepared query.
	 * @return int Affected rows.
	 */
	public function query( string $prepared ): int {
		list( $query, $args ) = json_decode( $prepared, true );

		if ( str_starts_with( $query, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $args[0] ] ) ) {
				return 0;
			}

			$this->rows[ $args[0] ] = $args[1];

			return 1;
		}

		if ( ! isset( $this->rows[ $args[0] ] ) || ( isset( $args[1] ) && $this->rows[ $args[0] ] !== $args[1] ) ) {
			return 0;
		}

		unset( $this->rows[ $args[0] ] );

		return 1;
	}

	/**
	 * Runs a SELECT option_value.
	 *
	 * @param string $prepared Prepared query.
	 * @return string|null
	 */
	public function get_var( string $prepared ): ?string {
		list( , $args ) = json_decode( $prepared, true );

		return $this->rows[ $args[0] ] ?? null;
	}
}

$GLOBALS['wpdb'] = new Odds_Aggregator_Test_Wpdb();

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $path Route.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . $path;
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Stub: strips tags, keeps entities.
	 *
	 * @param string       $content Content.
	 * @param array<mixed> $allowed Allowed tags; only the empty set is supported.
	 * @return string
	 */
	function wp_kses( string $content, array $allowed ): string {
		return (string) preg_replace( '/<[^>]*>/', '', $content );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $text  Text.
	 * @param int    $flags Quote style.
	 * @return string
	 */
	function wp_specialchars_decode( string $text, int $flags = ENT_NOQUOTES ): string {
		return htmlspecialchars_decode( $text, $flags );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $path Path below wp-admin.
	 * @return string
	 */
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}

$GLOBALS['odds_aggregator_test_actions'] = array();

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub: records the hook so a test can assert what was wired up.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Argument count.
	 * @return bool
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['odds_aggregator_test_actions'][] = array(
			'hook'     => $hook_name,
			'callback' => $callback,
			'priority' => $priority,
		);

		return true;
	}
}

$GLOBALS['odds_aggregator_test_rest_routes'] = array();

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Stub: records the route so a test can assert its arguments.
	 *
	 * @param string              $route_namespace Namespace.
	 * @param string              $route           Route.
	 * @param array<string,mixed> $args            Route arguments.
	 * @return bool
	 */
	function register_rest_route( string $route_namespace, string $route, array $args = array() ): bool {
		$GLOBALS['odds_aggregator_test_rest_routes'][ $route_namespace . $route ] = $args;

		return true;
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Stub: only the method constants are used.
	 */
	final class WP_REST_Server {

		/**
		 * Read methods.
		 *
		 * @var string
		 */
		public const READABLE = 'GET';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub: a bag of already-sanitised parameters.
	 *
	 * The real class applies the sanitise and default callbacks declared on the
	 * route; tests that care about those read the recorded route definition.
	 */
	final class WP_REST_Request {

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $params Request parameters.
		 */
		public function __construct( private array $params = array() ) {}

		/**
		 * One parameter.
		 *
		 * @param string $name Parameter name.
		 * @return mixed Null when absent.
		 */
		public function get_param( string $name ) {
			return $this->params[ $name ] ?? null;
		}

		/**
		 * Sets one parameter.
		 *
		 * @param string $name  Parameter name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set_param( string $name, $value ): void {
			$this->params[ $name ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stub: payload plus response headers.
	 */
	final class WP_REST_Response {

		/**
		 * Response headers.
		 *
		 * @var array<string,string>
		 */
		private array $headers = array();

		/**
		 * Constructor.
		 *
		 * @param mixed $data Payload.
		 */
		public function __construct( private mixed $data = null ) {}

		/**
		 * Payload.
		 *
		 * @return mixed
		 */
		public function get_data(): mixed {
			return $this->data;
		}

		/**
		 * Sets one response header.
		 *
		 * @param string $name  Header name.
		 * @param string $value Header value.
		 * @return void
		 */
		public function header( string $name, string $value ): void {
			$this->headers[ $name ] = $value;
		}

		/**
		 * All response headers.
		 *
		 * @return array<string,string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}
