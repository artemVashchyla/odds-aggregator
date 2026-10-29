<?php
/**
 * Tests for settings sanitisation across tabs.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Admin;

use OddsAggregator\Admin\Settings;
use PHPUnit\Framework\TestCase;

/**
 * A tabbed screen posts only the fields on the tab being saved, so the
 * sanitiser has to merge rather than rebuild. Getting this wrong silently
 * destroys configuration, which is exactly why it is tested here.
 */
final class SettingsTest extends TestCase {

	/**
	 * Seeds the stored option and returns a fresh accessor.
	 *
	 * @param array<string,mixed> $stored Stored settings.
	 * @return Settings
	 */
	private function with_stored( array $stored ): Settings {
		$GLOBALS['odds_aggregator_test_options'] = array(
			Settings::OPTION => array_merge( Settings::defaults(), $stored ),
		);

		return new Settings();
	}

	/**
	 * Every field belongs to exactly one tab, and all are covered.
	 */
	public function test_tabs_partition_every_setting(): void {
		$fields = array_merge( ...array_values( Settings::tab_fields() ) );

		$this->assertSame( count( $fields ), count( array_unique( $fields ) ), 'A field appears on two tabs.' );
		$this->assertEqualsCanonicalizing( array_keys( Settings::defaults() ), $fields );
	}

	/**
	 * Saving the markets tab leaves bookmakers and provider untouched.
	 */
	public function test_saving_one_tab_preserves_the_others(): void {
		$settings = $this->with_stored(
			array(
				'provider'       => 'oddsapi',
				'api_key'        => 'secret',
				'bookmakers'     => array( 'bet365', 'coral' ),
				'bookmaker_urls' => array( 'bet365' => 'https://example.test/b' ),
				'markets'        => array( '1x2' ),
				'rate_limit'     => 42,
			)
		);

		$clean = $settings->sanitize(
			array(
				'_tab'    => 'markets',
				'markets' => array( '1x2', 'btts' ),
			)
		);

		$this->assertSame( array( '1x2', 'btts' ), $clean['markets'] );
		$this->assertSame( 'oddsapi', $clean['provider'] );
		$this->assertSame( 'secret', $clean['api_key'] );
		$this->assertSame( array( 'bet365', 'coral' ), $clean['bookmakers'] );
		$this->assertSame( array( 'bet365' => 'https://example.test/b' ), $clean['bookmaker_urls'] );
		$this->assertSame( 42, $clean['rate_limit'] );
	}

	/**
	 * Saving the bookmakers tab cannot change the data source.
	 *
	 * This is the regression that broke the front end: every save posted the
	 * provider field, so editing a bookmaker re-applied a failing source.
	 */
	public function test_bookmaker_tab_cannot_change_the_provider(): void {
		$settings = $this->with_stored( array( 'provider' => 'mock' ) );

		$clean = $settings->sanitize(
			array(
				'_tab'       => 'bookmakers',
				'provider'   => 'oddschecker',
				'bookmakers' => array( 'unibet' ),
			)
		);

		$this->assertSame( 'mock', $clean['provider'] );
		$this->assertSame( array( 'unibet' ), $clean['bookmakers'] );
	}

	/**
	 * Unticking every box on a tab clears that field rather than being ignored.
	 */
	public function test_unticking_everything_clears_the_owning_field(): void {
		$settings = $this->with_stored( array( 'bookmakers' => array( 'bet365', 'coral' ) ) );

		$clean = $settings->sanitize( array( '_tab' => 'bookmakers' ) );

		$this->assertSame( array(), $clean['bookmakers'] );
	}

	/**
	 * A save with no tab behaves like the old single-screen form.
	 */
	public function test_unknown_tab_treats_every_field_as_submitted(): void {
		$settings = $this->with_stored( array( 'provider' => 'oddsapi' ) );

		$clean = $settings->sanitize(
			array(
				'provider' => 'mock',
				'markets'  => array( 'btts' ),
			)
		);

		$this->assertSame( 'mock', $clean['provider'] );
		$this->assertSame( array( 'btts' ), $clean['markets'] );
		$this->assertSame( array(), $clean['bookmakers'] );
	}

	/**
	 * The internal tab marker never reaches storage.
	 */
	public function test_tab_marker_is_not_persisted(): void {
		$clean = $this->with_stored( array() )->sanitize( array( '_tab' => 'general' ) );

		$this->assertArrayNotHasKey( '_tab', $clean );
	}

	/**
	 * Numeric fields are clamped rather than rejected.
	 */
	public function test_numeric_fields_are_clamped(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'       => 'performance',
				'cache_ttl'  => 1,
				'stale_ttl'  => 999999,
				'rate_limit' => 9000,
			)
		);

		$this->assertSame( 10, $clean['cache_ttl'] );
		$this->assertSame( 86400, $clean['stale_ttl'] );
		$this->assertSame( 600, $clean['rate_limit'] );
	}

	/**
	 * Stale data may never expire sooner than it stops being fresh.
	 */
	public function test_stale_window_cannot_undercut_the_fresh_window(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'      => 'performance',
				'cache_ttl' => 600,
				'stale_ttl' => 30,
			)
		);

		$this->assertSame( 600, $clean['cache_ttl'] );
		$this->assertGreaterThanOrEqual( $clean['cache_ttl'], $clean['stale_ttl'] );
	}

	/**
	 * Outbound links must be real URLs; anything else is dropped.
	 */
	public function test_bookmaker_urls_are_validated(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'           => 'bookmakers',
				'bookmakers'     => array( 'bet365' ),
				'bookmaker_urls' => array(
					'bet365' => '  https://example.test/go  ',
					'coral'  => 'javascript:alert(1)',
					'skybet' => '',
				),
			)
		);

		$this->assertSame( array( 'bet365' => 'https://example.test/go' ), $clean['bookmaker_urls'] );
	}

	/**
	 * An unrecognised display format falls back to decimal.
	 */
	public function test_invalid_format_falls_back(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'           => 'general',
				'default_format' => 'martian',
			)
		);

		$this->assertSame( 'decimal', $clean['default_format'] );
	}

	/**
	 * The untouched version 1 default bookmaker list is replaced by the current default.
	 */
	public function test_upgrade_replaces_old_default_bookmakers(): void {
		$GLOBALS['odds_aggregator_test_options'] = array(
			Settings::OPTION => array(
				'provider'   => 'mock',
				'bookmakers' => array( 'bet365', 'fanduel', 'draftkings', 'betmgm', 'caesars', 'fanatics', 'betrivers', 'borgata' ),
			),
		);

		$settings = new Settings();
		$settings->upgrade();

		$this->assertSame( Settings::defaults()['bookmakers'], $settings->bookmakers() );
		$this->assertSame( 'mock', $settings->provider() );
		$this->assertSame( Settings::SCHEMA, get_option( Settings::SCHEMA_OPTION ) );
	}

	/**
	 * A bookmaker list the administrator chose is kept.
	 */
	public function test_upgrade_keeps_custom_bookmakers(): void {
		$GLOBALS['odds_aggregator_test_options'] = array(
			Settings::OPTION => array( 'bookmakers' => array( 'bet365', 'coral' ) ),
		);

		$settings = new Settings();
		$settings->upgrade();

		$this->assertSame( array( 'bet365', 'coral' ), $settings->bookmakers() );
	}

	/**
	 * The upgrade runs once.
	 */
	public function test_upgrade_runs_once(): void {
		$old = array( 'bet365', 'fanduel', 'draftkings', 'betmgm', 'caesars', 'fanatics', 'betrivers', 'borgata' );

		$GLOBALS['odds_aggregator_test_options'] = array(
			Settings::OPTION        => array( 'bookmakers' => $old ),
			Settings::SCHEMA_OPTION => Settings::SCHEMA,
		);

		$settings = new Settings();
		$settings->upgrade();

		$this->assertSame( $old, $settings->bookmakers() );
	}

	/**
	 * An unrecognised bookmaker region falls back to the default.
	 */
	public function test_invalid_region_falls_back(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'            => 'general',
				'oddsapi_regions' => 'mars',
			)
		);

		$this->assertSame( 'us', $clean['oddsapi_regions'] );
	}

	/**
	 * Several regions are accepted, deduplicated and kept in order.
	 */
	public function test_regions_may_be_a_list(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'            => 'general',
				'oddsapi_regions' => 'us, uk ,us,mars',
			)
		);

		$this->assertSame( 'us,uk', $clean['oddsapi_regions'] );
	}

	/**
	 * Regions whose bookmakers are not in the catalogue are not offered.
	 */
	public function test_regions_outside_the_catalogue_are_refused(): void {
		$clean = $this->with_stored( array() )->sanitize(
			array(
				'_tab'            => 'general',
				'oddsapi_regions' => 'eu,au,us2',
			)
		);

		$this->assertSame( 'us', $clean['oddsapi_regions'] );
	}

	/**
	 * A region saved before the list was narrowed reads back as the default.
	 */
	public function test_a_previously_saved_region_falls_back(): void {
		$this->assertSame( 'us', $this->with_stored( array( 'oddsapi_regions' => 'eu' ) )->oddsapi_regions() );
		$this->assertSame( 'uk', $this->with_stored( array( 'oddsapi_regions' => 'uk' ) )->oddsapi_regions() );
	}

	/**
	 * The upstream fetch interval has a high floor.
	 *
	 * The feed charges per fetch, so a careless low value would burn a monthly
	 * allowance in hours. Clamped rather than rejected, like the other numbers.
	 */
	public function test_upstream_interval_is_clamped(): void {
		$floor = $this->with_stored( array() )->sanitize(
			array(
				'_tab'        => 'performance',
				'oddsapi_ttl' => 5,
			)
		);

		$ceiling = $this->with_stored( array() )->sanitize(
			array(
				'_tab'        => 'performance',
				'oddsapi_ttl' => 999999,
			)
		);

		$this->assertSame( 600, $floor['oddsapi_ttl'] );
		$this->assertSame( 86400, $ceiling['oddsapi_ttl'] );
	}
}
