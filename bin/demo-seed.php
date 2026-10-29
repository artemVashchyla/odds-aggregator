<?php
/**
 * Creates the demo page. Run with: wp eval-file bin/demo-seed.php
 *
 * @package OddsAggregator
 */

use OddsAggregator\Admin\Settings;
use OddsAggregator\Plugin;

$odds_aggregator_plugin = Plugin::instance();

if ( array() === $odds_aggregator_plugin->repository()->events() ) {
	$odds_aggregator_settings             = get_option( Settings::OPTION, array() );
	$odds_aggregator_settings             = is_array( $odds_aggregator_settings ) ? $odds_aggregator_settings : array();
	$odds_aggregator_settings['provider'] = 'mock';

	update_option( Settings::OPTION, $odds_aggregator_settings );
	$odds_aggregator_plugin->settings()->refresh();

	WP_CLI::warning( 'The configured data source returned no events; switched to sample data.' );
}

$odds_aggregator_events = $odds_aggregator_plugin->repository()->events();

if ( array() === $odds_aggregator_events ) {
	WP_CLI::error( 'No events available, demo page not created.' );
}

$odds_aggregator_page = get_page_by_path( 'odds-demo' );

if ( $odds_aggregator_page instanceof WP_Post ) {
	WP_CLI::success( 'Demo page: ' . get_permalink( $odds_aggregator_page ) );
	return;
}

$odds_aggregator_block = static function ( string $heading, array $attributes ): string {
	return '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $heading ) . '</h2><!-- /wp:heading -->'
		. '<!-- wp:odds-aggregator/odds-table ' . wp_json_encode( $attributes ) . ' /-->';
};

$odds_aggregator_first  = $odds_aggregator_events[0];
$odds_aggregator_second = $odds_aggregator_events[1] ?? $odds_aggregator_first;

$odds_aggregator_content = $odds_aggregator_block(
	$odds_aggregator_first->title() . ' — site defaults',
	array( 'eventId' => $odds_aggregator_first->id() )
)
	. $odds_aggregator_block(
		$odds_aggregator_second->title() . ' — fractional, Match Result only',
		array(
			'eventId' => $odds_aggregator_second->id(),
			'format'  => 'fractional',
			'markets' => array( '1x2' ),
		)
	)
	. $odds_aggregator_block(
		$odds_aggregator_first->title() . ' — American, no switcher, best price not highlighted',
		array(
			'eventId'       => $odds_aggregator_first->id(),
			'format'        => 'american',
			'showSwitcher'  => false,
			'highlightBest' => false,
		)
	);

$odds_aggregator_page_id = wp_insert_post(
	array(
		'post_title'   => 'Odds comparison demo',
		'post_name'    => 'odds-demo',
		'post_content' => $odds_aggregator_content,
		'post_status'  => 'publish',
		'post_type'    => 'page',
	),
	true
);

if ( is_wp_error( $odds_aggregator_page_id ) ) {
	WP_CLI::error( $odds_aggregator_page_id->get_error_message() );
}

WP_CLI::success( 'Demo page: ' . get_permalink( $odds_aggregator_page_id ) );
