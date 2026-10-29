/**
 * Front-end behaviour for the odds table.
 *
 * The only client-side work is switching display format. Every cell already
 * carries its decimal price in a data attribute, so switching is pure local
 * arithmetic: no refetch, no request, no layout shift.
 */

/**
 * Standard bookmaker fractional ladder, ascending.
 *
 * Mirrors OddsConverter::FRACTION_LADDER on the PHP side.
 */
const LADDER =
	'1/100 1/66 1/50 1/40 1/33 1/25 1/20 1/16 1/14 1/12 1/11 1/10 1/9 1/8 2/15 1/7 2/13 1/6 2/11 1/5 2/9 1/4 2/7 3/10 1/3 4/11 2/5 4/9 1/2 8/15 4/7 8/13 4/6 8/11 4/5 5/6 10/11 20/21 1/1 21/20 11/10 6/5 5/4 11/8 6/4 13/8 7/4 15/8 2/1 85/40 9/4 5/2 11/4 3/1 10/3 7/2 4/1 9/2 5/1 11/2 6/1 13/2 7/1 15/2 8/1 17/2 9/1 10/1 11/1 12/1 14/1 16/1 18/1 20/1 22/1 25/1 28/1 33/1 40/1 50/1 66/1 80/1 100/1 125/1 150/1 200/1 250/1 300/1 400/1 500/1 750/1 999/1'
		.split( ' ' )
		.map( ( step ) => step.split( '/' ).map( Number ) );

/**
 * Converts a decimal price to the closest price on the bookmaker ladder.
 *
 * @param {number} decimal Decimal price.
 * @return {string} Fraction as "n/d".
 */
function toFractional( decimal ) {
	let best = [ 1, 1 ];
	let bestError = Number.MAX_VALUE;

	for ( const [ numerator, denominator ] of LADDER ) {
		const error = Math.abs( decimal - ( numerator / denominator + 1 ) );

		if ( error < bestError - 1e-9 ) {
			bestError = error;
			best = [ numerator, denominator ];
		}
	}

	return `${ best[ 0 ] }/${ best[ 1 ] }`;
}

/**
 * Converts a decimal price to American (moneyline).
 *
 * @param {number} decimal Decimal price.
 * @return {string} Signed moneyline price.
 */
function toAmerican( decimal ) {
	const american =
		decimal >= 2
			? Math.round( ( decimal - 1 ) * 100 )
			: Math.round( -100 / ( decimal - 1 ) );

	return american > 0 ? `+${ american }` : `${ american }`;
}

/**
 * Renders one price in the requested format.
 *
 * @param {number} decimal Decimal price.
 * @param {string} format  Target format.
 * @return {string} Formatted price.
 */
function formatOdds( decimal, format ) {
	if ( format === 'fractional' ) {
		return toFractional( decimal );
	}

	if ( format === 'american' ) {
		return toAmerican( decimal );
	}

	return decimal.toFixed( 2 );
}

/**
 * Applies a format to one table and remembers the choice.
 *
 * @param {Element} table  Block root element.
 * @param {string}  format Target format.
 */
function applyFormat( table, format ) {
	table.querySelectorAll( '.oa-odds' ).forEach( ( cell ) => {
		const decimal = parseFloat( cell.dataset.decimal );

		if ( ! Number.isNaN( decimal ) && decimal > 1 ) {
			cell.textContent = formatOdds( decimal, format );
		}
	} );

	table.dataset.format = format;

	table.querySelectorAll( '.oa-format-button' ).forEach( ( button ) => {
		button.setAttribute(
			'aria-pressed',
			button.dataset.format === format ? 'true' : 'false'
		);
	} );

	try {
		window.localStorage.setItem( 'oddsAggregatorFormat', format );
	} catch {
		// Private mode or blocked storage; the switch still works for this page view.
	}
}

/**
 * Shows one market's rows, or all of them for an empty key.
 *
 * @param {Element} table  Block root element.
 * @param {string}  market Market key, or "" for all markets.
 */
function applyMarket( table, market ) {
	const groups = Array.from( table.querySelectorAll( 'tbody[data-market]' ) );
	const known = groups.some( ( group ) => group.dataset.market === market );
	const selected = known ? market : '';

	table.dataset.market = selected;

	groups.forEach( ( group ) => {
		group.hidden = '' !== selected && group.dataset.market !== selected;
	} );

	table.querySelectorAll( '.oa-market-select' ).forEach( ( select ) => {
		select.value = selected;
	} );
}

/**
 * Reveals the script-only controls and restores the selected market.
 *
 * @param {Element} table Block root element.
 */
function enhance( table ) {
	table.querySelectorAll( '.oa-market-select' ).forEach( ( select ) => {
		select.hidden = false;
	} );

	applyMarket( table, table.dataset.market || '' );
}

/**
 * Replaces a table's content with a fresh server render, keeping its format.
 *
 * @param {Element} table Block root element.
 */
function refresh( table ) {
	if ( document.visibilityState !== 'visible' ) {
		return;
	}

	window
		.fetch( table.dataset.refreshUrl, { credentials: 'omit' } )
		.then( ( response ) => ( response.ok ? response.json() : null ) )
		.then( ( data ) => {
			const template = document.createElement( 'template' );

			template.innerHTML = data?.html || '';

			const fresh = template.content.firstElementChild;

			if ( ! fresh?.querySelector( '.oa-market' ) ) {
				return;
			}

			const format = table.dataset.format;

			table.innerHTML = fresh.innerHTML;

			if ( format !== fresh.dataset.format ) {
				applyFormat( table, format );
			}

			enhance( table );
		} )
		.catch( () => {} );
}

/**
 * Wires up every odds table on the page.
 */
function init() {
	const tables = document.querySelectorAll( '.oa-odds-table' );

	if ( ! tables.length ) {
		return;
	}

	let preferred = null;

	try {
		preferred = window.localStorage.getItem( 'oddsAggregatorFormat' );
	} catch {
		preferred = null;
	}

	tables.forEach( ( table ) => {
		const switchable = null !== table.querySelector( '.oa-format-button' );

		if ( switchable && preferred && preferred !== table.dataset.format ) {
			applyFormat( table, preferred );
		}

		enhance( table );

		table.addEventListener( 'change', ( event ) => {
			if ( event.target.matches( '.oa-market-select' ) ) {
				applyMarket( table, event.target.value );
			}
		} );

		table.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '.oa-format-button' );

			if ( button ) {
				applyFormat( table, button.dataset.format );
			}
		} );

		const seconds = parseInt( table.dataset.refresh, 10 );

		if ( table.dataset.refreshUrl && seconds > 0 ) {
			window.setInterval( () => refresh( table ), seconds * 1000 );
		}
	} );
}

// Guarded so the conversion helpers can be imported and tested outside a browser.
if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}

export { formatOdds, toFractional, toAmerican, applyFormat, applyMarket };
