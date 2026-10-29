<?php
/**
 * Oddschecker scraper.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Http\HttpClient;

/**
 * Reads live prices from an Oddschecker listing page.
 *
 * One GET of the league page returns every upcoming match with the match
 * result prices of every bookmaker, embedded as JSON (see
 * {@see OddscheckerParser}). Events and odds therefore come from the same
 * page: it is fetched once, kept in a transient for {@see self::PAGE_TTL}
 * seconds, and every event on it is priced from that copy instead of one
 * request per event.
 *
 * Coverage: the listing page carries the Match Result (1X2) market only.
 * Over/Under and BTTS are not on it, so this provider returns no rows for
 * them and the block simply leaves those tables out.
 *
 * Caveats, documented in DOCS.md:
 *
 * - Oddschecker is behind Cloudflare. The page is requested with ordinary
 *   browser headers; if a challenge is served instead, the parser finds no
 *   data and throws, and the repository falls back to cached odds.
 * - Scraping is contrary to Oddschecker's terms of service. Production use
 *   needs their permission or a licensed feed ({@see OddsApiProvider}).
 */
final class OddscheckerProvider extends AbstractScraperProvider {

	/**
	 * League page scraped by default.
	 *
	 * @var string
	 */
	private const LISTING_URL = 'https://www.oddschecker.com/us/soccer/premier-league';

	/**
	 * Seconds a fetched page is reused across requests.
	 *
	 * @var int
	 */
	private const PAGE_TTL = 60;

	/**
	 * Status Oddschecker's Cloudflare front returns to blocked clients.
	 *
	 * @var int
	 */
	private const HTTP_FORBIDDEN = 403;

	/**
	 * Internal market key => upstream betTypeId and outcome mapping.
	 *
	 * @var array<string,array{bet_type:int,outcomes:array<string,string>}>
	 */
	private const MARKETS = array(
		'1x2' => array(
			'bet_type' => 1,
			'outcomes' => array(
				'HOME' => 'home',
				'DRAW' => 'draw',
				'AWAY' => 'away',
			),
		),
	);

	/**
	 * Oddschecker bookmaker code => internal bookmaker id.
	 *
	 * Codes not listed here (e.g. prediction markets) are ignored.
	 *
	 * @var array<string,string>
	 */
	private const BOOKMAKER_CODES = array(
		'J3'  => 'bet365',
		'U1'  => 'fanduel',
		'U6'  => 'draftkings',
		'UG'  => 'betmgm',
		'E6'  => 'caesars',
		'FNP' => 'fanatics',
		'FNJ' => 'fanatics',
		'UR'  => 'borgata',
		'U2'  => 'betrivers',
	);

	/**
	 * Constructor.
	 *
	 * @param HttpClient        $http   Transport.
	 * @param OddscheckerParser $parser Page parser.
	 */
	public function __construct(
		HttpClient $http,
		private readonly OddscheckerParser $parser = new OddscheckerParser()
	) {
		parent::__construct( $http );
	}

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'oddschecker';
	}

	/**
	 * Markets on the listing page.
	 *
	 * @return string[]
	 */
	public function supported_markets(): array {
		return array_keys( self::MARKETS );
	}

	/**
	 * Bookmakers in the code map.
	 *
	 * @return string[]
	 */
	public function supported_bookmakers(): array {
		return array_values( array_unique( $this->bookmaker_codes() ) );
	}

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Oddschecker (live scraper)', 'odds-aggregator' );
	}

	/**
	 * Upcoming matches on the league page.
	 *
	 * Matches already in play are skipped: their prices move by the second
	 * and would be stale by the time they were cached.
	 *
	 * @return Event[]
	 * @throws ProviderException When the page cannot be fetched or carries no matches.
	 */
	public function fetch_events(): array {
		$events = array();

		foreach ( $this->matches() as $match ) {
			if ( $match['in_running'] ) {
				continue;
			}

			$kickoff = $this->parse_time( (string) $match['start'] );

			if ( null === $kickoff ) {
				continue;
			}

			$events[] = new Event(
				(string) $match['id'],
				'football',
				(string) $match['league'],
				(string) $match['home'],
				(string) $match['away'],
				$kickoff
			);
		}

		if ( array() === $events ) {
			throw new ProviderException( 'Oddschecker page was read, but it lists no upcoming matches.' );
		}

		return $events;
	}

	/**
	 * Prices for one match, read from the same listing page.
	 *
	 * @param Event    $event   Event to price.
	 * @param Market[] $markets Markets to include.
	 * @return OddsLine[]
	 * @throws ProviderException When the page cannot be fetched.
	 */
	public function fetch_odds( Event $event, array $markets ): array {
		$match = $this->find_match( $event->id() );

		// The match has started or dropped off the page: nothing to price.
		if ( null === $match ) {
			return array();
		}

		$lines = array();

		foreach ( $markets as $market ) {
			$mapping = self::MARKETS[ $market->key() ] ?? null;

			if ( null === $mapping ) {
				continue;
			}

			$quotes = (array) ( $match['markets'][ $mapping['bet_type'] ] ?? array() );

			/**
			 * Prices accumulated per bookmaker before becoming OddsLine rows.
			 *
			 * @var array<string,array<string,Odds>> $by_bookmaker
			 */
			$by_bookmaker = array();

			foreach ( $mapping['outcomes'] as $upstream => $outcome_key ) {
				foreach ( (array) ( $quotes[ $upstream ] ?? array() ) as $code => $decimal ) {
					$bookmaker_id = $this->bookmaker_codes()[ (string) $code ] ?? null;
					$odds         = $this->make_odds( $decimal );

					if ( null === $bookmaker_id || null === $odds ) {
						continue;
					}

					// Two codes can map to one brand (e.g. Fanatics); keep the better price.
					$current = $by_bookmaker[ $bookmaker_id ][ $outcome_key ] ?? null;

					if ( null === $current || $odds->beats( $current ) ) {
						$by_bookmaker[ $bookmaker_id ][ $outcome_key ] = $odds;
					}
				}
			}

			foreach ( $by_bookmaker as $bookmaker_id => $prices ) {
				$lines[] = new OddsLine( $event->id(), $market->key(), (string) $bookmaker_id, $prices );
			}
		}

		return $lines;
	}

	/**
	 * Matches on the listing page, fetched at most once per {@see self::PAGE_TTL}.
	 *
	 * The parsed page lives in a transient only. The factory builds a new
	 * provider for every call, so the events lookup and every odds lookup in
	 * the next minute share one fetch; there is deliberately no static copy,
	 * which would outlive a cache flush and leak between unit tests.
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws ProviderException When the page cannot be fetched or parsed.
	 */
	private function matches(): array {
		$url       = $this->listing_url();
		$cache_key = 'odds_aggregator_oc_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$html = $this->fetch_html( $url, $this->request_headers() );
		} catch ( ProviderException $exception ) {
			// 403 is Cloudflare refusing a non-browser client. Say that plainly
			// instead of surfacing a bare status code.
			if ( self::HTTP_FORBIDDEN === $exception->getCode() ) {
				throw new ProviderException(
					esc_html__( 'Oddschecker refused the request (HTTP 403): the site blocks server-side requests with Cloudflare bot protection, so live odds cannot be read from this server. Use The Odds API or Sample data instead.', 'odds-aggregator' ),
					self::HTTP_FORBIDDEN, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an HTTP status code, not output.
					$exception // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- previous exception, not output.
				);
			}

			throw $exception;
		}

		$matches = $this->parser->matches( $html );

		set_transient( $cache_key, $matches, self::PAGE_TTL );

		return $matches;
	}

	/**
	 * One match by upstream id.
	 *
	 * @param string $id Upstream subevent id.
	 * @return array<string,mixed>|null
	 * @throws ProviderException When the page cannot be fetched or parsed.
	 */
	private function find_match( string $id ): ?array {
		foreach ( $this->matches() as $match ) {
			if ( (string) $match['id'] === $id ) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * League page to scrape.
	 *
	 * @return string
	 */
	private function listing_url(): string {
		/**
		 * Filters the Oddschecker page the scraper reads.
		 *
		 * Any Oddschecker US soccer league page has the same structure, e.g.
		 * https://www.oddschecker.com/us/soccer/champions-league.
		 *
		 * @param string $url Listing page URL.
		 */
		return (string) apply_filters( 'odds_aggregator_oddschecker_url', self::LISTING_URL );
	}

	/**
	 * Upstream code => bookmaker id map.
	 *
	 * @return array<string,string>
	 */
	private function bookmaker_codes(): array {
		/**
		 * Filters the Oddschecker bookmaker-code map.
		 *
		 * @param array<string,string> $codes Upstream code => bookmaker id.
		 */
		return (array) apply_filters( 'odds_aggregator_oddschecker_bookmakers', self::BOOKMAKER_CODES );
	}

	/**
	 * Request headers.
	 *
	 * The site answers ordinary browser requests, so the scraper sends the
	 * headers a browser would. Filterable for sites that need something else.
	 *
	 * @return array<string,string>
	 */
	private function request_headers(): array {
		$headers = array(
			'User-Agent'      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
			'Accept'          => 'text/html,application/xhtml+xml',
			'Accept-Language' => 'en-US,en;q=0.9',
		);

		/**
		 * Filters the headers sent to Oddschecker.
		 *
		 * @param array<string,string> $headers Header name => value.
		 */
		return (array) apply_filters( 'odds_aggregator_oddschecker_headers', $headers );
	}

	/**
	 * Parses an upstream ISO 8601 timestamp into UTC.
	 *
	 * @param string $raw Timestamp, e.g. "2026-10-31T12:30:00Z".
	 * @return DateTimeImmutable|null
	 */
	private function parse_time( string $raw ): ?DateTimeImmutable {
		try {
			return ( new DateTimeImmutable( $raw ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception $exception ) {
			return null;
		}
	}
}
