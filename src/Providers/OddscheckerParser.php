<?php
/**
 * Oddschecker page parser.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

/**
 * Extracts matches and prices from an Oddschecker listing page.
 *
 * Oddschecker renders its pages server-side and embeds the data it rendered
 * from as JSON inside the HTML:
 *
 * - `<script id="cx-island-data" type="application/json">` holds the
 *   dehydrated React Query state. Matches live in the query whose key starts
 *   with "matches-coupon", under `state.data.subevents[]`.
 * - `<script id="cx-bookmakers-store" type="application/json">` maps
 *   bookmaker codes (e.g. "U1") to brands (e.g. "FanDuel").
 *
 * Reading that JSON is far more stable than walking table markup: CSS classes
 * and layout change with every redesign, the data contract much less often.
 *
 * The parser is pure - no WordPress calls, no network - so it is unit-tested
 * against a saved page in tests/fixtures.
 */
final class OddscheckerParser {

	/**
	 * Id of the script tag carrying the page data.
	 *
	 * @var string
	 */
	public const ISLAND_ID = 'cx-island-data';

	/**
	 * Id of the script tag carrying the bookmaker directory.
	 *
	 * @var string
	 */
	public const BOOKMAKERS_ID = 'cx-bookmakers-store';

	/**
	 * First element of the query key that holds match odds.
	 *
	 * @var string
	 */
	public const COUPON_QUERY = 'matches-coupon';

	/**
	 * Matches listed on the page, with every active price.
	 *
	 * Shape of each match:
	 * - id, league, home, away, start (ISO 8601 UTC), in_running
	 * - markets: betTypeId => outcome (HOME|DRAW|AWAY|...) => bookmaker code => decimal
	 *
	 * @param string $html Page HTML.
	 * @return array<int,array<string,mixed>>
	 * @throws ProviderException When the page carries no odds data at all.
	 */
	public function matches( string $html ): array {
		$islands = $this->json_script( $html, self::ISLAND_ID );

		if ( null === $islands ) {
			throw new ProviderException(
				'Oddschecker page has no embedded odds data. The request was probably served a '
				. 'Cloudflare challenge, or the page layout has changed.'
			);
		}

		$matches = array();

		foreach ( $islands as $island ) {
			if ( ! is_array( $island ) ) {
				continue;
			}

			foreach ( (array) ( $island['queries'] ?? array() ) as $query ) {
				if ( ! is_array( $query ) || self::COUPON_QUERY !== ( $query['queryKey'][0] ?? null ) ) {
					continue;
				}

				foreach ( (array) ( $query['state']['data']['subevents'] ?? array() ) as $subevent ) {
					$match = $this->normalise_match( $subevent );

					// Keyed by id: the same match can appear in more than one coupon.
					if ( null !== $match ) {
						$matches[ $match['id'] ] = $match;
					}
				}
			}
		}

		return array_values( $matches );
	}

	/**
	 * Bookmaker directory: code => brand name.
	 *
	 * @param string $html Page HTML.
	 * @return array<string,string>
	 */
	public function bookmakers( string $html ): array {
		$regions = $this->json_script( $html, self::BOOKMAKERS_ID ) ?? array();
		$out     = array();

		foreach ( $regions as $bookmakers ) {
			foreach ( (array) $bookmakers as $code => $bookmaker ) {
				if ( is_array( $bookmaker ) && isset( $bookmaker['brand'] ) ) {
					$out[ (string) $code ] = (string) $bookmaker['brand'];
				}
			}
		}

		return $out;
	}

	/**
	 * Reduces one upstream subevent to the fields the plugin uses.
	 *
	 * @param mixed $subevent Raw subevent.
	 * @return array<string,mixed>|null Null when it is not a usable match.
	 */
	private function normalise_match( mixed $subevent ): ?array {
		if ( ! is_array( $subevent ) || 'MATCH' !== ( $subevent['type'] ?? 'MATCH' ) ) {
			return null;
		}

		$id    = (string) ( $subevent['id'] ?? '' );
		$home  = trim( (string) ( $subevent['homeTeam']['name'] ?? '' ) );
		$away  = trim( (string) ( $subevent['awayTeam']['name'] ?? '' ) );
		$start = (string) ( $subevent['startTime'] ?? '' );

		if ( '' === $id || '' === $home || '' === $away || '' === $start ) {
			return null;
		}

		return array(
			'id'         => $id,
			'league'     => $this->league( (string) ( $subevent['eventName'] ?? '' ) ),
			'home'       => $home,
			'away'       => $away,
			'start'      => $start,
			'in_running' => (bool) ( $subevent['inRunning'] ?? false ),
			'markets'    => $this->prices( (array) ( $subevent['markets'] ?? array() ) ),
		);
	}

	/**
	 * Active prices, indexed betTypeId => outcome => bookmaker code => decimal.
	 *
	 * Suspended prices are dropped here, so nothing downstream can show a price
	 * the bookmaker is not currently offering.
	 *
	 * @param array<int,mixed> $markets Raw markets of one subevent.
	 * @return array<int,array<string,array<string,float>>>
	 */
	private function prices( array $markets ): array {
		$out = array();

		foreach ( $markets as $market ) {
			if ( ! is_array( $market ) ) {
				continue;
			}

			$bet_type = (int) ( $market['betTypeId'] ?? 0 );

			foreach ( (array) ( $market['bets'] ?? array() ) as $bet ) {
				if ( ! is_array( $bet ) ) {
					continue;
				}

				// Two-way markets such as totals have no genericName; fall back to the name.
				$outcome = strtoupper( trim( (string) ( $bet['genericName'] ?? $bet['name'] ?? '' ) ) );

				if ( '' === $outcome ) {
					continue;
				}

				foreach ( (array) ( $bet['odds'] ?? array() ) as $odd ) {
					if ( ! is_array( $odd ) || 'ACTIVE' !== ( $odd['status'] ?? '' ) ) {
						continue;
					}

					$code    = (string) ( $odd['bookmakerCode'] ?? '' );
					$decimal = $odd['decimal'] ?? null;

					if ( '' === $code || ! is_numeric( $decimal ) ) {
						continue;
					}

					$out[ $bet_type ][ $outcome ][ $code ] = (float) $decimal;
				}
			}
		}

		return $out;
	}

	/**
	 * "English Premier League Matches" -> "English Premier League".
	 *
	 * @param string $event_name Upstream event name.
	 * @return string
	 */
	private function league( string $event_name ): string {
		return trim( (string) preg_replace( '/\s+matches$/i', '', trim( $event_name ) ) );
	}

	/**
	 * Decodes the JSON body of a `<script id="...">` tag.
	 *
	 * Uses string search rather than a DOM parse: the page is several hundred
	 * kilobytes, only one tag is needed, and a DOM parse would also have to
	 * guess the document encoding.
	 *
	 * @param string $html Page HTML.
	 * @param string $id   Script tag id.
	 * @return array<mixed>|null Null when the tag is missing or not valid JSON.
	 */
	private function json_script( string $html, string $id ): ?array {
		$pattern = '#<script\b[^>]*\bid=["\']' . preg_quote( $id, '#' ) . '["\'][^>]*>#i';

		if ( 1 !== preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$start = $match[0][1] + strlen( $match[0][0] );
		$end   = stripos( $html, '</script>', $start );

		if ( false === $end ) {
			return null;
		}

		$data = json_decode( substr( $html, $start, $end - $start ), true );

		return is_array( $data ) ? $data : null;
	}
}
