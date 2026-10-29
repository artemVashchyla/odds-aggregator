<?php
/**
 * Tests for the JSON bookmaker catalogue.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Domain;

use OddsAggregator\Domain\BookmakerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Covers reading bookmakers.json: what the shipped file must contain, and how a
 * replacement file is validated.
 *
 * Each test that supplies its own catalogue writes it to a fresh temporary path,
 * because the decoded file is memoised per path for the whole PHP request.
 */
final class BookmakerCatalogueTest extends TestCase {

	/**
	 * Temporary catalogues to clean up.
	 *
	 * @var string[]
	 */
	private array $written = array();

	/**
	 * Starts each test with no catalogue override in place.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_filters'] = array();
	}

	/**
	 * Removes the temporary catalogues and the override.
	 */
	protected function tearDown(): void {
		$GLOBALS['odds_aggregator_test_filters'] = array();

		foreach ( $this->written as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		$this->written = array();
	}

	/**
	 * Points the registry at a catalogue written for this test.
	 *
	 * @param string $json Raw file contents.
	 * @return void
	 */
	private function use_catalogue( string $json ): void {
		$file            = (string) tempnam( sys_get_temp_dir(), 'oa-books-' );
		$this->written[] = $file;

		file_put_contents( $file, $json );

		$GLOBALS['odds_aggregator_test_filters']['odds_aggregator_bookmakers_file'] = static fn(): string => $file;
	}

	/**
	 * Points the registry at a path that does not exist.
	 *
	 * @return void
	 */
	private function use_missing_catalogue(): void {
		$file = sys_get_temp_dir() . '/oa-books-absent-' . uniqid() . '.json';

		$GLOBALS['odds_aggregator_test_filters']['odds_aggregator_bookmakers_file'] = static fn(): string => $file;
	}

	/**
	 * The shipped catalogue is valid JSON and is what the registry serves.
	 */
	public function test_shipped_catalogue_backs_the_registry(): void {
		$file = ODDS_AGGREGATOR_PATH . 'bookmakers.json';

		$this->assertFileExists( $file );

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		$this->assertIsArray( $decoded, 'bookmakers.json must decode to an array.' );
		$this->assertSame( array_keys( $decoded ), ( new BookmakerRegistry() )->ids() );
	}

	/**
	 * Every entry carries the four fields the registry reads.
	 */
	public function test_shipped_entries_are_complete(): void {
		$decoded = (array) json_decode( (string) file_get_contents( ODDS_AGGREGATOR_PATH . 'bookmakers.json' ), true );

		foreach ( $decoded as $id => $entry ) {
			$this->assertIsArray( $entry, (string) $id );
			$this->assertArrayHasKey( 'name', $entry, (string) $id );
			$this->assertArrayHasKey( 'url', $entry, (string) $id );
			$this->assertArrayHasKey( 'region', $entry, (string) $id );
			$this->assertArrayHasKey( 'logo', $entry, (string) $id );
			$this->assertContains( $entry['region'], array( 'uk', 'us' ), (string) $id );
		}
	}

	/**
	 * A named logo must actually ship, or the table renders a broken image.
	 */
	public function test_named_logos_ship_with_the_plugin(): void {
		$decoded = (array) json_decode( (string) file_get_contents( ODDS_AGGREGATOR_PATH . 'bookmakers.json' ), true );

		foreach ( $decoded as $id => $entry ) {
			if ( '' === (string) $entry['logo'] ) {
				continue;
			}

			$this->assertFileExists(
				ODDS_AGGREGATOR_PATH . 'assets/logos/' . $entry['logo'],
				sprintf( '%s names a logo that is not bundled.', (string) $id )
			);
		}
	}

	/**
	 * The default URL and logo come from the file, not from the class.
	 */
	public function test_defaults_are_read_from_the_file(): void {
		$this->use_catalogue(
			(string) wp_json_encode(
				array(
					'examplebet' => array(
						'name'   => 'Example Bet',
						'url'    => 'https://example.test/book',
						'region' => 'uk',
						'logo'   => 'bet365.png',
					),
				)
			)
		);

		$registry  = new BookmakerRegistry();
		$bookmaker = $registry->get( 'examplebet' );

		$this->assertNotNull( $bookmaker );
		$this->assertSame( 'Example Bet', $bookmaker->name() );
		$this->assertSame( 'https://example.test/book', $registry->default_url( 'examplebet' ) );
		$this->assertSame( ODDS_AGGREGATOR_URL . 'assets/logos/bet365.png', $registry->default_logo( 'examplebet' ) );
	}

	/**
	 * Administrator overrides still win over a file-supplied default.
	 */
	public function test_overrides_still_beat_the_file(): void {
		$this->use_catalogue(
			(string) wp_json_encode(
				array(
					'examplebet' => array(
						'name' => 'Example Bet',
						'url'  => 'https://example.test/book',
					),
				)
			)
		);

		$registry = new BookmakerRegistry(
			array( 'examplebet' => 'https://example.test/affiliate' ),
			array( 'examplebet' => 'https://cdn.example.test/logo.png' )
		);

		$this->assertSame( 'https://example.test/affiliate', $registry->get( 'examplebet' )->url() );
		$this->assertSame( 'https://cdn.example.test/logo.png', $registry->get( 'examplebet' )->logo() );
	}

	/**
	 * Entries with no usable name are dropped; the rest of the file still loads.
	 */
	public function test_unusable_entries_are_dropped(): void {
		$this->use_catalogue(
			(string) wp_json_encode(
				array(
					'good'     => array( 'name' => 'Good Book' ),
					'noname'   => array( 'url' => 'https://example.test' ),
					'blank'    => array( 'name' => '   ' ),
					'notalist' => 'Good Book',
				)
			)
		);

		$this->assertSame( array( 'good' ), ( new BookmakerRegistry() )->ids() );
	}

	/**
	 * A logo path cannot climb out of the bundled logo directory.
	 */
	public function test_logo_path_cannot_escape_the_asset_directory(): void {
		$this->use_catalogue(
			(string) wp_json_encode(
				array(
					'examplebet' => array(
						'name' => 'Example Bet',
						'logo' => '../../../wp-config.php',
					),
				)
			)
		);

		$this->assertSame(
			ODDS_AGGREGATOR_URL . 'assets/logos/wp-config.php',
			( new BookmakerRegistry() )->default_logo( 'examplebet' )
		);
	}

	/**
	 * A malformed catalogue empties the registry instead of raising an error.
	 */
	public function test_malformed_catalogue_yields_an_empty_registry(): void {
		$this->use_catalogue( '{ not json' );

		$this->assertSame( array(), ( new BookmakerRegistry() )->all() );
	}

	/**
	 * So does a missing one.
	 */
	public function test_missing_catalogue_yields_an_empty_registry(): void {
		$this->use_missing_catalogue();

		$registry = new BookmakerRegistry();

		$this->assertSame( array(), $registry->all() );
		$this->assertSame( '', $registry->default_url( 'bet365' ) );
		$this->assertSame( '', $registry->default_logo( 'bet365' ) );
	}
}
