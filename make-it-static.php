<?php
/**
 * Plugin Name:       Make it Static
 * Plugin URI:        https://github.com/driedel/make-it-static-wp-plugin
 * Description:       Publishes your WordPress site as a static site via an external Make it Static API service (scrapes, optimizes assets, uploads to S3, optionally invalidates CloudFront).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Danilo Riedel
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       make-it-static
 * Domain Path:       /languages
 *
 * @package MakeItStatic
 */

defined( 'ABSPATH' ) || exit;

define( 'MIS_VERSION', '1.0.0' );
define( 'MIS_PLUGIN_FILE', __FILE__ );
define( 'MIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MIS_PLUGIN_DIR . 'includes/class-mis-plugin.php';

/**
 * Runs on plugin activation: creates the jobs table and schedules the poller.
 */
function mis_activate(): void {
	if ( class_exists( 'MIS_Job_Store' ) ) {
		MIS_Job_Store::install();
	}
	if ( class_exists( 'MIS_Poller' ) ) {
		MIS_Poller::activate();
	}
}
register_activation_hook( __FILE__, 'mis_activate' );

/**
 * Runs on plugin deactivation: stops the poller and any pending publish event.
 */
function mis_deactivate(): void {
	if ( class_exists( 'MIS_Poller' ) ) {
		MIS_Poller::deactivate();
	}
	wp_clear_scheduled_hook( 'mis_run_publish' );
}
register_deactivation_hook( __FILE__, 'mis_deactivate' );

MIS_Plugin::instance();
