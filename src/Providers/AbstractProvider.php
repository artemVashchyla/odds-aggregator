<?php
/**
 * Shared provider behaviour.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Providers;

use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Odds\OddsConverter;

/**
 * Normalisation and validation every provider needs.
 *
 * Data accuracy is largely won here: upstream names are mapped onto our
 * bookmaker ids, and anything that does not parse into a plausible price is
 * dropped rather than displayed.
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Upstream name (slugified) => internal bookmaker id.
	 *
	 * @var array<string,string>
	 */
	private const BOOKMAKER_ALIASES = array(
		'bet365'            => 'bet365',
		'williamhill'       => 'williamhill',
		'willhill'          => 'williamhill',
		'whill'             => 'williamhill',
		'paddypower'        => 'paddypower',
		'paddy'             => 'paddypower',
		'betfair'           => 'betfair',
		'betfairsportsbook' => 'betfair',
		'ladbrokes'         => 'ladbrokes',
		'coral'             => 'coral',
		'skybet'            => 'skybet',
		'skybetting'        => 'skybet',
		'unibet'            => 'unibet',
		'betvictor'         => 'betvictor',
		'victor'            => 'betvictor',
		'betway'            => 'betway',
		'fanduel'           => 'fanduel',
		'draftkings'        => 'draftkings',
		'betmgm'            => 'betmgm',
		'caesars'           => 'caesars',
		'fanatics'          => 'fanatics',
		'betrivers'         => 'betrivers',
		'borgata'           => 'borgata',
		'betonlineag'       => 'betonlineag',
		'betus'             => 'betus',
		'bovada'            => 'bovada',
		'lowvigag'          => 'lowvigag',
		'mybookieag'        => 'mybookieag',
	);

	/**
	 * Market keys this provider quotes, or null when it quotes every market.
	 *
	 * @return string[]|null
	 */
	public function supported_markets(): ?array {
		return null;
	}

	/**
	 * Bookmaker ids this provider quotes, or null when that is not known up front.
	 *
	 * @return string[]|null
	 */
	public function supported_bookmakers(): ?array {
		return null;
	}

	/**
	 * Takes over an event this provider does not list, when it can price it anyway.
	 *
	 * Called for a block whose event was chosen under another data source and
	 * has no counterpart here. Real sources cannot invent prices, so the
	 * default is null.
	 *
	 * @param Event $event Event as recorded under its original source.
	 * @return Event|null
	 */
	public function adopt_event( Event $event ): ?Event {
		return null;
	}

	/**
	 * Most providers are usable as soon as they are constructed.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Maps an upstream bookmaker name onto an internal id.
	 *
	 * @param string $raw Upstream name.
	 * @return string|null Internal id, or null when unrecognised.
	 */
	protected function normalise_bookmaker( string $raw ): ?string {
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '', $raw ) );

		/**
		 * Filters the upstream-name to bookmaker-id alias map.
		 *
		 * @param array<string,string> $aliases Slugified upstream name => bookmaker id.
		 */
		$aliases = (array) apply_filters( 'odds_aggregator_bookmaker_aliases', self::BOOKMAKER_ALIASES );

		return isset( $aliases[ $slug ] ) ? (string) $aliases[ $slug ] : null;
	}

	/**
	 * Builds a price, or null when the value is not a usable quote.
	 *
	 * @param mixed $decimal Raw decimal value from upstream.
	 * @return Odds|null
	 */
	protected function make_odds( mixed $decimal ): ?Odds {
		if ( ! is_numeric( $decimal ) ) {
			return null;
		}

		$value = round( (float) $decimal, 4 );

		if ( ! OddsConverter::is_plausible( $value ) ) {
			return null;
		}

		return new Odds( $value, time(), $this->id() );
	}

	/**
	 * Parses a fractional string such as "5/2" into a decimal price.
	 *
	 * @param string $fraction Fractional text.
	 * @return float|null
	 */
	protected function parse_fraction( string $fraction ): ?float {
		$fraction = trim( $fraction );

		if ( in_array( strtolower( $fraction ), array( 'evs', 'evens' ), true ) ) {
			return 2.0;
		}

		if ( 1 !== preg_match( '#^(\d+)\s*/\s*(\d+)$#', $fraction, $matches ) ) {
			return null;
		}

		$denominator = (int) $matches[2];

		if ( 0 === $denominator ) {
			return null;
		}

		return OddsConverter::fractional_to_decimal( (int) $matches[1], $denominator );
	}
}
