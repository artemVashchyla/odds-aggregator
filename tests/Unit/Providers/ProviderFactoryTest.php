<?php
/**
 * Tests for provider construction.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Providers\MockProvider;
use OddsAggregator\Providers\OddsApiProvider;
use OddsAggregator\Providers\OddscheckerProvider;
use OddsAggregator\Providers\ProviderException;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Factory: resolution by id, failure on unknown ids, and the
 * fallback that keeps the front end renderable.
 */
final class ProviderFactoryTest extends TestCase {

	/**
	 * Builds a factory over default settings.
	 *
	 * @return ProviderFactory
	 */
	private function factory(): ProviderFactory {
		$GLOBALS['odds_aggregator_test_options'] = array();

		return new ProviderFactory(
			new Settings(),
			new HttpClient( new RateLimiter(), new RetryPolicy() )
		);
	}

	/**
	 * Each registered id builds its own provider class.
	 */
	public function test_resolves_each_registered_provider(): void {
		$factory = $this->factory();

		$this->assertInstanceOf( MockProvider::class, $factory->create( 'mock' ) );
		$this->assertInstanceOf( OddscheckerProvider::class, $factory->create( 'oddschecker' ) );
		$this->assertInstanceOf( OddsApiProvider::class, $factory->create( 'oddsapi' ) );
	}

	/**
	 * An unknown id is an error, not a silent default.
	 */
	public function test_unknown_provider_throws(): void {
		$this->expectException( ProviderException::class );
		$this->factory()->create( 'nonexistent' );
	}

	/**
	 * With no settings saved, the configured provider is sample data.
	 *
	 * Deliberately not a live feed: naming one that has no API key yet would
	 * show its label in the admin while the front end quietly served samples.
	 */
	public function test_default_provider_is_sample_data(): void {
		$this->assertInstanceOf( MockProvider::class, $this->factory()->create() );
	}

	/**
	 * A provider that cannot run degrades to sample data rather than failing.
	 */
	public function test_fallback_replaces_unavailable_provider(): void {
		$GLOBALS['odds_aggregator_test_options'] = array(
			Settings::OPTION => array(
				'provider' => 'oddsapi',
				'api_key'  => '',
			),
		);

		$factory = new ProviderFactory(
			new Settings(),
			new HttpClient( new RateLimiter(), new RetryPolicy() )
		);

		// The Odds API has no key, so it is unavailable.
		$this->assertFalse( $factory->create( 'oddsapi' )->is_available() );
		$this->assertInstanceOf( MockProvider::class, $factory->create_or_fallback() );
	}

	/**
	 * The admin dropdown is populated from the same registry.
	 */
	public function test_choices_cover_registered_providers(): void {
		$choices = $this->factory()->choices();

		$this->assertSame( array( 'mock', 'oddschecker', 'oddsapi' ), array_keys( $choices ) );

		foreach ( $choices as $label ) {
			$this->assertNotSame( '', $label );
		}
	}
}
