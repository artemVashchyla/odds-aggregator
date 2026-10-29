/**
 * Editor UI for the odds table block.
 */

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Flex,
	FlexItem,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Loads the catalogues the inspector needs.
 *
 * All three are small and change rarely, so they are fetched once per editor
 * session rather than on every attribute change.
 *
 * @return {Object} Loading state, error and the three catalogues.
 */
function useCatalogues() {
	const [ state, setState ] = useState( {
		loading: true,
		error: null,
		events: [],
		markets: [],
		bookmakers: [],
		status: null,
	} );

	useEffect( () => {
		let cancelled = false;

		Promise.all( [
			apiFetch( { path: '/odds-aggregator/v1/events' } ),
			apiFetch( { path: '/odds-aggregator/v1/markets' } ),
			apiFetch( { path: '/odds-aggregator/v1/bookmakers' } ),
			apiFetch( { path: '/odds-aggregator/v1/status' } ),
		] )
			.then( ( [ events, markets, bookmakers, status ] ) => {
				if ( ! cancelled ) {
					setState( {
						loading: false,
						error: null,
						events,
						markets,
						bookmakers,
						status,
					} );
				}
			} )
			.catch( ( error ) => {
				if ( ! cancelled ) {
					setState( ( previous ) => ( {
						...previous,
						loading: false,
						error:
							error.message ||
							__(
								'Could not load odds data.',
								'odds-aggregator'
							),
					} ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	return state;
}

/**
 * Bookmakers with prices for an event.
 *
 * @param {string} eventId Event id.
 * @return {?string[]} Bookmaker ids, or null while unknown.
 */
function useQuotedBookmakers( eventId ) {
	const [ quoted, setQuoted ] = useState( null );

	useEffect( () => {
		let cancelled = false;

		setQuoted( null );

		if ( ! eventId ) {
			return;
		}

		apiFetch( {
			path: `/odds-aggregator/v1/odds?event=${ encodeURIComponent( eventId ) }`,
		} )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setQuoted( [
						...new Set(
							response.lines.map( ( line ) => line.bookmaker )
						),
					] );
				}
			} )
			.catch( () => {} );

		return () => {
			cancelled = true;
		};
	}, [ eventId ] );

	return quoted;
}

/**
 * Block edit component.
 *
 * @param {Object}                       props               Block props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} Editor markup.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		eventId,
		markets,
		bookmakers,
		format,
		highlightBest,
		highlightWorst,
		showSwitcher,
	} = attributes;
	const catalogues = useCatalogues();
	const quoted = useQuotedBookmakers( eventId );
	const blockProps = useBlockProps();

	// Only bookmakers enabled site-wide can be added to a block.
	const enabled = catalogues.bookmakers.filter( ( book ) => book.enabled );
	const available = quoted?.length
		? enabled.filter( ( book ) => quoted.includes( book.id ) )
		: enabled;
	const chosen = bookmakers.length
		? bookmakers
		: available.map( ( book ) => book.id );
	const addable = available.filter(
		( book ) => ! chosen.includes( book.id )
	);

	const nameFor = ( id ) => {
		const name = enabled.find( ( book ) => book.id === id )?.name || id;

		return quoted?.length && ! quoted.includes( id )
			? `${ name } ${ __( '(no prices for this event)', 'odds-aggregator' ) }`
			: name;
	};

	const addBookmaker = ( id ) => {
		if ( id && ! chosen.includes( id ) ) {
			setAttributes( { bookmakers: [ ...chosen, id ] } );
		}
	};

	const removeBookmaker = ( id ) => {
		setAttributes( {
			bookmakers: chosen.filter( ( value ) => value !== id ),
		} );
	};

	const moveBookmaker = ( index, offset ) => {
		const next = [ ...chosen ];
		const target = index + offset;

		if ( target < 0 || target >= next.length ) {
			return;
		}

		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		setAttributes( { bookmakers: next } );
	};

	const toggleMarket = ( key, checked ) => {
		const current = markets.length
			? markets
			: catalogues.markets.map( ( market ) => market.key );
		const next = checked
			? [ ...current, key ]
			: current.filter( ( value ) => value !== key );

		setAttributes( { markets: next } );
	};

	const activeMarkets = markets.length
		? markets
		: catalogues.markets.map( ( market ) => market.key );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Event', 'odds-aggregator' ) }>
					{ catalogues.loading && <Spinner /> }
					{ catalogues.error && (
						<Notice status="error" isDismissible={ false }>
							{ catalogues.error }
						</Notice>
					) }
					{ ! catalogues.loading &&
						! catalogues.error &&
						0 === catalogues.events.length && (
							<Notice status="warning" isDismissible={ false }>
								<p>
									{ __(
										'No events are available, so there is nothing to select. The configured data source returned none.',
										'odds-aggregator'
									) }
								</p>
								{ catalogues.status?.error && (
									<p>
										<code>{ catalogues.status.error }</code>
									</p>
								) }
								{ catalogues.status?.settingsUrl && (
									<p>
										<ExternalLink
											href={
												catalogues.status.settingsUrl
											}
										>
											{ __(
												'Open Odds Aggregator settings',
												'odds-aggregator'
											) }
										</ExternalLink>
									</p>
								) }
								<p>
									{ __(
										'Switching the provider to "Sample data (offline)" will populate this list immediately.',
										'odds-aggregator'
									) }
								</p>
							</Notice>
						) }

					{ ! catalogues.loading &&
						! catalogues.error &&
						catalogues.events.length > 0 && (
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Event', 'odds-aggregator' ) }
								value={ eventId }
								options={ [
									{
										label: __(
											'— Select an event —',
											'odds-aggregator'
										),
										value: '',
									},
									...catalogues.events.map( ( event ) => ( {
										label: `${ event.title } (${ event.league })`,
										value: event.id,
									} ) ),
								] }
								onChange={ ( value ) =>
									setAttributes( { eventId: value } )
								}
							/>
						) }
				</PanelBody>

				<PanelBody
					title={ __( 'Markets', 'odds-aggregator' ) }
					initialOpen={ false }
				>
					{ catalogues.markets.map( ( market ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ market.key }
							label={ market.label }
							checked={ activeMarkets.includes( market.key ) }
							onChange={ ( checked ) =>
								toggleMarket( market.key, checked )
							}
						/>
					) ) }
				</PanelBody>

				<PanelBody
					title={ __( 'Bookmakers', 'odds-aggregator' ) }
					initialOpen={ false }
				>
					<p>
						{ __(
							'Bookmakers shown in this block, in order. Only bookmakers enabled in settings can be added.',
							'odds-aggregator'
						) }
					</p>

					{ chosen.map( ( id, index ) => (
						<Flex key={ id } align="center" justify="space-between">
							<FlexItem>{ nameFor( id ) }</FlexItem>
							<FlexItem>
								<Button
									size="small"
									icon="arrow-up-alt2"
									label={ __( 'Move up', 'odds-aggregator' ) }
									disabled={ 0 === index }
									onClick={ () => moveBookmaker( index, -1 ) }
								/>
								<Button
									size="small"
									icon="arrow-down-alt2"
									label={ __(
										'Move down',
										'odds-aggregator'
									) }
									disabled={ index === chosen.length - 1 }
									onClick={ () => moveBookmaker( index, 1 ) }
								/>
								<Button
									size="small"
									isDestructive
									icon="no-alt"
									label={ __( 'Remove', 'odds-aggregator' ) }
									onClick={ () => removeBookmaker( id ) }
								/>
							</FlexItem>
						</Flex>
					) ) }

					{ addable.length > 0 && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Add a bookmaker', 'odds-aggregator' ) }
							value=""
							options={ [
								{
									label: __( '— Add —', 'odds-aggregator' ),
									value: '',
								},
								...addable.map( ( book ) => ( {
									label: book.name,
									value: book.id,
								} ) ),
							] }
							onChange={ addBookmaker }
						/>
					) }
				</PanelBody>

				<PanelBody
					title={ __( 'Display', 'odds-aggregator' ) }
					initialOpen={ false }
				>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Odds format', 'odds-aggregator' ) }
						value={ format }
						options={ [
							{
								label: __( 'Site default', 'odds-aggregator' ),
								value: '',
							},
							{
								label: __( 'Decimal', 'odds-aggregator' ),
								value: 'decimal',
							},
							{
								label: __( 'Fractional', 'odds-aggregator' ),
								value: 'fractional',
							},
							{
								label: __( 'American', 'odds-aggregator' ),
								value: 'american',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { format: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Highlight the best price',
							'odds-aggregator'
						) }
						checked={ highlightBest }
						onChange={ ( value ) =>
							setAttributes( { highlightBest: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Highlight the lowest price',
							'odds-aggregator'
						) }
						checked={ highlightWorst }
						onChange={ ( value ) =>
							setAttributes( { highlightWorst: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show the format switcher',
							'odds-aggregator'
						) }
						checked={ showSwitcher }
						onChange={ ( value ) =>
							setAttributes( { showSwitcher: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ eventId ? (
					<ServerSideRender
						block="odds-aggregator/odds-table"
						attributes={ attributes }
					/>
				) : (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Select an event in the block settings to show odds.',
							'odds-aggregator'
						) }
					</Notice>
				) }
			</div>
		</>
	);
}
