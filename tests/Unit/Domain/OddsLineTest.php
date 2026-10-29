<?php
/**
 * Tests for a bookmaker's row on one market.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Domain;

use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use PHPUnit\Framework\TestCase;

/**
 * Covers the accessors, the freshness roll-up and the cache round trip.
 *
 * Only primitives go into the cache, so the array form is the real storage
 * format: anything it cannot carry is lost on the next read.
 */
final class OddsLineTest extends TestCase {

	/**
	 * A complete 1X2 row.
	 *
	 * @return OddsLine
	 */
	private function line(): OddsLine {
		return new OddsLine(
			'chelsea-arsenal',
			'1x2',
			'bet365',
			array(
				'home' => new Odds( 2.1, 1_700_000_100, 'oddschecker' ),
				'draw' => new Odds( 3.4, 1_700_000_200, 'oddschecker' ),
				'away' => new Odds( 3.6, 1_700_000_050, 'oddschecker' ),
			)
		);
	}

	/**
	 * The accessors report what was passed in.
	 */
	public function test_accessors_report_the_construction_values(): void {
		$line = $this->line();

		$this->assertSame( 'chelsea-arsenal', $line->event_id() );
		$this->assertSame( '1x2', $line->market_key() );
		$this->assertSame( 'bet365', $line->bookmaker_id() );
		$this->assertSame( array( 'home', 'draw', 'away' ), array_keys( $line->prices() ) );
	}

	/**
	 * A quoted outcome returns its price.
	 */
	public function test_a_quoted_outcome_returns_its_price(): void {
		$this->assertSame( 2.1, $this->line()->price( 'home' )?->decimal() );
	}

	/**
	 * An outcome the bookmaker does not quote is null, not an error: the table
	 * renders an empty cell for it.
	 */
	public function test_an_unquoted_outcome_is_null(): void {
		$this->assertNull( $this->line()->price( 'over' ) );
	}

	/**
	 * A partial row is legitimate; a bookmaker may price some outcomes only.
	 */
	public function test_a_partial_row_is_allowed(): void {
		$line = new OddsLine( 'e', 'ou25', 'coral', array( 'over' => new Odds( 1.9, 0 ) ) );

		$this->assertSame( 1.9, $line->price( 'over' )?->decimal() );
		$this->assertNull( $line->price( 'under' ) );
	}

	/**
	 * The row is as fresh as its newest price, which is what the front end shows
	 * as the "updated" time.
	 */
	public function test_freshness_is_the_newest_price(): void {
		$this->assertSame( 1_700_000_200, $this->line()->fetched_at() );
	}

	/**
	 * An empty row has no timestamp rather than a misleading one.
	 */
	public function test_an_empty_row_has_no_timestamp(): void {
		$this->assertSame( 0, ( new OddsLine( 'e', '1x2', 'coral', array() ) )->fetched_at() );
	}

	/**
	 * The array form uses the keys the REST clients and the cache read.
	 */
	public function test_the_array_form_keys_are_stable(): void {
		$array = $this->line()->to_array();

		$this->assertSame( array( 'eventId', 'marketKey', 'bookmaker', 'prices', 'fetchedAt' ), array_keys( $array ) );
		$this->assertSame( array( 'home', 'draw', 'away' ), array_keys( $array['prices'] ) );
		$this->assertSame( 1_700_000_200, $array['fetchedAt'] );
	}

	/**
	 * A row survives the trip through the cache's primitive form.
	 */
	public function test_the_array_form_round_trips(): void {
		$original = $this->line();
		$restored = OddsLine::from_array( $original->to_array() );

		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	/**
	 * A stored row missing every field reads back empty rather than failing.
	 */
	public function test_an_empty_stored_row_reads_back_empty(): void {
		$line = OddsLine::from_array( array() );

		$this->assertSame( '', $line->event_id() );
		$this->assertSame( '', $line->market_key() );
		$this->assertSame( '', $line->bookmaker_id() );
		$this->assertSame( array(), $line->prices() );
		$this->assertSame( 0, $line->fetched_at() );
	}

	/**
	 * One unreadable price is dropped and the rest of the row survives, so a
	 * single bad cell cannot empty a table.
	 */
	public function test_an_unreadable_price_is_dropped_from_the_row(): void {
		$line = OddsLine::from_array(
			array(
				'eventId'   => 'chelsea-arsenal',
				'marketKey' => '1x2',
				'bookmaker' => 'bet365',
				'prices'    => array(
					'home' => array( 'decimal' => 2.1 ),
					'draw' => array( 'decimal' => 0 ),
					'away' => 'not even an array',
				),
			)
		);

		$this->assertSame( array( 'home' ), array_keys( $line->prices() ) );
		$this->assertSame( 2.1, $line->price( 'home' )?->decimal() );
	}

	/**
	 * Prices that are not a list at all are ignored.
	 */
	public function test_a_foreign_prices_value_is_ignored(): void {
		$line = OddsLine::from_array(
			array(
				'eventId' => 'chelsea-arsenal',
				'prices'  => 'corrupt',
			)
		);

		$this->assertSame( array(), $line->prices() );
	}
}
