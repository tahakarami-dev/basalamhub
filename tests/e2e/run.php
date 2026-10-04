<?php
/**
 * End-to-end scenarios against the mock Basalam server, inside a real WordPress +
 * WooCommerce install. Run with:
 *
 *   BSH_MOCK_STATE=/path/state.json php -S 127.0.0.1:8099 tests/mock-basalam/router.php &
 *   BSH_MOCK_STATE=/path/state.json wp eval-file tests/e2e/run.php
 *
 * (wp-config.php must define BSH_API_BASE as http://127.0.0.1:8099.)
 *
 * @package BasalamHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/helpers.php';

// ---------------------------------------------------------------------------
bsh_t_fresh_start( $state_file );

echo "\n[1] Not connected → clear Persian error, nothing sent\n";
$p = bsh_t_product();
BSH_Queue::enqueue_product( $p->get_id() );
bsh_t_ok( 'queued' === BSH_Links::get( 'product', $p->get_id() )->sync_status, 'status becomes «در صف» right after enqueue' );
bsh_t_run_queue();
$log = bsh_t_last_log( $p->get_id() );
bsh_t_ok( 'error' === BSH_Links::get( 'product', $p->get_id() )->sync_status, 'status becomes «خطا»' );
bsh_t_ok( $log && false !== strpos( $log->message, 'وصل نیست' ) && $log->suggestion, 'log says what happened and what to do' );
bsh_t_ok( 0 === count( bsh_t_requests( $state_file, 'POST', '#products#' ) ), 'no API call was made' );

echo "\n[2] Token encryption + connection test\n";
BSH_Settings::set_token( 'bad-token' );
$raw = get_option( BSH_Settings::TOKEN_OPTION );
bsh_t_ok( false === strpos( $raw, 'bad-token' ), 'token is not stored in plain text' );
bsh_t_ok( 'bad-token' === BSH_Settings::get_token(), 'token decrypts back' );
bsh_t_ok( false === strpos( BSH_Crypto::mask( 'abcdefgh1234' ), 'abcdefgh' ), 'masked token hides all but the last 4 chars' );
$r = BSH_Admin::test_connection();
bsh_t_ok( ! $r['ok'] && false !== strpos( $r['message'], 'توکن' ) && $r['suggestion'], 'invalid token → Persian error with a fix' );
bsh_t_ok( 'invalid' === BSH_Settings::connection()['status'], 'connection marked invalid' );
BSH_Settings::set_token( 'novendor-token' );
$r = BSH_Admin::test_connection();
bsh_t_ok( ! $r['ok'] && false !== strpos( $r['message'], 'غرفه ندارد' ), 'account without booth is explained' );
BSH_Settings::set_token( 'good-token' );
$r = BSH_Admin::test_connection();
bsh_t_ok( $r['ok'] && 555 === $r['vendor_id'] && false !== strpos( $r['message'], 'عسل طبیعی کوهپایه' ), 'valid token → booth name and ID' );
bsh_t_ok( BSH_Settings::is_connected(), 'is_connected() true' );

echo "\n[3] Missing category → blocked before any API call, retry works after fixing\n";
bsh_t_reset_mock( $state_file );
bsh_t_clear_queue();
BSH_Queue::enqueue_product( $p->get_id() );
bsh_t_run_queue();
$log = bsh_t_last_log( $p->get_id() );
bsh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'دسته' ), 'error explains the missing category' );
bsh_t_ok( 0 === count( bsh_t_requests( $state_file, 'POST', '#/products$#' ) ), 'nothing sent to Basalam' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287' ) ) );
bsh_t_ok( BSH_Queue::retry_from_log( $log ), 'retry from log re-queues' );
bsh_t_run_queue();
$link = BSH_Links::get( 'product', $p->get_id() );
bsh_t_ok( 'synced' === $link->sync_status && $link->basalam_id > 0, 'product created and linked' );
bsh_t_ok( 1 === (int) BSH_Logger::get( $log->id )->resolved, 'old error marked resolved' );

echo "\n[4] Payload correctness\n";
$mock = bsh_t_mock( $state_file );
$sent = $mock['products'][ $link->basalam_id ];
bsh_t_ok( 1500000 === $sent['primary_price'], 'price 150,000 Toman → 1,500,000 Rial' );
bsh_t_ok( 7 === $sent['stock'], 'stock 7' );
bsh_t_ok( 1200 === $sent['weight'] && 1200 === $sent['package_weight'], 'weight 1.2kg → 1200g' );
bsh_t_ok( array( 'length' => 10, 'width' => 10, 'height' => 15 ) == $sent['packaging_dimensions'], 'dimensions in cm' );
bsh_t_ok( 1287 === $sent['category_id'], 'default category used' );
bsh_t_ok( 'BSH-' . $p->get_id() === $sent['sku'], 'synthetic SKU for products without SKU' );
bsh_t_ok( ! empty( $sent['photo'] ) && isset( $mock['files'][ $sent['photo'] ] ), 'image uploaded and attached' );
bsh_t_ok( false === strpos( $sent['description'], '<' ) && false !== strpos( $sent['description'], "\n" ), 'description is plain text with line breaks' );
bsh_t_ok( 2976 === $sent['status'] && 3 === $sent['preparation_days'], 'status published, 3 preparation days' );

echo "\n[5] No change → no API call (hash)\n";
$before = count( bsh_t_mock( $state_file )['requests'] );
BSH_Queue::enqueue_product( $p->get_id() );
bsh_t_run_queue();
bsh_t_ok( count( bsh_t_mock( $state_file )['requests'] ) === $before, 'unchanged product makes zero requests' );
bsh_t_ok( 'synced' === BSH_Links::get( 'product', $p->get_id() )->sync_status, 'still «همگام»' );

echo "\n[6] Auto update after edit, only selected fields are sent\n";
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'sync_fields' => array( 'price', 'stock' ) ) ) );
$creates_before = count( bsh_t_requests( $state_file, 'POST', '#/products$#' ) );
$p = wc_get_product( $p->get_id() );
$p->set_regular_price( '200000' );
$p->set_description( 'متن جدید که نباید برود' );
$p->save(); // fires woocommerce_update_product → our hook.
bsh_t_ok( 1 === bsh_t_pending_product_actions(), 'saving the product queued exactly that product' );
bsh_t_run_queue();
$patches = bsh_t_requests( $state_file, 'PATCH', '#/v1/products/\d+#' );
$last    = end( $patches );
bsh_t_ok( $last && array( 'primary_price', 'stock' ) === array_keys( $last['body'] ), 'PATCH only contains price and stock' );
bsh_t_ok( 2000000 === $last['body']['primary_price'], 'new price sent' );
bsh_t_ok( count( bsh_t_requests( $state_file, 'POST', '#/products$#' ) ) === $creates_before, 'updated in place, no new product created' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'sync_fields' => array_keys( BSH_Settings::field_groups() ) ) ) );

echo "\n[7] Stock change from an order queues the product\n";
bsh_t_clear_queue();
wc_update_product_stock( $p, 3, 'set' );
bsh_t_ok( 1 === bsh_t_pending_product_actions(), 'stock change queued the product' );
bsh_t_run_queue();
$patches = bsh_t_requests( $state_file, 'PATCH', '#/v1/products/\d+#' );
bsh_t_ok( 3 === end( $patches )['body']['stock'], 'new stock 3 sent' );

echo "\n[8] Image dedupe\n";
$files_before = count( bsh_t_mock( $state_file )['files'] );
BSH_Queue::enqueue_product( $p->get_id(), true );
bsh_t_run_queue();
bsh_t_ok( count( bsh_t_mock( $state_file )['files'] ) === $files_before, 'forced update did not re-upload an unchanged image' );

echo "\n[9] Price guard\n";
$z = bsh_t_product( array( 'price' => '0', 'name' => 'محصول بی‌قیمت' ) );
BSH_Queue::enqueue_product( $z->get_id() );
bsh_t_run_queue();
$log = bsh_t_last_log( $z->get_id() );
bsh_t_ok( $log && false !== strpos( $log->reason, 'قیمت' ), 'zero price is never sent' );
bsh_t_ok( ! BSH_Links::get( 'product', $z->get_id() )->basalam_id, 'not created' );

echo "\n[10] Lost create response (504 after creating) → no duplicate\n";
bsh_t_set_fail( $state_file, array( 'POST /v1/vendors/*/products' => array( 'status' => 504, 'times' => 1, 'create' => true ) ) );
$sku = 'ARDE-' . wp_generate_password( 6, false );
$l   = bsh_t_product( array( 'name' => 'ارده کنجد', 'sku' => $sku ) );
BSH_Queue::enqueue_product( $l->get_id() );
bsh_t_run_queue();
bsh_t_ok( 'queued' === BSH_Links::get( 'product', $l->get_id() )->sync_status, 'server error → stays «در صف» for an automatic retry' );
$log = bsh_t_last_log( $l->get_id() );
bsh_t_ok( $log && 'warning' === $log->level && false !== strpos( $log->message, 'دقیقه' ), 'warning says when it will retry' );
// Make the backoff retry due now.
global $wpdb;
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 5 ), gmdate( 'Y-m-d H:i:s', time() - 5 ), BSH_Queue::HOOK_PRODUCT ) );
bsh_t_run_queue();
$same_sku = array_filter( bsh_t_mock( $state_file )['products'], function ( $x ) use ( $sku ) {
	return $sku === ( $x['sku'] ?? '' );
} );
bsh_t_ok( 1 === count( $same_sku ), 'exactly one product with that SKU exists on Basalam' );
bsh_t_ok( 'synced' === BSH_Links::get( 'product', $l->get_id() )->sync_status, 'recovered by SKU and linked' );

echo "\n[11] Product deleted on Basalam → unlink, explain, retry recreates\n";
$s = bsh_t_mock( $state_file );
$bid = (int) BSH_Links::get( 'product', $l->get_id() )->basalam_id;
unset( $s['products'][ $bid ] );
file_put_contents( $state_file, json_encode( $s ) );
BSH_Queue::enqueue_product( $l->get_id(), true );
bsh_t_run_queue();
$log = bsh_t_last_log( $l->get_id() );
bsh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'حذف شده' ), '404 explained as deleted on Basalam' );
bsh_t_ok( ! BSH_Links::get( 'product', $l->get_id() )->basalam_id, 'link cleared' );
BSH_Queue::retry_from_log( $log );
bsh_t_run_queue();
$new = BSH_Links::get( 'product', $l->get_id() );
bsh_t_ok( $new->basalam_id && (int) $new->basalam_id !== $bid && 'synced' === $new->sync_status, 'retry re-created it' );

echo "\n[12] Rate limit (429) → whole queue pauses, product keeps its place, no error\n";
bsh_t_set_fail( $state_file, array( 'PATCH /v1/products/*' => array( 'status' => 429, 'times' => 1 ) ) );
$l = wc_get_product( $l->get_id() );
$l->set_regular_price( '90000' );
$l->save();
bsh_t_run_queue();
$sys = BSH_Logger::query( array( 'object_type' => 'system', 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'rate_limited' === $sys->event && 'warning' === $sys->level, 'one system warning explains the pause' );
bsh_t_ok( 'queued' === BSH_Links::get( 'product', $l->get_id() )->sync_status, 'product still «در صف»' );
bsh_t_ok( (int) get_option( 'bsh_pause_until' ) > time(), 'queue paused' );
bsh_t_ok( 1 === bsh_t_pending_product_actions(), 'retry scheduled after the pause' );
bsh_t_ok( false === get_transient( 'bsh_attempt_product_' . $l->get_id() ), 'rate limit did not use up a retry attempt' );
$other = bsh_t_product( array( 'name' => 'نبات' ) );
BSH_Queue::enqueue_product( $other->get_id() );
$reqs_before = count( bsh_t_mock( $state_file )['requests'] );
bsh_t_run_queue();
bsh_t_ok( count( bsh_t_mock( $state_file )['requests'] ) === $reqs_before, 'other jobs wait during the pause (no requests)' );
delete_option( 'bsh_pause_until' );
bsh_t_clear_queue();

echo "\n[13] Validation error from Basalam (422) → Persian field names\n";
$v = bsh_t_product( array( 'price' => '50', 'name' => 'محصول ارزان' ) ); // 500 Rial < mock minimum.
BSH_Queue::enqueue_product( $v->get_id() );
bsh_t_run_queue();
$log = bsh_t_last_log( $v->get_id() );
bsh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'قیمت' ), '422 lists «قیمت» in Persian' );
bsh_t_ok( $log && false !== strpos( (string) $log->context, 'price too low' ), 'raw API message kept in technical details' );
bsh_t_ok( $log && false === strpos( (string) $log->context, 'good-token' ), 'token never written to logs' );

echo "\n[14] Worker lock: two jobs never run at the same time\n";
$owner = BSH_Lock::acquire( 'worker', 60 );
bsh_t_ok( false !== $owner, 'lock acquired' );
bsh_t_ok( false === BSH_Lock::acquire( 'worker', 60 ), 'second acquire fails while held' );
$w = bsh_t_product( array( 'name' => 'زعفران' ) );
BSH_Queue::enqueue_product( $w->get_id() );
$reqs_before = count( bsh_t_mock( $state_file )['requests'] );
bsh_t_run_queue();
bsh_t_ok( count( bsh_t_mock( $state_file )['requests'] ) === $reqs_before, 'job did not run while the lock was held' );
bsh_t_ok( 1 === bsh_t_pending_product_actions(), 'job was postponed, not lost' );
BSH_Lock::release( 'worker', $owner );
update_option( 'bsh_lock_worker', ( time() - 10 ) . '|stale' );
bsh_t_ok( false !== ( $o2 = BSH_Lock::acquire( 'worker', 60 ) ), 'a stale (expired) lock is taken over' );
BSH_Lock::release( 'worker', $o2 );
bsh_t_clear_queue();

echo "\n[15] Variable product → explained, not sent\n";
$var = new WC_Product_Variable();
$var->set_name( 'تیشرت' );
$var->set_status( 'publish' );
BSH_Plugin::$suspend_hooks = true;
$var_id = $var->save();
BSH_Plugin::$suspend_hooks = false;
BSH_Queue::enqueue_product( $var_id );
bsh_t_run_queue();
$log = bsh_t_last_log( $var_id );
bsh_t_ok( $log && false !== strpos( $log->reason, 'متغیر' ), 'variable product gets a clear message' );

echo "\n[16] Health counters\n";
bsh_t_clear_queue();
BSH_Queue::schedule_recurring();
$st = BSH_Queue::stats();
bsh_t_ok( 0 === $st['past_due'] && 0 === $st['pending'], 'daily maintenance job is not reported as stuck or pending work' );
BSH_Queue::enqueue_product( $w->get_id() );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s WHERE hook = %s AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 3600 ), BSH_Queue::HOOK_PRODUCT ) );
$st = BSH_Queue::stats();
bsh_t_ok( 1 === $st['past_due'] && 1 === $st['pending'], 'a job waiting an hour is reported (broken WP-Cron)' );
bsh_t_clear_queue();

echo "\n[17] Self-heal: «در صف» without a job is re-queued\n";
bsh_t_clear_queue();
BSH_Links::upsert( 'product', $w->get_id(), array( 'sync_status' => 'queued' ) );
$wpdb->update( BSH_Links::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'wc_id' => $w->get_id() ) );
bsh_t_ok( 0 === bsh_t_pending_product_actions(), 'orphan: queued but no job' );
bsh_t_ok( BSH_Queue::heal() >= 1 && 1 <= bsh_t_pending_product_actions(), 'heal() re-queued it' );
bsh_t_ok( 0 === BSH_Queue::heal(), 'second heal() does nothing (no duplicate jobs)' );
bsh_t_clear_queue();

echo "\n[18] Helpers\n";
bsh_t_ok( '۱۴۰۵/۰۷/۱۲' === bsh_fa_digits( vsprintf( '%04d/%02d/%02d', bsh_gregorian_to_jalali( 2026, 10, 4 ) ) ), '2026-10-04 → ۱۴۰۵/۰۷/۱۲' );
bsh_t_ok( '۱٬۵۰۰٬۰۰۰' === bsh_fa_number( 1500000 ), 'Persian number formatting' );

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
if ( $GLOBALS['bsh_failures'] ) {
	exit( 1 );
}
