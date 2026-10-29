<?php
/**
 * Tests for the REST endpoints.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Rest;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use OddsAggregator\Rest\OddsController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Covers route declaration, the permission split and each payload.
 *
 * The sample provider backs these tests, so the responses are deterministic and
 * nothing is fetched. The WordPress REST classes are stubbed in the bootstrap:
 * route arguments are asserted from the recorded declaration, because the stub
 * does not run sanitise or default callbacks.
 */
final class OddsControllerTest extends TestCase {

	/**
	 * Resets the stores and returns a controller over the given provider.
	 *
	 * @param string              $provider Provider id.
	 * @param array<string,mixed> $settings Extra stored settings.
	 * @return OddsController
	 */
	private function controller( string $provider = 'mock', array $settings = array() ): OddsController {
		$GLOBALS['odds_aggregator_test_options']     = array(
			Settings::OPTION => array_merge( array( 'provider' => $provider ), $settings ),
		);
		$GLOBALS['odds_aggregator_test_transients']  = array();
		$GLOBALS['odds_aggregator_test_cron']        = array();
		$GLOBALS['odds_aggregator_test_actions']     = array();
		$GLOBALS['odds_aggregator_test_rest_routes'] = array();
		$GLOBALS['odds_aggregator_test_caps']        = array();
		$GLOBALS['odds_aggregator_test_filters']     = array();
		$GLOBALS['wpdb']->rows                       = array();
		$GLOBALS['odds_aggregator_test_http']        = array(
			'responses' => array(),
			'requests'  => array(),
		);

		$plugin_settings = new Settings();
		$markets         = new MarketRegistry();
		$factory         = new ProviderFactory( $plugin_settings, new HttpClient( new RateLimiter(), new RetryPolicy( 1 ) ) );

		return new OddsController(
			new OddsRepository( $factory, new OddsCache(), $markets ),
			$plugin_settings,
			new BookmakerRegistry(),
			$markets
		);
	}

	/**
	 * Routes are declared on rest_api_init rather than at construction.
	 */
	public function test_registration_waits_for_rest_api_init(): void {
		$controller = $this->controller();

		$controller->register();

		$hooks = array_column( $GLOBALS['odds_aggregator_test_actions'], 'hook' );

		$this->assertSame( array( 'rest_api_init' ), $hooks );
		$this->assertSame( array(), $GLOBALS['odds_aggregator_test_rest_routes'] );
	}

	/**
	 * Every documented route is declared, read-only, under the plugin namespace.
	 */
	public function test_all_routes_are_declared(): void {
		$this->controller()->register_routes();

		$routes = $GLOBALS['odds_aggregator_test_rest_routes'];

		$this->assertSame(
			array(
				'odds-aggregator/v1/events',
				'odds-aggregator/v1/bookmakers',
				'odds-aggregator/v1/markets',
				'odds-aggregator/v1/status',
				'odds-aggregator/v1/odds',
				'odds-aggregator/v1/block',
			),
			array_keys( $routes )
		);

		foreach ( $routes as $route => $args ) {
			$this->assertSame( 'GET', $args['methods'], $route );
			$this->assertIsCallable( $args['callback'], $route );
		}
	}

	/**
	 * The editor routes are gated and the front-end route is public, because the
	 * block has to render for logged-out visitors.
	 */
	public function test_only_the_block_route_is_public(): void {
		$this->controller()->register_routes();

		$routes = $GLOBALS['odds_aggregator_test_rest_routes'];

		foreach ( array( 'events', 'bookmakers', 'markets', 'status', 'odds' ) as $name ) {
			$this->assertSame(
				'can_edit',
				$routes[ 'odds-aggregator/v1/' . $name ]['permission_callback'][1] ?? null,
				$name . ' must stay editor-only.'
			);
		}

		$this->assertSame( '__return_true', $routes['odds-aggregator/v1/block']['permission_callback'] );
	}

	/**
	 * The event id is required on both routes that take one, and sanitised.
	 */
	public function test_event_parameters_are_required_and_sanitised(): void {
		$this->controller()->register_routes();

		$routes = $GLOBALS['odds_aggregator_test_rest_routes'];

		$this->assertTrue( $routes['odds-aggregator/v1/odds']['args']['event']['required'] );
		$this->assertSame( 'sanitize_text_field', $routes['odds-aggregator/v1/odds']['args']['event']['sanitize_callback'] );
		$this->assertTrue( $routes['odds-aggregator/v1/block']['args']['eventId']['required'] );
		$this->assertSame( 'sanitize_text_field', $routes['odds-aggregator/v1/block']['args']['eventId']['sanitize_callback'] );
	}

	/**
	 * The block route accepts only formats the renderer understands.
	 */
	public function test_the_block_route_constrains_the_format(): void {
		$this->controller()->register_routes();

		$format = $GLOBALS['odds_aggregator_test_rest_routes']['odds-aggregator/v1/block']['args']['format'];

		$this->assertSame( array( '', 'decimal', 'fractional', 'american' ), $format['enum'] );
		$this->assertSame( '', $format['default'] );
	}

	/**
	 * Access follows the edit_posts capability.
	 */
	public function test_access_requires_the_edit_capability(): void {
		$controller = $this->controller();

		$this->assertFalse( $controller->can_edit() );

		$GLOBALS['odds_aggregator_test_caps'] = array( 'edit_posts' );

		$this->assertTrue( $controller->can_edit() );
	}

	/**
	 * The event list carries the fields the editor's dropdown needs.
	 */
	public function test_events_are_listed_for_the_editor(): void {
		$events = $this->controller()->get_events()->get_data();

		$this->assertNotSame( array(), $events );
		$this->assertSame(
			array( 'id', 'sport', 'league', 'home', 'away', 'kickoff', 'title' ),
			array_keys( $events[0] )
		);
		$this->assertContains( 'eng-ars-che', array_column( $events, 'id' ) );
	}

	/**
	 * The bookmaker list covers the whole catalogue and flags which are enabled,
	 * so the editor can show the disabled ones greyed out rather than hide them.
	 */
	public function test_bookmakers_are_listed_with_their_enabled_flag(): void {
		$bookmakers = $this->controller( 'mock', array( 'bookmakers' => array( 'bet365', 'coral' ) ) )
			->get_bookmakers()
			->get_data();

		$this->assertSame( ( new BookmakerRegistry() )->ids(), array_column( $bookmakers, 'id' ) );

		$enabled = array_column( array_filter( $bookmakers, static fn( array $row ): bool => $row['enabled'] ), 'id' );

		$this->assertSame( array( 'bet365', 'coral' ), $enabled );
	}

	/**
	 * Only markets enabled site-wide are offered.
	 */
	public function test_only_enabled_markets_are_listed(): void {
		$markets = $this->controller( 'mock', array( 'markets' => array( 'btts' ) ) )->get_markets()->get_data();

		$this->assertSame( array( 'btts' ), array_column( $markets, 'key' ) );
	}

	/**
	 * A healthy source reports itself as such, with a link to the settings tab.
	 */
	public function test_status_reports_a_healthy_source(): void {
		$status = $this->controller()->get_status()->get_data();

		$this->assertSame( 'mock', $status['provider'] );
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 6, $status['eventCount'] );
		$this->assertNull( $status['error'] );
		$this->assertStringContainsString( 'page=', (string) $status['settingsUrl'] );
	}

	/**
	 * A failing source explains itself, which is what turns an empty dropdown
	 * into an actionable message.
	 */
	public function test_status_reports_a_failing_source(): void {
		$status = $this->controller( 'oddschecker' )->get_status()->get_data();

		$this->assertSame( 'oddschecker', $status['provider'] );
		$this->assertFalse( $status['ok'] );
		$this->assertSame( 0, $status['eventCount'] );
		$this->assertNotSame( '', (string) $status['error'] );
	}

	/**
	 * Prices come back for every enabled market when none is requested.
	 */
	public function test_odds_default_to_every_enabled_market(): void {
		$data = $this->controller()->get_odds( new WP_REST_Request( array( 'event' => 'eng-ars-che' ) ) )->get_data();

		$this->assertSame( 'eng-ars-che', $data['event'] );
		$this->assertSame(
			array( '1x2', 'ou25', 'btts' ),
			array_values( array_unique( array_column( $data['lines'], 'marketKey' ) ) )
		);
	}

	/**
	 * A requested market narrows the response.
	 */
	public function test_odds_can_be_narrowed_to_one_market(): void {
		$data = $this->controller()->get_odds(
			new WP_REST_Request(
				array(
					'event'   => 'eng-ars-che',
					'markets' => '1x2',
				)
			)
		)->get_data();

		$this->assertSame( array( '1x2' ), array_values( array_unique( array_column( $data['lines'], 'marketKey' ) ) ) );
	}

	/**
	 * A market that is not enabled site-wide cannot be requested back into the
	 * response, so the settings stay authoritative.
	 */
	public function test_a_disabled_market_cannot_be_requested(): void {
		$data = $this->controller( 'mock', array( 'markets' => array( '1x2' ) ) )->get_odds(
			new WP_REST_Request(
				array(
					'event'   => 'eng-ars-che',
					'markets' => 'btts',
				)
			)
		)->get_data();

		$this->assertSame( array(), $data['lines'] );
	}

	/**
	 * An unknown event is an empty row set rather than an error.
	 */
	public function test_an_unknown_event_returns_no_lines(): void {
		$data = $this->controller()->get_odds( new WP_REST_Request( array( 'event' => 'no-such-match' ) ) )->get_data();

		$this->assertSame( 'no-such-match', $data['event'] );
		$this->assertSame( array(), $data['lines'] );
	}

	/**
	 * Each returned row has the shape the front end reads.
	 */
	public function test_odds_rows_carry_the_expected_shape(): void {
		$data = $this->controller()->get_odds( new WP_REST_Request( array( 'event' => 'eng-ars-che' ) ) )->get_data();

		$this->assertSame(
			array( 'eventId', 'marketKey', 'bookmaker', 'prices', 'fetchedAt' ),
			array_keys( $data['lines'][0] )
		);
	}

	/**
	 * The block route renders markup and lets it be cached for as long as the
	 * odds behind it are considered fresh.
	 */
	public function test_the_block_route_renders_cacheable_markup(): void {
		$response = $this->controller()->get_block(
			new WP_REST_Request(
				array(
					'eventId'        => 'eng-ars-che',
					'markets'        => array( '1x2' ),
					'bookmakers'     => array( 'bet365', 'coral' ),
					'format'         => 'decimal',
					'highlightBest'  => true,
					'highlightWorst' => false,
					'showSwitcher'   => true,
				)
			)
		);

		$html = $response->get_data()['html'];

		$this->assertStringContainsString( 'Arsenal v Chelsea', $html );
		$this->assertStringContainsString( 'bet365', $html );
		$this->assertSame( 'public, max-age=60', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * The cache header follows the configured fresh window.
	 */
	public function test_the_block_cache_header_follows_the_setting(): void {
		$response = $this->controller( 'mock', array( 'cache_ttl' => 120 ) )->get_block(
			new WP_REST_Request( array( 'eventId' => 'eng-ars-che' ) )
		);

		$this->assertSame( 'public, max-age=120', $response->get_headers()['Cache-Control'] );
	}
}
