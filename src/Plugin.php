<?php
/**
 * Main plugin class.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Admin\SettingsPage;
use OddsAggregator\Admin\SourceStatus;
use OddsAggregator\Blocks\BlockRegistrar;
use OddsAggregator\Cache\CacheWarmer;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use OddsAggregator\Rest\OddsController;

/**
 * Plugin bootstrap (Singleton).
 *
 * Owns one instance of each service and hands them to the pieces that need
 * them. Services are built lazily, so a request that only needs settings never
 * constructs an HTTP client.
 *
 * The Singleton is deliberately thin: it wires objects together and registers
 * hooks, and holds no behaviour of its own. Everything testable lives in the
 * collaborators rather than here.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Lazily built services, keyed by name.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Registers hooks. Called on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->settings()->upgrade();

		( new SettingsPage(
			$this->settings(),
			$this->provider_factory(),
			$this->bookmakers(),
			$this->markets(),
			$this->repository(),
			new SourceStatus( $this->settings(), $this->provider_factory(), $this->repository(), $this->markets() )
		) )->register();

		( new OddsController( $this->repository(), $this->settings(), $this->bookmakers(), $this->markets() ) )
			->register();

		( new BlockRegistrar( $this->repository(), $this->settings(), $this->bookmakers(), $this->markets() ) )
			->register();

		( new CacheWarmer( $this->repository(), $this->settings() ) )
			->register();

		// Saved settings must take effect immediately, and must say whether the
		// chosen data source actually works.
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ) );

		/**
		 * Fires after Odds Aggregator has booted.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'odds_aggregator_loaded', $this );
	}

	/**
	 * Re-reads settings, drops cached odds and re-tests the chosen provider.
	 *
	 * Without the probe an administrator only discovers that a data source is
	 * failing by visiting the front end and finding it blank; with it, the
	 * admin notice is accurate the moment Save is pressed.
	 *
	 * @return void
	 */
	public function on_settings_updated(): void {
		$this->settings()->refresh();
		$this->cache()->flush();

		delete_transient( OddsRepository::ERROR_TRANSIENT );

		// Records a fresh error, or clears it, depending on the new choice.
		$this->repository()->events();
	}

	/**
	 * Plugin settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->service( 'settings', static fn(): Settings => new Settings() );
	}

	/**
	 * Cache layer.
	 *
	 * @return OddsCache
	 */
	public function cache(): OddsCache {
		$settings = $this->settings();

		return $this->service(
			'cache',
			static fn(): OddsCache => new OddsCache( $settings->cache_ttl(), $settings->stale_ttl() )
		);
	}

	/**
	 * Shared HTTP transport.
	 *
	 * @return HttpClient
	 */
	public function http(): HttpClient {
		$settings = $this->settings();

		return $this->service(
			'http',
			static fn(): HttpClient => new HttpClient(
				new RateLimiter( $settings->rate_limit() ),
				new RetryPolicy()
			)
		);
	}

	/**
	 * Provider construction.
	 *
	 * @return ProviderFactory
	 */
	public function provider_factory(): ProviderFactory {
		$settings = $this->settings();
		$http     = $this->http();

		return $this->service(
			'provider_factory',
			static fn(): ProviderFactory => new ProviderFactory( $settings, $http )
		);
	}

	/**
	 * Cached odds access.
	 *
	 * @return OddsRepository
	 */
	public function repository(): OddsRepository {
		$factory = $this->provider_factory();
		$cache   = $this->cache();
		$markets = $this->markets();

		return $this->service(
			'repository',
			static fn(): OddsRepository => new OddsRepository( $factory, $cache, $markets )
		);
	}

	/**
	 * Bookmaker catalogue, with administrator URLs and logos applied.
	 *
	 * @return BookmakerRegistry
	 */
	public function bookmakers(): BookmakerRegistry {
		$urls  = $this->settings()->bookmaker_urls();
		$logos = $this->settings()->bookmaker_logos();

		return $this->service(
			'bookmakers',
			static fn(): BookmakerRegistry => new BookmakerRegistry( $urls, $logos )
		);
	}

	/**
	 * Market catalogue.
	 *
	 * @return MarketRegistry
	 */
	public function markets(): MarketRegistry {
		return $this->service( 'markets', static fn(): MarketRegistry => new MarketRegistry() );
	}

	/**
	 * Schedules background work. Called on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		CacheWarmer::schedule();
	}

	/**
	 * Clears background work. Called on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		CacheWarmer::unschedule();
	}

	/**
	 * Builds a service once and reuses it.
	 *
	 * @param string   $name    Service name.
	 * @param callable $builder Builder invoked on first use.
	 * @return mixed
	 */
	private function service( string $name, callable $builder ): mixed {
		if ( ! isset( $this->services[ $name ] ) ) {
			$this->services[ $name ] = $builder();
		}

		return $this->services[ $name ];
	}
}
