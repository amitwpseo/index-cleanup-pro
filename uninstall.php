<?php
/**
 * Removes plugin data on delete, only if the user opted in.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
$icp_settings = get_option( 'icp_settings', array() );
if ( empty( $icp_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}icp_log" );  // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}icp_urls" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}icp_rules" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}icp_404" ); // phpcs:ignore
delete_option( 'icp_rules_cache' );
delete_option( 'icp_retest_state' );
delete_option( 'icp_last_retest' );
delete_option( 'icp_search_blocked' );
delete_option( 'icp_settings' );
delete_option( 'icp_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_icp\_%' OR option_name LIKE '\_transient\_timeout\_icp\_%'" ); // phpcs:ignore
wp_clear_scheduled_hook( 'icp_daily_cleanup' );
wp_clear_scheduled_hook( 'icp_weekly_retest' );
wp_clear_scheduled_hook( 'icp_retest_tick' );
