<?php
/**
 * End-to-end test: Oddschecker page to rendered odds table.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Providers;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Blocks\OddsTableRenderer;
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
 * Runs the whole read path with Oddschecker selected: the HTTP response is the
 * saved page, and the assertions are made on the block markup.
 */
final class OddscheckerEndToEndTest extends TestCase {

	/**
	 * Upstream id of Chelsea v Man Utd in the fixture.
	 *
	 * @var string
	 */
	private const EVENT = '102012695';

	/**
	 * Selects Oddschecker and queues the saved page as the only HTTP response.
	 */
	protected function setUp(): void {
		$GLOBALS['odds_aggregator_test_options']    = array( Settings::OPTION => array( 'provider' => 'oddschecker' ) );
		$GLOBALS['odds_aggregator_test_transients'] = array();
		$GLOBALS['odds_aggregator_test_cron']       = array();
		$GLOBALS['wpdb']->rows                      = array();
		$GLOBALS['odds_aggregator_test_filters']    = array();
		$GLOBALS['odds_aggregator_test_http']       = array(
			'responses' => array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) file_get_contents( ODDS_AGGREGATOR_FIXTURES . 'oddschecker-premier-league.html' ),
				),
			),
			'requests'  => array(),
		);
	}

	/**
	 * Builds the repository the block reads through.
	 *
	 * @param Settings       $settings Settings.
	 * @param MarketRegistry $markets  Market catalogue.
	 * @return OddsRepository
	 */
	private function repository( Settings $settings, MarketRegistry $markets ): OddsRepository {
		return new OddsRepository(
			new ProviderFactory( $settings, new HttpClient( new RateLimiter(), new RetryPolicy( 1 ) ) ),
			new OddsCache(),
			$markets
		);
	}

	/**
	 * Events listed by the block editor come from the scraped page.
	 */
	public function test_events_come_from_the_page(): void {
		$events = $this->repository( new Settings(), new MarketRegistry() )->events();

		$this->assertCount( 2, $events );
		$this->assertSame( self::EVENT, $events[0]->id() );
		$this->assertSame( 'Chelsea FC v Manchester United', $events[0]->title() );
	}

	/**
	 * The block renders scraped prices, and the page is fetched only once.
	 */
	public function test_block_renders_scraped_prices_from_one_request(): void {
		$settings = new Settings();
		$markets  = new MarketRegistry();
		$renderer = new OddsTableRenderer( $this->repository( $settings, $markets ), $settings, new BookmakerRegistry(), $markets );

		$html = $renderer->render(
			array(
				'eventId' => self::EVENT,
				'markets' => array( '1x2' ),
			)
		);

		$this->assertStringContainsString( 'Chelsea FC v Manchester United', $html );
		$this->assertStringContainsString( 'FanDuel', $html );
		$this->assertStringContainsString( 'DraftKings', $html );
		$this->assertStringContainsString( 'data-decimal="1.833"', $html );
		$this->assertStringContainsString( 'data-decimal="4.1"', $html );
		$this->assertCount( 1, $GLOBALS['odds_aggregator_test_http']['requests'] );
		$this->assertStringContainsString( 'oddschecker.com', $GLOBALS['odds_aggregator_test_http']['requests'][0] );
	}
}
