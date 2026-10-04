<?php
/**
 * Phase 4 scenarios: Basalam orders ⇄ WooCommerce, two-way stock, safety stock.
 *
 *   BSH_MOCK_STATE=… wp eval-file tests/e2e/phase4.php
 *
 * @package BasalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' ); // No mail server in the test box.
bsh_t_fresh_start( $state_file );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287', 'orders_enabled' => 1, 'safety_stock' => '0', 'stock_reference' => 'site' ) ) );

/** Adds a ParcelResponse to the mock. */
function bsh_t_parcel( $state_file, array $over = array() ) {
	static $n = 0;
	++$n;
	$id = isset( $over['id'] ) ? $over['id'] : 70000 + $n + wp_rand( 0, 9999 ) * 10;
	$p  = array_replace_recursive(
		array(
			'id'                => $id,
			'created_at'        => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 + $n ),
			'total_items_price' => 0,
			'shipping_cost'     => 350000,
			'shipping_method'   => array( 'current' => array( 'id' => 3197, 'title' => 'پست پیشتاز' ) ),
			'status'            => array( 'id' => 3739, 'title' => 'سفارش جدید' ),
			'items'             => array(),
			'order'             => array(
				'id'         => 500000 + $n,
				'paid_at'    => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3500 ),
				'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 ),
				'customer'   => array(
					'recipient' => array( 'name' => 'مریم احمدی', 'mobile' => '09120000000', 'postal_code' => '1234567890', 'postal_address' => 'تهران، خیابان آزادی، کوچه ۵', 'house_number' => '12', 'house_unit' => '3' ),
					'city'      => array( 'id' => 1, 'title' => 'تهران', 'parent' => array( 'id' => 2, 'title' => 'استان تهران' ) ),
					'user'      => array( 'id' => 9, 'name' => 'مریم' ),
				),
			),
		),
		$over
	);
	if ( isset( $over['items'] ) ) {
		$p['items'] = $over['items'];
	}
	if ( ! isset( $over['total_items_price'] ) ) {
		$sum = 0;
		foreach ( $p['items'] as $it ) {
			$sum += $it['price'] * $it['quantity'];
		}
		$p['total_items_price'] = $sum;
	}
	$s = bsh_t_mock( $state_file );
	$s['parcels'][ (string) $id ] = $p;
	file_put_contents( $state_file, json_encode( $s ) );
	return $id;
}

function bsh_t_parcel_status( $state_file, $id, $status, $title = '' ) {
	$s = bsh_t_mock( $state_file );
	$s['parcels'][ (string) $id ]['status'] = array( 'id' => $status, 'title' => $title );
	file_put_contents( $state_file, json_encode( $s ) );
}

function bsh_t_poll() {
	BSH_Order_Sync::poll_now();
	return bsh_t_run_all();
}

function bsh_t_orders_for( $parcel_id ) {
	return wc_get_orders( array( 'limit' => 100, 'return' => 'ids', 'status' => 'any', 'meta_key' => BSH_Order_Sync::META_PARCEL, 'meta_value' => (string) $parcel_id ) ); // phpcs:ignore
}

// A linked product on Basalam, stock 7, price 150,000 Toman.
$product = bsh_t_product( array( 'name' => 'عسل سفارش ' . wp_generate_password( 4, false ), 'stock' => 7 ) );
BSH_Queue::enqueue_product( $product->get_id() );
bsh_t_run_all();
$link = BSH_Links::get( 'product', $product->get_id() );
$bid  = (int) $link->basalam_id;

echo "O1 New Basalam order is imported once, linked to the site product\n";
$pid1 = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 1, 'title' => 'عسل', 'quantity' => 2, 'weight' => 1000, 'price' => 1500000, 'product' => array( 'id' => $bid, 'name' => 'عسل' ), 'variation' => null ) ) ) );
bsh_t_poll();
$ids = bsh_t_orders_for( $pid1 );
bsh_t_ok( 1 === count( $ids ), 'exactly one WooCommerce order' );
$order = wc_get_order( $ids[0] );
bsh_t_ok( 'basalamhub' === $order->get_created_via(), 'created via basalamhub (tag)' );
bsh_t_ok( 'processing' === $order->get_status(), 'new parcel → processing' );
bsh_t_ok( 'مریم' === $order->get_billing_first_name() && 'احمدی' === $order->get_billing_last_name(), 'customer name split' );
bsh_t_ok( 'تهران' === $order->get_shipping_city() && 'IR' === $order->get_shipping_country() && false !== mb_strpos( $order->get_shipping_address_2(), 'پلاک 12' ) && 'استان تهران' === $order->get_shipping_state(), 'address mapped' );
bsh_t_ok( '09120000000' === $order->get_billing_phone(), 'mobile mapped' );
$items = array_values( $order->get_items() );
bsh_t_ok( 1 === count( $items ) && $product->get_id() === $items[0]->get_product_id() && 2 === $items[0]->get_quantity(), 'item linked to site product, qty 2' );
bsh_t_ok( 300000.0 === (float) $items[0]->get_total(), 'line total in Toman (2 × 1,500,000 Rial = 300,000 T)' );
bsh_t_ok( 335000.0 === (float) $order->get_total(), 'order total includes shipping (35,000 T)' );
bsh_t_ok( 5 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'site stock reduced 7 → 5' );
bsh_t_ok( (int) $order->get_meta( '_bsh_parcel_status' ) === 3739, 'Basalam status stored' );
bsh_t_ok( BSH_Links::get_by_basalam( 'order', $pid1 ) && (int) BSH_Links::get_by_basalam( 'order', $pid1 )->wc_id === $order->get_id(), 'links row order ↔ parcel' );
$mock = bsh_t_mock( $state_file );
bsh_t_ok( 5 === (int) $mock['products'][ $bid ]['stock'], 'new stock (5) pushed back to Basalam' );

echo "O2 Never duplicated: second poll, direct import, lost links row\n";
bsh_t_poll();
bsh_t_ok( 1 === count( bsh_t_orders_for( $pid1 ) ), 'second poll: still one order' );
bsh_t_ok( 'exists' === BSH_Order_Sync::import( $pid1 ), 'direct import says exists' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BSH_Links::table() . " WHERE object_type = 'order' AND basalam_id = %d", $pid1 ) ); // phpcs:ignore
bsh_t_ok( 'exists' === BSH_Order_Sync::import( $pid1 ), 'links row lost: order meta still prevents a duplicate' );
bsh_t_ok( BSH_Links::get_by_basalam( 'order', $pid1 ), 'links row restored from meta' );
bsh_t_ok( 1 === count( bsh_t_orders_for( $pid1 ) ), 'still one order' );
bsh_t_ok( 5 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'stock not reduced twice' );

echo "O3 Unknown product: order still created, warning with suggestion\n";
$pid3 = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 2, 'title' => 'محصول ناشناس', 'quantity' => 1, 'weight' => 100, 'price' => 200000, 'product' => array( 'id' => 123456 ), 'variation' => null ) ) ) );
bsh_t_poll();
$o3 = wc_get_order( bsh_t_orders_for( $pid3 )[0] );
bsh_t_ok( $o3 && 1 === count( $o3->get_items() ), 'order created with the unlinked line' );
$log = BSH_Logger::query( array( 'object_type' => 'order', 'object_id' => $o3->get_id(), 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'warning' === $log->level && false !== mb_strpos( $log->reason, 'محصول ناشناس' ) && $log->suggestion, 'warning names the product and suggests linking' );

echo "O4 Already-cancelled parcel is skipped and not fetched again\n";
$pid4 = bsh_t_parcel( $state_file, array( 'status' => array( 'id' => 3067, 'title' => 'لغو' ), 'items' => array( array( 'id' => 3, 'title' => 'x', 'quantity' => 1, 'weight' => 1, 'price' => 10000, 'product' => array( 'id' => $bid ), 'variation' => null ) ) ) );
bsh_t_poll();
bsh_t_ok( 0 === count( bsh_t_orders_for( $pid4 ) ), 'no order for a cancelled parcel' );
$before = count( bsh_t_requests( $state_file, 'GET', '#/v1/vendor-parcels/' . $pid4 . '$#' ) );
bsh_t_poll();
bsh_t_ok( count( bsh_t_requests( $state_file, 'GET', '#/v1/vendor-parcels/' . $pid4 . '$#' ) ) === $before, 'not fetched again' );

echo "O5 Basalam status change → WooCommerce status\n";
bsh_t_parcel_status( $state_file, $pid3, 3740, 'مشکل گزارش شده' );
bsh_t_poll();
$o3 = wc_get_order( $o3->get_id() );
bsh_t_ok( 'on-hold' === $o3->get_status(), 'problem reported → on-hold' );
$log = BSH_Logger::query( array( 'object_type' => 'order', 'object_id' => $o3->get_id(), 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'warning' === $log->level && 'order_status_remote' === $log->event, 'attention warning logged' );
bsh_t_parcel_status( $state_file, $pid3, 3067, 'لغو' );
bsh_t_poll();
bsh_t_ok( 'cancelled' === wc_get_order( $o3->get_id() )->get_status(), 'cancelled on Basalam → cancelled' );
$reqs_before = count( bsh_t_requests( $state_file, 'GET', '#^/v1/vendor-parcels$#' ) );
bsh_t_poll();
$last = bsh_t_requests( $state_file, 'GET', '#^/v1/vendor-parcels$#' );
$refresh = array_filter( array_slice( $last, $reqs_before ), function ( $r ) { return false !== strpos( $r['query'], 'ids=' ); } );
$asked = array();
foreach ( $refresh as $r ) {
	parse_str( $r['query'], $q );
	$asked = array_merge( $asked, explode( ',', $q['ids'] ) );
}
bsh_t_ok( ! in_array( (string) $pid3, $asked, true ), 'final orders are no longer refreshed' );

echo "O6 Confirm order on Basalam (set-preparation)\n";
bsh_t_ok( BSH_Order_Sync::do_action( $pid1, 'confirm', array() ), 'confirm succeeded' );
bsh_t_ok( 3237 === (int) bsh_t_mock( $state_file )['parcels'][ $pid1 ]['status']['id'], 'Basalam status is preparation' );
bsh_t_ok( 3237 === (int) wc_get_order( $order->get_id() )->get_meta( '_bsh_parcel_status' ), 'order meta updated' );

echo "O7 Completed in WooCommerce without shipping method → note, nothing sent\n";
$posted_before = count( bsh_t_requests( $state_file, 'POST', '#set-posted#' ) );
$o = wc_get_order( $order->get_id() );
$o->update_status( 'completed' );
bsh_t_run_all();
bsh_t_ok( count( bsh_t_requests( $state_file, 'POST', '#set-posted#' ) ) === $posted_before, 'no set-posted without method' );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 1 ) );
bsh_t_ok( $notes && false !== mb_strpos( $notes[0]->content, 'روش ارسال' ), 'order note explains what to do' );

echo "O8 Completed with method + tracking → set-posted\n";
$o = wc_get_order( $order->get_id() );
BSH_Plugin::$suspend_hooks = true;
$o->set_status( 'processing' );
$o->save();
BSH_Plugin::$suspend_hooks = false;
$o = wc_get_order( $order->get_id() );
$o->update_meta_data( '_bsh_shipping_method', 3197 );
$o->update_meta_data( '_bsh_tracking_code', '123456789012345678901234' );
$o->save();
$o->update_status( 'completed' );
bsh_t_run_all();
$posted = bsh_t_requests( $state_file, 'POST', '#/v1/vendor-parcels/' . $pid1 . '/set-posted#' );
bsh_t_ok( 1 === count( $posted ) && 3197 === (int) $posted[0]['body']['shipping_method'] && '123456789012345678901234' === $posted[0]['body']['tracking_code'], 'set-posted sent with method and tracking' );
bsh_t_ok( 3238 === (int) wc_get_order( $order->get_id() )->get_meta( '_bsh_parcel_status' ), 'order meta = posted' );
bsh_t_ok( 'completed' === wc_get_order( $order->get_id() )->get_status(), 'stays completed' );

echo "O9 Basalam refuses (422) → order note + error log with retry\n";
$pid9 = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 4, 'title' => 'عسل', 'quantity' => 1, 'weight' => 1, 'price' => 1500000, 'product' => array( 'id' => $bid ), 'variation' => null ) ) ) );
bsh_t_poll();
$o9 = wc_get_order( bsh_t_orders_for( $pid9 )[0] );
bsh_t_parcel_status( $state_file, $pid9, 3195, 'رضایت' ); // Already delivered on Basalam, we haven't polled yet.
bsh_t_ok( false === BSH_Order_Sync::do_action( $pid9, 'confirm', array() ), 'confirm refused' );
$log = BSH_Logger::query( array( 'object_type' => 'order', 'object_id' => $o9->get_id(), 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'error' === $log->level && 'bsh_parcel_action' === $log->retry_hook && $log->suggestion, 'error log with suggestion and retry' );
bsh_t_ok( 'error' === BSH_Links::get( 'order', $o9->get_id() )->sync_status, 'order link marked error' );
bsh_t_ok( false !== mb_strpos( BSH_Links::get( 'order', $o9->get_id() )->last_error, 'وضعیت' ), 'message talks about the order status, not product data' );
bsh_t_poll();
bsh_t_ok( 'completed' === wc_get_order( $o9->get_id() )->get_status() && 'synced' === BSH_Links::get( 'order', $o9->get_id() )->sync_status, 'next poll: delivered → completed, stale error cleared' );

echo "O10 Import failure → «سفارش جاافتاده», then retry fixes it\n";
$pid10 = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 5, 'title' => 'عسل', 'quantity' => 1, 'weight' => 1, 'price' => 1500000, 'product' => array( 'id' => $bid ), 'variation' => null ) ) ) );
bsh_t_set_fail( $state_file, array( 'GET /v1/vendor-parcels/*' => array( 'status' => 403, 'times' => 1 ) ) );
bsh_t_poll();
bsh_t_ok( 0 === count( bsh_t_orders_for( $pid10 ) ), 'not imported' );
bsh_t_ok( 1 === BSH_Order_Sync::missing_count(), 'missing count = 1' );
$log = BSH_Logger::query( array( 'object_type' => 'parcel', 'object_id' => $pid10, 'per_page' => 1 ) )['items'][0];
bsh_t_ok( $log && 'error' === $log->level && 'bsh_import_parcel' === $log->retry_hook, 'parcel error logged with retry' );
bsh_t_ok( BSH_Queue::retry_from_log( $log ), 'retry from log queued' );
bsh_t_run_all();
bsh_t_ok( 1 === count( bsh_t_orders_for( $pid10 ) ), 'imported on retry' );
bsh_t_ok( 0 === BSH_Order_Sync::missing_count(), 'missing count back to 0' );

echo "O11 Temporary failure retries by itself (no error log)\n";
$pid11 = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 6, 'title' => 'عسل', 'quantity' => 1, 'weight' => 1, 'price' => 1500000, 'product' => array( 'id' => $bid ), 'variation' => null ) ) ) );
bsh_t_set_fail( $state_file, array( 'GET /v1/vendor-parcels/*' => array( 'status' => 503, 'times' => 1 ) ) );
bsh_t_poll();
bsh_t_ok( 0 === count( bsh_t_orders_for( $pid11 ) ) && 0 === BSH_Order_Sync::missing_count(), 'not yet, not counted as missing' );
$retry = as_get_scheduled_actions( array( 'hook' => BSH_Order_Sync::HOOK_IMPORT, 'args' => array( 'parcel_id' => $pid11 ), 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
bsh_t_ok( 1 === count( $retry ), 'retry scheduled' );
ActionScheduler::runner()->process_action( reset( $retry ), 'e2e' );
bsh_t_ok( 1 === count( bsh_t_orders_for( $pid11 ) ), 'imported on the retry' );

echo "O12 Line-total prices are detected\n";
$pid12 = bsh_t_parcel(
	$state_file,
	array(
		'items'             => array( array( 'id' => 7, 'title' => 'عسل', 'quantity' => 3, 'weight' => 1, 'price' => 4500000, 'product' => array( 'id' => $bid ), 'variation' => null ) ),
		'total_items_price' => 4500000,
	)
);
bsh_t_poll();
$o12 = wc_get_order( bsh_t_orders_for( $pid12 )[0] );
bsh_t_ok( 450000.0 === (float) array_values( $o12->get_items() )[0]->get_total(), 'price used as line total (3 × 150,000 T = 450,000 T)' );

echo "O13 Rate limit on the list pauses everything\n";
delete_option( 'bsh_pause_until' );
bsh_t_set_fail( $state_file, array( 'GET /v1/vendor-parcels' => array( 'status' => 429, 'times' => 1, 'headers' => array( 'Retry-After' => '120' ) ) ) );
BSH_Order_Sync::handle_poll();
bsh_t_ok( (int) get_option( 'bsh_pause_until' ) >= time() + 25, 'global pause set (Retry-After)' );
bsh_t_ok( (bool) get_option( 'bsh_orders_poll_error' ), 'poll error remembered for the page' );
delete_option( 'bsh_pause_until' );
BSH_Order_Sync::handle_poll();
bsh_t_ok( ! get_option( 'bsh_orders_poll_error' ), 'cleared after a good poll' );

echo "O14 Webhook only triggers a poll, with the secret\n";
$req = new WP_REST_Request( 'POST', '/basalamhub/v1/basalam-webhook' );
$req->set_param( 'key', 'wrong' );
bsh_t_ok( 403 === rest_do_request( $req )->get_status(), 'wrong key → 403' );
as_unschedule_all_actions( BSH_Order_Sync::HOOK_POLL, array( 'now' => 1 ), 'basalamhub' );
$req->set_param( 'key', BSH_Order_Sync::webhook_secret() );
bsh_t_ok( 200 === rest_do_request( $req )->get_status(), 'right key → 200' );
bsh_t_ok( as_has_scheduled_action( BSH_Order_Sync::HOOK_POLL, array( 'now' => 1 ), 'basalamhub' ), 'poll queued' );
bsh_t_ok( false !== strpos( BSH_Order_Sync::webhook_url(), 'key=' ), 'webhook URL carries the key' );

echo "O15 Orders turned off → recurring poll removed\n";
BSH_Order_Sync::schedule();
bsh_t_ok( (bool) as_next_scheduled_action( BSH_Order_Sync::HOOK_POLL, array(), 'basalamhub' ), 'recurring poll scheduled while on' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'orders_enabled' => 0 ) ) );
bsh_t_ok( ! as_next_scheduled_action( BSH_Order_Sync::HOOK_POLL, array(), 'basalamhub' ), 'removed when off' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'orders_enabled' => 1 ) ) );
bsh_t_ok( (bool) as_next_scheduled_action( BSH_Order_Sync::HOOK_POLL, array(), 'basalamhub' ), 'back when on' );

echo "O16 Variable product: Basalam variant → the right WooCommerce variation\n";
$tee = bsh_t_variable( 'تیشرت سفارش ' . wp_generate_password( 4, false ) );
BSH_Queue::enqueue_product( $tee->get_id() );
bsh_t_run_all();
$tee_bid = (int) BSH_Links::get( 'product', $tee->get_id() )->basalam_id;
$vmap    = BSH_Product_Sync::variant_map( $tee );
$vid     = array_keys( $vmap )[1];
$stock_v = wc_get_product( $vid )->get_stock_quantity();
$pid16   = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 9, 'title' => 'تیشرت', 'quantity' => 1, 'weight' => 1, 'price' => 2100000, 'product' => array( 'id' => $tee_bid ), 'variation' => array( 'id' => $vmap[ $vid ]['id'] ) ) ) ) );
bsh_t_poll();
$o16 = wc_get_order( bsh_t_orders_for( $pid16 )[0] );
$li  = array_values( $o16->get_items() )[0];
bsh_t_ok( $vid === $li->get_variation_id() && $tee->get_id() === $li->get_product_id(), 'line item is the matching variation' );
bsh_t_ok( $stock_v - 1 === wc_get_product( $vid )->get_stock_quantity(), 'that variation’s stock reduced' );

echo "S1 Safety stock\n";
$sp = bsh_t_product( array( 'name' => 'زعفران ' . wp_generate_password( 4, false ), 'stock' => 10 ) );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'safety_stock' => '2' ) ) );
$mapped = ( new BSH_Product_Mapper() )->map( $sp );
bsh_t_ok( 8 === $mapped['payload']['stock'], 'global safety 2: 10 → 8 on Basalam' );
$sp->update_meta_data( BSH_Inventory::META_SAFETY, 0 );
$sp->save();
bsh_t_ok( 10 === ( new BSH_Product_Mapper() )->map( wc_get_product( $sp->get_id() ) )['payload']['stock'], 'product override 0: 10' );
$sp->update_meta_data( BSH_Inventory::META_SAFETY, 15 );
$sp->save();
bsh_t_ok( 0 === ( new BSH_Product_Mapper() )->map( wc_get_product( $sp->get_id() ) )['payload']['stock'], 'never negative' );
$sp->delete_meta_data( BSH_Inventory::META_SAFETY );
$sp->set_manage_stock( false );
$sp->save();
bsh_t_ok( (int) BSH_Settings::get( 'unmanaged_stock' ) === ( new BSH_Product_Mapper() )->map( wc_get_product( $sp->get_id() ) )['payload']['stock'], 'unmanaged stock not reduced by safety' );

echo "S2 Safety stock applies to orders' push-back too\n";
BSH_Queue::enqueue_product( $product->get_id(), true );
bsh_t_run_all();
$local = wc_get_product( $product->get_id() )->get_stock_quantity();
bsh_t_ok( max( 0, $local - 2 ) === (int) bsh_t_mock( $state_file )['products'][ $bid ]['stock'], 'Basalam = site − 2' );

echo "S3 Basalam as reference: no stock push, hourly pull\n";
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'stock_reference' => 'basalam', 'safety_stock' => '0' ) ) );
bsh_t_ok( (bool) as_next_scheduled_action( BSH_Inventory::HOOK_PULL, array( 'page' => 1 ), 'basalamhub' ), 'hourly pull scheduled' );
bsh_t_ok( ! in_array( 'stock', BSH_Inventory::push_groups(), true ), 'stock left out of updates' );
$s = bsh_t_mock( $state_file );
$s['products'][ $bid ]['stock'] = 40;
file_put_contents( $state_file, json_encode( $s ) );
$p = wc_get_product( $product->get_id() );
$p->set_stock_quantity( 3 );
$p->save();
bsh_t_run_all();
bsh_t_ok( 40 === (int) bsh_t_mock( $state_file )['products'][ $bid ]['stock'], 'site edit did not overwrite Basalam stock' );
$pulled = 0;
BSH_Inventory::pull_now();
bsh_t_run_all();
bsh_t_ok( 40 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'site stock = Basalam stock (40)' );
bsh_t_ok( (bool) get_option( 'bsh_stock_pulled_at' ), 'pull time recorded' );
$before = count( bsh_t_requests( $state_file, 'PATCH', '#/v1/products/' . $bid . '$#' ) );
bsh_t_run_all();
bsh_t_ok( count( bsh_t_requests( $state_file, 'PATCH', '#/v1/products/' . $bid . '$#' ) ) === $before, 'pull did not echo back to Basalam' );

$s = bsh_t_mock( $state_file );
foreach ( $s['products'][ $tee_bid ]['variants'] as $i => $v ) {
	$s['products'][ $tee_bid ]['variants'][ $i ]['stock'] = 20 + $i;
}
file_put_contents( $state_file, json_encode( $s ) );
BSH_Inventory::pull_now();
bsh_t_run_all();
$ok = true;
foreach ( BSH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) ) as $wvid => $row ) {
	foreach ( bsh_t_mock( $state_file )['products'][ $tee_bid ]['variants'] as $rv ) {
		if ( (int) $rv['id'] === (int) $row['id'] && (int) $rv['stock'] !== wc_get_product( $wvid )->get_stock_quantity() ) {
			$ok = false;
		}
	}
}
bsh_t_ok( $ok, 'each variation’s stock pulled from its Basalam variant' );

echo "S4 Basalam as reference: a site sale subtracts from Basalam's number\n";
$s = bsh_t_mock( $state_file );
$s['products'][ $bid ]['stock'] = 38; // Someone sold 2 on Basalam meanwhile.
file_put_contents( $state_file, json_encode( $s ) );
$site_order = wc_create_order();
$site_order->add_product( wc_get_product( $product->get_id() ), 3 );
$site_order->calculate_totals();
$site_order->update_status( 'processing' );
bsh_t_run_all();
bsh_t_ok( 35 === (int) bsh_t_mock( $state_file )['products'][ $bid ]['stock'], 'Basalam 38 − 3 = 35 (not overwritten with site stock)' );
bsh_t_ok( 35 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'site mirrors Basalam (35)' );

echo "S5 Basalam as reference: Basalam orders are not subtracted twice\n";
$pid_s5 = bsh_t_parcel( $state_file, array( 'items' => array( array( 'id' => 8, 'title' => 'عسل', 'quantity' => 1, 'weight' => 1, 'price' => 1500000, 'product' => array( 'id' => $bid ), 'variation' => null ) ) ) );
$dec_before = count( bsh_t_requests( $state_file, 'GET', '#^/v1/products/' . $bid . '$#' ) );
bsh_t_poll();
bsh_t_ok( count( bsh_t_requests( $state_file, 'GET', '#^/v1/products/' . $bid . '$#' ) ) === $dec_before, 'no decrement job for a Basalam order' );
bsh_t_ok( 35 === (int) bsh_t_mock( $state_file )['products'][ $bid ]['stock'], 'Basalam stock untouched by import' );

BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'stock_reference' => 'site' ) ) );
bsh_t_ok( ! as_next_scheduled_action( BSH_Inventory::HOOK_PULL, array( 'page' => 1 ), 'basalamhub' ), 'pull unscheduled when site is reference again' );

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
