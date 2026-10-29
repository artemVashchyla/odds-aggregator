<?php
/**
 * A sporting event.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Domain;

use DateTimeImmutable;

/**
 * Immutable event definition.
 */
final class Event {

	/**
	 * Constructor.
	 *
	 * @param string            $id      Machine id, unique per provider.
	 * @param string            $sport   Sport key, e.g. "football".
	 * @param string            $league  League name.
	 * @param string            $home    Home team or competitor.
	 * @param string            $away    Away team or competitor.
	 * @param DateTimeImmutable $kickoff Scheduled start, in UTC.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $sport,
		private readonly string $league,
		private readonly string $home,
		private readonly string $away,
		private readonly DateTimeImmutable $kickoff
	) {}

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Sport key.
	 *
	 * @return string
	 */
	public function sport(): string {
		return $this->sport;
	}

	/**
	 * League name.
	 *
	 * @return string
	 */
	public function league(): string {
		return $this->league;
	}

	/**
	 * Home team.
	 *
	 * @return string
	 */
	public function home(): string {
		return $this->home;
	}

	/**
	 * Away team.
	 *
	 * @return string
	 */
	public function away(): string {
		return $this->away;
	}

	/**
	 * Scheduled start.
	 *
	 * @return DateTimeImmutable
	 */
	public function kickoff(): DateTimeImmutable {
		return $this->kickoff;
	}

	/**
	 * Whether another event is the same real-world fixture.
	 *
	 * Providers name the same match differently ("Chelsea FC" v "Chelsea",
	 * "Man Utd" v "Manchester United") and give it different ids, so a block
	 * saved under one data source is matched under another by teams and
	 * kick-off instead of by id.
	 *
	 * @param Event $other Event from another provider.
	 * @param int   $window Allowed kick-off difference, in seconds (default two days).
	 * @return bool
	 */
	public function is_same_fixture( Event $other, int $window = 172800 ): bool {
		if ( abs( $this->kickoff->getTimestamp() - $other->kickoff()->getTimestamp() ) > $window ) {
			return false;
		}

		return self::same_team( $this->home, $other->home() ) && self::same_team( $this->away, $other->away() );
	}

	/**
	 * Loose team-name comparison: equal after normalising, or one name is the
	 * other plus a suffix ("Leeds" and "Leeds United").
	 *
	 * @param string $a First name.
	 * @param string $b Second name.
	 * @return bool
	 */
	private static function same_team( string $a, string $b ): bool {
		$a = self::normalise_team( $a );
		$b = self::normalise_team( $b );

		if ( '' === $a || '' === $b ) {
			return false;
		}

		return $a === $b || str_starts_with( $a, $b . ' ' ) || str_starts_with( $b, $a . ' ' );
	}

	/**
	 * Lowercase, punctuation-free team name with club suffixes and common
	 * abbreviations resolved.
	 *
	 * @param string $name Team name.
	 * @return string
	 */
	private static function normalise_team( string $name ): string {
		$name  = strtolower( str_replace( '&', ' and ', $name ) );
		$name  = (string) preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$words = array_diff( explode( ' ', $name ), array( '', 'fc', 'afc', 'cf', 'sc' ) );
		$name  = implode( ' ', $words );

		$aliases = array(
			'man utd'                  => 'manchester united',
			'man united'               => 'manchester united',
			'man city'                 => 'manchester city',
			'spurs'                    => 'tottenham',
			'nottm forest'             => 'nottingham forest',
			'nott m forest'            => 'nottingham forest',
			'wolves'                   => 'wolverhampton',
			'brighton and hove albion' => 'brighton',
		);

		return $aliases[ $name ] ?? $name;
	}

	/**
	 * "Home v Away", used as the table caption and editor label.
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->home . ' v ' . $this->away;
	}

	/**
	 * Array form, used by the REST API and the block editor.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'sport'   => $this->sport,
			'league'  => $this->league,
			'home'    => $this->home,
			'away'    => $this->away,
			'kickoff' => $this->kickoff->format( DATE_ATOM ),
			'title'   => $this->title(),
		);
	}
}
