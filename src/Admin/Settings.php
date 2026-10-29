<?php
/**
 * Typed access to plugin settings.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Admin;

use OddsAggregator\Domain\BookmakerRegistry;

/**
 * Reads and sanitises the single options row the plugin stores.
 *
 * Everything lives in one option so a settings read is one autoloaded row
 * rather than a dozen, and so the whole configuration can be filtered or
 * exported in one piece.
 */
final class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	public const OPTION = 'odds_aggregator_settings';

	/**
	 * Option holding the settings schema version.
	 *
	 * @var string
	 */
	public const SCHEMA_OPTION = 'odds_aggregator_schema';

	/**
	 * Current settings schema version.
	 *
	 * @var int
	 */
	public const SCHEMA = 2;

	/**
	 * The Odds API regions offered.
	 *
	 * Only regions whose bookmakers are in the catalogue: the feed's other
	 * regions (eu, au, us2) return books the plugin would drop, so they would
	 * spend credits on nothing.
	 *
	 * @var string[]
	 */
	public const ODDSAPI_REGIONS = array( 'us', 'uk' );

	/**
	 * Default bookmakers of schema version 1.
	 *
	 * @var string[]
	 */
	private const V1_DEFAULT_BOOKMAKERS = array( 'bet365', 'fanduel', 'draftkings', 'betmgm', 'caesars', 'fanatics', 'betrivers', 'borgata' );

	/**
	 * Cached option value for this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Factory defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'        => 'mock',
			'api_key'         => '',
			'oddsapi_regions' => 'us',
			'bookmakers'      => ( new BookmakerRegistry() )->ids(),
			'markets'         => array( '1x2', 'ou25', 'btts' ),
			'bookmaker_urls'  => array(),
			'bookmaker_logos' => array(),
			'cache_ttl'       => 60,
			'stale_ttl'       => 600,
			'oddsapi_ttl'     => 10800,
			'rate_limit'      => 20,
			'default_format'  => 'decimal',
		);
	}

	/**
	 * Brings stored settings up to the current schema.
	 *
	 * @return void
	 */
	public function upgrade(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 1 ) >= self::SCHEMA ) {
			return;
		}

		$stored = get_option( self::OPTION );

		if ( is_array( $stored ) && ( $stored['bookmakers'] ?? null ) === self::V1_DEFAULT_BOOKMAKERS ) {
			$stored['bookmakers'] = self::defaults()['bookmakers'];
			update_option( self::OPTION, $stored );
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA );

		$this->cache = null;
	}

	/**
	 * Full settings array, defaults merged in.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );

		$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is unset.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->all()[ $key ] ?? $fallback;
	}

	/**
	 * Active provider id.
	 *
	 * @return string
	 */
	public function provider(): string {
		return (string) $this->get( 'provider', 'mock' );
	}

	/**
	 * API key for providers that need one.
	 *
	 * @return string
	 */
	public function api_key(): string {
		return (string) $this->get( 'api_key', '' );
	}

	/**
	 * Bookmaker regions requested from The Odds API.
	 *
	 * Comma separated. Each extra region multiplies the credit cost of every
	 * upstream fetch, which is why it is whitelisted rather than free text.
	 *
	 * @return string
	 */
	public function oddsapi_regions(): string {
		$regions = array_intersect(
			array_map( 'trim', explode( ',', (string) $this->get( 'oddsapi_regions', 'us' ) ) ),
			self::ODDSAPI_REGIONS
		);

		// A region saved before the list was narrowed falls back to the default.
		return array() === $regions ? 'us' : implode( ',', array_unique( $regions ) );
	}

	/**
	 * Seconds The Odds API payload is reused before another fetch is paid for.
	 *
	 * @return int
	 */
	public function oddsapi_ttl(): int {
		return max( 600, (int) $this->get( 'oddsapi_ttl', 10800 ) );
	}

	/**
	 * Bookmaker ids enabled site-wide.
	 *
	 * @return string[]
	 */
	public function bookmakers(): array {
		return array_values( array_filter( array_map( 'strval', (array) $this->get( 'bookmakers', array() ) ) ) );
	}

	/**
	 * Market keys enabled site-wide.
	 *
	 * @return string[]
	 */
	public function markets(): array {
		return array_values( array_filter( array_map( 'strval', (array) $this->get( 'markets', array() ) ) ) );
	}

	/**
	 * Administrator-supplied outbound URLs, keyed by bookmaker id.
	 *
	 * @return array<string,string>
	 */
	public function bookmaker_urls(): array {
		return $this->url_map( 'bookmaker_urls' );
	}

	/**
	 * Administrator-supplied logo URLs, keyed by bookmaker id.
	 *
	 * @return array<string,string>
	 */
	public function bookmaker_logos(): array {
		return $this->url_map( 'bookmaker_logos' );
	}

	/**
	 * One of the bookmaker-keyed URL maps, with string keys and values.
	 *
	 * @param string $key Setting name.
	 * @return array<string,string>
	 */
	private function url_map( string $key ): array {
		$urls = (array) $this->get( $key, array() );
		$out  = array();

		foreach ( $urls as $id => $url ) {
			$out[ (string) $id ] = (string) $url;
		}

		return $out;
	}

	/**
	 * Seconds a cached payload counts as fresh.
	 *
	 * @return int
	 */
	public function cache_ttl(): int {
		return max( 10, (int) $this->get( 'cache_ttl', 60 ) );
	}

	/**
	 * Seconds a stale payload may still be served.
	 *
	 * @return int
	 */
	public function stale_ttl(): int {
		return max( $this->cache_ttl(), (int) $this->get( 'stale_ttl', 600 ) );
	}

	/**
	 * Outbound requests permitted per minute.
	 *
	 * @return int
	 */
	public function rate_limit(): int {
		return max( 1, (int) $this->get( 'rate_limit', 20 ) );
	}

	/**
	 * Default odds display format.
	 *
	 * @return string
	 */
	public function default_format(): string {
		return (string) $this->get( 'default_format', 'decimal' );
	}

	/**
	 * A copy of these settings with some values replaced.
	 *
	 * The settings guard runs on pre_update_option, which fires before the row
	 * is written, so validating the submitted values means reading them from the
	 * submission rather than from the database.
	 *
	 * @param array<string,mixed> $overrides Values to overlay, already sanitised.
	 * @return self
	 */
	public function with_overrides( array $overrides ): self {
		$copy        = new self();
		$copy->cache = array_merge( $this->all(), $overrides );

		return $copy;
	}

	/**
	 * Forgets the per-request cache, so a later read sees a fresh write.
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->cache = null;
	}

	/**
	 * Fields each settings tab is responsible for.
	 *
	 * A tabbed screen only posts the fields on the tab being saved. Without an
	 * explicit map, "this tab was not submitted" and "every checkbox on this
	 * tab was unticked" are indistinguishable, and saving one tab would wipe
	 * the others.
	 *
	 * @return array<string,string[]>
	 */
	public static function tab_fields(): array {
		return array(
			'general'     => array( 'provider', 'api_key', 'oddsapi_regions', 'default_format' ),
			'bookmakers'  => array( 'bookmakers', 'bookmaker_urls', 'bookmaker_logos' ),
			'markets'     => array( 'markets' ),
			'performance' => array( 'cache_ttl', 'stale_ttl', 'oddsapi_ttl', 'rate_limit' ),
		);
	}

	/**
	 * Sanitises submitted settings, preserving fields the tab did not post.
	 *
	 * Registered as the Settings API sanitize_callback, so nothing reaches the
	 * database without passing through here. The submitted `_tab` names which
	 * group of fields is authoritative for this save; everything else is
	 * carried over from the stored value untouched.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$groups = self::tab_fields();
		$tab    = sanitize_key( (string) ( $input['_tab'] ?? '' ) );

		// An unrecognised tab means a programmatic or legacy save: treat every
		// field as submitted, which is the pre-tabs behaviour.
		$fields = $groups[ $tab ] ?? array_merge( ...array_values( $groups ) );

		$clean = $this->all();

		foreach ( $fields as $field ) {
			$clean[ $field ] = $this->sanitize_field( $field, $input );
		}

		// Serving stale data for less time than it stays fresh is meaningless.
		$clean['stale_ttl'] = max( (int) $clean['cache_ttl'], (int) $clean['stale_ttl'] );

		unset( $clean['_tab'] );

		$this->cache = null;

		return $clean;
	}

	/**
	 * Sanitises one submitted field.
	 *
	 * Only called for fields the current tab owns, so an absent value here
	 * genuinely means "cleared", not "not on this screen".
	 *
	 * @param string              $field Field name.
	 * @param array<string,mixed> $input Raw submitted values.
	 * @return mixed
	 */
	private function sanitize_field( string $field, array $input ): mixed {
		$defaults = self::defaults();

		switch ( $field ) {
			case 'provider':
				return sanitize_key( (string) ( $input['provider'] ?? $defaults['provider'] ) );

			case 'api_key':
				return sanitize_text_field( (string) ( $input['api_key'] ?? '' ) );

			case 'default_format':
				$format = (string) ( $input['default_format'] ?? '' );

				return in_array( $format, array( 'decimal', 'fractional', 'american' ), true )
					? $format
					: 'decimal';

			// Whitelisted because every extra region multiplies the credit cost
			// of each upstream fetch, and only these regions return bookmakers
			// the catalogue knows.
			case 'oddsapi_regions':
				$allowed = self::ODDSAPI_REGIONS;
				$regions = array();

				foreach ( explode( ',', (string) ( $input['oddsapi_regions'] ?? '' ) ) as $region ) {
					$region = sanitize_key( trim( $region ) );

					if ( in_array( $region, $allowed, true ) ) {
						$regions[] = $region;
					}
				}

				$regions = array_unique( $regions );

				return array() === $regions ? 'us' : implode( ',', $regions );

			case 'bookmakers':
			case 'markets':
				return array_values(
					array_unique( array_map( 'sanitize_key', (array) ( $input[ $field ] ?? array() ) ) )
				);

			// Both maps are bookmaker id => URL, so they sanitise identically.
			case 'bookmaker_urls':
			case 'bookmaker_logos':
				$urls = array();

				foreach ( (array) ( $input[ $field ] ?? array() ) as $id => $url ) {
					$url = esc_url_raw( trim( (string) $url ) );

					if ( '' !== $url ) {
						$urls[ sanitize_key( (string) $id ) ] = $url;
					}
				}

				return $urls;

			// Clamp rather than reject, so a typo degrades instead of breaking the page.
			case 'cache_ttl':
				return min( 3600, max( 10, (int) ( $input['cache_ttl'] ?? $defaults['cache_ttl'] ) ) );

			case 'stale_ttl':
				return min( 86400, max( 10, (int) ( $input['stale_ttl'] ?? $defaults['stale_ttl'] ) ) );

			// The floor is high on purpose: a metered feed charges per fetch, and
			// 60s would be 43,200 fetches a month.
			case 'oddsapi_ttl':
				return min( 86400, max( 600, (int) ( $input['oddsapi_ttl'] ?? $defaults['oddsapi_ttl'] ) ) );

			case 'rate_limit':
				return min( 600, max( 1, (int) ( $input['rate_limit'] ?? $defaults['rate_limit'] ) ) );
		}

		return $defaults[ $field ] ?? null;
	}
}
