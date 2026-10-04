<?php
/**
 * App shell scenarios: dashboard activity data, products page filters, page rendering.
 *
 * @package SalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
slh_t_fresh_start( $state_file );
SLH_Settings::set_token( 'good-token' );
SLH_Admin::test_connection();

echo "\n[A-1] Activity chart data (site timezone)\n";
update_option( 'timezone_string', 'Asia/Tehran' );
$p   = slh_t_product( array( 'name' => 'نمودار', 'sku' => 'CHART-' . wp_generate_password( 4, false ) ) );
$now = new DateTimeImmutable( 'now', wp_timezone() );
// 23:50 Tehran yesterday is 20:20 UTC yesterday → must count for yesterday (local), not today.
$yesterday_late = $now->modify( '-1 day' )->setTime( 23, 50 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
// 00:30 Tehran today is 21:00 UTC *yesterday* → must count for today (local).
$today_early = $now->setTime( 0, 30 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
foreach ( array( array( $yesterday_late, 'product_updated', 'success' ), array( $today_early, 'product_created', 'success' ), array( $today_early, 'product_failed', 'error' ), array( $today_early, 'connected', 'success' ) ) as $r ) {
	$wpdb->insert( SLH_Logger::table(), array( 'created_at' => $r[0], 'level' => $r[2], 'event' => $r[1], 'object_type' => 'connected' === $r[1] ? 'connection' : 'product', 'object_id' => $p->get_id(), 'title' => 't', 'message' => 'm' ) );
}
$act = SLH_App::activity( 14 );
slh_t_ok( 14 === count( $act ), '14 days' );
$today = end( $act );
$yday  = $act[12];
slh_t_ok( $today['date'] === $now->format( 'Y-m-d' ), 'last bucket is today (local)' );
slh_t_ok( 1 === $today['ok'] && 1 === $today['error'], 'today: 1 success + 1 error (00:30 local counted as today)' );
slh_t_ok( 1 === $yday['ok'] && 0 === $yday['error'], 'yesterday: 23:50 local counted as yesterday' );
slh_t_ok( 0 === array_sum( wp_list_pluck( array_slice( $act, 0, 12 ), 'ok' ) ), 'non-product events are not counted' );
update_option( 'timezone_string', '' );

echo "\n[A-2] Products page filters\n";
$sku    = 'FIND-' . wp_generate_password( 5, false );
$rose   = 'گلاب کاشان ' . wp_generate_password( 5, false );
$a      = slh_t_product( array( 'name' => 'زعفران سرگل', 'sku' => $sku ) );
$b      = slh_t_product( array( 'name' => $rose ) );
SLH_Links::upsert( 'product', $a->get_id(), array( 'sync_status' => 'synced', 'basalam_id' => 777 ) );
SLH_Links::upsert( 'product', $b->get_id(), array( 'sync_status' => 'error' ) );
$r = SLH_App::products( array( 'search' => $sku ) );
slh_t_ok( 1 === $r['total'] && (int) $r['items'][0]->ID === $a->get_id(), 'search by SKU' );
$r = SLH_App::products( array( 'search' => $rose ) );
slh_t_ok( 1 === $r['total'] && 'error' === $r['items'][0]->sync_status, 'search by name, joined with link status' );
$r = SLH_App::products( array( 'search' => $rose, 'status' => 'unsent' ) );
slh_t_ok( 1 === $r['total'], 'error product without Basalam ID also shows under «ارسال‌نشده»' );
$r = SLH_App::products( array( 'search' => $sku, 'status' => 'error' ) );
slh_t_ok( 0 === $r['total'], 'status filter applies' );
$all = SLH_App::products( array() );
slh_t_ok( $all['counts'][''] >= $all['counts']['synced'] + $all['counts']['error'] && $all['counts']['synced'] >= 1, 'tab counts are consistent' );
$page2 = SLH_App::products( array( 'per_page' => 1, 'page' => 2 ) );
slh_t_ok( 1 === count( $page2['items'] ), 'pagination' );

echo "\n[A-3] Every app page renders without PHP notices\n";
wp_set_current_user( 1 );
set_current_screen( 'dashboard' );
$errors = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$errors ) {
	if ( false !== strpos( $file, 'salamhub' ) ) {
		$errors[] = "$str @ " . basename( $file ) . ":$line";
	}
	return false;
} );
foreach ( array_keys( SLH_App::pages() ) as $slug ) {
	$_GET['page'] = $slug;
	$views        = array( 'salamhub' => 'dashboard', 'salamhub-products' => 'products', 'salamhub-bulk' => 'bulk', 'salamhub-link' => 'link', 'salamhub-categories' => 'categories', 'salamhub-pricing' => 'pricing', 'salamhub-logs' => 'logs', 'salamhub-settings' => 'settings' );
	ob_start();
	SLH_App::render( $views[ $slug ], $slug );
	$html = ob_get_clean();
	slh_t_ok( false !== strpos( $html, 'data-slh-content' ) && false !== strpos( $html, 'is-active' ) && strlen( $html ) > 2000, "{$slug} renders inside the shell with an active menu item" );
}
restore_error_handler();
slh_t_ok( ! $errors, 'no PHP warnings/notices from SalamHub' . ( $errors ? ': ' . implode( ' | ', array_slice( $errors, 0, 3 ) ) : '' ) );
slh_t_ok( SLH_App::is_app_screen(), 'body class hook recognises app pages' );
$_GET['page'] = 'wc-settings';
slh_t_ok( ! SLH_App::is_app_screen(), 'other admin pages are untouched' );

echo "\n" . $GLOBALS['slh_passes'] . ' passed, ' . $GLOBALS['slh_failures'] . " failed\n";
if ( $GLOBALS['slh_failures'] ) {
	exit( 1 );
}
