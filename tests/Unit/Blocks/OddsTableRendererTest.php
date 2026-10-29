<?php
/**
 * Tests for the server-rendered odds table.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Blocks;

use DateTimeImmutable;
use OddsAggregator\Admin\Settings;
use OddsAggregator\Blocks\OddsTableRenderer;
use OddsAggregator\Cache\OddsCache;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Http\HttpClient;
use OddsAggregator\Http\RateLimiter;
use OddsAggregator\Http\RetryPolicy;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Odds\OddsFormat;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Providers\AbstractProvider;
use OddsAggregator\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * OddsTableRenderer over the sample provider.
 */
final class OddsTableRendererTest extends TestCase {

	/**
	 * Sample event rendered in every test.
	 *
	 * @var string
	 */
	private const EVENT = 'eng-ars-che';

	/**
	 * Resets the in-memory WordPress state.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']    = array( Settings::OPTION => array( 'provider' => 'mock' ) );
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_cron']       = array();
		$GLOBALS['wpdb']->rows                      = array();
		$GLOBALS['odds_aggregator_test_filters']    = array();
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed>  $attributes Block attributes.
	 * @param array<string,string> $logos      Administrator logo overrides.
	 * @return string
	 */
	private function render( array $attributes, array $logos = array() ): string {
		$settings = new Settings();
		$markets  = new MarketRegistry();

		$repository = new OddsRepository(
			new ProviderFactory( $settings, new HttpClient( new RateLimiter(), new RetryPolicy() ) ),
			new OddsCache(),
			$markets
		);

		return ( new OddsTableRenderer( $repository, $settings, new BookmakerRegistry( array(), $logos ), $markets ) )
			->render( array( 'eventId' => self::EVENT ) + $attributes );
	}

	/**
	 * Price cells as decimal => displayed text pairs.
	 *
	 * @param string $html Rendered markup.
	 * @return array<int,array{0:float,1:string}>
	 */
	private function cells( string $html ): array {
		preg_match_all( '#data-decimal="([^"]+)">([^<]+)<#', $html, $matches, PREG_SET_ORDER );

		return array_map( static fn( array $cell ): array => array( (float) $cell[1], $cell[2] ), $matches );
	}

	/**
	 * Prices are printed in the block's format, not always as decimals.
	 *
	 * @dataProvider formats
	 *
	 * @param OddsFormat $format Block format.
	 */
	public function test_prices_are_rendered_in_block_format( OddsFormat $format ): void {
		$html  = $this->render( array( 'format' => $format->value ) );
		$cells = $this->cells( $html );

		$this->assertStringContainsString( 'data-format="' . $format->value . '"', $html );
		$this->assertNotEmpty( $cells );

		foreach ( $cells as [ $decimal, $text ] ) {
			$this->assertSame( OddsConverter::format( $decimal, $format ), $text );
		}
	}

	/**
	 * Every display format.
	 *
	 * @return array<string,array{0:OddsFormat}>
	 */
	public static function formats(): array {
		return array(
			'decimal'    => array( OddsFormat::Decimal ),
			'fractional' => array( OddsFormat::Fractional ),
			'american'   => array( OddsFormat::American ),
		);
	}

	/**
	 * The block names the league and the kick-off time.
	 */
	public function test_event_league_and_kickoff_are_shown(): void {
		$html = $this->render( array() );

		$this->assertMatchesRegularExpression(
			'#<div class="oa-head"><span class="oa-head-title">Arsenal v Chelsea</span><span class="oa-head-meta">Premier League &middot; <time datetime="\d{4}-\d\d-\d\dT15:00:00\+00:00">[^<]+</time></span></div>#',
			$html
		);
	}

	/**
	 * The block carries a REST URL that re-renders it with the same attributes.
	 */
	public function test_refresh_url_carries_block_attributes(): void {
		$html = $this->render(
			array(
				'markets'      => array( '1x2' ),
				'bookmakers'   => array( 'bet365', 'coral' ),
				'format'       => 'american',
				'showSwitcher' => false,
			)
		);

		$this->assertSame( 1, preg_match( '#data-refresh="(\d+)" data-refresh-url="([^"]+)"#', $html, $found ) );
		$this->assertSame( '60', $found[1] );

		$url = html_entity_decode( $found[2] );

		$this->assertStringStartsWith( 'https://example.test/wp-json/odds-aggregator/v1/block?', $url );

		foreach ( array( 'eventId=' . self::EVENT, 'markets[0]=1x2', 'bookmakers[1]=coral', 'format=american', 'highlightBest=1', 'showSwitcher=0' ) as $part ) {
			$this->assertStringContainsString( $part, $url );
		}
	}

	/**
	 * Default settings show every bookmaker the sample provider quotes.
	 */
	public function test_default_settings_show_all_quoted_bookmakers(): void {
		$html = $this->render( array( 'markets' => array( '1x2' ) ) );

		$this->assertSame( 10, substr_count( $html, '<th scope="col" class="oa-bk">' ) );
	}

	/**
	 * Outcomes are rows named after the teams, each with its best price and bookmaker.
	 */
	public function test_grid_rows_are_outcomes_with_best_price(): void {
		$html = $this->render( array( 'markets' => array( '1x2' ) ) );

		$this->assertSame( 1, substr_count( $html, '<tr class="oa-group">' ) );
		$this->assertMatchesRegularExpression( '#<tr><th scope="row">Arsenal<span class="oa-best-note">Best <span class="oa-odds" data-decimal="[\d.]+">[\d.]+</span> · [^<]+</span></th>#', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Draw<span class="oa-best-note">', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Chelsea<span class="oa-best-note">', $html );
		$this->assertSame( 10, substr_count( $html, 'class="oa-bet"' ) );
	}

	/**
	 * Several markets get a filter, hidden until the front-end script shows it.
	 */
	public function test_market_filter_lists_rendered_markets(): void {
		$html = $this->render( array() );

		$this->assertStringContainsString( '<select class="oa-market-select" aria-label="Markets" hidden><option value="">All markets</option>', $html );
		$this->assertSame( 4, substr_count( $html, '<option value="' ) );

		foreach ( array( '1x2', 'ou25', 'btts' ) as $key ) {
			$this->assertStringContainsString( '<option value="' . $key . '">', $html );
			$this->assertStringContainsString( '<tbody data-market="' . $key . '">', $html );
		}
	}

	/**
	 * The market select and the format buttons share one bar.
	 */
	public function test_bar_holds_filters_and_format_select(): void {
		$html = $this->render( array( 'format' => 'fractional' ) );

		$this->assertMatchesRegularExpression( '#<div class="oa-bar"><select class="oa-market-select"[^>]*>.*</select><div class="oa-switcher" role="group" aria-label="Odds format">#s', $html );
		$this->assertStringContainsString( 'data-format="fractional" aria-pressed="true"', $html );
	}

	/**
	 * A single market shows a label beside the format buttons, and no bar without a switcher.
	 */
	public function test_single_market_has_no_filter_bar(): void {
		$with_switcher    = $this->render( array( 'markets' => array( '1x2' ) ) );
		$without_switcher = $this->render(
			array(
				'markets'      => array( '1x2' ),
				'showSwitcher' => false,
			)
		);

		$this->assertStringNotContainsString( 'oa-market-select', $with_switcher );
		$this->assertStringContainsString( '<div class="oa-bar"><span class="oa-bar-label">Odds format</span><div class="oa-switcher"', $with_switcher );
		$this->assertStringNotContainsString( 'oa-bar', $without_switcher );
	}

	/**
	 * The lowest price is marked only when asked for.
	 */
	public function test_lowest_price_is_marked_on_request(): void {
		$this->assertStringNotContainsString( 'is-worst', $this->render( array() ) );

		$html = $this->render(
			array(
				'markets'        => array( '1x2' ),
				'highlightWorst' => true,
			)
		);

		$this->assertSame( 3, substr_count( $html, 'is-worst' ) );
		$this->assertSame( 3, substr_count( $html, '(lowest price)' ) );
		$this->assertStringContainsString( 'highlightWorst=1', html_entity_decode( $html ) );

		preg_match_all( '#<tr><th scope="row">([^<]+)<.*?</tr>#s', $html, $rows );

		foreach ( $rows[0] as $row ) {
			if ( ! preg_match( '#class="oa-price is-worst"><span class="oa-odds" data-decimal="([\d.]+)"#', $row, $marked ) ) {
				continue;
			}

			preg_match_all( '#data-decimal="([\d.]+)"#', $row, $prices );

			$this->assertSame( min( array_map( 'floatval', $prices[1] ) ), (float) $marked[1] );
		}
	}

	/**
	 * Equal prices are neither best nor lowest.
	 */
	public function test_equal_prices_are_not_marked_lowest(): void {
		$GLOBALS['odds_aggregator_test_options'][ Settings::OPTION ]['provider'] = 'flat';
		$GLOBALS['odds_aggregator_test_filters']['odds_aggregator_providers']    = static fn( array $builders ): array => $builders + array(
			'flat' => static fn() => new class() extends AbstractProvider {
				/**
				 * Machine id.
				 *
				 * @return string
				 */
				public function id(): string {
					return 'flat';
				}

				/**
				 * Label.
				 *
				 * @return string
				 */
				public function label(): string {
					return 'Flat';
				}

				/**
				 * One event.
				 *
				 * @return Event[]
				 */
				public function fetch_events(): array {
					return array( new Event( 'eng-ars-che', 'football', 'L', 'Arsenal', 'Chelsea', new DateTimeImmutable() ) );
				}

				/**
				 * The same prices from two bookmakers.
				 *
				 * @param Event $event   Event.
				 * @param array $markets Markets.
				 * @return OddsLine[]
				 */
				public function fetch_odds( Event $event, array $markets ): array {
					$prices = array(
						'home' => $this->make_odds( 2.0 ),
						'draw' => $this->make_odds( 3.0 ),
						'away' => $this->make_odds( 4.0 ),
					);

					return array(
						new OddsLine( $event->id(), '1x2', 'bet365', $prices ),
						new OddsLine( $event->id(), '1x2', 'coral', $prices ),
					);
				}
			},
		);

		$html = $this->render(
			array(
				'markets'        => array( '1x2' ),
				'highlightWorst' => true,
			)
		);

		$GLOBALS['odds_aggregator_test_filters'] = array();

		$this->assertStringContainsString( 'oa-price', $html );
		$this->assertStringNotContainsString( 'is-worst', $html );
	}

	/**
	 * Without highlighting there is no best price cell or note.
	 */
	public function test_no_highlight_means_no_best_marks(): void {
		$html = $this->render( array( 'highlightBest' => false ) );

		$this->assertStringNotContainsString( 'is-best', $html );
		$this->assertStringNotContainsString( 'oa-best-note', $html );
	}

	/**
	 * A block narrowed to one market renders one table from the site-wide cache entry.
	 */
	public function test_block_markets_share_site_wide_cache_entry(): void {
		$html = $this->render( array( 'markets' => array( '1x2' ) ) );

		$this->assertSame( 1, substr_count( $html, '<table' ) );

		$site_markets = ( new Settings() )->markets();
		sort( $site_markets );

		$this->assertNotFalse(
			get_transient( 'oa_1_' . OddsCache::key( 'odds', 'mock', self::EVENT, implode( ',', $site_markets ) ) )
		);
	}

	/**
	 * A bundled icon is used when one ships with the plugin.
	 */
	public function test_bundled_logo_is_used(): void {
		$this->assertStringContainsString( 'assets/logos/williamhill.png', $this->render( array() ) );
	}

	/**
	 * An administrator's logo overrides the bundled one.
	 */
	public function test_admin_logo_overrides_the_bundled_one(): void {
		$html = $this->render( array(), array( 'bet365' => 'https://example.test/bet365.svg' ) );

		$this->assertStringContainsString( '<img class="oa-book__logo" src="https://example.test/bet365.svg"', $html );
		$this->assertStringNotContainsString( 'assets/logos/bet365.png', $html );
	}

	/**
	 * A monogram stands in for bookmakers no icon ships for.
	 */
	public function test_monogram_stands_in_without_a_logo(): void {
		$this->assertMatchesRegularExpression(
			'#<span class="oa-book__monogram"[^>]*>B</span>#',
			$this->render( array( 'bookmakers' => array( 'betway', 'bet365' ) ) )
		);
	}

	/**
	 * A table whose bookmakers quote nothing says so instead of rendering empty.
	 */
	public function test_unquoted_bookmakers_give_a_notice_not_an_empty_table(): void {
		// The sample data quotes UK books only, so US books have no prices.
		$html = $this->render( array( 'bookmakers' => array( 'fanduel', 'draftkings' ) ) );

		$this->assertStringContainsString( 'No odds to show for this table.', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}
}
