<?php
/**
 * Catalogue of known bookmakers.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

/**
 * Registry of the bookmakers the plugin knows about.
 *
 * Ships with UK and US defaults and is filterable, so a site can add or rename a
 * bookmaker without forking the plugin. Each default carries a homepage URL and,
 * for most, a bundled icon; administrator-supplied URLs and logos replace them.
 *
 * The defaults are data, not code, so they live in bookmakers.json beside the
 * plugin file: the catalogue can be reviewed, diffed or regenerated without
 * touching a class, and a distribution can ship a different one.
 */
final class BookmakerRegistry {

	/**
	 * Catalogue file, relative to the plugin directory.
	 *
	 * @var string
	 */
	private const CATALOGUE_FILE = 'bookmakers.json';

	/**
	 * Decoded catalogues, keyed by the file they were read from.
	 *
	 * Decoding is per PHP request, not per instance: several instances are built
	 * in one page view (the renderer, the REST controller, the settings screen)
	 * and they all want the same file.
	 *
	 * @var array<string,array<string,array{name:string,url:string,region:string,logo:string}>>
	 */
	private static array $catalogues = array();

	/**
	 * Resolved bookmakers, keyed by id.
	 *
	 * @var array<string,Bookmaker>|null
	 */
	private ?array $bookmakers = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $url_overrides  Bookmaker id => outbound URL, from settings.
	 * @param array<string,string> $logo_overrides Bookmaker id => logo URL, from settings.
	 */
	public function __construct(
		private readonly array $url_overrides = array(),
		private readonly array $logo_overrides = array()
	) {}

	/**
	 * All known bookmakers, keyed by id.
	 *
	 * @return array<string,Bookmaker>
	 */
	public function all(): array {
		if ( null !== $this->bookmakers ) {
			return $this->bookmakers;
		}

		$bookmakers = array();

		foreach ( self::catalogue() as $id => $defaults ) {
			// A saved empty string counts as "not set", so the default applies.
			$override = trim( (string) ( $this->url_overrides[ $id ] ?? '' ) );
			$logo     = trim( (string) ( $this->logo_overrides[ $id ] ?? '' ) );

			$bookmakers[ $id ] = new Bookmaker(
				$id,
				$defaults['name'],
				'' !== $override ? $override : $defaults['url'],
				$defaults['region'],
				'' !== $logo ? $logo : $this->default_logo( $id )
			);
		}

		/**
		 * Filters the bookmaker catalogue.
		 *
		 * @param array<string,Bookmaker> $bookmakers    Bookmakers keyed by id.
		 * @param array<string,string>    $url_overrides Administrator URL overrides.
		 */
		$filtered = apply_filters( 'odds_aggregator_bookmakers', $bookmakers, $this->url_overrides );

		$this->bookmakers = array_filter(
			is_array( $filtered ) ? $filtered : $bookmakers,
			static fn( $bookmaker ): bool => $bookmaker instanceof Bookmaker
		);

		return $this->bookmakers;
	}

	/**
	 * One bookmaker by id, or null when unknown.
	 *
	 * @param string $id Bookmaker id.
	 * @return Bookmaker|null
	 */
	public function get( string $id ): ?Bookmaker {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Subset of the catalogue, in the order requested.
	 *
	 * Unknown ids are skipped rather than throwing, so a block that references
	 * a bookmaker which has since been removed still renders.
	 *
	 * @param string[] $ids Bookmaker ids.
	 * @return array<string,Bookmaker>
	 */
	public function only( array $ids ): array {
		$subset = array();

		foreach ( $ids as $id ) {
			$bookmaker = $this->get( (string) $id );

			if ( $bookmaker instanceof Bookmaker ) {
				$subset[ $bookmaker->id() ] = $bookmaker;
			}
		}

		return $subset;
	}

	/**
	 * Outbound URL used when no override is set.
	 *
	 * @param string $id Bookmaker id.
	 * @return string Empty for bookmakers outside the default catalogue.
	 */
	public function default_url( string $id ): string {
		return self::catalogue()[ $id ]['url'] ?? '';
	}

	/**
	 * URL of the icon bundled for a bookmaker.
	 *
	 * Served from the site's own copy of the plugin, so no third party is
	 * contacted on a page view.
	 *
	 * @param string $id Bookmaker id.
	 * @return string Empty when no icon ships for that bookmaker.
	 */
	public function default_logo( string $id ): string {
		$file = self::catalogue()[ $id ]['logo'] ?? '';

		return '' === $file ? '' : ODDS_AGGREGATOR_URL . 'assets/logos/' . $file;
	}

	/**
	 * All known bookmaker ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->all() );
	}

	/**
	 * The shipped catalogue, read from disk at most once per file per request.
	 *
	 * @return array<string,array{name:string,url:string,region:string,logo:string}>
	 */
	private static function catalogue(): array {
		$file = self::catalogue_file();

		if ( ! isset( self::$catalogues[ $file ] ) ) {
			self::$catalogues[ $file ] = self::read_catalogue( $file );
		}

		return self::$catalogues[ $file ];
	}

	/**
	 * Absolute path of the catalogue file.
	 *
	 * @return string
	 */
	private static function catalogue_file(): string {
		$directory = defined( 'ODDS_AGGREGATOR_PATH' ) ? ODDS_AGGREGATOR_PATH : dirname( __DIR__, 2 ) . '/';

		/**
		 * Filters the path of the bookmaker catalogue file.
		 *
		 * Lets a distribution or a test ship its own list without replacing the
		 * one bundled with the plugin.
		 *
		 * @param string $file Absolute path to a JSON catalogue.
		 */
		return (string) apply_filters( 'odds_aggregator_bookmakers_file', $directory . self::CATALOGUE_FILE );
	}

	/**
	 * Reads and validates one catalogue file.
	 *
	 * A missing or malformed file leaves the catalogue empty rather than fatal:
	 * the block then renders no columns, which an administrator can see and fix,
	 * whereas a thrown error would take the whole site down.
	 *
	 * @param string $file Absolute path to a JSON catalogue.
	 * @return array<string,array{name:string,url:string,region:string,logo:string}>
	 */
	private static function read_catalogue( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		// A bundled data file, not user input: the filesystem API is not loaded
		// this early and would bring no benefit here.
		$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$catalogue = array();

		foreach ( $decoded as $id => $entry ) {
			$id = sanitize_key( (string) $id );

			// A nameless entry has nothing to render, so it is dropped outright.
			if ( '' === $id || ! is_array( $entry ) || '' === trim( (string) ( $entry['name'] ?? '' ) ) ) {
				continue;
			}

			$catalogue[ $id ] = array(
				'name'   => trim( (string) $entry['name'] ),
				'url'    => trim( (string) ( $entry['url'] ?? '' ) ),
				'region' => sanitize_key( (string) ( $entry['region'] ?? '' ) ),
				'logo'   => basename( trim( (string) ( $entry['logo'] ?? '' ) ) ),
			);
		}

		return $catalogue;
	}
}
