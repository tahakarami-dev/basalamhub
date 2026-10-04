<?php
/**
 * Shared helpers for the end-to-end scenarios.
 *
 * @package BasalamHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$state_file = getenv( 'BSH_MOCK_STATE' ) ?: sys_get_temp_dir() . '/bsh-mock-state.json';
$GLOBALS['bsh_failures'] = 0;
$GLOBALS['bsh_passes']   = 0;

function bsh_t_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['bsh_passes']++;
		echo "  ✓ {$label}\n";
	} else {
		$GLOBALS['bsh_failures']++;
		echo "  ✗ FAIL: {$label}\n";
	}
}

function bsh_t_mock( $state_file ) {
	clearstatcache();
	return json_decode( (string) file_get_contents( $state_file ), true );
}

function bsh_t_reset_mock( $state_file, array $fail = array() ) {
	file_put_contents( $state_file, json_encode( array( 'products' => new stdClass(), 'files' => new stdClass(), 'next_id' => 9000, 'requests' => array(), 'fail' => (object) $fail ) ) );
}

function bsh_t_set_fail( $state_file, array $fail ) {
	$s         = bsh_t_mock( $state_file );
	$s['fail'] = $fail;
	file_put_contents( $state_file, json_encode( $s ) );
}

/** Runs every pending BasalamHub action that is due now (like WP-Cron would). */
function bsh_t_run_queue() {
	$store = ActionScheduler::store();
	$ids   = $store->query_actions( array( 'group' => 'basalamhub', 'status' => ActionScheduler_Store::STATUS_PENDING, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=', 'per_page' => 50, 'hook' => BSH_Queue::HOOK_PRODUCT ) );
	foreach ( $ids as $id ) {
		ActionScheduler::runner()->process_action( $id, 'e2e' );
	}
	return count( $ids );
}

function bsh_t_pending_product_actions() {
	return (int) ActionScheduler::store()->query_actions( array( 'group' => 'basalamhub', 'hook' => BSH_Queue::HOOK_PRODUCT, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' );
}

function bsh_t_clear_queue() {
	as_unschedule_all_actions( BSH_Queue::HOOK_PRODUCT, null, 'basalamhub' );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bsh_%' OR option_name LIKE '_transient_timeout_bsh_%'" );
	wp_cache_flush();
}

function bsh_t_image() {
	$upload = wp_upload_dir();
	$file   = $upload['path'] . '/bsh-test-' . wp_generate_password( 6, false ) . '.jpg';
	$img    = imagecreatetruecolor( 400, 400 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 15, 110, 106 ) );
	imagejpeg( $img, $file );
	imagedestroy( $img );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'test', 'post_status' => 'inherit' ), $file );
	return $id;
}

function bsh_t_product( array $props = array() ) {
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
		$p->set_image_id( bsh_t_image() );
	}
	BSH_Plugin::$suspend_hooks = true;
	$id = $p->save();
	BSH_Plugin::$suspend_hooks = false;
	return wc_get_product( $id );
}

function bsh_t_last_log( $product_id ) {
	$r = BSH_Logger::query( array( 'object_id' => $product_id, 'per_page' => 1 ) );
	return $r['items'] ? $r['items'][0] : null;
}

function bsh_t_requests( $state_file, $method, $pattern ) {
	return array_values( array_filter( bsh_t_mock( $state_file )['requests'], function ( $r ) use ( $method, $pattern ) {
		return $r['method'] === $method && preg_match( $pattern, $r['path'] );
	} ) );
}

/** Runs every due BasalamHub job (products and bulk planners), repeatedly, like WP-Cron would. */
function bsh_t_run_all( $max_rounds = 50 ) {
	$total = 0;
	for ( $i = 0; $i < $max_rounds; $i++ ) {
		$ids = ActionScheduler::store()->query_actions( array( 'group' => 'basalamhub', 'status' => ActionScheduler_Store::STATUS_PENDING, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=', 'per_page' => 200 ) );
		$ids = array_filter( $ids, function ( $id ) {
			return BSH_Queue::HOOK_MAINTENANCE !== ActionScheduler::store()->fetch_action( $id )->get_hook();
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

/** Resets everything BasalamHub stores (disconnected, empty mock). */
function bsh_t_fresh_start( $state_file ) {
	global $wpdb;
	update_option( 'woocommerce_currency', 'IRT' );
	update_option( 'woocommerce_weight_unit', 'kg' );
	update_option( 'woocommerce_dimension_unit', 'cm' );
	foreach ( array( BSH_Settings::OPTION, BSH_Settings::TOKEN_OPTION, BSH_Settings::CONNECTION_OPTION, BSH_Bulk::OPTION, BSH_Categories::MAP_OPTION, BSH_Categories::CACHE_OPTION, BSH_Categories::ATTR_OPTION, BSH_Price_Rules::OPTION, 'bsh_pause_until', 'bsh_orders_polled_at', 'bsh_orders_poll_error', 'bsh_webhook_last', 'bsh_stock_pulled_at', 'bsh_stock_pull_changed', 'bsh_import_state', 'bsh_reconcile_last', 'bsh_notify' ) as $o ) {
		delete_option( $o );
	}
	$wpdb->query( 'DELETE FROM ' . BSH_Links::table() );
	$wpdb->query( 'DELETE FROM ' . BSH_Logger::table() );
	as_unschedule_all_actions( BSH_Bulk::HOOK_PLAN, null, 'basalamhub' );
	foreach ( array( BSH_Order_Sync::HOOK_POLL, BSH_Order_Sync::HOOK_IMPORT, BSH_Order_Sync::HOOK_ACTION, BSH_Inventory::HOOK_PULL, BSH_Inventory::HOOK_DECREMENT, BSH_Importer::HOOK, BSH_Importer::HOOK_ONE, BSH_Reconcile::HOOK, BSH_Notifier::HOOK ) as $h ) {
		as_unschedule_all_actions( $h, null, 'basalamhub' );
	}
	bsh_t_clear_queue();
	bsh_t_reset_mock( $state_file );
}

/**
 * Variable product with local attributes رنگ × سایز and one variation per combination.
 */
function bsh_t_variable( $name, array $colors = array( 'قرمز', 'آبی' ), array $sizes = array( 'S', 'M' ), $price = '200000' ) {
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
	$p->set_image_id( bsh_t_image() );
	BSH_Plugin::$suspend_hooks = true;
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
	BSH_Plugin::$suspend_hooks = false;
	return wc_get_product( $id );
}
