<?php
/**
 * Shared helpers for the end-to-end scenarios.
 *
 * @package SalamHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$state_file = getenv( 'SLH_MOCK_STATE' ) ?: sys_get_temp_dir() . '/slh-mock-state.json';
$GLOBALS['slh_failures'] = 0;
$GLOBALS['slh_passes']   = 0;

function slh_t_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['slh_passes']++;
		echo "  ✓ {$label}\n";
	} else {
		$GLOBALS['slh_failures']++;
		echo "  ✗ FAIL: {$label}\n";
	}
}

function slh_t_mock( $state_file ) {
	clearstatcache();
	return json_decode( (string) file_get_contents( $state_file ), true );
}

function slh_t_reset_mock( $state_file, array $fail = array() ) {
	file_put_contents( $state_file, json_encode( array( 'products' => new stdClass(), 'files' => new stdClass(), 'next_id' => 9000, 'requests' => array(), 'fail' => (object) $fail ) ) );
}

function slh_t_set_fail( $state_file, array $fail ) {
	$s         = slh_t_mock( $state_file );
	$s['fail'] = $fail;
	file_put_contents( $state_file, json_encode( $s ) );
}

/** Runs every pending SalamHub action that is due now (like WP-Cron would). */
function slh_t_run_queue() {
	$store = ActionScheduler::store();
	$ids   = $store->query_actions( array( 'group' => 'salamhub', 'status' => ActionScheduler_Store::STATUS_PENDING, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=', 'per_page' => 50, 'hook' => SLH_Queue::HOOK_PRODUCT ) );
	foreach ( $ids as $id ) {
		ActionScheduler::runner()->process_action( $id, 'e2e' );
	}
	return count( $ids );
}

function slh_t_pending_product_actions() {
	return (int) ActionScheduler::store()->query_actions( array( 'group' => 'salamhub', 'hook' => SLH_Queue::HOOK_PRODUCT, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' );
}

function slh_t_clear_queue() {
	as_unschedule_all_actions( SLH_Queue::HOOK_PRODUCT, null, 'salamhub' );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_slh_%' OR option_name LIKE '_transient_timeout_slh_%'" );
	wp_cache_flush();
}

function slh_t_image() {
	$upload = wp_upload_dir();
	$file   = $upload['path'] . '/slh-test-' . wp_generate_password( 6, false ) . '.jpg';
	$img    = imagecreatetruecolor( 400, 400 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 15, 110, 106 ) );
	imagejpeg( $img, $file );
	imagedestroy( $img );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'test', 'post_status' => 'inherit' ), $file );
	return $id;
}

function slh_t_product( array $props = array() ) {
	$p = new WC_Product_Simple();
	$p->set_name( isset( $props['name'] ) ? $props['name'] : 'عسل گون ۱ کیلویی' );
	$p->set_status( 'publish' );
	$p->set_regular_price( isset( $props['price'] ) ? $props['price'] : '150000' );
	$p->set_description( '<p>عسل طبیعی <strong>گون</strong></p><p>برداشت ۱۴۰۵</p>' );
	$p->set_short_description( 'عسل خالص' );
	$p->set_weight( isset( $props['weight'] ) ? $props['weight'] : '1.2' );
	$p->set_length( '10' );
	$p->set_width( '10' );
	$p->set_height( '15' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( isset( $props['stock'] ) ? $props['stock'] : 7 );
	if ( isset( $props['sku'] ) ) {
		$p->set_sku( $props['sku'] );
	}
	if ( ! isset( $props['no_image'] ) ) {
		$p->set_image_id( slh_t_image() );
	}
	SLH_Plugin::$suspend_hooks = true;
	$id = $p->save();
	SLH_Plugin::$suspend_hooks = false;
	return wc_get_product( $id );
}

function slh_t_last_log( $product_id ) {
	$r = SLH_Logger::query( array( 'object_id' => $product_id, 'per_page' => 1 ) );
	return $r['items'] ? $r['items'][0] : null;
}

function slh_t_requests( $state_file, $method, $pattern ) {
	return array_values( array_filter( slh_t_mock( $state_file )['requests'], function ( $r ) use ( $method, $pattern ) {
		return $r['method'] === $method && preg_match( $pattern, $r['path'] );
	} ) );
}

/** Runs every due SalamHub job (products and bulk planners), repeatedly, like WP-Cron would. */
function slh_t_run_all( $max_rounds = 50 ) {
	$total = 0;
	for ( $i = 0; $i < $max_rounds; $i++ ) {
		$ids = ActionScheduler::store()->query_actions( array( 'group' => 'salamhub', 'status' => ActionScheduler_Store::STATUS_PENDING, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=', 'per_page' => 200 ) );
		$ids = array_filter( $ids, function ( $id ) {
			return SLH_Queue::HOOK_MAINTENANCE !== ActionScheduler::store()->fetch_action( $id )->get_hook();
		} );
		if ( ! $ids ) {
			break;
		}
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, 'e2e' );
			++$total;
		}
	}
	return $total;
}

/** Resets everything SalamHub stores (disconnected, empty mock). */
function slh_t_fresh_start( $state_file ) {
	global $wpdb;
	update_option( 'woocommerce_currency', 'IRT' );
	update_option( 'woocommerce_weight_unit', 'kg' );
	update_option( 'woocommerce_dimension_unit', 'cm' );
	foreach ( array( SLH_Settings::OPTION, SLH_Settings::TOKEN_OPTION, SLH_Settings::CONNECTION_OPTION, SLH_Bulk::OPTION, SLH_Categories::MAP_OPTION, SLH_Categories::CACHE_OPTION, SLH_Categories::ATTR_OPTION, SLH_Price_Rules::OPTION, 'slh_pause_until', 'slh_orders_polled_at', 'slh_orders_poll_error', 'slh_webhook_last', 'slh_stock_pulled_at', 'slh_stock_pull_changed', 'slh_import_state', 'slh_reconcile_last', 'slh_notify' ) as $o ) {
		delete_option( $o );
	}
	$wpdb->query( 'DELETE FROM ' . SLH_Links::table() );
	$wpdb->query( 'DELETE FROM ' . SLH_Logger::table() );
	as_unschedule_all_actions( SLH_Bulk::HOOK_PLAN, null, 'salamhub' );
	foreach ( array( SLH_Order_Sync::HOOK_POLL, SLH_Order_Sync::HOOK_IMPORT, SLH_Order_Sync::HOOK_ACTION, SLH_Inventory::HOOK_PULL, SLH_Inventory::HOOK_DECREMENT, SLH_Importer::HOOK, SLH_Importer::HOOK_ONE, SLH_Reconcile::HOOK, SLH_Notifier::HOOK ) as $h ) {
		as_unschedule_all_actions( $h, null, 'salamhub' );
	}
	slh_t_clear_queue();
	slh_t_reset_mock( $state_file );
}

/**
 * Variable product with local attributes رنگ × سایز and one variation per combination.
 */
function slh_t_variable( $name, array $colors = array( 'قرمز', 'آبی' ), array $sizes = array( 'S', 'M' ), $price = '200000' ) {
	$p = new WC_Product_Variable();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$a1 = new WC_Product_Attribute();
	$a1->set_name( 'رنگ' );
	$a1->set_options( $colors );
	$a1->set_variation( true );
	$a1->set_visible( true );
	$a2 = new WC_Product_Attribute();
	$a2->set_name( 'سایز' );
	$a2->set_options( $sizes );
	$a2->set_variation( true );
	$a2->set_visible( true );
	$p->set_attributes( array( $a1, $a2 ) );
	$p->set_image_id( slh_t_image() );
	SLH_Plugin::$suspend_hooks = true;
	$id = $p->save();
	$i  = 0;
	foreach ( $colors as $c ) {
		foreach ( $sizes as $s ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $id );
			$v->set_attributes( array( sanitize_title( 'رنگ' ) => $c, sanitize_title( 'سایز' ) => $s ) );
			$v->set_regular_price( (string) ( (int) $price + 10000 * $i ) );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( 3 + $i );
			$v->set_status( 'publish' );
			$v->save();
			++$i;
		}
	}
	WC_Product_Variable::sync( $id );
	SLH_Plugin::$suspend_hooks = false;
	return wc_get_product( $id );
}
