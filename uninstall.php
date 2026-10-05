<?php
/**
 * Removes all BasalamHub data when the plugin is deleted from the Plugins screen.
 * Products on Basalam are never touched.
 *
 * @package BasalamHub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'bsh_settings', 'bsh_token', 'bsh_connection', 'bsh_db_version', 'bsh_last_sync_at', 'bsh_lock_worker', 'bsh_batch', 'bsh_basalam_categories', 'bsh_category_map', 'bsh_category_attributes', 'bsh_price_rules', 'bsh_pause_until', 'bsh_link_state', 'bsh_orders_polled_at', 'bsh_orders_poll_error', 'bsh_webhook_secret', 'bsh_webhook_last', 'bsh_stock_pulled_at', 'bsh_stock_pull_changed', 'bsh_import_state', 'bsh_reconcile_last', 'bsh_notify', 'bsh_sales', 'bsh_sales_ver', 'bsh_report_last' ) as $bsh_option ) {
	delete_option( $bsh_option );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bsh_links" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bsh_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bsh_remote_products" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_bsh_category_id','_bsh_preparation_days','_bsh_basalam_file_id','_bsh_basalam_file_sig','_bsh_variants','_bsh_safety_stock','_bsh_imported_from','_bsh_low_alert')" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bsh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bsh\\_%' OR option_name LIKE 'bsh\\_lock\\_%'" );

// Order meta (_bsh_parcel_id …) is kept on purpose: it is the order's history and does no harm.

$bsh_uploads = wp_upload_dir( null, false );
$bsh_tmp     = trailingslashit( $bsh_uploads['basedir'] ) . 'basalamhub-tmp';
if ( is_dir( $bsh_tmp ) ) {
	array_map( 'wp_delete_file', glob( $bsh_tmp . '/*' ) ?: array() );
	rmdir( $bsh_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'basalamhub' );
}
