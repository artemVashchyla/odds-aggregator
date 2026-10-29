( function () {
	const root = document.querySelector( '.oa-admin' );

	if ( ! root ) {
		return;
	}

	const tabs = Array.from( root.querySelectorAll( '[role="tab"]' ) );
	const panels = Array.from( root.querySelectorAll( '.oa-panel' ) );

	function show( id, focus ) {
		const panel = panels.find( ( item ) => item.id === id ) || panels[ 0 ];

		if ( ! panel ) {
			return;
		}

		panels.forEach( ( item ) =>
			item.classList.toggle( 'is-active', item === panel )
		);

		tabs.forEach( ( tab ) => {
			const selected = tab.getAttribute( 'aria-controls' ) === panel.id;

			tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			tab.tabIndex = selected ? 0 : -1;

			if ( selected && focus ) {
				tab.focus();
			}
		} );
	}

	function fromHash() {
		show( 'oa-' + window.location.hash.slice( 1 ), false );
	}

	if ( tabs.length && panels.length ) {
		root.classList.add( 'has-tabs' );

		tabs.forEach( ( tab, index ) => {
			tab.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				show( tab.getAttribute( 'aria-controls' ), false );
				window.history.replaceState(
					null,
					'',
					'#' +
						tab
							.getAttribute( 'aria-controls' )
							.replace( /^oa-/, '' )
				);
			} );

			tab.addEventListener( 'keydown', ( event ) => {
				const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ] || 0;

				if ( ! step ) {
					return;
				}

				event.preventDefault();

				const next =
					tabs[ ( index + step + tabs.length ) % tabs.length ];

				next.click();
				next.focus();
			} );
		} );

		window.addEventListener( 'hashchange', fromHash );
		fromHash();
	}

	root.querySelectorAll( '.oa-toggle-region' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const boxes = Array.from(
				button
					.closest( '.oa-bkcol' )
					.querySelectorAll( 'input[type="checkbox"]' )
			);
			const target = ! boxes.every( ( box ) => box.checked );

			boxes.forEach( ( box ) => {
				box.checked = target;
			} );
		} );
	} );

	root.querySelectorAll( '.oa-only-quoted' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const form = button.closest( 'form' );
			const on = ! form.classList.contains( 'is-only-quoted' );

			form.classList.toggle( 'is-only-quoted', on );
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
	} );

	const fresh = root.querySelector( '#oa-cache_ttl' );
	const stale = root.querySelector( '#oa-stale_ttl' );
	const text = root.querySelector( '.oa-tl-text' );

	function timeline() {
		if ( ! fresh || ! stale || ! text ) {
			return;
		}

		const f = Math.max( 0, parseInt( fresh.value, 10 ) || 0 );
		const s = Math.max( f, parseInt( stale.value, 10 ) || 0 );
		const share = s > 0 ? Math.min( 80, Math.max( 6, ( f / s ) * 85 ) ) : 6;
		const fill = ( template ) =>
			template.replace( '%1$d', f ).replace( '%2$d', s );

		root.querySelector( '.oa-tl-fresh' ).style.width = share + '%';
		root.querySelector( '.oa-tl-stale' ).style.width = 85 - share + '%';
		text.textContent = [
			fill( text.dataset.fresh ),
			fill( text.dataset.stale ),
			text.dataset.gone.replace( '%1$d', s ),
		].join( ' · ' );
	}

	[ fresh, stale ].forEach(
		( input ) => input && input.addEventListener( 'input', timeline )
	);
	timeline();

	root.querySelectorAll( '.oa-restore-defaults' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			button
				.closest( 'form' )
				.querySelectorAll( 'input[data-default]' )
				.forEach( ( input ) => {
					input.value = input.dataset.default;
				} );
			timeline();
		} );
	} );
} )();
