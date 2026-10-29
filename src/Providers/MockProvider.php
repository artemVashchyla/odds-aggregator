<?php
/**
 * Offline fixture provider.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use DateTimeImmutable;
use DateTimeZone;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\OddsLine;

/**
 * Deterministic sample data, and the default provider.
 *
 * Shipping this as the default means the plugin works the moment it is
 * activated - no API key, no network, nothing to configure - which is what
 * makes the block and the admin screens reviewable. Prices are derived from a
 * hash of the event, market, bookmaker and outcome, so they vary realistically
 * between bookmakers yet stay identical across requests, which in turn makes
 * the "best price" highlight stable and the output easy to eyeball.
 */
final class MockProvider extends AbstractProvider {

	/**
	 * Fixture matches: id => [league, home, away, days from today].
	 *
	 * @var array<string,array{0:string,1:string,2:string,3:int}>
	 */
	private const FIXTURES = array(
		'eng-ars-che' => array( 'Premier League', 'Arsenal', 'Chelsea', 2 ),
		'eng-liv-mci' => array( 'Premier League', 'Liverpool', 'Manchester City', 3 ),
		'eng-mun-tot' => array( 'Premier League', 'Manchester United', 'Tottenham', 4 ),
		'esp-bar-rma' => array( 'La Liga', 'Barcelona', 'Real Madrid', 5 ),
		'ita-int-juv' => array( 'Serie A', 'Inter', 'Juventus', 6 ),
		'ger-bay-bvb' => array( 'Bundesliga', 'Bayern Munich', 'Borussia Dortmund', 7 ),
	);

	/**
	 * Baseline price per market outcome, before per-bookmaker variation.
	 *
	 * @var array<string,array<string,float>>
	 */
	private const BASE_PRICES = array(
		'1x2'  => array(
			'home' => 2.10,
			'draw' => 3.40,
			'away' => 3.60,
		),
		'ou25' => array(
			'over'  => 1.90,
			'under' => 1.95,
		),
		'btts' => array(
			'yes' => 1.80,
			'no'  => 2.00,
		),
	);

	/**
	 * Bookmakers quoted by the fixture data.
	 *
	 * @var string[]
	 */
	private const BOOKMAKERS = array(
		'bet365',
		'williamhill',
		'paddypower',
		'betfair',
		'ladbrokes',
		'coral',
		'skybet',
		'unibet',
		'betvictor',
		'betway',
	);

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'mock';
	}

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Sample data (offline)', 'odds-aggregator' );
	}

	/**
	 * Bookmakers in the fixtures.
	 *
	 * @return string[]
	 */
	public function supported_bookmakers(): array {
		return self::BOOKMAKERS;
	}

	/**
	 * Sample data can price any event, so a block chosen under a live source
	 * keeps rendering - with sample prices - after switching to sample data.
	 *
	 * @param Event $event Event as recorded under its original source.
	 * @return Event
	 */
	public function adopt_event( Event $event ): Event {
		return $event;
	}

	/**
	 * Fixture events.
	 *
	 * @return Event[]
	 */
	public function fetch_events(): array {
		$today  = new DateTimeImmutable( 'today 15:00', new DateTimeZone( 'UTC' ) );
		$events = array();

		foreach ( self::FIXTURES as $id => $fixture ) {
			list( $league, $home, $away, $offset ) = $fixture;

			$events[] = new Event(
				$id,
				'football',
				$league,
				$home,
				$away,
				$today->modify( sprintf( '+%d days', $offset ) )
			);
		}

		return $events;
	}

	/**
	 * Fixture prices for one event.
	 *
	 * @param Event    $event   Event to price.
	 * @param Market[] $markets Markets to include.
	 * @return OddsLine[]
	 */
	public function fetch_odds( Event $event, array $markets ): array {
		$lines = array();

		foreach ( $markets as $market ) {
			$base = self::BASE_PRICES[ $market->key() ] ?? array();

			if ( array() === $base ) {
				continue;
			}

			foreach ( self::BOOKMAKERS as $bookmaker_id ) {
				$prices = array();

				foreach ( $market->outcomes() as $outcome ) {
					$baseline = $base[ $outcome->key() ] ?? null;

					if ( null === $baseline ) {
						continue;
					}

					$odds = $this->make_odds(
						$this->vary( $baseline, $event->id(), $market->key(), $bookmaker_id, $outcome->key() )
					);

					if ( null !== $odds ) {
						$prices[ $outcome->key() ] = $odds;
					}
				}

				if ( array() !== $prices ) {
					$lines[] = new OddsLine( $event->id(), $market->key(), $bookmaker_id, $prices );
				}
			}
		}

		return $lines;
	}

	/**
	 * Applies a stable pseudo-random spread around a baseline price.
	 *
	 * @param float  $baseline     Baseline decimal price.
	 * @param string ...$key_parts Parts identifying this exact cell.
	 * @return float
	 */
	private function vary( float $baseline, string ...$key_parts ): float {
		$seed = crc32( implode( '|', $key_parts ) );

		// Spread of roughly -6% to +7%, which is a realistic bookmaker range.
		$factor = 0.94 + ( ( $seed % 131 ) / 1000 );

		return max( 1.01, round( $baseline * $factor, 2 ) );
	}
}
