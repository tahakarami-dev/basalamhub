<?php
/**
 * Moving a SalamHub (≤ 0.6.1) install to BasalamHub: builds the old layout by hand, runs
 * the migration and checks that everything arrived, nothing was lost and nothing doubled.
 *
 *   BSH_MOCK_STATE=… wp eval-file tests/e2e/migrate.php
 *
 * @package BasalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
bsh_t_fresh_start( $state_file );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();

$p = $wpdb->prefix;

// Old layout: copy today's tables to slh_*, rename options/meta/jobs back, legacy token.
$product = bsh_t_product( array( 'name' => 'محصول مهاجرت ' . wp_generate_password( 4, false ), 'no_image' => 1 ) );
BSH_Links::upsert( 'product', $product->get_id(), array( 'basalam_id' => 424242, 'sync_status' => 'synced' ) );
update_post_meta( $product->get_id(), '_bsh_safety_stock', 3 );
$order = wc_create_order();
$order->update_meta_data( '_bsh_parcel_id', 777123 );
$order->save();
BSH_Logger::log( array( 'level' => 'error', 'event' => 'x', 'object_type' => 'product', 'object_id' => $product->get_id(), 'title' => 't', 'message' => 'm', 'retry_hook' => 'bsh_sync_product', 'retry_args' => array( 'product_id' => $product->get_id() ) ) );
as_schedule_single_action( time() + 3600, 'bsh_sync_product', array( 'product_id' => $product->get_id() ), BSH_Queue::GROUP );
$links_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_links" );
$logs_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_logs" );

foreach ( array( 'links', 'logs', 'remote_products' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$p}slh_{$t}" );
	$create = $wpdb->get_row( "SHOW CREATE TABLE {$p}bsh_{$t}", ARRAY_N );
	$wpdb->query( str_replace( "{$p}bsh_{$t}", "{$p}slh_{$t}", $create[1] ) );
	$wpdb->query( "INSERT INTO {$p}slh_{$t} SELECT * FROM {$p}bsh_{$t}" );
	$wpdb->query( "DROP TABLE {$p}bsh_{$t}" );
}
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bsh\\_%'" ) as $o ) {
	if ( 'bsh_migrated_from_salamhub' === $o ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $o ) );
		continue;
	}
	$wpdb->update( $wpdb->options, array( 'option_name' => 'slh_' . substr( $o, 4 ) ), array( 'option_name' => $o ) );
}
$key = hash( 'sha256', 'salamhub|' . wp_salt( 'auth' ), true );
$n   = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
$wpdb->update( $wpdb->options, array( 'option_value' => 'slh1s:' . base64_encode( $n . sodium_crypto_secretbox( 'good-token', $n, $key ) ) ), array( 'option_name' => 'slh_token' ) );
foreach ( array( $wpdb->postmeta, "{$p}wc_orders_meta" ) as $t ) {
	foreach ( $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$t} WHERE meta_key LIKE '\\_bsh\\_%'" ) as $k ) {
		$wpdb->update( $t, array( 'meta_key' => '_slh_' . substr( $k, 5 ) ), array( 'meta_key' => $k ) );
	}
}
foreach ( $wpdb->get_col( "SELECT DISTINCT hook FROM {$p}actionscheduler_actions WHERE hook LIKE 'bsh\\_%'" ) as $h ) {
	$wpdb->update( "{$p}actionscheduler_actions", array( 'hook' => 'slh_' . substr( $h, 4 ) ), array( 'hook' => $h ) );
}
$wpdb->update( "{$p}actionscheduler_groups", array( 'slug' => 'salamhub' ), array( 'slug' => BSH_Queue::GROUP ) );
$wpdb->query( "UPDATE {$p}slh_logs SET retry_hook = 'slh_sync_product' WHERE retry_hook = 'bsh_sync_product'" );
wp_cache_flush();

echo "M1 Old layout in place\n";
bsh_t_ok( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'bsh\\_%'" ) && false !== get_option( 'slh_token' ) && false !== get_option( 'slh_db_version' ), 'only slh_ options' );
bsh_t_ok( 0 === strpos( (string) get_option( 'slh_token' ), 'slh1s:' ), 'token in the old format' );

echo "M2 Migration\n";
bsh_t_ok( true === BSH_Migrate::maybe_run(), 'ran' );
wp_cache_flush();
bsh_t_ok( 'good-token' === BSH_Settings::get_token() && 0 === strpos( (string) get_option( 'bsh_token' ), 'bsh1s:' ), 'token readable and re-encrypted in the new format' );
bsh_t_ok( BSH_Settings::is_connected(), 'still connected' );
bsh_t_ok( $links_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_links" ), 'every link moved' );
bsh_t_ok( $logs_before + 1 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_logs" ), 'every log row moved (+ the migration note)' );
bsh_t_ok( 424242 === (int) BSH_Links::get( 'product', $product->get_id() )->basalam_id, 'product still linked to its Basalam product' );
bsh_t_ok( 3 === (int) get_post_meta( $product->get_id(), '_bsh_safety_stock', true ), 'product meta moved' );
bsh_t_ok( 777123 === (int) wc_get_order( $order->get_id() )->get_meta( '_bsh_parcel_id' ), 'order meta moved (HPOS)' );
bsh_t_ok( $order->get_id() === BSH_Order_Sync::find_order( 777123 ), 'parcel still recognized → no duplicate import' );
bsh_t_ok( as_has_scheduled_action( 'bsh_sync_product', array( 'product_id' => $product->get_id() ), BSH_Queue::GROUP ), 'queued job kept under the new hook and group' );
bsh_t_ok( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$p}actionscheduler_actions WHERE hook LIKE 'slh\\_%'" ), 'no job left on old hooks' );
bsh_t_ok( (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_logs WHERE retry_hook = 'bsh_sync_product'" ), 'retry buttons point to the new hooks' );
bsh_t_ok( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'slh\\_%'" ), 'no slh_ option left' );
$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$p}slh%'" );
bsh_t_ok( ! array_filter( $tables, function ( $t ) use ( $p ) { return 0 === strpos( $t, $p . 'slh_' ); } ), 'old tables gone' );

echo "M3 Runs once\n";
$links_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_links" ); // find_order() above rebuilt the order's link from its meta.
bsh_t_ok( false === BSH_Migrate::maybe_run(), 'second call does nothing' );
bsh_t_ok( $links_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bsh_links" ), 'nothing doubled' );

echo "M4 Old webhook URL keeps working\n";
$req = new WP_REST_Request( 'POST', '/salamhub/v1/basalam-webhook' );
$req->set_param( 'key', BSH_Order_Sync::webhook_secret() );
bsh_t_ok( 200 === rest_do_request( $req )->get_status(), 'salamhub/v1 route answers' );

echo "M5 Old synthetic SKU still matches when linking\n";
$row = (object) array( 'basalam_id' => 1, 'sku' => 'SLH-' . $product->get_id(), 'title' => 'x', 'variants' => null );
$idx = new ReflectionMethod( 'BSH_Linker', 'wc_index' );
$idx->setAccessible( true );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bsh_links WHERE wc_id = %d", $product->get_id() ) );
$res = BSH_Linker::match_one( $row, $idx->invoke( null ) );
bsh_t_ok( 'certain' === $res['status'] && $product->get_id() === (int) $res['wc_id'], 'SLH-{id} → «قطعی»' );

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
