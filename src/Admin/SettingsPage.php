<?php
/**
 * Admin settings screen.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Admin;

use OddsAggregator\Domain\Bookmaker;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Odds\OddsFormat;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\MockProvider;
use OddsAggregator\Providers\OddsApiProvider;
use OddsAggregator\Providers\ProviderException;
use OddsAggregator\Providers\ProviderFactory;
use OddsAggregator\Rest\OddsController;

/**
 * Top-level "Odds Aggregator" admin screen.
 *
 * Built on the Settings API rather than a hand-rolled form, so nonces,
 * capability checks and the options write are handled by core. The screen
 * opens with the health of the data source, followed by one form per section;
 * each form posts only its own fields - {@see Settings::sanitize()} merges
 * them over the stored value so saving one section never clears another.
 */
final class SettingsPage {

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	private const GROUP = 'odds_aggregator_settings_group';

	/**
	 * Menu and page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'odds-aggregator';

	/**
	 * Admin-post action that switches to sample data.
	 *
	 * @var string
	 */
	public const ACTION_USE_SAMPLE = 'odds_aggregator_use_sample';

	/**
	 * Admin-post action that re-tests the active source.
	 *
	 * @var string
	 */
	public const ACTION_CHECK_SOURCE = 'odds_aggregator_check_source';

	/**
	 * Provider id of the sample data source.
	 *
	 * @var string
	 */
	private const SAMPLE_PROVIDER = 'mock';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings   Plugin settings.
	 * @param ProviderFactory   $factory    Provider construction.
	 * @param BookmakerRegistry $bookmakers Bookmaker catalogue.
	 * @param MarketRegistry    $markets    Market catalogue.
	 * @param OddsRepository    $repository Cached odds access.
	 * @param SourceStatus      $status     Health of the active source.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderFactory $factory,
		private readonly BookmakerRegistry $bookmakers,
		private readonly MarketRegistry $markets,
		private readonly OddsRepository $repository,
		private readonly SourceStatus $status
	) {}

	/**
	 * Section ids mapped to their titles.
	 *
	 * @return array<string,string>
	 */
	public static function sections(): array {
		return array(
			'general'     => __( 'Data source & display', 'odds-aggregator' ),
			'bookmakers'  => __( 'Bookmakers', 'odds-aggregator' ),
			'markets'     => __( 'Markets', 'odds-aggregator' ),
			'performance' => __( 'Performance', 'odds-aggregator' ),
		);
	}

	/**
	 * Admin URL of the screen, optionally at a section.
	 *
	 * @param string $section Section id.
	 * @return string
	 */
	public static function url( string $section = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $section ? $url : $url . '#' . $section;
	}

	/**
	 * Registers the menu entry, settings, actions and assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_provider_error' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION_USE_SAMPLE, array( $this, 'handle_use_sample' ) );
		add_action( 'admin_post_' . self::ACTION_CHECK_SOURCE, array( $this, 'handle_check_source' ) );
		add_filter( 'pre_update_option_' . Settings::OPTION, array( $this, 'guard_provider_change' ), 10, 2 );
	}

	/**
	 * Adds the top-level menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Odds Aggregator', 'odds-aggregator' ),
			__( 'Odds Aggregator', 'odds-aggregator' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-line',
			81
		);
	}

	/**
	 * Registers the option.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Loads the screen's stylesheet and script on this screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'odds-aggregator-admin',
			plugins_url( 'assets/admin.css', ODDS_AGGREGATOR_FILE ),
			array(),
			ODDS_AGGREGATOR_VERSION
		);

		wp_enqueue_script(
			'odds-aggregator-admin',
			plugins_url( 'assets/admin.js', ODDS_AGGREGATOR_FILE ),
			array(),
			ODDS_AGGREGATOR_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$demo = get_page_by_path( 'odds-demo' );
		$docs = file_exists( ODDS_AGGREGATOR_PATH . 'DOCS.md' ) ? plugins_url( 'DOCS.md', ODDS_AGGREGATOR_FILE ) : '';

		?>
		<div class="wrap oa-admin">
			<div class="oa-top">
				<div>
					<h1><?php echo esc_html__( 'Odds Aggregator', 'odds-aggregator' ); ?></h1>
					<p class="oa-sub"><?php echo esc_html__( 'Compare bookmaker prices in posts and pages with the Odds Table block.', 'odds-aggregator' ); ?></p>
				</div>
				<div class="oa-top-actions">
					<?php if ( $demo instanceof \WP_Post ) : ?>
						<a class="button" href="<?php echo esc_url( (string) get_permalink( $demo ) ); ?>"><?php echo esc_html__( 'View demo page', 'odds-aggregator' ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $docs ) : ?>
						<a class="button" href="<?php echo esc_url( $docs ); ?>"><?php echo esc_html__( 'Documentation', 'odds-aggregator' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end">

			<?php settings_errors(); ?>

			<?php $this->render_health(); ?>
			<?php $this->render_tabs(); ?>

			<div class="oa-grid">
				<div class="oa-main">
					<?php
					$this->render_general_section();
					$this->render_bookmakers_section();
					$this->render_markets_section();
					$this->render_performance_section();
					?>
				</div>
				<aside class="oa-side">
					<?php $this->render_preview(); ?>
					<?php $this->render_quick_links( $demo instanceof \WP_Post ? $demo : null, $docs ); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Health panel: state of the data source and key figures.
	 *
	 * @return void
	 */
	private function render_health(): void {
		$provider = $this->status->provider();
		$error    = $this->status->error();
		$events   = count( $this->status->events() );
		$healthy  = $this->status->is_healthy();

		?>
		<section class="oa-health <?php echo $healthy ? 'is-ok' : 'is-bad'; ?>" aria-label="<?php echo esc_attr__( 'Data source health', 'odds-aggregator' ); ?>">
			<div class="oa-health-main">
				<h2 class="oa-health-title">
					<span class="oa-health-ico" aria-hidden="true"><?php echo $healthy ? '&#10003;' : '!'; ?></span>
					<?php
					echo esc_html(
						$healthy
							? __( 'Odds are flowing', 'odds-aggregator' )
							: __( 'The data source is not returning odds', 'odds-aggregator' )
					);
					?>
				</h2>
				<p class="oa-health-meta">
					<strong><?php echo esc_html( $provider->label() ); ?></strong>
					&middot;
					<?php
					if ( null !== $error ) {
						printf(
							/* translators: 1: human readable time difference, 2: date and time. */
							esc_html__( 'last failure %1$s ago (%2$s)', 'odds-aggregator' ),
							esc_html( human_time_diff( $error['time'], time() ) ),
							esc_html( (string) wp_date( get_option( 'date_format', 'j M Y' ) . ', ' . get_option( 'time_format', 'H:i' ), $error['time'] ) )
						);
						echo ' &middot; ' . esc_html__( 'odds tables currently render empty', 'odds-aggregator' );
					} else {
						echo esc_html(
							sprintf(
								/* translators: %d: number of events. */
								_n( '%d event available to editors', '%d events available to editors', $events, 'odds-aggregator' ),
								$events
							)
						);
					}

					$allowance = 'oddsapi' === $provider->id() ? get_transient( OddsApiProvider::ALLOWANCE_KEY ) : false;

					if ( is_array( $allowance ) ) {
						echo ' &middot; ';
						printf(
							/* translators: 1: credits remaining, 2: credits used, 3: human readable age. */
							esc_html__( '%1$d API credits left, %2$d used (reported %3$s ago)', 'odds-aggregator' ),
							(int) ( $allowance['remaining'] ?? 0 ),
							(int) ( $allowance['used'] ?? 0 ),
							esc_html( human_time_diff( (int) ( $allowance['checked'] ?? time() ), time() ) )
						);
					}
					?>
				</p>
				<?php if ( null !== $error ) : ?>
					<div class="oa-err"><?php echo wp_kses( $error['message'], array() ); ?></div>
				<?php elseif ( ! $healthy ) : ?>
					<div class="oa-err"><?php echo esc_html__( 'The source answered but listed no events.', 'odds-aggregator' ); ?></div>
				<?php endif; ?>
				<div class="oa-health-actions">
					<?php if ( ! $healthy && self::SAMPLE_PROVIDER !== $provider->id() ) : ?>
						<?php $this->render_action_button( self::ACTION_USE_SAMPLE, __( 'Switch to Sample data (offline)', 'odds-aggregator' ), true ); ?>
					<?php endif; ?>
					<?php $this->render_action_button( self::ACTION_CHECK_SOURCE, __( 'Check again', 'odds-aggregator' ), $healthy ? false : self::SAMPLE_PROVIDER === $provider->id() ); ?>
					<?php if ( ! $healthy ) : ?>
						<a class="oa-link" href="#general"><?php echo esc_html__( 'Choose another source', 'odds-aggregator' ); ?></a>
						<span class="oa-note"><?php echo esc_html__( 'Switching is tested first; if it fails your current source is kept.', 'odds-aggregator' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="oa-kpis">
				<?php $this->render_kpis( $events ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * A one-button form posting to admin-post.php.
	 *
	 * @param string $action  admin-post action.
	 * @param string $label   Button label.
	 * @param bool   $primary Whether this is the primary action.
	 * @return void
	 */
	private function render_action_button( string $action, string $label, bool $primary ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oa-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Key figures beside the health message.
	 *
	 * @param int $events Number of available events.
	 * @return void
	 */
	private function render_kpis( int $events ): void {
		$enabled_books = count( array_intersect( $this->settings->bookmakers(), $this->bookmakers->ids() ) );
		$quoted_books  = $this->status->enabled_quoted_bookmakers();
		$enabled_mkts  = $this->settings->markets();
		$unquoted      = $this->status->unquoted_markets();
		$quoted_mkts   = array_values( array_diff( $enabled_mkts, $unquoted ) );

		$this->render_kpi(
			__( 'Events available', 'odds-aggregator' ),
			(string) $events,
			'',
			0 === $events ? __( 'Editors have nothing to pick', 'odds-aggregator' ) : __( 'Editors can pick any of them', 'odds-aggregator' ),
			0 === $events ? 'bad' : ''
		);

		$this->render_kpi(
			__( 'Bookmakers', 'odds-aggregator' ),
			(string) $enabled_books,
			/* translators: %d: number of known bookmakers. */
			sprintf( __( 'of %d shown', 'odds-aggregator' ), count( $this->bookmakers->ids() ) ),
			null === $quoted_books
				? __( 'Coverage depends on the source region', 'odds-aggregator' )
				/* translators: %d: number of enabled bookmakers the source quotes. */
				: sprintf( _n( '%d quoted by this source', '%d quoted by this source', count( $quoted_books ), 'odds-aggregator' ), count( $quoted_books ) ),
			null !== $quoted_books && count( $quoted_books ) < $enabled_books ? 'warn' : ''
		);

		$quoted_labels = array_map(
			static fn( Market $market ): string => $market->label(),
			array_values( $this->markets->only( $quoted_mkts ) )
		);

		$this->render_kpi(
			__( 'Markets', 'odds-aggregator' ),
			(string) count( $enabled_mkts ),
			__( 'enabled', 'odds-aggregator' ),
			array() === $unquoted
				? __( 'All quoted by this source', 'odds-aggregator' )
				/* translators: %s: comma-separated market names. */
				: sprintf( __( 'Only %s quoted', 'odds-aggregator' ), array() === $quoted_labels ? __( 'none', 'odds-aggregator' ) : implode( ', ', $quoted_labels ) ),
			array() === $unquoted ? '' : 'warn'
		);

		$this->render_kpi(
			__( 'Cache', 'odds-aggregator' ),
			sprintf( '%ds / %ds', $this->settings->cache_ttl(), $this->settings->stale_ttl() ),
			__( 'fresh / stale', 'odds-aggregator' ),
			/* translators: %d: outbound requests per minute. */
			sprintf( __( '%d outbound requests / min', 'odds-aggregator' ), $this->settings->rate_limit() ),
			''
		);
	}

	/**
	 * One key figure.
	 *
	 * @param string $label Caption.
	 * @param string $value Figure.
	 * @param string $unit  Text after the figure.
	 * @param string $note  Line below.
	 * @param string $tone  "", "warn" or "bad".
	 * @return void
	 */
	private function render_kpi( string $label, string $value, string $unit, string $note, string $tone ): void {
		?>
		<div class="oa-kpi<?php echo '' === $tone ? '' : ' is-' . esc_attr( $tone ); ?>">
			<div class="oa-kpi-k"><?php echo esc_html( $label ); ?></div>
			<div class="oa-kpi-v"><?php echo esc_html( $value ); ?> <small><?php echo esc_html( $unit ); ?></small></div>
			<div class="oa-kpi-s"><?php echo esc_html( $note ); ?></div>
		</div>
		<?php
	}

	/**
	 * Tabs switching between the section forms.
	 *
	 * @return void
	 */
	private function render_tabs(): void {
		$flags = array(
			'general' => $this->status->is_healthy() ? '' : 'bad',
			'markets' => array() === $this->status->unquoted_markets() ? '' : 'warn',
		);

		?>
		<nav class="oa-tabs" aria-label="<?php echo esc_attr__( 'Settings sections', 'odds-aggregator' ); ?>">
			<div role="tablist">
				<?php foreach ( self::sections() as $id => $label ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" id="oa-tab-<?php echo esc_attr( $id ); ?>" role="tab" aria-controls="oa-<?php echo esc_attr( $id ); ?>" aria-selected="false">
						<?php if ( '' !== ( $flags[ $id ] ?? '' ) ) : ?>
							<span class="oa-flag is-<?php echo esc_attr( $flags[ $id ] ); ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</div>
			<span class="oa-tabs-note"><?php echo esc_html__( 'Each tab saves on its own.', 'odds-aggregator' ); ?></span>
		</nav>
		<?php
	}

	/**
	 * Opens a section form.
	 *
	 * @param string $id          Section id, also the Settings::tab_fields() group.
	 * @param string $description Intro text.
	 * @param string $meta        Text on the right of the header.
	 * @return void
	 */
	private function open_section( string $id, string $description, string $meta = '' ): void {
		?>
		<form class="oa-sec oa-panel" id="oa-<?php echo esc_attr( $id ); ?>" role="tabpanel" aria-labelledby="oa-tab-<?php echo esc_attr( $id ); ?>" action="options.php" method="post">
			<input type="hidden" name="option_page" value="<?php echo esc_attr( self::GROUP ); ?>">
			<input type="hidden" name="action" value="update">
			<?php wp_nonce_field( self::GROUP . '-options', '_wpnonce', false ); ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( self::url( $id ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $id ); ?>">
			<header>
				<div>
					<h2><?php echo esc_html( self::sections()[ $id ] ); ?></h2>
					<p><?php echo esc_html( $description ); ?></p>
				</div>
				<?php if ( '' !== $meta ) : ?>
					<span class="oa-sec-meta"><?php echo esc_html( $meta ); ?></span>
				<?php endif; ?>
			</header>
			<div class="oa-sec-body">
		<?php
	}

	/**
	 * Closes a section form with its save button.
	 *
	 * @param string $button Save button label.
	 * @param string $extra  Extra footer markup, already escaped.
	 * @return void
	 */
	private function close_section( string $button, string $extra = '' ): void {
		?>
			</div>
			<footer>
				<?php submit_button( $button, 'primary', 'submit', false ); ?>
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</footer>
		</form>
		<?php
	}

	/**
	 * Data source and display section.
	 *
	 * @return void
	 */
	private function render_general_section(): void {
		$current = $this->settings->provider();
		$error   = $this->status->error();
		$option  = Settings::OPTION;
		$key_set = false;

		$this->open_section(
			'general',
			__( 'A source is tested when you save, and so is a new API key or region. If it cannot return odds the change is rejected and your working configuration is kept.', 'odds-aggregator' )
		);

		?>
		<span class="oa-label" id="oa-provider-label"><?php echo esc_html__( 'Provider', 'odds-aggregator' ); ?></span>
		<div class="oa-prov" role="radiogroup" aria-labelledby="oa-provider-label">
			<?php foreach ( $this->factory->choices() as $id => $label ) : ?>
				<?php
				$failing = null !== $error && $id === $current;
				$needs   = 'oddsapi' === $id;
				$key_set = $key_set || $needs;
				?>
				<label class="oa-prov-item">
					<input type="radio" name="<?php echo esc_attr( $option ); ?>[provider]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $current, $id ); ?>>
					<strong><?php echo esc_html( $label ); ?></strong>
					<span class="oa-prov-state">
						<?php if ( $failing ) : ?>
							<span class="oa-tag is-bad"><?php echo esc_html__( 'Failing', 'odds-aggregator' ); ?></span>
						<?php elseif ( $id === $current ) : ?>
							<span class="oa-tag is-ok"><?php echo esc_html__( 'Active', 'odds-aggregator' ); ?></span>
						<?php endif; ?>
					</span>
					<?php $description = $this->provider_description( $id ); ?>
					<?php if ( '' !== $description ) : ?>
						<span class="oa-prov-desc"><?php echo esc_html( $description ); ?></span>
					<?php endif; ?>
					<span class="oa-prov-caps">
						<?php foreach ( $this->provider_caps( $id, $needs ) as $cap ) : ?>
							<span class="oa-tag"><?php echo esc_html( $cap ); ?></span>
						<?php endforeach; ?>
					</span>
					<?php if ( $needs ) : ?>
						<span class="oa-prov-key">
							<?php $this->render_api_key_input(); ?>
							<?php $this->render_regions_select(); ?>
							<span class="oa-muted"><?php echo esc_html__( 'Only used by The Odds API. Each refresh costs one credit per market per region.', 'odds-aggregator' ); ?></span>
						</span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $key_set ) : ?>
			<p class="oa-key-standalone">
				<label class="oa-label" for="oa-api-key"><?php echo esc_html__( 'API key', 'odds-aggregator' ); ?></label>
				<?php $this->render_api_key_input(); ?>
			</p>
		<?php endif; ?>

		<div class="oa-row2">
			<div>
				<span class="oa-label" id="oa-format-label"><?php echo esc_html__( 'Default odds format', 'odds-aggregator' ); ?></span>
				<div class="oa-seg" role="radiogroup" aria-labelledby="oa-format-label">
					<?php foreach ( OddsFormat::cases() as $format ) : ?>
						<label>
							<input type="radio" name="<?php echo esc_attr( $option ); ?>[default_format]" value="<?php echo esc_attr( $format->value ); ?>" <?php checked( $this->settings->default_format(), $format->value ); ?>>
							<span><?php echo esc_html( $format->label() ); ?></span>
							<small><?php echo esc_html( OddsConverter::format( 2.5, $format ) ); ?></small>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
			<p class="description"><?php echo esc_html__( 'The format a page opens in. Readers can switch it themselves unless a block hides the switcher.', 'odds-aggregator' ); ?></p>
		</div>
		<?php

		$this->close_section( __( 'Save data source', 'odds-aggregator' ) );
	}

	/**
	 * API key password input.
	 *
	 * @return void
	 */
	private function render_api_key_input(): void {
		?>
		<input id="oa-api-key" type="password" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr__( 'API key', 'odds-aggregator' ); ?>" aria-label="<?php echo esc_attr__( 'API key', 'odds-aggregator' ); ?>" name="<?php echo esc_attr( Settings::OPTION ); ?>[api_key]" value="<?php echo esc_attr( $this->settings->api_key() ); ?>">
		<?php
	}

	/**
	 * Bookmaker region dropdown for The Odds API.
	 *
	 * @return void
	 */
	private function render_regions_select(): void {
		$current = $this->settings->oddsapi_regions();
		$regions = array(
			'us' => __( 'United States', 'odds-aggregator' ),
			'uk' => __( 'United Kingdom', 'odds-aggregator' ),
		);

		?>
		<select name="<?php echo esc_attr( Settings::OPTION ); ?>[oddsapi_regions]" aria-label="<?php echo esc_attr__( 'The Odds API region', 'odds-aggregator' ); ?>">
			<?php foreach ( $regions as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * One-line description of a bundled provider.
	 *
	 * @param string $id Provider id.
	 * @return string Empty for providers added by other plugins.
	 */
	private function provider_description( string $id ): string {
		return match ( $id ) {
			'oddschecker' => __( 'Scrapes oddschecker.com. Behind Cloudflare and its terms do not permit scraping, so it will often fail.', 'odds-aggregator' ),
			'oddsapi'     => __( 'Licensed JSON feed from the-odds-api.com. Recommended for production.', 'odds-aggregator' ),
			'mock'        => __( 'Bundled fixtures. Works offline with no configuration; ideal for building pages.', 'odds-aggregator' ),
			default       => '',
		};
	}

	/**
	 * Coverage tags for a provider.
	 *
	 * @param string $id    Provider id.
	 * @param bool   $needs Whether the provider needs an API key.
	 * @return string[]
	 */
	private function provider_caps( string $id, bool $needs ): array {
		try {
			$provider = $this->factory->create( $id );
		} catch ( ProviderException $exception ) {
			return array();
		}

		$caps  = array();
		$books = $this->status->supported_bookmakers( $provider );

		if ( null !== $books ) {
			/* translators: %d: number of bookmakers. */
			$caps[] = sprintf( _n( '%d bookmaker', '%d bookmakers', count( $books ), 'odds-aggregator' ), count( $books ) );
		}

		$markets = $this->status->supported_markets( $provider );
		$caps[]  = null === $markets
			? __( 'All markets', 'odds-aggregator' )
			: implode( ', ', array_map( static fn( Market $market ): string => $market->label(), array_values( $this->markets->only( $markets ) ) ) );
		$caps[]  = $needs ? __( 'API key required', 'odds-aggregator' ) : __( 'No key', 'odds-aggregator' );

		return $caps;
	}

	/**
	 * Bookmakers section.
	 *
	 * @return void
	 */
	private function render_bookmakers_section(): void {
		$all     = $this->bookmakers->all();
		$enabled = $this->settings->bookmakers();
		$quoted  = $this->status->quoted_bookmakers();
		$urls    = $this->settings->bookmaker_urls();
		$logos   = $this->settings->bookmaker_logos();
		$label   = $this->status->provider()->label();
		$groups  = array();

		foreach ( $all as $bookmaker ) {
			$groups[ $bookmaker->region() ][] = $bookmaker;
		}

		$this->open_section(
			'bookmakers',
			__( 'Which bookmakers are available site-wide, with the outbound link and logo for each. Blocks can show fewer, never more. An empty link uses the bookmaker\'s homepage; an empty logo uses the bundled icon, or initials when none ships.', 'odds-aggregator' ),
			/* translators: 1: enabled bookmakers, 2: known bookmakers. */
			sprintf( __( '%1$d of %2$d shown', 'odds-aggregator' ), count( array_intersect( $enabled, array_keys( $all ) ) ), count( $all ) )
		);

		?>
		<div class="oa-bkcols">
			<?php foreach ( $groups as $region => $members ) : ?>
				<?php
				$on        = count( array_filter( $members, static fn( Bookmaker $b ): bool => in_array( $b->id(), $enabled, true ) ) );
				$in_source = null === $quoted ? null : count( array_filter( $members, static fn( Bookmaker $b ): bool => in_array( $b->id(), $quoted, true ) ) );
				?>
				<div class="oa-bkcol" data-region="<?php echo esc_attr( (string) $region ); ?>">
					<h3>
						<?php echo esc_html( $this->region_label( (string) $region ) ); ?>
						<span class="oa-n">
							<?php
							/* translators: 1: enabled, 2: total. */
							echo esc_html( sprintf( __( '%1$d of %2$d', 'odds-aggregator' ), $on, count( $members ) ) );
							if ( null !== $in_source ) {
								/* translators: %d: bookmakers quoted by the source. */
								echo ' &middot; ' . esc_html( sprintf( __( '%d quoted', 'odds-aggregator' ), $in_source ) );
							}
							?>
						</span>
						<button type="button" class="button-link oa-toggle-region"><?php echo esc_html__( 'Toggle all', 'odds-aggregator' ); ?></button>
					</h3>
					<ul>
						<?php foreach ( $members as $bookmaker ) : ?>
							<?php
							$id        = $bookmaker->id();
							$is_on     = in_array( $id, $enabled, true );
							$is_quoted = null !== $quoted && in_array( $id, $quoted, true );
							$override  = $urls[ $id ] ?? '';
							$logo      = $logos[ $id ] ?? '';
							?>
							<li class="<?php echo $is_on ? '' : 'is-off'; ?>" data-quoted="<?php echo null === $quoted ? '' : ( $is_quoted ? '1' : '0' ); ?>">
								<label class="oa-bk">
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[bookmakers][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $is_on ); ?>>
									<?php if ( null !== $quoted ) : ?>
										<span class="oa-dot<?php echo $is_quoted ? ' is-ok' : ''; ?>" title="<?php echo esc_attr( $is_quoted ? __( 'Quoted by the current source', 'odds-aggregator' ) : __( 'Not quoted by the current source', 'odds-aggregator' ) ); ?>"></span>
									<?php endif; ?>
									<?php if ( '' !== $bookmaker->logo() ) : ?>
										<img class="oa-bk-logo" src="<?php echo esc_url( $bookmaker->logo() ); ?>" alt="" width="16" height="16">
									<?php endif; ?>
									<span><?php echo esc_html( $bookmaker->name() ); ?></span>
								</label>
								<span class="oa-bk-links">
									<input type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[bookmaker_urls][<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $override ); ?>" placeholder="<?php echo esc_attr( $this->bookmakers->default_url( $id ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: bookmaker name. */ __( '%s outbound link', 'odds-aggregator' ), $bookmaker->name() ) ); ?>">
									<input type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[bookmaker_logos][<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $logo ); ?>" placeholder="<?php echo esc_attr( '' !== $this->bookmakers->default_logo( $id ) ? $this->bookmakers->default_logo( $id ) : __( 'Logo URL (initials if empty)', 'odds-aggregator' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: bookmaker name. */ __( '%s logo URL', 'odds-aggregator' ), $bookmaker->name() ) ); ?>">
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( null !== $quoted ) : ?>
			<div class="oa-legend">
				<span><i class="oa-dot is-ok"></i>
				<?php
				/* translators: %s: data source name. */
				echo esc_html( sprintf( __( 'Quoted by %s', 'odds-aggregator' ), $label ) );
				?>
				</span>
				<span><i class="oa-dot"></i><?php echo esc_html__( 'Not quoted by the current source', 'odds-aggregator' ); ?></span>
			</div>
		<?php endif; ?>
		<?php

		$this->close_section(
			__( 'Save bookmakers', 'odds-aggregator' ),
			null === $quoted ? '' : '<button type="button" class="button oa-only-quoted" aria-pressed="false">' . esc_html__( 'Show only quoted', 'odds-aggregator' ) . '</button>'
		);
	}

	/**
	 * Display name of a region code.
	 *
	 * @param string $region Region code.
	 * @return string
	 */
	private function region_label( string $region ): string {
		return match ( $region ) {
			'uk'    => __( 'United Kingdom', 'odds-aggregator' ),
			'us'    => __( 'United States', 'odds-aggregator' ),
			default => __( 'Other', 'odds-aggregator' ),
		};
	}

	/**
	 * Markets section.
	 *
	 * @return void
	 */
	private function render_markets_section(): void {
		$enabled   = $this->settings->markets();
		$provider  = $this->status->provider();
		$supported = $this->status->supported_markets( $provider );

		$this->open_section(
			'markets',
			__( 'Markets available to blocks. Individual blocks can narrow this further. Markets the data source does not quote are left out of the tables.', 'odds-aggregator' )
		);

		?>
		<div class="oa-mkts">
			<?php foreach ( $this->markets->all() as $market ) : ?>
				<?php
				$is_on     = in_array( $market->key(), $enabled, true );
				$is_quoted = null === $supported || in_array( $market->key(), $supported, true );
				?>
				<label class="oa-mkt">
					<span class="oa-mkt-h">
						<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[markets][]" value="<?php echo esc_attr( $market->key() ); ?>" <?php checked( $is_on ); ?>>
						<?php echo esc_html( $market->label() ); ?>
					</span>
					<span class="oa-muted"><?php echo esc_html( implode( ' · ', array_map( static fn( $outcome ): string => $outcome->label(), $market->outcomes() ) ) ); ?></span>
					<?php if ( $is_quoted ) : ?>
						<span class="oa-tag is-ok"><?php echo esc_html__( 'Quoted', 'odds-aggregator' ); ?></span>
					<?php else : ?>
						<span class="oa-tag is-warn">
							<?php
							/* translators: %s: data source name. */
							echo esc_html( sprintf( __( '(not quoted by %s)', 'odds-aggregator' ), $provider->label() ) );
							?>
						</span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php

		$this->close_section( __( 'Save markets', 'odds-aggregator' ) );
	}

	/**
	 * Performance section.
	 *
	 * @return void
	 */
	private function render_performance_section(): void {
		$defaults = Settings::defaults();
		$fields   = array(
			'cache_ttl'   => array( __( 'Fresh window', 'odds-aggregator' ), $this->settings->cache_ttl(), 10, 3600, __( 'seconds', 'odds-aggregator' ), __( 'Lower means livelier prices and more outbound requests.', 'odds-aggregator' ) ),
			'stale_ttl'   => array( __( 'Serve stale until', 'odds-aggregator' ), $this->settings->stale_ttl(), 10, 86400, __( 'seconds', 'odds-aggregator' ), __( 'Age after which odds are fetched again before showing.', 'odds-aggregator' ) ),
			'oddsapi_ttl' => array( __( 'The Odds API: seconds between fetches', 'odds-aggregator' ), $this->settings->oddsapi_ttl(), 600, 86400, __( 'seconds', 'odds-aggregator' ), __( 'One fetch serves every event. At 10800 two markets in one region cost about 480 credits a month, within the free plan of 500.', 'odds-aggregator' ) ),
			'rate_limit'  => array( __( 'Outbound requests per minute', 'odds-aggregator' ), $this->settings->rate_limit(), 1, 600, __( '/ min', 'odds-aggregator' ), __( 'Protects you from upstream bans.', 'odds-aggregator' ) ),
		);

		$this->open_section(
			'performance',
			__( 'Odds older than the fresh window are still shown while a background refresh runs, so visitors never wait on a fetch.', 'odds-aggregator' )
		);

		?>
		<div class="oa-perf">
			<?php foreach ( $fields as $name => [ $label, $value, $min, $max, $unit, $help ] ) : ?>
				<div>
					<label for="oa-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
					<div class="oa-perf-in">
						<input id="oa-<?php echo esc_attr( $name ); ?>" type="number" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" data-default="<?php echo esc_attr( (string) $defaults[ $name ] ); ?>">
						<?php echo esc_html( $unit ); ?>
					</div>
					<p class="description">
						<?php
						/* translators: 1: minimum, 2: maximum, 3: default. */
						echo esc_html( sprintf( __( '%1$d–%2$d · default %3$d.', 'odds-aggregator' ), $min, $max, $defaults[ $name ] ) ) . ' ' . esc_html( $help );
						?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="oa-tl">
			<span class="oa-label"><?php echo esc_html__( 'What visitors get, by age of the cached odds', 'odds-aggregator' ); ?></span>
			<div class="oa-tl-bar" aria-hidden="true">
				<div class="oa-tl-fresh"></div>
				<div class="oa-tl-stale"></div>
				<div class="oa-tl-gone"></div>
			</div>
			<p class="oa-tl-text description"
				data-fresh="<?php /* translators: %1$d: fresh window in seconds. */ echo esc_attr__( '0–%1$ds: served from cache', 'odds-aggregator' ); ?>"
				data-stale="<?php /* translators: 1: fresh window in seconds, 2: stale limit in seconds. */ echo esc_attr__( '%1$d–%2$ds: served instantly, refreshed in the background', 'odds-aggregator' ); ?>"
				data-gone="<?php /* translators: %1$d: stale limit in seconds. */ echo esc_attr__( 'after %1$ds: fetched again', 'odds-aggregator' ); ?>"></p>
		</div>
		<?php

		$this->close_section(
			__( 'Save performance', 'odds-aggregator' ),
			'<button type="button" class="button-link oa-restore-defaults">' . esc_html__( 'Restore defaults', 'odds-aggregator' ) . '</button>'
		);
	}

	/**
	 * Sidebar preview of an odds table.
	 *
	 * @return void
	 */
	private function render_preview(): void {
		$preview = $this->preview();

		?>
		<section class="oa-sec">
			<header><h2><?php echo esc_html__( 'Odds table preview', 'odds-aggregator' ); ?></h2></header>
			<div class="oa-sec-body">
				<?php if ( null === $preview ) : ?>
					<p class="oa-muted"><?php echo esc_html__( 'Nothing to preview yet.', 'odds-aggregator' ); ?></p>
				<?php else : ?>
					<?php if ( $preview['sample'] ) : ?>
						<p class="oa-preview-note"><?php echo esc_html__( 'The live source has no events, so this preview uses sample data.', 'odds-aggregator' ); ?></p>
					<?php endif; ?>
					<div class="oa-preview">
						<div class="oa-preview-ev">
							<span><?php echo esc_html( $preview['event']->title() ); ?></span>
							<small><?php echo esc_html( (string) wp_date( 'D H:i', $preview['event']->kickoff()->getTimestamp() ) ); ?></small>
						</div>
						<table>
							<thead>
								<tr>
									<th scope="col"><?php echo esc_html( $preview['market']->label() ); ?></th>
									<?php foreach ( $preview['market']->outcomes() as $outcome ) : ?>
										<th scope="col"><?php echo esc_html( $outcome->label() ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $preview['rows'] as [ $bookmaker, $line ] ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $bookmaker->name() ); ?></th>
										<?php foreach ( $preview['market']->outcome_keys() as $key ) : ?>
											<?php $price = $line->price( $key ); ?>
											<td class="<?php echo $price instanceof Odds && abs( ( $preview['best'][ $key ] ?? 0.0 ) - $price->decimal() ) < 0.0001 ? 'is-best' : ''; ?>">
												<?php echo $price instanceof Odds ? esc_html( OddsConverter::format( $price->decimal(), $preview['format'] ) ) : '&mdash;'; ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="description">
						<?php
						/* translators: %s: odds format name. */
						echo esc_html( sprintf( __( '%s format · best price highlighted · links use your outbound URLs.', 'odds-aggregator' ), $preview['format']->label() ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Data for the preview: first event, Match Result, enabled bookmakers.
	 *
	 * @return array{event:Event,market:Market,rows:array<int,array{0:Bookmaker,1:OddsLine}>,best:array<string,float>,format:OddsFormat,sample:bool}|null
	 */
	private function preview(): ?array {
		$market = $this->markets->get( '1x2' ) ?? ( array_values( $this->markets->all() )[0] ?? null );

		if ( ! $market instanceof Market ) {
			return null;
		}

		$events = $this->status->events();
		$sample = array() === $events;

		if ( $sample ) {
			$mock   = new MockProvider();
			$events = $mock->fetch_events();
		}

		$event = $events[0] ?? null;

		if ( ! $event instanceof Event ) {
			return null;
		}

		$lines = $sample
			? ( new MockProvider() )->fetch_odds( $event, array( $market ) )
			: $this->repository->odds( $event->id(), $this->settings->markets() );

		$by_bookmaker = array();

		foreach ( $lines as $line ) {
			if ( $line->market_key() === $market->key() ) {
				$by_bookmaker[ $line->bookmaker_id() ] = $line;
			}
		}

		$rows = array();
		$best = array();

		foreach ( $this->bookmakers->only( $this->settings->bookmakers() ) as $id => $bookmaker ) {
			if ( ! isset( $by_bookmaker[ $id ] ) || count( $rows ) >= 5 ) {
				continue;
			}

			$rows[] = array( $bookmaker, $by_bookmaker[ $id ] );

			foreach ( $market->outcome_keys() as $key ) {
				$price = $by_bookmaker[ $id ]->price( $key );

				if ( $price instanceof Odds && $price->decimal() > ( $best[ $key ] ?? 0.0 ) ) {
					$best[ $key ] = $price->decimal();
				}
			}
		}

		return array(
			'event'  => $event,
			'market' => $market,
			'rows'   => $rows,
			'best'   => $best,
			'format' => OddsFormat::from_string_or_default( $this->settings->default_format() ),
			'sample' => $sample,
		);
	}

	/**
	 * Sidebar quick links.
	 *
	 * @param \WP_Post|null $demo Demo page, if it exists.
	 * @param string        $docs Documentation URL, empty when not shipped.
	 * @return void
	 */
	private function render_quick_links( ?\WP_Post $demo, string $docs ): void {
		$status = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( OddsController::NAMESPACE . '/status' ) );

		?>
		<section class="oa-sec">
			<header><h2><?php echo esc_html__( 'Quick links', 'odds-aggregator' ); ?></h2></header>
			<div class="oa-sec-body">
				<ul class="oa-links">
					<?php if ( null !== $demo ) : ?>
						<li><a href="<?php echo esc_url( (string) get_permalink( $demo ) ); ?>"><?php echo esc_html__( 'Demo page', 'odds-aggregator' ); ?></a><span class="oa-muted">/<?php echo esc_html( $demo->post_name ); ?>/</span></li>
					<?php endif; ?>
					<li><a href="<?php echo esc_url( $status ); ?>"><?php echo esc_html__( 'REST status', 'odds-aggregator' ); ?></a><span class="oa-muted">/status</span></li>
					<?php if ( '' !== $docs ) : ?>
						<li><a href="<?php echo esc_url( $docs ); ?>"><?php echo esc_html__( 'Documentation', 'odds-aggregator' ); ?></a><span class="oa-muted">DOCS.md</span></li>
					<?php endif; ?>
				</ul>
			</div>
		</section>
		<?php
	}

	/**
	 * Switches the data source to sample data.
	 *
	 * @return void
	 */
	public function handle_use_sample(): void {
		$this->authorize( self::ACTION_USE_SAMPLE );

		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		update_option( Settings::OPTION, array( 'provider' => self::SAMPLE_PROVIDER ) + $stored + Settings::defaults() );

		$this->redirect_with_notices();
	}

	/**
	 * Re-tests the active data source.
	 *
	 * @return void
	 */
	public function handle_check_source(): void {
		$this->authorize( self::ACTION_CHECK_SOURCE );

		$events = $this->repository->refresh_events();
		$error  = OddsRepository::last_error();

		if ( null === $error && array() !== $events ) {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_source_ok',
				sprintf(
					/* translators: %d: number of events. */
					_n( 'The data source is working and returned %d event.', 'The data source is working and returned %d events.', count( $events ), 'odds-aggregator' ),
					count( $events )
				),
				'success'
			);
		} else {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_source_failed',
				sprintf(
					/* translators: %s: failure detail. */
					__( 'The data source is still not returning odds: %s', 'odds-aggregator' ),
					null === $error ? __( 'it returned no events.', 'odds-aggregator' ) : $error['message']
				),
				'error'
			);
		}

		$this->redirect_with_notices();
	}

	/**
	 * Checks the nonce and capability of an admin-post request.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( string $action ): void {
		check_admin_referer( $action );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'odds-aggregator' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Returns to the screen, carrying settings notices across the redirect.
	 *
	 * @return never
	 */
	private function redirect_with_notices(): never {
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', self::url( 'general' ) ) );
		exit;
	}

	/**
	 * Refuses to switch to a data source that cannot currently deliver odds.
	 *
	 * Selecting a dead source used to be silently destructive: the event list
	 * emptied and every odds table blanked, with the only clue a notice on a
	 * later page load. The source is therefore probed once, and a failing one
	 * is rejected with an explanation while the working source stays in place.
	 *
	 * @param mixed $value     Settings about to be written.
	 * @param mixed $old_value Settings currently stored.
	 * @return mixed
	 */
	public function guard_provider_change( $value, $old_value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$old      = is_array( $old_value ) ? $old_value : array();
		$previous = (string) ( $old['provider'] ?? 'mock' );
		$chosen   = (string) ( $value['provider'] ?? $previous );
		$switched = $chosen !== $previous;

		/*
		 * A new key or a new region changes what the source would return, so it
		 * earns one probe even though the source itself did not change - that is
		 * the only way to tell an administrator their key works. Every other edit
		 * on the tab still pays for nothing.
		 */
		$recredentialed = ! $switched
			&& 'oddsapi' === $chosen
			&& (
				(string) ( $old['api_key'] ?? '' ) !== (string) ( $value['api_key'] ?? '' )
				|| (string) ( $old['oddsapi_regions'] ?? '' ) !== (string) ( $value['oddsapi_regions'] ?? '' )
			);

		if ( ! $switched && ! $recredentialed ) {
			return $value;
		}

		$probe = $this->probe_provider( $chosen, $value );

		if ( null === $probe['failure'] ) {
			if ( null !== $probe['warning'] ) {
				add_settings_error(
					Settings::OPTION,
					'odds_aggregator_provider_empty',
					sprintf(
						/* translators: 1: provider name, 2: detail. */
						__( 'Saved %1$s, but %2$s', 'odds-aggregator' ),
						$this->provider_label( $chosen ),
						$probe['warning']
					),
					'warning'
				);

				return $value;
			}

			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_provider_ok',
				$switched
					? sprintf(
						/* translators: %s: provider name. */
						__( 'Data source switched to %s.', 'odds-aggregator' ),
						$this->provider_label( $chosen )
					)
					: sprintf(
						/* translators: %s: provider name. */
						__( '%s accepted the new settings.', 'odds-aggregator' ),
						$this->provider_label( $chosen )
					),
				'updated'
			);

			return $value;
		}

		if ( $switched ) {
			add_settings_error(
				Settings::OPTION,
				'odds_aggregator_provider_failed',
				sprintf(
					/* translators: 1: attempted provider name, 2: failure detail, 3: provider left in place. */
					__( 'Could not switch to %1$s: %2$s The data source has been left on %3$s so your odds keep working.', 'odds-aggregator' ),
					$this->provider_label( $chosen ),
					$probe['failure'],
					$this->provider_label( $previous )
				),
				'error'
			);

			$value['provider'] = $previous;

			return $value;
		}

		add_settings_error(
			Settings::OPTION,
			'odds_aggregator_credentials_failed',
			sprintf(
				/* translators: 1: provider name, 2: failure detail. */
				__( '%1$s rejected the new settings: %2$s Your previous API key and region have been kept.', 'odds-aggregator' ),
				$this->provider_label( $chosen ),
				$probe['failure']
			),
			'error'
		);

		// The source stays selected, so leaving broken credentials in place would
		// blank the front end with nothing on screen to explain it.
		$value['api_key']         = (string) ( $old['api_key'] ?? '' );
		$value['oddsapi_regions'] = (string) ( $old['oddsapi_regions'] ?? 'us' );

		return $value;
	}

	/**
	 * Tries a provider once.
	 *
	 * @param string              $id      Provider id.
	 * @param array<string,mixed> $pending Settings being saved, if any.
	 * @return array{failure:?string,warning:?string}
	 */
	private function probe_provider( string $id, array $pending = array() ): array {
		$factory = array() === $pending
			? $this->factory
			: $this->factory->for_settings( $this->settings->with_overrides( $pending ) );

		try {
			$provider = $factory->create( $id );

			if ( ! $provider->is_available() ) {
				return array(
					'failure' => __( 'it is not configured yet (an API key is required).', 'odds-aggregator' ),
					'warning' => null,
				);
			}

			if ( array() === $provider->fetch_events() ) {
				// A licensed feed returns nothing between fixtures, so an empty list
				// is not a reason to reject a key that answered at all.
				return array(
					'failure' => null,
					'warning' => __( 'it has no events to quote at the moment.', 'odds-aggregator' ),
				);
			}
		} catch ( ProviderException $exception ) {
			return array(
				'failure' => $exception->getMessage(),
				'warning' => null,
			);
		}

		return array(
			'failure' => null,
			'warning' => null,
		);
	}

	/**
	 * Human readable name for a provider id.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	private function provider_label( string $id ): string {
		return (string) ( $this->factory->choices()[ $id ] ?? $id );
	}

	/**
	 * Warns administrators on other screens when the active data source is failing.
	 *
	 * @return void
	 */
	public function render_provider_error(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && 'toplevel_page_' . self::SLUG === $screen->id ) {
			return;
		}

		$error = OddsRepository::last_error();

		if ( null === $error ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Odds Aggregator: the selected data source is not returning odds.', 'odds-aggregator' ); ?></strong>
			</p>
			<p><code><?php echo wp_kses( $error['message'], array() ); ?></code></p>
			<p>
				<?php
				printf(
					/* translators: %s: link to the plugin settings screen. */
					esc_html__( 'Until this is resolved there will be no events to choose from in the editor and odds tables will render empty. %s', 'odds-aggregator' ),
					'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open Odds Aggregator to fix it', 'odds-aggregator' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
