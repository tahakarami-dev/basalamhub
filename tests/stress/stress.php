<?php
/**
 * Stress test (phase 6): a big catalogue and a big booth against the mock API, with and
 * without chaos (429, 5xx, latency, lost "create" responses). Prints timings per job, the
 * slowest job, query counts and memory, and checks that nothing was lost or duplicated.
 *
 *   BSH_STRESS_N=600 BSH_MOCK_STATE=… wp eval-file tests/stress/stress.php
 *
 * Background jobs run in-process, one after another, like Action Scheduler's runner does.
 * Waiting periods (backoff, 429 pause) are fast-forwarded instead of slept through.
 *
 * @package BasalamHub
 */

require_once dirname( __DIR__ ) . '/e2e/helpers.php';

global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter(
	'http_allowed_safe_ports',
	function ( $p ) {
		$p[] = 8099;
		return $p;
	}
);
$n = max( 50, (int) getenv( 'BSH_STRESS_N' ) ?: 600 );

$recurring = array( BSH_Queue::HOOK_MAINTENANCE, BSH_Order_Sync::HOOK_POLL, BSH_Reconcile::HOOK, BSH_Inventory::HOOK_PULL );

/** Mock state helpers that don't log every request (the file would grow huge). */
function bsh_s_mock_patch( $state_file, callable $fn ) {
	$s = bsh_t_mock( $state_file );
	$s = $fn( $s );
	file_put_contents( $state_file, json_encode( $s ) );
}

/**
 * Runs every due job until the queue is empty, fast-forwarding waits.
 *
 * @return array Metrics.
 */
function bsh_s_drain( array $recurring, $label ) {
	global $wpdb;
	$store = ActionScheduler::store();
	$m     = array( 'jobs' => 0, 'ms' => array(), 'queries' => array(), 'forwards' => 0 );
	$start = microtime( true );
	for ( $round = 0; $round < 5000; $round++ ) {
		$ids = $store->query_actions( array( 'group' => BSH_Queue::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=', 'per_page' => 100, 'orderby' => 'date', 'order' => 'ASC' ) );
		$ids = array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $store, $recurring ) {
					$a = $store->fetch_action( $id );
					return ! ( in_array( $a->get_hook(), $recurring, true ) && ! $a->get_args() );
				}
			)
		);
		if ( ! $ids ) {
			// Anything left in the future (backoff, 429 pause)? Bring it forward.
			$later = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = %s AND a.status = 'pending' AND a.hook NOT IN ('" . implode( "','", $recurring ) . "')", BSH_Queue::GROUP ) ); // phpcs:ignore
			$later += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = %s AND a.status = 'pending' AND a.hook IN ('" . implode( "','", $recurring ) . "') AND a.args <> '[]'", BSH_Queue::GROUP ) ); // phpcs:ignore
			if ( ! $later ) {
				break;
			}
			delete_option( 'bsh_pause_until' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE status = 'pending' AND hook NOT IN ('" . implode( "','", $recurring ) . "')", gmdate( 'Y-m-d H:i:s', time() - 1 ), gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE status = 'pending' AND args <> '[]' AND hook IN ('" . implode( "','", $recurring ) . "')", gmdate( 'Y-m-d H:i:s', time() - 1 ), gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); // phpcs:ignore
			++$m['forwards'];
			continue;
		}
		foreach ( $ids as $id ) {
			$q0 = $wpdb->num_queries;
			$t0 = microtime( true );
			ActionScheduler::runner()->process_action( $id, 'stress' );
			$m['ms'][]      = ( microtime( true ) - $t0 ) * 1000;
			$m['queries'][] = $wpdb->num_queries - $q0;
			++$m['jobs'];
		}
		wp_cache_flush(); // Like separate cron requests: no ever-growing object cache.
	}
	$m['total_s'] = microtime( true ) - $start;
	sort( $m['ms'] );
	$count = count( $m['ms'] );
	printf(
		"  [%s] %d jobs in %.1fs · avg %.0f ms · p95 %.0f ms · slowest %.0f ms · max %d queries/job · peak memory %.0f MB · %d fast-forwards\n",
		$label,
		$m['jobs'],
		$m['total_s'],
		$count ? array_sum( $m['ms'] ) / $count : 0,
		$count ? $m['ms'][ (int) floor( 0.95 * ( $count - 1 ) ) ] : 0,
		$count ? end( $m['ms'] ) : 0,
		$m['queries'] ? max( $m['queries'] ) : 0,
		memory_get_peak_usage( true ) / MB_IN_BYTES,
		$m['forwards']
	);
	return $m;
}

function bsh_s_mock_skus( $state_file ) {
	$skus = array();
	foreach ( bsh_t_mock( $state_file )['products'] as $p ) {
		if ( ! empty( $p['sku'] ) ) {
			$skus[] = $p['sku'];
		}
	}
	return $skus;
}

/* ---------------------------------------------------------------------- */

bsh_t_fresh_start( $state_file );
bsh_s_mock_patch(
	$state_file,
	function ( $s ) {
		$s['no_request_log'] = true;
		return $s;
	}
);
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287', 'orders_enabled' => 1 ) ) );
// Old stress products out of the way.
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE 'استرس %'" ) as $old ) { // phpcs:ignore
	wp_delete_post( (int) $old, true );
}
foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bsh_imported_from'" ) as $old ) { // phpcs:ignore
	$p = wc_get_product( $old );
	if ( $p ) {
		$p->delete( true );
	}
}

echo "\nST1 Bulk send of {$n} products (healthy API)\n";
$t0  = microtime( true );
$ids = array();
BSH_Plugin::$suspend_hooks = true;
for ( $i = 0; $i < $n; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'استرس ' . $i . ' ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( (string) ( 10000 + $i * 10 ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $i % 20 );
	$p->set_weight( '0.5' );
	if ( 0 === $i % 10 ) {
		$p->set_image_id( bsh_t_image() );
	}
	$ids[] = $p->save();
}
BSH_Plugin::$suspend_hooks = false;
printf( "  created %d WooCommerce products in %.1fs\n", $n, microtime( true ) - $t0 );
$t0    = microtime( true );
$start = BSH_Bulk::start( array( 'scope' => 'unsent' ) );
printf( "  «ارسال گروهی» button answered in %.0f ms (only plans; the browser never waits)\n", ( microtime( true ) - $t0 ) * 1000 );
$m1     = bsh_s_drain( $recurring, 'bulk' );
$synced = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . " WHERE object_type = 'product' AND sync_status = 'synced' AND wc_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ) ); // phpcs:ignore
$skus   = bsh_s_mock_skus( $state_file );
bsh_t_ok( $synced === $n, "all {$n} synced ({$synced})" );
bsh_t_ok( count( $skus ) === count( array_unique( $skus ) ), 'no duplicate product on Basalam (' . count( $skus ) . ' products)' );
bsh_t_ok( max( $m1['ms'] ) < 20000, 'slowest job well under a shared host’s 30 s limit' );

echo "\nST2 Same again with chaos: 15% 429, 8% 5xx, 5% lost create responses, 40 ms latency\n";
bsh_s_mock_patch(
	$state_file,
	function ( $s ) {
		$s['chaos'] = array( 'latency_ms' => 40, 'p429' => 0.15, 'p5xx' => 0.08, 'p_lost_create' => 0.05 );
		return $s;
	}
);
$ids2 = array();
BSH_Plugin::$suspend_hooks = true;
for ( $i = 0; $i < (int) ( $n / 2 ); $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'استرس آشوب ' . $i . ' ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( (string) ( 20000 + $i ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 5 );
	$ids2[] = $p->save();
}
BSH_Plugin::$suspend_hooks = false;
BSH_Bulk::start( array( 'scope' => 'unsent' ) );
$m2     = bsh_s_drain( $recurring, 'bulk+chaos' );
$hits   = bsh_t_mock( $state_file )['chaos_hits'] ?? array();
printf( "  chaos injected: %d × 429, %d × 5xx, %d lost creates\n", $hits['429'] ?? 0, $hits['5xx'] ?? 0, $hits['lost'] ?? 0 );
$synced = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . " WHERE object_type = 'product' AND sync_status = 'synced' AND wc_id IN (" . implode( ',', array_map( 'intval', $ids2 ) ) . ')' ); // phpcs:ignore
$failed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . " WHERE object_type = 'product' AND sync_status = 'error' AND wc_id IN (" . implode( ',', array_map( 'intval', $ids2 ) ) . ')' ); // phpcs:ignore
$skus   = bsh_s_mock_skus( $state_file );
printf( "  result: %d synced, %d error (with Persian reason + retry in the log)\n", $synced, $failed );
bsh_t_ok( $synced + $failed === count( $ids2 ), 'every product ended synced or with a visible error — none silently lost' );
bsh_t_ok( $synced >= count( $ids2 ) * 0.97, 'at least 97% made it despite the chaos' );
bsh_t_ok( count( $skus ) === count( array_unique( $skus ) ), 'no duplicate product on Basalam even with lost create responses' );
// One more pass, like the seller pressing «تلاش مجدد همه».
if ( $failed ) {
	bsh_s_mock_patch( $state_file, function ( $s ) { unset( $s['chaos'] ); return $s; } );
	foreach ( BSH_Logger::query( array( 'level' => 'error', 'unresolved' => 1, 'per_page' => 500 ) )['items'] as $log ) {
		BSH_Queue::retry_from_log( $log );
	}
	bsh_s_drain( $recurring, 'retry-all' );
	$synced = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . " WHERE object_type = 'product' AND sync_status = 'synced' AND wc_id IN (" . implode( ',', array_map( 'intval', $ids2 ) ) . ')' ); // phpcs:ignore
	bsh_t_ok( $synced === count( $ids2 ), '«تلاش مجدد همه» brings the rest in' );
	$skus = bsh_s_mock_skus( $state_file );
	bsh_t_ok( count( $skus ) === count( array_unique( $skus ) ), 'still no duplicates after retrying' );
}
bsh_s_mock_patch( $state_file, function ( $s ) { unset( $s['chaos'] ); return $s; } );

echo "\nST3 Import a booth of {$n} products (20% variable, 10% with photos)\n";
bsh_s_mock_patch(
	$state_file,
	function ( $s ) use ( $n ) {
		$tag = wp_generate_password( 4, false );
		for ( $i = 0; $i < $n; $i++ ) {
			$id  = $s['next_id']++;
			$row = array(
				'id'            => $id,
				'name'          => 'غرفه ' . $tag . ' کالای ' . $i,
				'sku'           => 'BOOTH-' . $tag . '-' . $i,
				'primary_price' => 100000 + $i * 100,
				'stock'         => $i % 7,
				'category'      => array( 'id' => 900 + $i % 5, 'title' => 'دسته‌ی غرفه ' . ( $i % 5 ) ),
			);
			if ( 0 === $i % 10 ) {
				$row['photo'] = 600000 + $i;
			}
			if ( 0 === $i % 5 ) {
				$row['variants'] = array();
				foreach ( array( 'کوچک', 'متوسط', 'بزرگ' ) as $k => $size ) {
					$row['variants'][] = array( 'id' => $s['next_id']++, 'primary_price' => 100000 + $k * 5000, 'stock' => 2, 'sku' => 'BOOTH-' . $tag . '-' . $i . '-' . $k, 'properties' => array( array( 'property' => array( 'id' => 1, 'title' => 'اندازه' ), 'value' => array( 'id' => 10 + $k, 'title' => $size ) ) ) );
				}
			}
			$s['products'][ $id ] = $row;
		}
		return $s;
	}
);
BSH_Linker::start();
$m3a = bsh_s_drain( $recurring, 'fetch+match' );
$pv  = BSH_Importer::preview();
printf( "  preview: %d new, %d linked, %d to review\n", $pv['new'], $pv['linked'], $pv['review'] );
BSH_Importer::start( array( 'update_linked' => 0 ) );
$m3 = bsh_s_drain( $recurring, 'import' );
$st = BSH_Importer::state();
printf( "  import: %d created, %d skipped, %d failed\n", $st['created'], $st['skipped'], $st['failed'] );
bsh_t_ok( $st['created'] === $pv['new'] && 0 === $st['failed'], 'every new booth product created' );
BSH_Linker::start();
bsh_s_drain( $recurring, 'fetch again' );
$pv2 = BSH_Importer::preview();
bsh_t_ok( 0 === $pv2['new'], 'second run: nothing new → no duplicates' );

echo "\nST4 Nightly reconciliation over " . (int) ( $n / 2 ) . " orders of the last 7 days\n";
$basalam_ids = array_slice( array_keys( bsh_t_mock( $state_file )['products'] ), -20 );
bsh_s_mock_patch(
	$state_file,
	function ( $s ) use ( $n, $basalam_ids ) {
		for ( $i = 0; $i < (int) ( $n / 2 ); $i++ ) {
			$id                         = 500000 + $i + wp_rand( 0, 9999 ) * 1000;
			$s['parcels'][ (string) $id ] = array(
				'id'                => $id,
				'created_at'        => gmdate( 'Y-m-d\TH:i:s\Z', time() - (int) ( $i * 6 * DAY_IN_SECONDS / max( 1, $n / 2 ) ) - 60 ),
				'total_items_price' => 1000000,
				'shipping_cost'     => 0,
				'status'            => array( 'id' => 3739, 'title' => 'سفارش جدید' ),
				'items'             => array( array( 'id' => 1, 'title' => 'کالا', 'quantity' => 1, 'weight' => 1, 'price' => 1000000, 'product' => array( 'id' => (int) $basalam_ids[ $i % count( $basalam_ids ) ] ), 'variation' => null ) ),
				'order'             => array( 'id' => $i, 'customer' => array( 'recipient' => array( 'name' => 'مشتری ' . $i, 'mobile' => '0912' ), 'city' => array( 'title' => 'تهران' ) ) ),
			);
		}
		return $s;
	}
);
$t0  = microtime( true );
$res = BSH_Reconcile::run();
printf( "  reconciliation pass: %d checked, %d missing, %.1fs\n", $res['checked'], $res['missing'], microtime( true ) - $t0 );
$m4 = bsh_s_drain( $recurring, 'order import' );
bsh_t_ok( 0 === BSH_Reconcile::run()['missing'], 'second pass: every order is in WooCommerce' );
bsh_t_ok( 0 === BSH_Order_Sync::missing_count(), 'no «سفارش جاافتاده»' );

echo "\nST5 Admin pages with a big store\n";
$wpdb->query( 'START TRANSACTION' );
$values = array();
for ( $i = 0; $i < 20000; $i++ ) {
	$values[] = $wpdb->prepare( '(%s,%s,%s,%s,%d,%s,%s,%d)', gmdate( 'Y-m-d H:i:s', time() - $i * 60 ), 0 === $i % 9 ? 'error' : 'success', 'product_updated', 'product', $i % 500, 'محصول ' . $i, 'پیام', 0 === $i % 9 ? 0 : 1 );
	if ( 500 === count( $values ) ) {
		$wpdb->query( 'INSERT INTO ' . BSH_Logger::table() . ' (created_at, level, event, object_type, object_id, title, message, resolved) VALUES ' . implode( ',', $values ) ); // phpcs:ignore
		$values = array();
	}
}
$wpdb->query( 'COMMIT' );
wp_set_current_user( 1 );
foreach ( array( 'salamhub' => 'dashboard', 'basalamhub-products' => 'products', 'basalamhub-logs' => 'logs', 'basalamhub-orders' => 'orders', 'basalamhub-import' => 'import' ) as $slug => $view ) {
	$slug        = 'salamhub' === $slug ? 'basalamhub' : $slug;
	$_GET['page'] = $slug;
	$q0          = $wpdb->num_queries;
	$t0          = microtime( true );
	ob_start();
	BSH_App::render( $view, $slug );
	ob_end_clean();
	$ms = ( microtime( true ) - $t0 ) * 1000;
	printf( "  %-22s %5.0f ms · %3d queries\n", $slug, $ms, $wpdb->num_queries - $q0 );
	bsh_t_ok( $ms < 1500, "{$slug} renders fast enough on a big store" );
}
$t0 = microtime( true );
update_option( 'bsh_settings', array_merge( BSH_Settings::all(), array( 'log_retention_days' => 7 ) ) );
BSH_Logger::prune();
printf( "  log prune of 20k rows: %.2fs, %d rows left\n", microtime( true ) - $t0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() ) ); // phpcs:ignore

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
