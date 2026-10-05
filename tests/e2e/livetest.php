<?php
/**
 * «تست با باسلام واقعی» against the mock: every step runs, reports, and changes only what it says.
 *
 *   BSH_MOCK_STATE=… wp eval-file tests/e2e/livetest.php
 *
 * @package BasalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' );
wp_set_current_user( 1 );
bsh_t_fresh_start( $state_file );
BSH_Settings::set_token( 'good-token' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287', 'orders_enabled' => 1, 'safety_stock' => '0' ) ) );

$states = function ( $res ) {
	return array_count_values( wp_list_pluck( $res['data']['lines'], 'state' ) );
};
$line   = function ( $res, $needle ) {
	foreach ( $res['data']['lines'] as $l ) {
		if ( false !== mb_strpos( $l['label'], $needle ) ) {
			return $l;
		}
	}
	return null;
};

echo "L1 Read (no booth changes)\n";
$before = count( bsh_t_mock( $state_file )['requests'] );
$r      = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'read' ) );
bsh_t_ok( ! empty( $r['success'] ) && 0 === $r['data']['failed'], 'read step: no failures' );
bsh_t_ok( $line( $r, 'غرفه' ) && 'ok' === $line( $r, 'غرفه' )['state'], 'booth found' );
bsh_t_ok( null !== $line( $r, 'دسته‌بندی‌های باسلام' ) && null !== $line( $r, 'ویژگی‌های دسته' ), 'categories and attributes checked' );
$writes = array_filter(
	array_slice( bsh_t_mock( $state_file )['requests'], $before ),
	function ( $q ) {
		return 'GET' !== $q['method'];
	}
);
bsh_t_ok( ! $writes, 'read step sends only GET requests' );
bsh_t_ok( false !== strpos( $r['data']['text'], '== read' ) && false === strpos( $r['data']['text'], 'good-token' ), 'report text has the step and never the token' );
BSH_Settings::set_token( 'bad-token' );
$bad = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'read' ) );
bsh_t_ok( 1 === $bad['data']['failed'] && 'fail' === $bad['data']['lines'][0]['state'] && false !== strpos( $bad['data']['lines'][0]['detail'], '401' ), 'bad token → one clear failure with HTTP 401' );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();

echo "L2 Upload\n";
$r = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'upload' ) );
bsh_t_ok( 0 === $r['data']['failed'] && false !== mb_strpos( $r['data']['lines'][0]['detail'], 'شناسه‌ی فایل' ), 'image uploaded, file id reported' );
bsh_t_ok( ! glob( get_temp_dir() . 'bsh-live-test-*' ), 'temporary image removed' );

echo "L3 Product\n";
$p = bsh_t_product( array( 'name' => 'محصول تست واقعی', 'price' => '120000', 'stock' => 9 ) );
$r = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'product', 'product_id' => $p->get_id() ) );
bsh_t_ok( 0 === $r['data']['failed'], 'product sent and read back without failures' );
bsh_t_ok( $line( $r, 'نوع ارسال' ) && false !== mb_strpos( $line( $r, 'نوع ارسال' )['detail'], 'ساخت' ), 'first run creates' );
bsh_t_ok( $line( $r, 'قیمت' ) && 'ok' === $line( $r, 'قیمت' )['state'] && false !== strpos( $line( $r, 'قیمت' )['detail'], '1200000' ), 'price matches in rial (120,000 toman → 1,200,000)' );
bsh_t_ok( $line( $r, 'موجودی' ) && 'ok' === $line( $r, 'موجودی' )['state'], 'stock matches' );
bsh_t_ok( $line( $r, 'نام' ) && 'ok' === $line( $r, 'نام' )['state'], 'name matches' );
$p->set_regular_price( '130000' );
BSH_Plugin::$suspend_hooks = true;
$p->save();
BSH_Plugin::$suspend_hooks = false;
$r2 = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'product', 'product_id' => $p->get_id() ) );
bsh_t_ok( 0 === $r2['data']['failed'] && false !== mb_strpos( $line( $r2, 'نوع ارسال' )['detail'], 'به‌روزرسانی' ) && 'ok' === $line( $r2, 'قیمت' )['state'], 'second run updates and the new price matches' );
$noimg = bsh_t_product( array( 'name' => 'بی‌عکس', 'no_image' => 1 ) );
$r3    = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'product', 'product_id' => $noimg->get_id() ) );
bsh_t_ok( 1 === $r3['data']['failed'] && false !== mb_strpos( $r3['data']['lines'][0]['detail'], 'failed' ), 'product without image → failure with the plugin’s own reason' );
$none = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'product' ) );
bsh_t_ok( 1 === $none['data']['failed'], 'no product chosen → refused' );

echo "L4 Hide\n";
$bid = (int) BSH_Links::get( 'product', $p->get_id() )->basalam_id;
$r   = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'hide', 'product_id' => $p->get_id() ) );
$mp  = bsh_t_mock( $state_file )['products'][ (string) $bid ];
bsh_t_ok( 0 === $r['data']['failed'] && 0 === (int) ( isset( $mp['inventory'] ) ? $mp['inventory'] : $mp['stock'] ), 'stock on Basalam set to 0' );
bsh_t_ok( 9 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'site stock untouched' );

echo "L5 Orders\n";
$r = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'orders' ) );
bsh_t_ok( 0 === $r['data']['failed'] && $line( $r, 'سفارش‌ها' ), 'no orders → explained, no failure' );
$s                     = bsh_t_mock( $state_file );
$pid                   = 88001;
$s['parcels']['88001'] = array(
	'id'                => $pid,
	'created_at'        => gmdate( 'Y-m-d\TH:i:s\Z', time() - 600 ),
	'total_items_price' => 1300000,
	'shipping_cost'     => 300000,
	'shipping_method'   => array( 'current' => array( 'id' => 3197, 'title' => 'پست پیشتاز' ) ),
	'status'            => array( 'id' => 3739, 'title' => 'سفارش جدید' ),
	'items'             => array( array( 'title' => 'محصول تست واقعی', 'price' => 1300000, 'quantity' => 1, 'product' => array( 'id' => $bid ), 'variation' => null ) ),
	'order'             => array(
		'id'         => 990001,
		'paid_at'    => gmdate( 'Y-m-d\TH:i:s\Z', time() - 500 ),
		'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 600 ),
		'customer'   => array(
			'recipient' => array( 'name' => 'نرگس جعفری', 'mobile' => '09121112233', 'postal_code' => '1234567890', 'postal_address' => 'شیراز، خیابان زند' ),
			'city'      => array( 'id' => 5, 'title' => 'شیراز', 'parent' => array( 'id' => 6, 'title' => 'فارس' ) ),
		),
	),
);
file_put_contents( $state_file, wp_json_encode( $s ) );
$r = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'orders' ) );
$o = $line( $r, '#88001' );
bsh_t_ok( $o && 'ok' === $o['state'] && false !== mb_strpos( $o['detail'], 'شیراز' ), 'new Basalam order imported into WooCommerce and summarised' );
bsh_t_ok( false === mb_strpos( $r['data']['text'], 'نرگس جعفری' ) && false === strpos( $r['data']['text'], '09121112233' ) && false !== mb_strpos( $o['detail'], 'ن***' ), 'customer name and phone masked in the report' );
$r2 = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'orders' ) );
bsh_t_ok( 1 === count( wc_get_orders( array( 'limit' => 5, 'return' => 'ids', 'status' => 'any', 'meta_key' => BSH_Order_Sync::META_PARCEL, 'meta_value' => '88001' ) ) ), 'running again does not duplicate the order' ); // phpcs:ignore

echo "L6 Guard and page\n";
wp_set_current_user( 0 );
$anon = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'read' ) );
wp_set_current_user( 1 );
bsh_t_ok( empty( $anon['success'] ), 'logged-out user is refused' );
$weird = bsh_t_ajax( 'bsh_live_test', array( 'step' => 'drop_tables' ) );
bsh_t_ok( empty( $weird['success'] ), 'unknown step is refused' );
ob_start();
BSH_App::render( 'live-test', 'basalamhub-livetest' );
$html = ob_get_clean();
bsh_t_ok( 5 === substr_count( $html, 'data-bsh-lt-run=' ) && false !== strpos( $html, 'BasalamHub ' . BSH_VERSION ), 'page: five steps and the environment header' );
bsh_t_ok( false !== strpos( $html, 'data-confirm="' . esc_attr__( 'یک تصویر آزمایشی در باسلام آپلود شود؟', 'basalamhub' ) ), 'steps that change something ask first' );

echo "\n{$GLOBALS['bsh_passes']} passed, {$GLOBALS['bsh_failures']} failed\n";
