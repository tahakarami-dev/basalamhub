<?php
/**
 * v0.8 features: two-channel sales, low-stock alerts, weekly report.
 *
 *   BSH_MOCK_STATE=… wp eval-file tests/e2e/features.php
 *
 * @package BasalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'bsh_notify_api_base',
	function () {
		return 'http://127.0.0.1:8099';
	}
);
bsh_t_fresh_start( $state_file );
delete_option( BSH_Sales::OPTION );
delete_option( BSH_Report::OPTION );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287', 'low_stock_alert' => 1, 'low_stock_threshold' => '3', 'safety_stock' => '0' ) ) );

// Earlier suites' orders would mix into the numbers: count only what this suite creates.
$wpdb->query( "UPDATE {$wpdb->prefix}wc_orders SET status = 'wc-cancelled' WHERE type = 'shop_order'" ); // phpcs:ignore
BSH_Sales::bust();

$sfx = wp_generate_password( 4, false );

/** An order of one product, on a given channel and day. */
function bsh_t_sale( WC_Product $product, $qty, $channel, $days_ago, $status = 'processing', $shipping = 0 ) {
	$o = wc_create_order( array( 'created_via' => 'basalam' === $channel ? 'basalamhub' : 'checkout' ) );
	$o->add_product( $product, $qty );
	if ( $shipping ) {
		$s = new WC_Order_Item_Shipping();
		$s->set_method_title( 'پست' );
		$s->set_total( $shipping );
		$o->add_item( $s );
	}
	$o->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
	$o->calculate_totals( false );
	$o->set_status( $status );
	BSH_Plugin::$suspend_hooks = true;
	$o->save();
	BSH_Plugin::$suspend_hooks = false;
	return $o;
}

$honey   = bsh_t_product( array( 'name' => 'عسل فروش ' . $sfx, 'price' => '100000', 'stock' => 500, 'no_image' => 1 ) );
$saffron = bsh_t_product( array( 'name' => 'زعفران فروش ' . $sfx, 'price' => '250000', 'stock' => 500, 'no_image' => 1 ) );

echo "F1 Sales by channel\n";
bsh_t_sale( $honey, 2, 'basalam', 0, 'processing', 30000 );   // 200,000 items + 30,000 shipping.
bsh_t_sale( $saffron, 1, 'basalam', 3, 'completed' );         // 250,000.
bsh_t_sale( $honey, 1, 'site', 1 );                           // 100,000.
bsh_t_sale( $saffron, 2, 'site', 10, 'on-hold' );             // 500,000.
bsh_t_sale( $honey, 5, 'basalam', 2, 'cancelled' );           // Not counted.
bsh_t_sale( $honey, 9, 'site', 40 );                          // Outside 30 days (previous period).
bsh_t_sale( $honey, 1, 'basalam', 35 );                       // Previous period, Basalam: 100,000.
$s = BSH_Sales::summary( 30 );
$b = $s['channels']['basalam'];
$w = $s['channels']['site'];
bsh_t_ok( 2 === $b['orders'] && 480000.0 === (float) $b['revenue'], 'Basalam: 2 orders, 480,000 (cancelled left out)' );
bsh_t_ok( 2 === $w['orders'] && 600000.0 === (float) $w['revenue'], 'site: 2 orders, 600,000' );
bsh_t_ok( 3 === $b['units'] && 450000.0 === (float) $b['item_sales'], 'Basalam units 3, items 450,000 (shipping not an item)' );
bsh_t_ok( 30 === count( $s['series'] ) && abs( array_sum( wp_list_pluck( $s['series'], 'basalam' ) ) - 480000 ) < 0.01, 'daily series: 30 days adding up to the total' );
bsh_t_ok( 230000.0 === (float) end( $s['series'] )['basalam'], 'today’s Basalam bar holds today’s order (230,000 with shipping)' );
bsh_t_ok( 'زعفران فروش ' . $sfx === $s['top']['basalam'][0]['name'] && 250000.0 === (float) $s['top']['basalam'][0]['revenue'], 'top Basalam product by revenue' );
bsh_t_ok( 1 === $s['previous']['basalam']['orders'] && 380 === (int) round( BSH_Sales::change( $b['revenue'], $s['previous']['basalam']['revenue'] ) ), 'previous period and change: +380%' );
bsh_t_ok( null === BSH_Sales::change( 5, 0 ), 'no change shown without a previous period' );

echo "F2 Commission and cache\n";
update_option( BSH_Sales::OPTION, array( 'commission' => 10 ) );
$s = BSH_Sales::summary( 30 );
bsh_t_ok( 45000.0 === (float) $s['channels']['basalam']['commission'] && 435000.0 === (float) $s['channels']['basalam']['net'], '10% of items (not shipping): 45,000 → net 435,000' );
$cached = BSH_Sales::summary( 7 );
bsh_t_sale( $honey, 1, 'basalam', 0 );
bsh_t_ok( $cached['channels']['basalam']['orders'] + 1 === BSH_Sales::summary( 7 )['channels']['basalam']['orders'], 'a new order refreshes the cached numbers' );
bsh_t_ok( '۱٫۲ میلیون' === BSH_Sales::compact( 1200000 ) && '۴۵ هزار' === BSH_Sales::compact( 45000 ) && '۲ میلیارد' === BSH_Sales::compact( 2000000000 ), 'compact amounts: ۱٫۲ میلیون / ۴۵ هزار / ۲ میلیارد' );

echo "F3 Sales page renders\n";
$errors = array();
set_error_handler(
	function ( $no, $str, $file, $line ) use ( &$errors ) {
		if ( false !== strpos( $file, 'salamhub' ) || false !== strpos( $file, 'basalamhub' ) ) {
			$errors[] = "$str @ " . basename( $file ) . ":$line";
		}
		return false;
	}
);
wp_set_current_user( 1 );
foreach ( array( 7, 30, 90 ) as $d ) {
	$_GET['page'] = 'basalamhub-sales';
	$_GET['days'] = $d;
	ob_start();
	BSH_App::render( 'sales', 'basalamhub-sales' );
	$html = ob_get_clean();
	bsh_t_ok( false !== mb_strpos( $html, 'فروش دوکاناله' ) && false !== strpos( $html, 'data-bsh-chart' ) && substr_count( $html, 'bsh-chart__col' ) >= $d, "{$d}-day view with {$d} columns" );
}
restore_error_handler();
bsh_t_ok( ! $errors, 'no PHP warnings' . ( $errors ? ': ' . implode( ' | ', array_slice( $errors, 0, 3 ) ) : '' ) );

echo "F4 Low-stock alerts\n";
BSH_Notifier::save(
	array(
		'bale'   => array(
			'enabled' => 1,
			'token'   => '123456789:AAHgoodTokenForBaleBot_0123456789',
			'chat_id' => '4242',
		),
		'events' => array(
			'new_order' => 0,
			'errors'    => 0,
			'reconcile' => 0,
			'low_stock' => 1,
			'weekly'    => 1,
		),
	)
);
$lp = bsh_t_product( array( 'name' => 'گلاب هشدار ' . $sfx, 'stock' => 10, 'no_image' => 1 ) );
BSH_Links::upsert( 'product', $lp->get_id(), array( 'basalam_id' => 880001, 'sync_status' => 'synced' ) );
$alerts = function () use ( $wpdb, $lp ) {
	return $wpdb->get_col( $wpdb->prepare( 'SELECT event FROM ' . BSH_Logger::table() . " WHERE object_id = %d AND event IN ('stock_low','stock_out') ORDER BY id ASC", $lp->get_id() ) ); // phpcs:ignore
};
$bot = function () use ( $state_file ) {
	$m = bsh_t_mock( $state_file );
	return isset( $m['bot_messages'] ) ? $m['bot_messages'] : array();
};
$bot_before = count( $bot() );
wc_update_product_stock( $lp, 3, 'set' );
bsh_t_ok( array( 'stock_low' ) === $alerts(), 'drop to the threshold (3) → one «رو به اتمام» warning' );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 2, 'set' );
bsh_t_ok( 1 === count( $alerts() ), 'staying low → no repeat' );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 0, 'set' );
bsh_t_ok( array( 'stock_low', 'stock_out' ) === $alerts(), 'runs out → «تمام شد»' );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 2, 'set' );
bsh_t_ok( 2 === count( $alerts() ), 'out → low again: no new alert' );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 10, 'set' );
bsh_t_ok( '' === get_post_meta( $lp->get_id(), BSH_Stock_Alerts::META, true ), 'restocked → flag cleared' );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 1, 'set' );
bsh_t_ok( 3 === count( $alerts() ), 'next drop alerts again' );
$log = BSH_Logger::query( array( 'object_id' => $lp->get_id(), 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'warning' === $log->level && false !== mb_strpos( $log->message, 'حد هشدار ۳' ) && $log->suggestion, 'Persian message with the threshold and what to do' );
bsh_t_run_all();
$msgs = array_slice( $bot(), $bot_before );
bsh_t_ok( 3 === count( $msgs ) && false !== mb_strpos( $msgs[0]['text'], 'رو به اتمام' ) && false !== mb_strpos( $msgs[1]['text'], 'تمام شد' ), 'each of the 3 alerts reached Bale (low, out, low again)' );

$unlinked = bsh_t_product( array( 'name' => 'سایت‌فقط ' . $sfx, 'stock' => 10, 'no_image' => 1 ) );
wc_update_product_stock( $unlinked, 0, 'set' );
bsh_t_ok( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE object_id = %d AND event LIKE 'stock_%%'", $unlinked->get_id() ) ), 'products not on Basalam never alert' ); // phpcs:ignore

$own = bsh_t_product( array( 'name' => 'آستانه‌ی خود ' . $sfx, 'stock' => 20, 'no_image' => 1 ) );
$own->set_low_stock_amount( 8 );
$own->save();
BSH_Links::upsert( 'product', $own->get_id(), array( 'basalam_id' => 880002, 'sync_status' => 'synced' ) );
wc_update_product_stock( wc_get_product( $own->get_id() ), 7, 'set' );
bsh_t_ok( 8 === BSH_Stock_Alerts::threshold( wc_get_product( $own->get_id() ) ) && 'low' === get_post_meta( $own->get_id(), BSH_Stock_Alerts::META, true ), 'WooCommerce per-product threshold (8) wins' );

$names = wp_list_pluck( BSH_Stock_Alerts::low_items(), 'name' );
bsh_t_ok( in_array( 'گلاب هشدار ' . $sfx, $names, true ) && in_array( 'آستانه‌ی خود ' . $sfx, $names, true ) && ! in_array( 'سایت‌فقط ' . $sfx, $names, true ), 'low-stock list: linked low items only' );
$hc = array_filter(
	BSH_App::health_checks(),
	function ( $c ) {
		return 'موجودی باسلام' === $c[1];
	}
);
bsh_t_ok( $hc && 'warn' === reset( $hc )[0], 'health check warns' );

BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'low_stock_alert' => 0 ) ) );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 10, 'set' );
wc_update_product_stock( wc_get_product( $lp->get_id() ), 1, 'set' );
bsh_t_ok( 3 === count( $alerts() ), 'alerts off → silent' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'low_stock_alert' => 1 ) ) );

echo "F5 Weekly report\n";
$text = BSH_Report::build();
bsh_t_ok( false !== mb_strpos( $text, 'گزارش هفتگی' ) && false !== mb_strpos( $text, 'باسلام:' ) && false !== mb_strpos( $text, 'سایت:' ), 'both channels in the report' );
bsh_t_ok( false !== mb_strpos( $text, 'سهم باسلام' ) && false !== mb_strpos( $text, 'پس از کمیسیون' ), 'share and net after commission' );
bsh_t_ok( false !== mb_strpos( $text, 'کالای رو به اتمام' ) && false !== mb_strpos( $text, 'گلاب هشدار' ), 'low-stock items named' );
bsh_t_ok( false !== mb_strpos( $text, 'جا نیفتاده' ), 'missed-orders line' );
bsh_t_ok( false !== mb_strpos( $text, '۵۸۰٬۰۰۰ تومان' ) || false !== mb_strpos( $text, '٬۰۰۰ تومان' ), 'amounts as «۵۸۰٬۰۰۰ تومان»' );
bsh_t_run_all(); // Earlier alerts out of the way.
$before = count( $bot() );
$r      = BSH_Report::send( true );
bsh_t_run_all();
$all = $bot();
bsh_t_ok( 1 === $r['channels'] && count( $all ) === $before + 1 && false !== mb_strpos( end( $all )['text'], 'گزارش هفتگی' ), '«ارسال الان» reaches Bale' );
bsh_t_ok( is_array( get_option( BSH_Report::OPTION ) ), 'last report kept for the page' );
BSH_Notifier::save(
	array(
		'bale'   => array(
			'enabled' => 1,
			'chat_id' => '4242',
		),
		'events' => array(
			'new_order' => 0,
			'errors'    => 0,
			'reconcile' => 0,
			'low_stock' => 1,
			'weekly'    => 0,
		),
	)
);
$before = count( $bot() );
BSH_Report::handle( 0 );
bsh_t_run_all();
bsh_t_ok( count( $bot() ) === $before, 'weekly switch off → scheduled report is only logged' );
BSH_Report::schedule();
$next  = as_next_scheduled_action( BSH_Report::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
$local = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
bsh_t_ok( $next && 'Sat' === $local->format( 'D' ) && '09' === $local->format( 'H' ) && $next - time() <= WEEK_IN_SECONDS + HOUR_IN_SECONDS, 'scheduled for Saturday 09:00 site time' );

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
