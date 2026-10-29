<?php
/**
 * REST endpoints.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Rest;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Admin\SettingsPage;
use OddsAggregator\Blocks\OddsTableRenderer;
use OddsAggregator\Domain\Bookmaker;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Odds\OddsRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only endpoints backing the block editor, plus the public /block route
 * the front end polls to keep rendered tables current.
 */
final class OddsController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'odds-aggregator/v1';

	/**
	 * Constructor.
	 *
	 * @param OddsRepository    $repository Cached odds access.
	 * @param Settings          $settings   Plugin settings.
	 * @param BookmakerRegistry $bookmakers Bookmaker catalogue.
	 * @param MarketRegistry    $markets    Market catalogue.
	 */
	public function __construct(
		private readonly OddsRepository $repository,
		private readonly Settings $settings,
		private readonly BookmakerRegistry $bookmakers,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_events' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookmakers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bookmakers' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/markets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_markets' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/odds',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_odds' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'event'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'markets' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		$list = array(
			'type'    => 'array',
			'items'   => array( 'type' => 'string' ),
			'default' => array(),
		);

		register_rest_route(
			self::NAMESPACE,
			'/block',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_block' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'eventId'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'markets'        => $list,
					'bookmakers'     => $list,
					'format'         => array(
						'type'    => 'string',
						'enum'    => array( '', 'decimal', 'fractional', 'american' ),
						'default' => '',
					),
					'highlightBest'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'highlightWorst' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'showSwitcher'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Renders an odds table block from its attributes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_block( WP_REST_Request $request ): WP_REST_Response {
		$attributes = array();

		foreach ( array( 'eventId', 'markets', 'bookmakers', 'format', 'highlightBest', 'highlightWorst', 'showSwitcher' ) as $name ) {
			$attributes[ $name ] = $request->get_param( $name );
		}

		$renderer = new OddsTableRenderer( $this->repository, $this->settings, $this->bookmakers, $this->markets );
		$response = new WP_REST_Response( array( 'html' => $renderer->render( $attributes ) ) );

		$response->header( 'Cache-Control', 'public, max-age=' . $this->settings->cache_ttl() );

		return $response;
	}

	/**
	 * Editor-only access.
	 *
	 * @return bool
	 */
	public function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Lists available events.
	 *
	 * @return WP_REST_Response
	 */
	public function get_events(): WP_REST_Response {
		return new WP_REST_Response(
			array_map(
				static fn( Event $event ): array => $event->to_array(),
				$this->repository->events()
			)
		);
	}

	/**
	 * Lists known bookmakers, flagging the ones enabled site-wide.
	 *
	 * @return WP_REST_Response
	 */
	public function get_bookmakers(): WP_REST_Response {
		$enabled = $this->settings->bookmakers();
		$out     = array();

		foreach ( $this->bookmakers->all() as $bookmaker ) {
			if ( ! $bookmaker instanceof Bookmaker ) {
				continue;
			}

			$out[] = $bookmaker->to_array() + array(
				'enabled' => in_array( $bookmaker->id(), $enabled, true ),
			);
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * Lists markets enabled site-wide.
	 *
	 * @return WP_REST_Response
	 */
	public function get_markets(): WP_REST_Response {
		$enabled = $this->settings->markets();
		$out     = array();

		foreach ( $this->markets->all() as $market ) {
			if ( $market instanceof Market && in_array( $market->key(), $enabled, true ) ) {
				$out[] = $market->to_array();
			}
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * Reports which source is active and whether it last succeeded.
	 *
	 * The editor uses this to explain an empty event list rather than showing
	 * a bare empty dropdown.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		// Fetch first: a failing fetch is what records the error we report.
		$events = $this->repository->events();
		$error  = OddsRepository::last_error();

		return new WP_REST_Response(
			array(
				'provider'    => $this->settings->provider(),
				'eventCount'  => count( $events ),
				'ok'          => null === $error && array() !== $events,
				'error'       => null === $error ? null : wp_specialchars_decode( $error['message'], ENT_QUOTES ),
				'settingsUrl' => SettingsPage::url( 'general' ),
			)
		);
	}

	/**
	 * Returns prices for one event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_odds( WP_REST_Request $request ): WP_REST_Response {
		$event_id  = (string) $request->get_param( 'event' );
		$requested = (string) $request->get_param( 'markets' );

		$market_keys = '' === $requested
			? $this->settings->markets()
			: array_values( array_intersect( explode( ',', $requested ), $this->settings->markets() ) );

		$lines = $this->repository->odds( $event_id, $market_keys );

		return new WP_REST_Response(
			array(
				'event' => $event_id,
				'lines' => array_map(
					static fn( OddsLine $line ): array => $line->to_array(),
					$lines
				),
			)
		);
	}
}
