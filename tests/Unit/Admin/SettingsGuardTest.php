<?php
/**
 * Tests for the data-source guard on the settings screen.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Admin;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Admin\SettingsPage;
use OddsAggregator\Admin\SourceStatus;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pre_update_option guard that probes a data source before it is
 * applied.
 *
 * The guard runs before the option row is written, so it has to validate the
 * values being submitted rather than the ones still stored. Pasting an API key
 * and selecting the source that needs it is a single save, and it has to work.
 */
final class SettingsGuardTest extends TestCase {

	/**
	 * Clears the stores the stubs are backed by.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']         = array();
		$GLOBALS['odds_aggregator_test_transients']      = array();
		$GLOBALS['odds_aggregator_test_settings_errors'] = array();
		$GLOBALS['odds_aggregator_test_http']            = array(
			'responses' => array(),
			'requests'  => array(),
		);
	}

	/**
	 * Settings page wired to real collaborators.
	 *
	 * @param Settings $settings Settings instance.
	 * @return SettingsPage
	 */
	private function page( Settings $settings ): SettingsPage {
		$http       = new HttpClient( new RateLimiter(), new RetryPolicy() );
		$factory    = new ProviderFactory( $settings, $http );
		$markets    = new MarketRegistry();
		$repository = new OddsRepository( $factory, new OddsCache(), $markets );

		return new SettingsPage(
			$settings,
			$factory,
			new BookmakerRegistry(),
			$markets,
			$repository,
			new SourceStatus( $settings, $factory, $repository, $markets )
		);
	}

	/**
	 * Mirrors OddsApiProvider's cache key, so a probe can be satisfied offline.
	 *
	 * @param string $api_key API key.
	 * @param string $regions Regions.
	 * @param string $markets Upstream market list.
	 * @return string
	 */
	private function cache_key( string $api_key, string $regions = 'us', string $markets = 'h2h,totals' ): string {
		$parts = array( 'soccer_epl', $regions, $markets, hash( 'sha256', $api_key ) );

		return 'odds_aggregator_oapi_' . md5( implode( '|', $parts ) );
	}

	/**
	 * A payload the probe will accept, keyed to one API key.
	 *
	 * @param string $api_key API key the payload belongs to.
	 */
	private function seed_payload( string $api_key ): void {
		$GLOBALS['odds_aggregator_test_transients'][ $this->cache_key( $api_key ) ] = array(
			array(
				'id'            => 'evt',
				'sport_title'   => 'EPL',
				'home_team'     => 'A',
				'away_team'     => 'B',
				'commence_time' => '2026-11-01T15:00:00Z',
				'bookmakers'    => array(),
			),
		);
	}

	/**
	 * Notice types raised during a save.
	 *
	 * @return string[]
	 */
	private function notice_types(): array {
		return array_column( $GLOBALS['odds_aggregator_test_settings_errors'], 'type' );
	}

	/**
	 * A key and the source that needs it can be saved together.
	 *
	 * Before the fix the guard probed the stored settings, saw the empty key the
	 * save was about to replace, and rejected the change as unconfigured.
	 */
	public function test_a_key_and_its_provider_save_in_one_step(): void {
		$this->seed_payload( 'fresh-key' );

		$settings = new Settings();
		$value    = array_merge(
			Settings::defaults(),
			array(
				'provider' => 'oddsapi',
				'api_key'  => 'fresh-key',
			)
		);

		$saved = $this->page( $settings )->guard_provider_change(
			$value,
			array_merge( Settings::defaults(), array( 'provider' => 'mock' ) )
		);

		$this->assertSame( 'oddsapi', $saved['provider'] );
		$this->assertSame( 'fresh-key', $saved['api_key'] );
		$this->assertContains( 'updated', $this->notice_types() );
	}

	/**
	 * A source that cannot answer is refused and the working one is kept.
	 */
	public function test_an_unusable_provider_is_refused(): void {
		$settings = new Settings();
		$value    = array_merge(
			Settings::defaults(),
			array(
				'provider' => 'oddsapi',
				'api_key'  => '',
			)
		);

		$saved = $this->page( $settings )->guard_provider_change(
			$value,
			array_merge( Settings::defaults(), array( 'provider' => 'mock' ) )
		);

		$this->assertSame( 'mock', $saved['provider'] );
		$this->assertContains( 'error', $this->notice_types() );
	}

	/**
	 * Editing an unrelated field never pays for a probe.
	 */
	public function test_an_unrelated_edit_makes_no_request(): void {
		$stored = array_merge(
			Settings::defaults(),
			array(
				'provider' => 'oddsapi',
				'api_key'  => 'kept',
			)
		);

		$value                   = $stored;
		$value['default_format'] = 'american';

		$saved = $this->page( new Settings() )->guard_provider_change( $value, $stored );

		$this->assertSame( 'american', $saved['default_format'] );
		$this->assertSame( array(), $GLOBALS['odds_aggregator_test_http']['requests'] );
		$this->assertSame( array(), $this->notice_types() );
	}

	/**
	 * A replacement key that does not work leaves the old one in place.
	 *
	 * The source stays selected either way, so persisting a broken key would
	 * blank the front end with nothing on screen to explain it.
	 */
	public function test_a_broken_replacement_key_is_rolled_back(): void {
		$GLOBALS['odds_aggregator_test_http']['responses'][] = array(
			'response' => array( 'code' => 401 ),
			'headers'  => array(),
			'body'     => '',
		);

		$stored = array_merge(
			Settings::defaults(),
			array(
				'provider' => 'oddsapi',
				'api_key'  => 'the-good-one',
			)
		);

		$value            = $stored;
		$value['api_key'] = 'a-typo';

		$saved = $this->page( new Settings() )->guard_provider_change( $value, $stored );

		$this->assertSame( 'the-good-one', $saved['api_key'] );
		$this->assertSame( 'oddsapi', $saved['provider'] );
		$this->assertContains( 'error', $this->notice_types() );
	}

	/**
	 * A source that answers but has no fixtures yet is a warning, not a refusal.
	 *
	 * A licensed feed returns nothing between seasons, and rejecting a valid key
	 * for that would be wrong.
	 */
	public function test_an_empty_event_list_is_only_a_warning(): void {
		$GLOBALS['odds_aggregator_test_transients'][ $this->cache_key( 'out-of-season' ) ] = array();

		$settings = new Settings();
		$value    = array_merge(
			Settings::defaults(),
			array(
				'provider' => 'oddsapi',
				'api_key'  => 'out-of-season',
			)
		);

		$saved = $this->page( $settings )->guard_provider_change(
			$value,
			array_merge( Settings::defaults(), array( 'provider' => 'mock' ) )
		);

		$this->assertSame( 'oddsapi', $saved['provider'] );
		$this->assertContains( 'warning', $this->notice_types() );
	}
}
