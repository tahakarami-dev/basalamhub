<?php
/**
 * v0.9 presentation features: health score, «امروز چی شد», live order feed, demo mode.
 *
 *   BSH_MOCK_STATE=… wp eval-file tests/e2e/presentation.php
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
wp_set_current_user( 1 );
if ( BSH_Demo::active() ) {
	BSH_Demo::clear();
}
bsh_t_fresh_start( $state_file );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();
$wpdb->query( "UPDATE {$wpdb->prefix}wc_orders SET status = 'wc-cancelled' WHERE type = 'shop_order'" ); // phpcs:ignore
BSH_Sales::bust();

/** Calls an admin-ajax action and returns the decoded JSON. */
function bsh_t_ajax( $action, array $post = array() ) {
	$_POST    = $post;
	$_REQUEST = array_merge( $post, array( 'nonce' => wp_create_nonce( 'bsh_admin' ) ) );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$die = function () {
		return function () {
			throw new RuntimeException( 'die' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
	}
	$out = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $die );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$_POST    = array();
	$_REQUEST = array();
	return json_decode( $out, true );
}

echo "P1 Health score\n";
update_option( BSH_Reconcile::OPTION, array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'checked' => 3, 'missing' => 0, 'ids' => array(), 'error' => '' ), false );
$s = BSH_Insights::score();
bsh_t_ok( $s['score'] >= 0 && $s['score'] <= 100, 'score is between 0 and 100 (' . $s['score'] . ')' );
$base = $s['score'];
foreach ( range( 1, 3 ) as $i ) {
	BSH_Logger::log( array( 'level' => 'error', 'event' => 'sync_failed', 'object_type' => 'product', 'object_id' => 990000 + $i, 'title' => 'x', 'message' => 'y' ) );
}
$s2 = BSH_Insights::score();
bsh_t_ok( $base - 6 === $s2['score'] || ( $base < 6 && 0 === $s2['score'] ), '3 open errors take 6 points (' . $base . ' → ' . $s2['score'] . ')' );
foreach ( range( 4, 12 ) as $i ) {
	BSH_Logger::log( array( 'level' => 'error', 'event' => 'sync_failed', 'object_type' => 'product', 'object_id' => 990000 + $i, 'title' => 'x', 'message' => 'y' ) );
}
bsh_t_ok( $base - 10 === BSH_Insights::score()['score'], 'errors take at most 10 points' );
BSH_Logger::log( array( 'level' => 'error', 'event' => 'order_import_failed', 'object_type' => 'parcel', 'object_id' => 555001, 'title' => 'x', 'message' => 'y' ) );
$s3 = BSH_Insights::score();
bsh_t_ok( $base - 20 === $s3['score'], 'a missing Basalam order takes 10 more' );
bsh_t_ok( $s3['reasons'] && $s3['reasons'][0]['points'] >= $s3['reasons'][ count( $s3['reasons'] ) - 1 ]['points'], 'reasons sorted by points, largest first' );
bsh_t_ok( ! array_filter( $s3['reasons'], function ( $r ) { return '' === $r['text']; } ), 'every reason has a text' );
$wpdb->query( 'UPDATE ' . BSH_Logger::table() . ' SET resolved = 1' ); // phpcs:ignore
delete_option( BSH_Settings::TOKEN_OPTION );
$s4 = BSH_Insights::score();
bsh_t_ok( $s4['score'] <= 80 && 'ok' !== $s4['level'], 'no token → at least 20 off, not «عالی»' );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();

echo "P2 Today\n";
$t = BSH_Insights::today();
bsh_t_ok( 0 === $t['orders'] && false !== mb_strpos( $t['sentence'], 'هنوز سفارشی ثبت نشده' ), 'no orders → «امروز هنوز سفارشی ثبت نشده»' );
bsh_t_ok( 'ok' === $t['mood'] && false !== mb_strpos( $t['sentence'], 'همه‌چیز سالم است' ), 'nothing wrong → «همه‌چیز سالم است»' );
$p  = bsh_t_product( array( 'name' => 'محصول امروز', 'price' => '200000', 'no_image' => 1 ) );
$o1 = wc_create_order( array( 'created_via' => 'basalamhub' ) );
$o1->add_product( $p, 2 );
$o1->calculate_totals( false );
$o1->set_status( 'processing' );
$o1->save();
$o2 = wc_create_order( array( 'created_via' => 'checkout' ) );
$o2->add_product( $p, 1 );
$o2->calculate_totals( false );
$o2->set_status( 'processing' );
$o2->save();
BSH_Sales::bust();
$t = BSH_Insights::today();
bsh_t_ok( 2 === $t['orders'] && 1 === $t['basalam'], 'two orders today, one from Basalam' );
bsh_t_ok( 600000.0 === (float) $t['revenue'] && 400000.0 === (float) $t['basalam_revenue'], 'revenue split by channel' );
bsh_t_ok( false !== mb_strpos( $t['sentence'], '۲ سفارش (۱ از باسلام)' ) && false !== mb_strpos( $t['sentence'], '۶۰۰٬۰۰۰ تومان' ), 'sentence: «۲ سفارش (۱ از باسلام) به ارزش ۶۰۰٬۰۰۰ تومان»' );
bsh_t_ok( '.' === mb_substr( $t['sentence'], -1 ), 'sentence ends with a full stop' );
BSH_Logger::log( array( 'level' => 'error', 'event' => 'sync_failed', 'object_type' => 'product', 'object_id' => 991000, 'title' => 'x', 'message' => 'y' ) );
$t = BSH_Insights::today();
bsh_t_ok( 'warn' === $t['mood'] && false !== mb_strpos( $t['sentence'], '۱ خطای باز داری' ), 'an open error → warn and mentioned' );
BSH_Logger::log( array( 'level' => 'error', 'event' => 'order_import_failed', 'object_type' => 'parcel', 'object_id' => 555002, 'title' => 'x', 'message' => 'y' ) );
bsh_t_ok( 'bad' === BSH_Insights::today()['mood'], 'a missing Basalam order → bad' );
$wpdb->query( 'UPDATE ' . BSH_Logger::table() . ' SET resolved = 1' ); // phpcs:ignore
bsh_t_ok( in_array( BSH_Insights::greeting(), array( 'صبح بخیر', 'ظهر بخیر', 'عصر بخیر', 'شب بخیر' ), true ), 'greeting by site time' );

echo "P3 Live feed\n";
$first = bsh_t_ajax( 'bsh_live' );
bsh_t_ok( ! empty( $first['success'] ) && array() === $first['data']['items'], 'first call only sets the starting point' );
$since = (int) $first['data']['latest'];
$again = bsh_t_ajax( 'bsh_live', array( 'since' => $since, 'primed' => 1 ) );
bsh_t_ok( array() === $again['data']['items'], 'nothing new → no toast' );
$o1->set_shipping_city( 'شیراز' );
$o1->save();
BSH_Logger::log( array( 'level' => 'success', 'event' => 'order_imported', 'object_type' => 'order', 'object_id' => $o1->get_id(), 'title' => 'x', 'message' => 'y' ) );
$new = bsh_t_ajax( 'bsh_live', array( 'since' => $since, 'primed' => 1 ) );
bsh_t_ok( 1 === count( $new['data']['items'] ) && (string) $o1->get_order_number() === (string) $new['data']['items'][0]['number'], 'a new Basalam order → one toast' );
bsh_t_ok( 'شیراز' === $new['data']['items'][0]['city'] && false !== mb_strpos( $new['data']['items'][0]['total'], '۴۰۰٬۰۰۰' ) && '۱' === $new['data']['items'][0]['items'], 'toast has city, amount and item count' );
bsh_t_ok( $new['data']['latest'] > $since, 'starting point moves forward' );
$zero = bsh_t_ajax( 'bsh_live', array( 'since' => 0, 'primed' => 1 ) );
bsh_t_ok( count( $zero['data']['items'] ) >= 1, 'primed at 0 (no orders before) still shows the first one' );
foreach ( range( 1, 5 ) as $i ) {
	BSH_Logger::log( array( 'level' => 'success', 'event' => 'order_imported', 'object_type' => 'order', 'object_id' => $o1->get_id(), 'title' => 'x', 'message' => 'y' ) );
}
bsh_t_ok( 3 === count( bsh_t_ajax( 'bsh_live', array( 'since' => $new['data']['latest'], 'primed' => 1 ) )['data']['items'] ), 'at most 3 toasts at once' );
wp_set_current_user( 0 );
$anon = bsh_t_ajax( 'bsh_live' );
wp_set_current_user( 1 );
bsh_t_ok( empty( $anon['success'] ), 'logged-out user is refused' );

echo "P4 Demo mode\n";
// A real product the demo must never touch, and notifications on so muting is tested.
$real = bsh_t_product( array( 'name' => 'محصول واقعی', 'no_image' => 1 ) );
BSH_Links::upsert( 'product', $real->get_id(), array( 'basalam_id' => 4242, 'sync_status' => 'synced' ) );
BSH_Notifier::save(
	array(
		'bale'   => array(
			'enabled' => 1,
			'token'   => '123456789:AAHgoodTokenForBaleBot_0123456789',
			'chat_id' => '4242',
		),
		'events' => array(
			'new_order' => 1,
			'errors'    => 1,
			'reconcile' => 1,
			'low_stock' => 1,
			'weekly'    => 1,
		),
	)
);
bsh_t_clear_queue();
as_unschedule_all_actions( BSH_Notifier::HOOK, null, 'basalamhub' );
$prev_reconcile = get_option( BSH_Reconcile::OPTION );
$orders_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ); // phpcs:ignore
$prod_before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'" ); // phpcs:ignore
$requests       = count( bsh_t_mock( $state_file )['requests'] );

$mails = 0;
$count = function () use ( &$mails ) {
	++$mails;
};
remove_filter( 'pre_wp_mail', '__return_true' );
add_action( 'wp_mail_failed', $count );
add_action( 'wp_mail_succeeded', $count );
$fill = bsh_t_ajax( 'bsh_demo_fill' );
remove_action( 'wp_mail_failed', $count );
remove_action( 'wp_mail_succeeded', $count );
add_filter( 'pre_wp_mail', '__return_true' );
bsh_t_ok( ! empty( $fill['success'] ) && BSH_Demo::active(), 'fill → demo active' );
bsh_t_ok( 0 === $mails, 'no WooCommerce e-mail for demo orders' );
$demo_ids = get_posts( array( 'post_type' => 'product', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => BSH_Demo::META, 'meta_value' => '1' ) ); // phpcs:ignore
bsh_t_ok( 8 === count( $demo_ids ), '8 demo products' );
$demo_orders = wc_get_orders( array( 'limit' => 500, 'status' => 'any', 'meta_key' => BSH_Demo::META, 'meta_value' => '1' ) ); // phpcs:ignore
bsh_t_ok( count( $demo_orders ) >= 35, 'a month of demo orders (' . count( $demo_orders ) . ')' );
$via = array_count_values(
	array_map(
		function ( $o ) {
			return $o->get_created_via();
		},
		$demo_orders
	)
);
bsh_t_ok( ! empty( $via['basalamhub'] ) && ! empty( $via['checkout'] ), 'both channels present' );
$summary = BSH_Sales::summary( 30 );
bsh_t_ok( $summary['channels']['basalam']['orders'] > 0 && $summary['channels']['site']['orders'] > 0, 'sales dashboard shows both channels' );
bsh_t_ok( 0 === bsh_t_pending_product_actions(), 'no product queued for Basalam' );
bsh_t_ok( $requests === count( bsh_t_mock( $state_file )['requests'] ), 'not a single request reached Basalam' );
bsh_t_ok( 0 === (int) ActionScheduler::store()->query_actions( array( 'hook' => BSH_Notifier::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' ), 'no notification sent while seeding' );
bsh_t_ok( count( BSH_Stock_Alerts::low_items( 50 ) ) >= 2, 'low-stock card has demo items' );
$sc = BSH_Insights::score();
bsh_t_ok( $sc['score'] === max( 0, 100 - array_sum( wp_list_pluck( $sc['reasons'], 'points' ) ) ), 'score = 100 − listed reasons (' . $sc['score'] . ')' );
foreach ( $demo_ids as $id ) {
	BSH_Queue::enqueue_product( $id, true );
}
bsh_t_ok( 0 === bsh_t_pending_product_actions(), 'enqueue of a demo product is refused' );
$dp = wc_get_product( $demo_ids[0] );
$dp->set_regular_price( '999000' );
$dp->save();
bsh_t_ok( 0 === bsh_t_pending_product_actions(), 'editing a demo product does not queue it' );
$do = $demo_orders[0];
$do->set_status( 'completed' );
$do->save();
bsh_t_run_all();
bsh_t_ok( $requests === count( bsh_t_mock( $state_file )['requests'] ), 'demo order status change does not call Basalam' );

echo "P5 Simulated order\n";
$live0 = bsh_t_ajax( 'bsh_live' );
$sim   = bsh_t_ajax( 'bsh_demo_order' );
bsh_t_ok( ! empty( $sim['success'] ) && false !== mb_strpos( $sim['data']['message'], 'ثبت شد' ), 'simulate → «سفارش نمایشی … ثبت شد»' );
$live1 = bsh_t_ajax( 'bsh_live', array( 'since' => $live0['data']['latest'], 'primed' => 1 ) );
bsh_t_ok( 1 === count( $live1['data']['items'] ), 'the simulated order pops up as a toast' );
bsh_t_ok( 1 === (int) ActionScheduler::store()->query_actions( array( 'hook' => BSH_Notifier::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' ), 'and goes to Bale like a real one' );
bsh_t_ok( BSH_Insights::today()['basalam'] >= 1, 'today card counts it' );
bsh_t_ok( $requests === count( bsh_t_mock( $state_file )['requests'] ), 'still no request to Basalam' );
as_unschedule_all_actions( BSH_Notifier::HOOK, null, 'basalamhub' );

echo "P6 Pages in demo mode\n";
$errors = array();
set_error_handler(
	function ( $no, $str, $file, $line ) use ( &$errors ) {
		$errors[] = "$str @ " . basename( $file ) . ":$line";
		return true;
	}
);
foreach ( array( 'dashboard' => 'basalamhub', 'sales' => 'basalamhub-sales', 'settings' => 'basalamhub-settings' ) as $view => $slug ) {
	ob_start();
	BSH_App::render( $view, $slug );
	$html = ob_get_clean();
	bsh_t_ok( false !== mb_strpos( $html, 'حالت نمایشی' ) && false !== strpos( $html, 'data-bsh-demo="order"' ), "{$view}: demo bar with «شبیه‌سازی سفارش باسلام»" );
	if ( 'dashboard' === $view ) {
		bsh_t_ok( false !== mb_strpos( $html, 'امروز چی شد' ) && false !== strpos( $html, 'bsh-hscore__ring' ), 'dashboard: today card and score ring' );
		bsh_t_ok( false !== mb_strpos( $html, BSH_Insights::greeting() ), 'dashboard: greeting' );
	}
}
restore_error_handler();
bsh_t_ok( ! $errors, 'no PHP warnings' . ( $errors ? ': ' . implode( ' | ', array_slice( $errors, 0, 3 ) ) : '' ) );

echo "P7 Clear\n";
$clear = bsh_t_ajax( 'bsh_demo_clear' );
bsh_t_ok( ! empty( $clear['success'] ) && ! BSH_Demo::active(), 'clear → demo off' );
bsh_t_ok( 0 === count( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => BSH_Demo::META, 'meta_value' => '1' ) ) ), 'demo products gone' ); // phpcs:ignore
bsh_t_ok( 0 === count( wc_get_orders( array( 'limit' => 10, 'status' => 'any', 'meta_key' => BSH_Demo::META, 'meta_value' => '1' ) ) ), 'demo orders gone' ); // phpcs:ignore
bsh_t_ok( $orders_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ), 'real orders untouched (count as before)' ); // phpcs:ignore
bsh_t_ok( $prod_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'" ), 'real products untouched (count as before)' ); // phpcs:ignore
bsh_t_ok( wc_get_product( $real->get_id() ) && 4242 === (int) BSH_Links::get( 'product', $real->get_id() )->basalam_id, 'real product and its link kept' );
$left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . ' WHERE basalam_id >= 7000000' ); // phpcs:ignore
bsh_t_ok( 0 === $left, 'demo link rows gone' );
$logs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE event = 'demo_filled' OR context LIKE '%\"demo\": 1%'" ); // phpcs:ignore
bsh_t_ok( 0 === $logs, 'demo log rows gone' );
bsh_t_ok( get_option( BSH_Reconcile::OPTION ) == $prev_reconcile, 'reconcile status restored' ); // phpcs:ignore Universal.Operators.StrictComparisons
bsh_t_ok( 0 === (int) ActionScheduler::store()->query_actions( array( 'hook' => BSH_Notifier::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' ), 'no notification while clearing' );
$again = bsh_t_ajax( 'bsh_demo_order' );
bsh_t_ok( empty( $again['success'] ), 'simulate refused when demo is off' );

echo "P8 Demo on a disconnected site\n";
delete_option( BSH_Settings::TOKEN_OPTION );
delete_option( BSH_Settings::CONNECTION_OPTION );
BSH_Demo::fill();
$tok = array_values(
	array_filter(
		BSH_App::health_checks(),
		function ( $c ) {
			return 'توکن باسلام' === $c[1];
		}
	)
);
bsh_t_ok( $tok && 'ok' === $tok[0][0], 'token row reads «حالت نمایشی» instead of an error' );
ob_start();
BSH_App::render( 'dashboard', 'basalamhub' );
$html = ob_get_clean();
bsh_t_ok( false === strpos( $html, 'data-bsh-demo="fill"' ) && false !== mb_strpos( $html, 'داده‌ی نمایشی کار می‌کنی' ), 'dashboard explains demo, no second start button' );
BSH_Demo::clear();
ob_start();
BSH_App::render( 'dashboard', 'basalamhub' );
$html = ob_get_clean();
bsh_t_ok( false !== strpos( $html, 'data-bsh-demo="fill"' ), 'disconnected without demo → «روشن کردن حالت نمایشی»' );
delete_option( BSH_Notifier::OPTION );

echo "\n{$GLOBALS['bsh_passes']} passed, {$GLOBALS['bsh_failures']} failed\n";
