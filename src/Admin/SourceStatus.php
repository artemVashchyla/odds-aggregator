<?php
/**
 * State of the active data source, for the settings screen.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Admin;

use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\AbstractProvider;
use OddsAggregator\Providers\ProviderFactory;
use OddsAggregator\Providers\ProviderInterface;

/**
 * Health and coverage of the active data source.
 */
final class SourceStatus {

	/**
	 * Events read for this request.
	 *
	 * @var Event[]|null
	 */
	private ?array $events = null;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings   Plugin settings.
	 * @param ProviderFactory $factory    Provider construction.
	 * @param OddsRepository  $repository Cached odds access.
	 * @param MarketRegistry  $markets    Market catalogue.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderFactory $factory,
		private readonly OddsRepository $repository,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Active provider.
	 *
	 * @return ProviderInterface
	 */
	public function provider(): ProviderInterface {
		return $this->factory->create_or_fallback();
	}

	/**
	 * Events the active provider currently offers.
	 *
	 * @return Event[]
	 */
	public function events(): array {
		$this->events ??= $this->repository->events();

		return $this->events;
	}

	/**
	 * Most recent failure of the active provider, or null.
	 *
	 * @return array{provider:string,message:string,time:int}|null
	 */
	public function error(): ?array {
		$this->events();

		$error = OddsRepository::last_error();

		return null !== $error && $error['provider'] === $this->provider()->id() ? $error : null;
	}

	/**
	 * Whether the active provider is returning events without errors.
	 *
	 * @return bool
	 */
	public function is_healthy(): bool {
		return null === $this->error() && array() !== $this->events();
	}

	/**
	 * Bookmaker ids the active provider quotes, or null when unknown.
	 *
	 * @return string[]|null
	 */
	public function quoted_bookmakers(): ?array {
		return $this->supported_bookmakers( $this->provider() );
	}

	/**
	 * Enabled bookmakers the active provider quotes, or null when unknown.
	 *
	 * @return string[]|null
	 */
	public function enabled_quoted_bookmakers(): ?array {
		$quoted = $this->quoted_bookmakers();

		return null === $quoted ? null : array_values( array_intersect( $this->settings->bookmakers(), $quoted ) );
	}

	/**
	 * Enabled market keys the active provider does not quote.
	 *
	 * @return string[]
	 */
	public function unquoted_markets(): array {
		$supported = $this->supported_markets( $this->provider() );

		if ( null === $supported ) {
			return array();
		}

		return array_values( array_diff( $this->settings->markets(), $supported ) );
	}

	/**
	 * Market keys a provider quotes, or null for all.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return string[]|null
	 */
	public function supported_markets( ProviderInterface $provider ): ?array {
		if ( ! $provider instanceof AbstractProvider ) {
			return null;
		}

		$supported = $provider->supported_markets();

		return null === $supported ? null : array_values( array_intersect( $supported, $this->markets->keys() ) );
	}

	/**
	 * Bookmaker ids a provider quotes, or null when unknown.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return string[]|null
	 */
	public function supported_bookmakers( ProviderInterface $provider ): ?array {
		return $provider instanceof AbstractProvider ? $provider->supported_bookmakers() : null;
	}
}
