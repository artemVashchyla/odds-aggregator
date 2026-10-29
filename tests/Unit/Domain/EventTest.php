<?php
/**
 * Tests for the event value object.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use OddsAggregator\Domain\Event;
use PHPUnit\Framework\TestCase;

/**
 * Covers the array form and, mainly, fixture matching across providers.
 *
 * Matching teams loosely is what lets a block saved against one data source keep
 * working after the source is switched, so it carries most of the risk in this
 * class: both a missed match (the table empties) and a false match (the table
 * shows the wrong game) are visible failures.
 */
final class EventTest extends TestCase {

	/**
	 * Builds an event at a fixed kick-off.
	 *
	 * @param string $home   Home team.
	 * @param string $away   Away team.
	 * @param string $moment Kick-off, parseable as UTC.
	 * @return Event
	 */
	private function event( string $home, string $away, string $moment = '2026-05-01 14:00:00' ): Event {
		return new Event(
			strtolower( $home . '-' . $away ),
			'football',
			'Premier League',
			$home,
			$away,
			new DateTimeImmutable( $moment, new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * The accessors report what was passed in.
	 */
	public function test_accessors_report_the_construction_values(): void {
		$event = $this->event( 'Chelsea', 'Arsenal' );

		$this->assertSame( 'chelsea-arsenal', $event->id() );
		$this->assertSame( 'football', $event->sport() );
		$this->assertSame( 'Premier League', $event->league() );
		$this->assertSame( 'Chelsea', $event->home() );
		$this->assertSame( 'Arsenal', $event->away() );
		$this->assertSame( '2026-05-01 14:00', $event->kickoff()->format( 'Y-m-d H:i' ) );
	}

	/**
	 * The caption reads home first.
	 */
	public function test_title_reads_home_versus_away(): void {
		$this->assertSame( 'Chelsea v Arsenal', $this->event( 'Chelsea', 'Arsenal' )->title() );
	}

	/**
	 * The array form carries the title and an ISO 8601 kick-off, which is what
	 * the editor and the REST clients read.
	 */
	public function test_array_form_is_complete(): void {
		$array = $this->event( 'Chelsea', 'Arsenal' )->to_array();

		$this->assertSame(
			array( 'id', 'sport', 'league', 'home', 'away', 'kickoff', 'title' ),
			array_keys( $array )
		);
		$this->assertSame( 'Chelsea v Arsenal', $array['title'] );
		$this->assertSame( '2026-05-01T14:00:00+00:00', $array['kickoff'] );
	}

	/**
	 * An event is the same fixture as itself.
	 */
	public function test_an_event_matches_itself(): void {
		$event = $this->event( 'Chelsea', 'Arsenal' );

		$this->assertTrue( $event->is_same_fixture( $event ) );
	}

	/**
	 * Team names that differ only in punctuation, club suffixes or a known
	 * abbreviation are the same team.
	 *
	 * @param string $first  Name as one provider writes it.
	 * @param string $second Name as another provider writes it.
	 * @dataProvider provide_equivalent_names
	 */
	public function test_equivalent_team_names_match( string $first, string $second ): void {
		$this->assertTrue(
			$this->event( $first, 'Arsenal' )->is_same_fixture( $this->event( $second, 'Arsenal' ) ),
			sprintf( '"%s" and "%s" should be the same team.', $first, $second )
		);
	}

	/**
	 * Name pairs the providers actually produce for one club.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_equivalent_names(): array {
		return array(
			'club suffix'          => array( 'Chelsea FC', 'Chelsea' ),
			'other club suffix'    => array( 'AFC Bournemouth', 'Bournemouth' ),
			'punctuation'          => array( 'Nott\'m Forest', 'Nottingham Forest' ),
			'abbreviated united'   => array( 'Man Utd', 'Manchester United' ),
			'spelled out united'   => array( 'Man United', 'Manchester United' ),
			'abbreviated city'     => array( 'Man City', 'Manchester City' ),
			'nickname'             => array( 'Spurs', 'Tottenham' ),
			'short nickname'       => array( 'Wolves', 'Wolverhampton' ),
			'ampersand'            => array( 'Brighton & Hove Albion', 'Brighton' ),
			'name plus suffix'     => array( 'Leeds', 'Leeds United' ),
			'case and whitespace'  => array( 'west ham united', 'West Ham United' ),
			'identical both sides' => array( 'Everton', 'Everton' ),
		);
	}

	/**
	 * Two different clubs are never the same fixture, however similar the names.
	 *
	 * @param string $first  One club.
	 * @param string $second Another club.
	 * @dataProvider provide_distinct_names
	 */
	public function test_distinct_teams_do_not_match( string $first, string $second ): void {
		$this->assertFalse(
			$this->event( $first, 'Arsenal' )->is_same_fixture( $this->event( $second, 'Arsenal' ) ),
			sprintf( '"%s" and "%s" are different clubs.', $first, $second )
		);
	}

	/**
	 * Name pairs that must stay apart, including the city derbies that make a
	 * prefix match on its own unsafe.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_distinct_names(): array {
		return array(
			'different clubs'   => array( 'Chelsea', 'Arsenal' ),
			'same city'         => array( 'Manchester United', 'Manchester City' ),
			'same first word'   => array( 'West Ham United', 'West Bromwich Albion' ),
			'one name is empty' => array( '', 'Arsenal' ),
			'both empty'        => array( '', '' ),
		);
	}

	/**
	 * A fixture is directional: the same two clubs the other way round is the
	 * reverse leg, not the same match.
	 */
	public function test_swapped_teams_do_not_match(): void {
		$this->assertFalse(
			$this->event( 'Chelsea', 'Arsenal' )->is_same_fixture( $this->event( 'Arsenal', 'Chelsea' ) )
		);
	}

	/**
	 * A kick-off inside the window still matches, because providers disagree by
	 * hours when a match is moved.
	 */
	public function test_a_nearby_kickoff_still_matches(): void {
		$this->assertTrue(
			$this->event( 'Chelsea', 'Arsenal', '2026-05-01 14:00:00' )
				->is_same_fixture( $this->event( 'Chelsea FC', 'Arsenal', '2026-05-02 12:30:00' ) )
		);
	}

	/**
	 * Beyond the window it is a different meeting of the same two clubs.
	 */
	public function test_a_distant_kickoff_does_not_match(): void {
		$this->assertFalse(
			$this->event( 'Chelsea', 'Arsenal', '2026-05-01 14:00:00' )
				->is_same_fixture( $this->event( 'Chelsea', 'Arsenal', '2026-09-20 14:00:00' ) )
		);
	}

	/**
	 * The window is configurable, and narrowing it rejects a match the default
	 * would accept.
	 */
	public function test_the_window_can_be_narrowed(): void {
		$first  = $this->event( 'Chelsea', 'Arsenal', '2026-05-01 14:00:00' );
		$second = $this->event( 'Chelsea', 'Arsenal', '2026-05-01 18:00:00' );

		$this->assertTrue( $first->is_same_fixture( $second ) );
		$this->assertFalse( $first->is_same_fixture( $second, 3600 ) );
	}

	/**
	 * Matching does not depend on the ids, which is the whole point: providers
	 * give the same fixture different ones.
	 */
	public function test_matching_ignores_the_ids(): void {
		$first  = new Event(
			'oddschecker-1',
			'football',
			'Premier League',
			'Man Utd',
			'Spurs',
			new DateTimeImmutable( '2026-05-01 14:00:00', new DateTimeZone( 'UTC' ) )
		);
		$second = new Event(
			'0a1b2c3d4e5f',
			'football',
			'EPL',
			'Manchester United',
			'Tottenham Hotspur',
			new DateTimeImmutable( '2026-05-01 14:00:00', new DateTimeZone( 'UTC' ) )
		);

		$this->assertTrue( $first->is_same_fixture( $second ) );
		$this->assertTrue( $second->is_same_fixture( $first ) );
	}
}
