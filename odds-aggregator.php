<?php
/**
 * Plugin Name:       Odds Aggregator
 * Plugin URI:
 * Description:       Live odds comparison from multiple bookmakers.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Author:
 * License:           GPL-2.0-or-later
 * Text Domain:       odds-aggregator
 *
 * @package OddsAggregator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ODDS_AGGREGATOR_VERSION', '1.0.0' );
define( 'ODDS_AGGREGATOR_FILE', __FILE__ );
define( 'ODDS_AGGREGATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ODDS_AGGREGATOR_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader only if dependencies have been installed.
if ( ! file_exists( ODDS_AGGREGATOR_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Odds Aggregator: run "composer install" in the plugin directory.', 'odds-aggregator' )
				. '</p></div>';
		}
	);
	return;
}

require_once ODDS_AGGREGATOR_PATH . 'vendor/autoload.php';

add_action(
	'plugins_loaded',
	static function (): void {
		\OddsAggregator\Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( \OddsAggregator\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \OddsAggregator\Plugin::class, 'deactivate' ) );
