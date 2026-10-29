<?php
/**
 * Provider construction.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Http\HttpClient;

/**
 * Factory for odds providers.
 *
 * Callers ask for a provider by id and get back something satisfying
 * ProviderInterface; they never name a concrete class. New sources register
 * themselves through the `odds_aggregator_providers` filter, which is what
 * makes "add another bookmaker feed" a plugin-level change rather than a core
 * edit.
 */
final class ProviderFactory {

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Plugin settings.
	 * @param HttpClient $http     Transport shared by networked providers.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly HttpClient $http
	) {}

	/**
	 * Builds a provider.
	 *
	 * @param string|null $id Provider id; defaults to the configured one.
	 * @return ProviderInterface
	 * @throws ProviderException When the id is unknown.
	 */
	public function create( ?string $id = null ): ProviderInterface {
		$id       = $id ?? $this->settings->provider();
		$builders = $this->builders();

		if ( ! isset( $builders[ $id ] ) ) {
			throw new ProviderException( sprintf( 'Unknown odds provider "%s".', esc_html( $id ) ) );
		}

		$provider = ( $builders[ $id ] )();

		if ( ! $provider instanceof ProviderInterface ) {
			throw new ProviderException( sprintf( 'Provider "%s" did not build a ProviderInterface.', esc_html( $id ) ) );
		}

		return $provider;
	}

	/**
	 * Builds the configured provider, falling back to sample data.
	 *
	 * Used on the front end, where an unknown or unconfigured provider should
	 * degrade to something renderable rather than fatal the page.
	 *
	 * @return ProviderInterface
	 */
	public function create_or_fallback(): ProviderInterface {
		try {
			$provider = $this->create();
		} catch ( ProviderException $exception ) {
			return new MockProvider();
		}

		return $provider->is_available() ? $provider : new MockProvider();
	}

	/**
	 * The same factory bound to different settings.
	 *
	 * Exists for the settings guard, which must build a provider from the values
	 * being saved rather than the ones still in the database.
	 *
	 * @param Settings $settings Settings to build providers from.
	 * @return self
	 */
	public function for_settings( Settings $settings ): self {
		return new self( $settings, $this->http );
	}

	/**
	 * Available providers as id => label, for the settings dropdown.
	 *
	 * @return array<string,string>
	 */
	public function choices(): array {
		$choices = array();

		foreach ( array_keys( $this->builders() ) as $id ) {
			try {
				$choices[ $id ] = $this->create( $id )->label();
			} catch ( ProviderException $exception ) {
				continue;
			}
		}

		return $choices;
	}

	/**
	 * Registered builders, keyed by provider id.
	 *
	 * @return array<string,callable():ProviderInterface>
	 */
	private function builders(): array {
		$http     = $this->http;
		$settings = $this->settings;

		$builders = array(
			'mock'        => static fn(): ProviderInterface => new MockProvider(),
			'oddschecker' => static fn(): ProviderInterface => new OddscheckerProvider( $http ),
			'oddsapi'     => static fn(): ProviderInterface => new OddsApiProvider(
				$http,
				$settings->api_key(),
				$settings->oddsapi_regions(),
				$settings->markets(),
				$settings->oddsapi_ttl()
			),
		);

		/**
		 * Filters the registered odds providers.
		 *
		 * Add an entry whose value is a callable returning a ProviderInterface
		 * to make a new source selectable in the admin.
		 *
		 * @param array<string,callable> $builders Provider id => builder.
		 * @param Settings               $settings Plugin settings.
		 * @param HttpClient             $http     Shared transport.
		 */
		$filtered = apply_filters( 'odds_aggregator_providers', $builders, $settings, $http );

		return is_array( $filtered ) ? $filtered : $builders;
	}
}
