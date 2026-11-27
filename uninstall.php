<?php
/**
 * Cleans up all plugin data on uninstall.
 *
 * @package MakeItStatic
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes plugin data for a single site.
 */
function mis_uninstall_site(): void {
	global $wpdb;

	delete_option( 'mis_settings' );
	delete_transient( 'mis_pending_publish' );
	delete_transient( 'mis_followup_publish' );

	wp_clear_scheduled_hook( 'mis_poll_jobs' );
	wp_clear_scheduled_hook( 'mis_run_publish' );

	// Table name is built from the prefix only; dbDelta-created, safe to drop.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mis_jobs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup; the table is derived from $wpdb->prefix only and dropping the plugin's own table is the expected behavior.
}

if ( is_multisite() ) {
	$mis_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $mis_site_ids as $mis_site_id ) {
		switch_to_blog( (int) $mis_site_id );
		mis_uninstall_site();
		restore_current_blog();
	}
} else {
	mis_uninstall_site();
}
