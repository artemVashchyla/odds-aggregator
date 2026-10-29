<?php
/**
 * Server-side block rendering.
 *
 * @package OddsAggregator
 */

declare(strict_types=1);

namespace OddsAggregator\Blocks;

use OddsAggregator\Admin\Settings;
use OddsAggregator\Domain\Bookmaker;
use OddsAggregator\Domain\BookmakerRegistry;
use OddsAggregator\Domain\Event;
use OddsAggregator\Domain\Market;
use OddsAggregator\Domain\MarketRegistry;
use OddsAggregator\Domain\Odds;
use OddsAggregator\Domain\OddsLine;
use OddsAggregator\Domain\Outcome;
use OddsAggregator\Odds\OddsConverter;
use OddsAggregator\Odds\OddsFormat;
use OddsAggregator\Odds\OddsRepository;
use OddsAggregator\Rest\OddsController;

/**
 * Turns cached odds into an accessible comparison table.
 *
 * Rendering happens on the server so the published page needs no JavaScript to
 * show prices. Client-side, the format switcher recomputes from the decimal
 * value on each cell, and the table is re-rendered through the REST API.
 */
final class OddsTableRenderer {

	/**
	 * Constructor.
	 *
	 * @param OddsRepository    $repository Cached odds access.
	 * @param Settings          $settings   Plugin settings.
	 * @param BookmakerRegistry $bookmakers Bookmaker catalogue.
	 * @param MarketRegistry    $markets    Market catalogue.
	 */
	public function __construct(
		private readonly OddsRepository $repository,
		private readonly Settings $settings,
		private readonly BookmakerRegistry $bookmakers,
		private readonly MarketRegistry $markets
	) {}

	/**
	 * Renders the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes ): string {
		$event_id = (string) ( $attributes['eventId'] ?? '' );

		if ( '' === $event_id ) {
			return $this->notice( __( 'Select an event for this odds table.', 'odds-aggregator' ) );
		}

		$event = $this->repository->find_event( $event_id );

		if ( ! $event instanceof Event ) {
			// An event *is* selected, so telling the reader to pick one would be
			// wrong: the data source simply cannot supply it right now.
			return $this->notice(
				__( 'These odds are temporarily unavailable.', 'odds-aggregator' ),
				__( 'The current data source does not list the event this block is set to. It was probably chosen while another data source was active, or the match has finished. Edit the block and choose an event again, or check the provider on the Odds Aggregator settings screen.', 'odds-aggregator' )
			);
		}

		$market_keys = $this->resolve_markets( $attributes );
		$markets     = $this->markets->only( $market_keys );

		if ( array() === $markets ) {
			return $this->notice( __( 'No markets are enabled for this odds table.', 'odds-aggregator' ) );
		}

		// The resolved id, which differs from the block's when the event was
		// matched across data sources.
		$lines = $this->repository->odds( $event->id(), $this->settings->markets() );

		if ( array() === $lines ) {
			return $this->notice( __( 'Odds are being refreshed. Please check back shortly.', 'odds-aggregator' ) );
		}

		$bookmakers = $this->resolve_bookmakers( $attributes );

		if ( array() === $bookmakers ) {
			return $this->notice(
				__( 'No odds to show for this table.', 'odds-aggregator' ),
				$this->empty_bookmaker_hint( $attributes )
			);
		}

		$requested = (string) ( $attributes['format'] ?? '' );
		$format    = OddsFormat::from_string_or_default(
			'' === $requested ? $this->settings->default_format() : $requested
		);
		$highlight = (bool) ( $attributes['highlightBest'] ?? true );
		$lowest    = (bool) ( $attributes['highlightWorst'] ?? false );
		$switcher  = (bool) ( $attributes['showSwitcher'] ?? true );

		$html = sprintf(
			'<div %s data-format="%s" data-refresh="%d" data-refresh-url="%s">',
			wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'oa-odds-table' ) ) ),
			esc_attr( $format->value ),
			max( 30, $this->settings->cache_ttl() ),
			esc_url( $this->refresh_url( $attributes ) )
		);

		$by_market = $this->prices_by_market( $markets, $lines, $bookmakers );

		// Prices exist, but none from a bookmaker this table shows: say so,
		// rather than printing a table shell with no rows in it.
		if ( array() === $by_market ) {
			return $this->notice(
				__( 'No odds to show for this table.', 'odds-aggregator' ),
				$this->unquoted_hint( $markets, $lines )
			);
		}

		$filters = $this->render_filters( array_intersect_key( $markets, $by_market ) );

		$html .= $this->render_head( $event );

		if ( '' !== $filters || $switcher ) {
			$html .= '<div class="oa-bar">'
				. ( '' !== $filters ? $filters : '<span class="oa-bar-label">' . esc_html__( 'Odds format', 'odds-aggregator' ) . '</span>' )
				. ( $switcher ? $this->render_switcher( $format ) : '' )
				. '</div>';
		}

		$html .= $this->render_grid( $event, $markets, $by_market, $bookmakers, $format, $highlight, $lowest );

		$html .= $this->render_footer( $lines );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Price rows of each market that has any, limited to the shown bookmakers.
	 *
	 * @param array<string,Market>    $markets    Markets to render.
	 * @param OddsLine[]              $lines      All price rows for the event.
	 * @param array<string,Bookmaker> $bookmakers Bookmakers to show.
	 * @return array<string,array<string,OddsLine>>
	 */
	private function prices_by_market( array $markets, array $lines, array $bookmakers ): array {
		$by_market = array();

		foreach ( $markets as $market ) {
			$rows = array_intersect_key( $this->rows_for_market( $lines, $market->key() ), $bookmakers );

			if ( array() !== $rows ) {
				$by_market[ $market->key() ] = $rows;
			}
		}

		return $by_market;
	}

	/**
	 * Why a table with prices has nothing to show, for administrators.
	 *
	 * @param array<string,Market> $markets Markets the table shows.
	 * @param OddsLine[]           $lines   All price rows for the event.
	 * @return string
	 */
	private function unquoted_hint( array $markets, array $lines ): string {
		$names = array();

		foreach ( $lines as $line ) {
			if ( ! isset( $markets[ $line->market_key() ] ) ) {
				continue;
			}

			$bookmaker = $this->bookmakers->get( $line->bookmaker_id() );

			$names[ $line->bookmaker_id() ] = $bookmaker instanceof Bookmaker ? $bookmaker->name() : $line->bookmaker_id();
		}

		if ( array() === $names ) {
			return __( 'The current data source has no prices for the markets this table shows.', 'odds-aggregator' );
		}

		return sprintf(
			/* translators: %s: comma-separated bookmaker names. */
			__( 'None of the bookmakers this table shows quote this event. The current data source quotes it at %s: add them in the block\'s Bookmakers panel, or enable them on the Bookmakers tab of the Odds Aggregator settings.', 'odds-aggregator' ),
			implode( ', ', $names )
		);
	}

	/**
	 * Market filter select, shown by the front-end script when there is a choice.
	 *
	 * @param array<string,Market> $markets Markets with prices.
	 * @return string
	 */
	private function render_filters( array $markets ): string {
		if ( count( $markets ) < 2 ) {
			return '';
		}

		$html = '<select class="oa-market-select" aria-label="' . esc_attr__( 'Markets', 'odds-aggregator' ) . '" hidden>'
			. '<option value="">' . esc_html__( 'All markets', 'odds-aggregator' ) . '</option>';

		foreach ( $markets as $market ) {
			$html .= sprintf(
				'<option value="%s">%s</option>',
				esc_attr( $market->key() ),
				esc_html( $market->label() )
			);
		}

		return $html . '</select>';
	}

	/**
	 * Renders the comparison grid: bookmakers as columns, outcomes as rows.
	 *
	 * @param Event                                $event      Event being priced.
	 * @param array<string,Market>                 $markets    Markets to render.
	 * @param array<string,array<string,OddsLine>> $by_market  Price rows per market, by bookmaker.
	 * @param array<string,Bookmaker>              $bookmakers Bookmakers to show, in order.
	 * @param OddsFormat                           $format     Display format.
	 * @param bool                                 $highlight  Whether to mark the best price.
	 * @param bool                                 $lowest     Whether to mark the lowest price.
	 * @return string
	 */
	private function render_grid(
		Event $event,
		array $markets,
		array $by_market,
		array $bookmakers,
		OddsFormat $format,
		bool $highlight,
		bool $lowest
	): string {
		$columns = array_filter(
			$bookmakers,
			static function ( Bookmaker $bookmaker ) use ( $by_market ): bool {
				foreach ( $by_market as $rows ) {
					if ( isset( $rows[ $bookmaker->id() ] ) ) {
						return true;
					}
				}

				return false;
			}
		);

		if ( array() === $columns ) {
			return '';
		}

		$html  = '<div class="oa-scroll"><table class="oa-market">';
		$html .= '<caption class="screen-reader-text">' . esc_html( $event->title() ) . '</caption>';
		$html .= '<thead><tr><th scope="col">' . esc_html__( 'Bookmaker', 'odds-aggregator' ) . '</th>';

		foreach ( $columns as $bookmaker ) {
			$html .= '<th scope="col" class="oa-bk"><span class="oa-book">' . $this->bookmaker_mark( $bookmaker )
				. '<span class="oa-book__name">' . esc_html( $bookmaker->name() ) . '</span></span></th>';
		}

		$html .= '</tr></thead>';

		foreach ( $markets as $market ) {
			if ( ! isset( $by_market[ $market->key() ] ) ) {
				continue;
			}

			$rows  = $by_market[ $market->key() ];
			$best  = $highlight ? $this->best_prices( $rows, $market ) : array();
			$worst = $lowest ? $this->worst_prices( $rows, $market ) : array();
			$html .= sprintf(
				'<tbody data-market="%s"><tr class="oa-group"><th scope="colgroup" colspan="%d"><span>%s</span></th></tr>',
				esc_attr( $market->key() ),
				count( $columns ) + 1,
				esc_html( $market->label() )
			);

			foreach ( $market->outcomes() as $outcome ) {
				$key   = $outcome->key();
				$html .= '<tr><th scope="row">' . esc_html( $this->outcome_label( $event, $outcome ) )
					. $this->best_note( $best[ $key ] ?? null, $key, $rows, $columns, $format ) . '</th>';

				foreach ( $columns as $bookmaker ) {
					$price    = isset( $rows[ $bookmaker->id() ] ) ? $rows[ $bookmaker->id() ]->price( $key ) : null;
					$is_best  = $price instanceof Odds
						&& isset( $best[ $key ] )
						&& abs( $best[ $key ] - $price->decimal() ) < 0.0001;
					$is_worst = $price instanceof Odds
						&& isset( $worst[ $key ] )
						&& abs( $worst[ $key ] - $price->decimal() ) < 0.0001;
					$html    .= $this->price_cell( $price, $is_best, $is_worst, $format );
				}

				$html .= '</tr>';
			}

			$html .= '</tbody>';
		}

		$html .= '<tfoot><tr><th scope="row">' . esc_html__( 'Visit bookmaker', 'odds-aggregator' ) . '</th>';

		foreach ( $columns as $bookmaker ) {
			$html .= '<td>' . $this->bet_link( $bookmaker ) . '</td>';
		}

		$html .= '</tr></tfoot></table></div>';

		if ( count( $columns ) > 3 ) {
			$html .= '<p class="oa-scroll-hint">' . esc_html(
				sprintf(
					/* translators: %d: number of bookmakers. */
					__( 'Swipe to compare all %d bookmakers →', 'odds-aggregator' ),
					count( $columns )
				)
			) . '</p>';
		}

		return $html;
	}

	/**
	 * Row label of an outcome: team names for home and away.
	 *
	 * @param Event   $event   Event being priced.
	 * @param Outcome $outcome Outcome.
	 * @return string
	 */
	private function outcome_label( Event $event, Outcome $outcome ): string {
		return match ( $outcome->key() ) {
			'home'  => $event->home(),
			'away'  => $event->away(),
			default => $outcome->label(),
		};
	}

	/**
	 * "Best 2.22 · Betfair" under an outcome.
	 *
	 * @param float|null              $best    Best decimal price, if highlighted.
	 * @param string                  $key     Outcome key.
	 * @param array<string,OddsLine>  $rows    Rows of the market, by bookmaker.
	 * @param array<string,Bookmaker> $columns Bookmakers shown.
	 * @param OddsFormat              $format  Display format.
	 * @return string
	 */
	private function best_note( ?float $best, string $key, array $rows, array $columns, OddsFormat $format ): string {
		if ( null === $best ) {
			return '';
		}

		foreach ( $columns as $bookmaker ) {
			$price = isset( $rows[ $bookmaker->id() ] ) ? $rows[ $bookmaker->id() ]->price( $key ) : null;

			if ( $price instanceof Odds && abs( $best - $price->decimal() ) < 0.0001 ) {
				return '<span class="oa-best-note">' . sprintf(
					/* translators: 1: price, 2: bookmaker name. */
					esc_html__( 'Best %1$s · %2$s', 'odds-aggregator' ),
					sprintf(
						'<span class="oa-odds" data-decimal="%s">%s</span>',
						esc_attr( (string) $price->decimal() ),
						esc_html( OddsConverter::format( $price->decimal(), $format ) )
					),
					esc_html( $bookmaker->name() )
				) . '</span>';
			}
		}

		return '';
	}

	/**
	 * Renders a single price cell.
	 *
	 * @param Odds|null  $price    Price, or null when not quoted.
	 * @param bool       $is_best  Whether this is the best price for the outcome.
	 * @param bool       $is_worst Whether this is the lowest price for the outcome.
	 * @param OddsFormat $format   Display format the page opens in.
	 * @return string
	 */
	private function price_cell( ?Odds $price, bool $is_best, bool $is_worst, OddsFormat $format ): string {
		if ( ! $price instanceof Odds ) {
			return '<td class="oa-price oa-price--none"><span aria-label="'
				. esc_attr__( 'Not quoted', 'odds-aggregator' ) . '">&mdash;</span></td>';
		}

		$classes = 'oa-price' . ( $is_best ? ' is-best' : '' ) . ( $is_worst ? ' is-worst' : '' );
		$label   = '';

		if ( $is_best ) {
			$label = ' <span class="screen-reader-text">' . esc_html__( '(best price)', 'odds-aggregator' ) . '</span>';
		} elseif ( $is_worst ) {
			$label = ' <span class="screen-reader-text">' . esc_html__( '(lowest price)', 'odds-aggregator' ) . '</span>';
		}

		// data-decimal lets the front-end switcher reformat without refetching.
		return sprintf(
			'<td class="%s"><span class="oa-odds" data-decimal="%s">%s</span>%s</td>',
			esc_attr( $classes ),
			esc_attr( (string) $price->decimal() ),
			esc_html( OddsConverter::format( $price->decimal(), $format ) ),
			$label
		);
	}

	/**
	 * The bookmaker's logo, or a monogram standing in for it.
	 *
	 * The logo is decorative and carries an empty alt, because the name is
	 * printed right beside it.
	 *
	 * @param Bookmaker $bookmaker Bookmaker.
	 * @return string
	 */
	private function bookmaker_mark( Bookmaker $bookmaker ): string {
		if ( '' !== $bookmaker->logo() ) {
			return sprintf(
				'<img class="oa-book__logo" src="%s" alt="" loading="lazy" decoding="async" />',
				esc_url( $bookmaker->logo() )
			);
		}

		// Derived from the id rather than picked at random, so a brand keeps
		// the same colour between page loads and between pages.
		$hue = absint( crc32( $bookmaker->id() ) ) % 360;

		return sprintf(
			'<span class="oa-book__monogram" style="--oa-monogram-hue:%d" aria-hidden="true">%s</span>',
			$hue,
			esc_html( $this->monogram( $bookmaker->name() ) )
		);
	}

	/**
	 * One or two initials standing in for a missing logo.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	private function monogram( string $name ): string {
		$words = preg_split( '/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) || array() === $words ) {
			return '?';
		}

		$initials = strtoupper( substr( (string) $words[0], 0, 1 ) );

		if ( isset( $words[1] ) ) {
			$initials .= strtoupper( substr( (string) $words[1], 0, 1 ) );
		}

		return $initials;
	}

	/**
	 * Outbound "Bet" link to a bookmaker.
	 *
	 * @param Bookmaker $bookmaker Bookmaker.
	 * @return string
	 */
	private function bet_link( Bookmaker $bookmaker ): string {
		if ( '' === $bookmaker->url() ) {
			return '';
		}

		return sprintf(
			'<a class="oa-bet" href="%s" rel="nofollow sponsored noopener" target="_blank">%s<span class="screen-reader-text"> %s</span></a>',
			esc_url( $bookmaker->url() ),
			esc_html__( 'Bet', 'odds-aggregator' ),
			esc_html(
				sprintf(
					/* translators: %s: bookmaker name. */
					__( 'at %s', 'odds-aggregator' ),
					$bookmaker->name()
				)
			)
		);
	}

	/**
	 * REST URL that re-renders this block with the same attributes.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function refresh_url( array $attributes ): string {
		$list = static fn( string $name ): array => array_values(
			array_map( 'rawurlencode', array_map( 'strval', (array) ( $attributes[ $name ] ?? array() ) ) )
		);

		$args = array(
			'eventId'        => rawurlencode( (string) ( $attributes['eventId'] ?? '' ) ),
			'markets'        => $list( 'markets' ),
			'bookmakers'     => $list( 'bookmakers' ),
			'format'         => rawurlencode( (string) ( $attributes['format'] ?? '' ) ),
			'highlightBest'  => ( $attributes['highlightBest'] ?? true ) ? '1' : '0',
			'highlightWorst' => ( $attributes['highlightWorst'] ?? false ) ? '1' : '0',
			'showSwitcher'   => ( $attributes['showSwitcher'] ?? true ) ? '1' : '0',
		);

		return add_query_arg(
			array_filter( $args, static fn( $value ): bool => '' !== $value && array() !== $value ),
			rest_url( OddsController::NAMESPACE . '/block' )
		);
	}

	/**
	 * Renders the header bar: event, league and kick-off time.
	 *
	 * @param Event $event Event being priced.
	 * @return string
	 */
	private function render_head( Event $event ): string {
		$timestamp = $event->kickoff()->getTimestamp();
		$kickoff   = wp_date( 'D j M', $timestamp ) . ', ' . wp_date( (string) get_option( 'time_format', 'H:i' ), $timestamp );

		return sprintf(
			'<div class="oa-head"><span class="oa-head-title">%s</span><span class="oa-head-meta">%s<time datetime="%s">%s</time></span></div>',
			esc_html( $event->title() ),
			'' === $event->league() ? '' : esc_html( $event->league() ) . ' &middot; ',
			esc_attr( $event->kickoff()->format( DATE_ATOM ) ),
			esc_html( $kickoff )
		);
	}

	/**
	 * Renders the format switcher.
	 *
	 * @param OddsFormat $current Active format.
	 * @return string
	 */
	private function render_switcher( OddsFormat $current ): string {
		$html = '<div class="oa-switcher" role="group" aria-label="'
			. esc_attr__( 'Odds format', 'odds-aggregator' ) . '">';

		foreach ( OddsFormat::cases() as $format ) {
			$html .= sprintf(
				'<button type="button" class="oa-format-button" data-format="%s" aria-pressed="%s">%s</button>',
				esc_attr( $format->value ),
				$format === $current ? 'true' : 'false',
				esc_html( $format->label() )
			);
		}

		return $html . '</div>';
	}

	/**
	 * Renders the freshness note.
	 *
	 * Showing the age is a deliberate accuracy measure: stale odds are useful,
	 * but only if the reader knows they are stale.
	 *
	 * @param OddsLine[] $lines Price rows.
	 * @return string
	 */
	private function render_footer( array $lines ): string {
		$timestamps = array_filter( array_map( static fn( OddsLine $line ): int => $line->fetched_at(), $lines ) );

		if ( array() === $timestamps ) {
			return '';
		}

		$fetched = max( $timestamps );
		$text    = sprintf(
			/* translators: %s: human readable time difference, e.g. "2 mins". */
			__( 'Odds updated %s ago. Always check the bookmaker before betting.', 'odds-aggregator' ),
			human_time_diff( $fetched, time() )
		);

		// Sample prices can now sit next to a real fixture name; never let
		// them pass for live odds.
		if ( $this->is_sample( $lines ) ) {
			$text = __( 'Sample odds for preview only - not live bookmaker prices.', 'odds-aggregator' );
		}

		return '<p class="oa-updated">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Whether the rows come from the offline sample provider.
	 *
	 * @param OddsLine[] $lines Price rows.
	 * @return bool
	 */
	private function is_sample( array $lines ): bool {
		foreach ( $lines as $line ) {
			foreach ( $line->prices() as $price ) {
				return 'mock' === $price->source();
			}
		}

		return false;
	}

	/**
	 * Indexes price rows for one market by bookmaker id.
	 *
	 * @param OddsLine[] $lines      All rows.
	 * @param string     $market_key Market key.
	 * @return array<string,OddsLine>
	 */
	private function rows_for_market( array $lines, string $market_key ): array {
		$rows = array();

		foreach ( $lines as $line ) {
			if ( $line->market_key() === $market_key ) {
				$rows[ $line->bookmaker_id() ] = $line;
			}
		}

		return $rows;
	}

	/**
	 * Best available decimal price per outcome.
	 *
	 * @param array<string,OddsLine> $rows   Rows for one market.
	 * @param Market                 $market Market being rendered.
	 * @return array<string,float>
	 */
	private function best_prices( array $rows, Market $market ): array {
		$best = array();

		foreach ( $market->outcome_keys() as $outcome_key ) {
			foreach ( $rows as $row ) {
				$price = $row->price( $outcome_key );

				if ( ! $price instanceof Odds ) {
					continue;
				}

				if ( ! isset( $best[ $outcome_key ] ) || $price->decimal() > $best[ $outcome_key ] ) {
					$best[ $outcome_key ] = $price->decimal();
				}
			}
		}

		return $best;
	}

	/**
	 * Lowest decimal price per outcome, where prices differ.
	 *
	 * @param array<string,OddsLine> $rows   Rows for one market.
	 * @param Market                 $market Market being rendered.
	 * @return array<string,float>
	 */
	private function worst_prices( array $rows, Market $market ): array {
		$best  = $this->best_prices( $rows, $market );
		$worst = array();

		foreach ( $market->outcome_keys() as $outcome_key ) {
			foreach ( $rows as $row ) {
				$price = $row->price( $outcome_key );

				if ( $price instanceof Odds && ( ! isset( $worst[ $outcome_key ] ) || $price->decimal() < $worst[ $outcome_key ] ) ) {
					$worst[ $outcome_key ] = $price->decimal();
				}
			}
		}

		return array_filter(
			$worst,
			static fn( float $price, string $outcome_key ): bool => $price < ( $best[ $outcome_key ] ?? $price ) - 0.0001,
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Markets for this block: the block's choice, narrowed to what is enabled.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string[]
	 */
	private function resolve_markets( array $attributes ): array {
		$enabled  = $this->settings->markets();
		$selected = array_map( 'strval', (array) ( $attributes['markets'] ?? array() ) );

		if ( array() === $selected ) {
			return $enabled;
		}

		return array_values( array_intersect( $selected, $enabled ) );
	}

	/**
	 * Bookmakers for this block: the block's choice, narrowed to what is enabled.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return array<string,Bookmaker>
	 */
	private function resolve_bookmakers( array $attributes ): array {
		$enabled  = $this->settings->bookmakers();
		$selected = array_map( 'strval', (array) ( $attributes['bookmakers'] ?? array() ) );
		$ids      = array() === $selected ? $enabled : array_values( array_intersect( $selected, $enabled ) );

		return $this->bookmakers->only( $ids );
	}

	/**
	 * Explains to an administrator why no bookmakers resolved.
	 *
	 * A block may pin its own bookmakers. Site settings are a ceiling, so
	 * pinning names that are all switched off leaves nothing to render - and
	 * saying "none are enabled" would be wrong when others clearly are.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function empty_bookmaker_hint( array $attributes ): string {
		$pinned = array_filter( array_map( 'strval', (array) ( $attributes['bookmakers'] ?? array() ) ) );

		if ( array() === $pinned ) {
			return __(
				'No bookmakers are enabled on the Odds Aggregator settings screen.',
				'odds-aggregator'
			);
		}

		$names = array();

		foreach ( $pinned as $id ) {
			$bookmaker = $this->bookmakers->get( $id );
			$names[]   = $bookmaker instanceof Bookmaker ? $bookmaker->name() : $id;
		}

		return sprintf(
			/* translators: %s: comma-separated bookmaker names pinned to this block. */
			__(
				'This block is pinned to %s, and none of those are enabled on the Odds Aggregator settings screen. Either enable one of them, or remove them from the block so it follows the site settings.',
				'odds-aggregator'
			),
			implode( ', ', $names )
		);
	}

	/**
	 * Renders an inline notice in place of a table.
	 *
	 * @param string $message    Message shown to everyone.
	 * @param string $admin_hint Extra diagnostic shown only to administrators.
	 * @return string
	 */
	private function notice( string $message, string $admin_hint = '' ): string {
		$html = sprintf(
			'<div %s><p class="oa-notice">%s</p>',
			wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'oa-odds-table' ) ) ),
			esc_html( $message )
		);

		// Diagnostics belong to whoever can act on them, not to every visitor.
		if ( '' !== $admin_hint && current_user_can( 'manage_options' ) ) {
			$html .= '<p class="oa-notice"><em>' . esc_html( $admin_hint ) . '</em></p>';
		}

		return $html . '</div>';
	}
}
