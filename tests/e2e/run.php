<?php
/**
 * End-to-end scenarios against the mock Basalam server, inside a real WordPress +
 * WooCommerce install. Run with:
 *
 *   SLH_MOCK_STATE=/path/state.json php -S 127.0.0.1:8099 tests/mock-basalam/router.php &
 *   SLH_MOCK_STATE=/path/state.json wp eval-file tests/e2e/run.php
 *
 * (wp-config.php must define SLH_API_BASE as http://127.0.0.1:8099.)
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

// ---------------------------------------------------------------------------
update_option( 'woocommerce_currency', 'IRT' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_dimension_unit', 'cm' );
delete_option( SLH_Settings::OPTION );
delete_option( SLH_Settings::TOKEN_OPTION );
delete_option( SLH_Settings::CONNECTION_OPTION );
global $wpdb;
$wpdb->query( 'DELETE FROM ' . SLH_Links::table() );
$wpdb->query( 'DELETE FROM ' . SLH_Logger::table() );
slh_t_clear_queue();
slh_t_reset_mock( $state_file );

echo "\n[1] Not connected → clear Persian error, nothing sent\n";
$p = slh_t_product();
SLH_Queue::enqueue_product( $p->get_id() );
slh_t_ok( 'queued' === SLH_Links::get( 'product', $p->get_id() )->sync_status, 'status becomes «در صف» right after enqueue' );
slh_t_run_queue();
$log = slh_t_last_log( $p->get_id() );
slh_t_ok( 'error' === SLH_Links::get( 'product', $p->get_id() )->sync_status, 'status becomes «خطا»' );
slh_t_ok( $log && false !== strpos( $log->message, 'وصل نیست' ) && $log->suggestion, 'log says what happened and what to do' );
slh_t_ok( 0 === count( slh_t_requests( $state_file, 'POST', '#products#' ) ), 'no API call was made' );

echo "\n[2] Token encryption + connection test\n";
SLH_Settings::set_token( 'bad-token' );
$raw = get_option( SLH_Settings::TOKEN_OPTION );
slh_t_ok( false === strpos( $raw, 'bad-token' ), 'token is not stored in plain text' );
slh_t_ok( 'bad-token' === SLH_Settings::get_token(), 'token decrypts back' );
slh_t_ok( false === strpos( SLH_Crypto::mask( 'abcdefgh1234' ), 'abcdefgh' ), 'masked token hides all but the last 4 chars' );
$r = SLH_Admin::test_connection();
slh_t_ok( ! $r['ok'] && false !== strpos( $r['message'], 'توکن' ) && $r['suggestion'], 'invalid token → Persian error with a fix' );
slh_t_ok( 'invalid' === SLH_Settings::connection()['status'], 'connection marked invalid' );
SLH_Settings::set_token( 'novendor-token' );
$r = SLH_Admin::test_connection();
slh_t_ok( ! $r['ok'] && false !== strpos( $r['message'], 'غرفه ندارد' ), 'account without booth is explained' );
SLH_Settings::set_token( 'good-token' );
$r = SLH_Admin::test_connection();
slh_t_ok( $r['ok'] && 555 === $r['vendor_id'] && false !== strpos( $r['message'], 'عسل طبیعی کوهپایه' ), 'valid token → booth name and ID' );
slh_t_ok( SLH_Settings::is_connected(), 'is_connected() true' );

echo "\n[3] Missing category → blocked before any API call, retry works after fixing\n";
slh_t_reset_mock( $state_file );
slh_t_clear_queue();
SLH_Queue::enqueue_product( $p->get_id() );
slh_t_run_queue();
$log = slh_t_last_log( $p->get_id() );
slh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'دسته' ), 'error explains the missing category' );
slh_t_ok( 0 === count( slh_t_requests( $state_file, 'POST', '#/products$#' ) ), 'nothing sent to Basalam' );
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'default_category_id' => '1287' ) ) );
slh_t_ok( SLH_Queue::retry_from_log( $log ), 'retry from log re-queues' );
slh_t_run_queue();
$link = SLH_Links::get( 'product', $p->get_id() );
slh_t_ok( 'synced' === $link->sync_status && $link->basalam_id > 0, 'product created and linked' );
slh_t_ok( 1 === (int) SLH_Logger::get( $log->id )->resolved, 'old error marked resolved' );

echo "\n[4] Payload correctness\n";
$mock = slh_t_mock( $state_file );
$sent = $mock['products'][ $link->basalam_id ];
slh_t_ok( 1500000 === $sent['primary_price'], 'price 150,000 Toman → 1,500,000 Rial' );
slh_t_ok( 7 === $sent['stock'], 'stock 7' );
slh_t_ok( 1200 === $sent['weight'] && 1200 === $sent['package_weight'], 'weight 1.2kg → 1200g' );
slh_t_ok( array( 'length' => 10, 'width' => 10, 'height' => 15 ) == $sent['packaging_dimensions'], 'dimensions in cm' );
slh_t_ok( 1287 === $sent['category_id'], 'default category used' );
slh_t_ok( 'SLH-' . $p->get_id() === $sent['sku'], 'synthetic SKU for products without SKU' );
slh_t_ok( ! empty( $sent['photo'] ) && isset( $mock['files'][ $sent['photo'] ] ), 'image uploaded and attached' );
slh_t_ok( false === strpos( $sent['description'], '<' ) && false !== strpos( $sent['description'], "\n" ), 'description is plain text with line breaks' );
slh_t_ok( 2976 === $sent['status'] && 3 === $sent['preparation_days'], 'status published, 3 preparation days' );

echo "\n[5] No change → no API call (hash)\n";
$before = count( slh_t_mock( $state_file )['requests'] );
SLH_Queue::enqueue_product( $p->get_id() );
slh_t_run_queue();
slh_t_ok( count( slh_t_mock( $state_file )['requests'] ) === $before, 'unchanged product makes zero requests' );
slh_t_ok( 'synced' === SLH_Links::get( 'product', $p->get_id() )->sync_status, 'still «همگام»' );

echo "\n[6] Auto update after edit, only selected fields are sent\n";
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'sync_fields' => array( 'price', 'stock' ) ) ) );
$creates_before = count( slh_t_requests( $state_file, 'POST', '#/products$#' ) );
$p = wc_get_product( $p->get_id() );
$p->set_regular_price( '200000' );
$p->set_description( 'متن جدید که نباید برود' );
$p->save(); // fires woocommerce_update_product → our hook.
slh_t_ok( 1 === slh_t_pending_product_actions(), 'saving the product queued exactly that product' );
slh_t_run_queue();
$patches = slh_t_requests( $state_file, 'PATCH', '#/v1/products/\d+#' );
$last    = end( $patches );
slh_t_ok( $last && array( 'primary_price', 'stock' ) === array_keys( $last['body'] ), 'PATCH only contains price and stock' );
slh_t_ok( 2000000 === $last['body']['primary_price'], 'new price sent' );
slh_t_ok( count( slh_t_requests( $state_file, 'POST', '#/products$#' ) ) === $creates_before, 'updated in place, no new product created' );
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'sync_fields' => array_keys( SLH_Settings::field_groups() ) ) ) );

echo "\n[7] Stock change from an order queues the product\n";
slh_t_clear_queue();
wc_update_product_stock( $p, 3, 'set' );
slh_t_ok( 1 === slh_t_pending_product_actions(), 'stock change queued the product' );
slh_t_run_queue();
$patches = slh_t_requests( $state_file, 'PATCH', '#/v1/products/\d+#' );
slh_t_ok( 3 === end( $patches )['body']['stock'], 'new stock 3 sent' );

echo "\n[8] Image dedupe\n";
$files_before = count( slh_t_mock( $state_file )['files'] );
SLH_Queue::enqueue_product( $p->get_id(), true );
slh_t_run_queue();
slh_t_ok( count( slh_t_mock( $state_file )['files'] ) === $files_before, 'forced update did not re-upload an unchanged image' );

echo "\n[9] Price guard\n";
$z = slh_t_product( array( 'price' => '0', 'name' => 'محصول بی‌قیمت' ) );
SLH_Queue::enqueue_product( $z->get_id() );
slh_t_run_queue();
$log = slh_t_last_log( $z->get_id() );
slh_t_ok( $log && false !== strpos( $log->reason, 'قیمت' ), 'zero price is never sent' );
slh_t_ok( ! SLH_Links::get( 'product', $z->get_id() )->basalam_id, 'not created' );

echo "\n[10] Lost create response (504 after creating) → no duplicate\n";
slh_t_set_fail( $state_file, array( 'POST /v1/vendors/*/products' => array( 'status' => 504, 'times' => 1, 'create' => true ) ) );
$sku = 'ARDE-' . wp_generate_password( 6, false );
$l   = slh_t_product( array( 'name' => 'ارده کنجد', 'sku' => $sku ) );
SLH_Queue::enqueue_product( $l->get_id() );
slh_t_run_queue();
slh_t_ok( 'queued' === SLH_Links::get( 'product', $l->get_id() )->sync_status, 'server error → stays «در صف» for an automatic retry' );
$log = slh_t_last_log( $l->get_id() );
slh_t_ok( $log && 'warning' === $log->level && false !== strpos( $log->message, 'دقیقه' ), 'warning says when it will retry' );
// Make the backoff retry due now.
global $wpdb;
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 5 ), gmdate( 'Y-m-d H:i:s', time() - 5 ), SLH_Queue::HOOK_PRODUCT ) );
slh_t_run_queue();
$same_sku = array_filter( slh_t_mock( $state_file )['products'], function ( $x ) use ( $sku ) {
	return $sku === ( $x['sku'] ?? '' );
} );
slh_t_ok( 1 === count( $same_sku ), 'exactly one product with that SKU exists on Basalam' );
slh_t_ok( 'synced' === SLH_Links::get( 'product', $l->get_id() )->sync_status, 'recovered by SKU and linked' );

echo "\n[11] Product deleted on Basalam → unlink, explain, retry recreates\n";
$s = slh_t_mock( $state_file );
$bid = (int) SLH_Links::get( 'product', $l->get_id() )->basalam_id;
unset( $s['products'][ $bid ] );
file_put_contents( $state_file, json_encode( $s ) );
SLH_Queue::enqueue_product( $l->get_id(), true );
slh_t_run_queue();
$log = slh_t_last_log( $l->get_id() );
slh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'حذف شده' ), '404 explained as deleted on Basalam' );
slh_t_ok( ! SLH_Links::get( 'product', $l->get_id() )->basalam_id, 'link cleared' );
SLH_Queue::retry_from_log( $log );
slh_t_run_queue();
$new = SLH_Links::get( 'product', $l->get_id() );
slh_t_ok( $new->basalam_id && (int) $new->basalam_id !== $bid && 'synced' === $new->sync_status, 'retry re-created it' );

echo "\n[12] Rate limit (429) → waits, no error\n";
slh_t_set_fail( $state_file, array( 'PATCH /v1/products/*' => array( 'status' => 429, 'times' => 1 ) ) );
$l = wc_get_product( $l->get_id() );
$l->set_regular_price( '90000' );
$l->save();
slh_t_run_queue();
$log = slh_t_last_log( $l->get_id() );
slh_t_ok( $log && 'warning' === $log->level && 'queued' === SLH_Links::get( 'product', $l->get_id() )->sync_status, '429 → warning + still queued' );
slh_t_ok( 1 === slh_t_pending_product_actions(), 'retry scheduled' );
slh_t_clear_queue();

echo "\n[13] Validation error from Basalam (422) → Persian field names\n";
$v = slh_t_product( array( 'price' => '50', 'name' => 'محصول ارزان' ) ); // 500 Rial < mock minimum.
SLH_Queue::enqueue_product( $v->get_id() );
slh_t_run_queue();
$log = slh_t_last_log( $v->get_id() );
slh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'قیمت' ), '422 lists «قیمت» in Persian' );
slh_t_ok( $log && false !== strpos( (string) $log->context, 'price too low' ), 'raw API message kept in technical details' );
slh_t_ok( $log && false === strpos( (string) $log->context, 'good-token' ), 'token never written to logs' );

echo "\n[14] Worker lock: two jobs never run at the same time\n";
$owner = SLH_Lock::acquire( 'worker', 60 );
slh_t_ok( false !== $owner, 'lock acquired' );
slh_t_ok( false === SLH_Lock::acquire( 'worker', 60 ), 'second acquire fails while held' );
$w = slh_t_product( array( 'name' => 'زعفران' ) );
SLH_Queue::enqueue_product( $w->get_id() );
$reqs_before = count( slh_t_mock( $state_file )['requests'] );
slh_t_run_queue();
slh_t_ok( count( slh_t_mock( $state_file )['requests'] ) === $reqs_before, 'job did not run while the lock was held' );
slh_t_ok( 1 === slh_t_pending_product_actions(), 'job was postponed, not lost' );
SLH_Lock::release( 'worker', $owner );
update_option( 'slh_lock_worker', ( time() - 10 ) . '|stale' );
slh_t_ok( false !== ( $o2 = SLH_Lock::acquire( 'worker', 60 ) ), 'a stale (expired) lock is taken over' );
SLH_Lock::release( 'worker', $o2 );
slh_t_clear_queue();

echo "\n[15] Variable product → explained, not sent\n";
$var = new WC_Product_Variable();
$var->set_name( 'تیشرت' );
$var->set_status( 'publish' );
SLH_Plugin::$suspend_hooks = true;
$var_id = $var->save();
SLH_Plugin::$suspend_hooks = false;
SLH_Queue::enqueue_product( $var_id );
slh_t_run_queue();
$log = slh_t_last_log( $var_id );
slh_t_ok( $log && false !== strpos( $log->reason, 'متغیر' ), 'variable product gets a clear message' );

echo "\n[16] Health counters\n";
slh_t_clear_queue();
SLH_Queue::schedule_recurring();
$st = SLH_Queue::stats();
slh_t_ok( 0 === $st['past_due'] && 0 === $st['pending'], 'daily maintenance job is not reported as stuck or pending work' );
SLH_Queue::enqueue_product( $w->get_id() );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s WHERE hook = %s AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 3600 ), SLH_Queue::HOOK_PRODUCT ) );
$st = SLH_Queue::stats();
slh_t_ok( 1 === $st['past_due'] && 1 === $st['pending'], 'a job waiting an hour is reported (broken WP-Cron)' );
slh_t_clear_queue();

echo "\n[17] Self-heal: «در صف» without a job is re-queued\n";
slh_t_clear_queue();
SLH_Links::upsert( 'product', $w->get_id(), array( 'sync_status' => 'queued' ) );
$wpdb->update( SLH_Links::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'wc_id' => $w->get_id() ) );
slh_t_ok( 0 === slh_t_pending_product_actions(), 'orphan: queued but no job' );
slh_t_ok( SLH_Queue::heal() >= 1 && 1 <= slh_t_pending_product_actions(), 'heal() re-queued it' );
slh_t_ok( 0 === SLH_Queue::heal(), 'second heal() does nothing (no duplicate jobs)' );
slh_t_clear_queue();

echo "\n[18] Helpers\n";
slh_t_ok( '۱۴۰۵/۰۷/۱۲' === slh_fa_digits( vsprintf( '%04d/%02d/%02d', slh_gregorian_to_jalali( 2026, 10, 4 ) ) ), '2026-10-04 → ۱۴۰۵/۰۷/۱۲' );
slh_t_ok( '۱٬۵۰۰٬۰۰۰' === slh_fa_number( 1500000 ), 'Persian number formatting' );

echo "\n" . $GLOBALS['slh_passes'] . ' passed, ' . $GLOBALS['slh_failures'] . " failed\n";
if ( $GLOBALS['slh_failures'] ) {
	exit( 1 );
}
