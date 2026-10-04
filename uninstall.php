<?php
/**
 * Removes all SalamHub data when the plugin is deleted from the Plugins screen.
 * Products on Basalam are never touched.
 *
 * @package SalamHub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'slh_settings', 'slh_token', 'slh_connection', 'slh_db_version', 'slh_last_sync_at', 'slh_lock_worker', 'slh_batch', 'slh_basalam_categories', 'slh_category_map', 'slh_category_attributes', 'slh_price_rules', 'slh_pause_until' ) as $slh_option ) {
	delete_option( $slh_option );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}slh_links" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}slh_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_slh_category_id','_slh_preparation_days','_slh_basalam_file_id','_slh_basalam_file_sig')" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_slh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_slh\\_%'" );

$slh_uploads = wp_upload_dir( null, false );
$slh_tmp     = trailingslashit( $slh_uploads['basedir'] ) . 'salamhub-tmp';
if ( is_dir( $slh_tmp ) ) {
	array_map( 'wp_delete_file', glob( $slh_tmp . '/*' ) ?: array() );
	rmdir( $slh_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'salamhub' );
}
