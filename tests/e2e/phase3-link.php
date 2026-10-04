<?php
/**
 * Phase 3, part 2: linking existing booth products (included from phase3.php).
 *
 * @package SalamHub
 */

if ( ! function_exists( 'slh_t_ok' ) ) {
	exit( 1 );
}

global $wpdb;

/** Adds a product to the mock booth directly (as if the seller created it on Basalam). */
function slh_t_booth( $state_file, array $p ) {
	$s = slh_t_mock( $state_file );
	$id = $s['next_id']++;
	if ( ! empty( $p['variants'] ) ) {
		$vars = array();
		foreach ( $p['variants'] as $v ) {
			$vars[] = array(
				'id'            => $s['next_id']++,
				'sku'           => $v['sku'],
				'primary_price' => 1000000,
				'stock'         => 2,
				'properties'    => array( array( 'property' => array( 'id' => 1, 'title' => 'رنگ' ), 'value' => array( 'id' => 2, 'title' => $v['label'] ) ) ),
			);
		}
		$p['variants'] = $vars;
	}
	$s['products'][ $id ] = array_merge( array( 'id' => $id, 'primary_price' => 1500000, 'stock' => 4 ), $p );
	file_put_contents( $state_file, json_encode( $s ) );
	return $id;
}

function slh_t_row( $basalam_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SLH_Linker::table() . ' WHERE basalam_id = %d', $basalam_id ) );
}

function slh_t_run_linker() {
	for ( $i = 0; $i < 40 && SLH_Linker::is_running(); $i++ ) {
		$ids = ActionScheduler::store()->query_actions( array( 'group' => 'salamhub', 'status' => 'pending', 'per_page' => 20, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=' ) );
		$ids = array_filter( $ids, function ( $id ) {
			return in_array( ActionScheduler::store()->fetch_action( $id )->get_hook(), array( SLH_Linker::HOOK_FETCH, SLH_Linker::HOOK_MATCH ), true );
		} );
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, 'e2e' );
		}
	}
}

// Fresh booth and links for this part.
slh_t_clear_queue();
slh_t_reset_mock( $state_file );
$wpdb->query( 'DELETE FROM ' . SLH_Links::table() );
$wpdb->query( 'DELETE FROM ' . SLH_Linker::table() );
delete_option( SLH_Linker::STATE );
$run = wp_generate_password( 4, false );

// WooCommerce side.
$w_honey  = slh_t_product( array( 'name' => 'عسل گون ' . $run, 'sku' => 'HONEY-' . $run ) );
$w_synth  = slh_t_product( array( 'name' => 'شیره انگور ' . $run ) );
$w_exact  = slh_t_product( array( 'name' => 'ارده کنجد ممتاز ' . $run ) );
$w_fuzzy  = slh_t_product( array( 'name' => 'زعفران سرگل قائنات ' . $run ) );
$w_kilo   = slh_t_product( array( 'name' => 'عسل کنار 1 کیلویی ' . $run ) );
$w_dupe   = slh_t_product( array( 'name' => 'گلاب دوآتشه ' . $run, 'sku' => 'ROSE-' . $run ) );
$w_taken  = slh_t_product( array( 'name' => 'نبات زعفرانی ' . $run, 'sku' => 'NABAT-' . $run ) );
SLH_Links::upsert( 'product', $w_taken->get_id(), array( 'basalam_id' => 424242, 'sync_status' => 'synced' ) );
$w_var    = slh_t_variable( 'پیراهن ' . $run, array( 'سفید', 'مشکی' ), array( 'M' ) );
$vars     = $w_var->get_children();
foreach ( $vars as $i => $vid ) {
	$vv = wc_get_product( $vid );
	$vv->set_sku( 'SHIRT-' . $run . '-' . $i );
	SLH_Plugin::$suspend_hooks = true;
	$vv->save();
	SLH_Plugin::$suspend_hooks = false;
}

// Basalam booth side.
$b_honey = slh_t_booth( $state_file, array( 'title' => 'عسل گون اعلا', 'sku' => 'HONEY-' . $run ) );
$b_synth = slh_t_booth( $state_file, array( 'title' => 'شیره', 'sku' => 'SLH-' . $w_synth->get_id() ) );
$b_exact = slh_t_booth( $state_file, array( 'title' => 'ارده کنجد ممتاز ' . $run ) );
$b_fuzzy = slh_t_booth( $state_file, array( 'title' => 'زعفران سرگل قائن ' . $run ) );
$b_kilo  = slh_t_booth( $state_file, array( 'title' => 'عسل کنار 2 کیلویی ' . $run ) );
$b_dup1  = slh_t_booth( $state_file, array( 'title' => 'گلاب ۱', 'sku' => 'ROSE-' . $run ) );
$b_dup2  = slh_t_booth( $state_file, array( 'title' => 'گلاب ۲', 'sku' => 'ROSE-' . $run ) );
$b_taken = slh_t_booth( $state_file, array( 'title' => 'نبات', 'sku' => 'NABAT-' . $run ) );
$b_var   = slh_t_booth( $state_file, array( 'title' => 'پیراهن مردانه', 'variants' => array( array( 'sku' => 'SHIRT-' . $run . '-0', 'label' => 'سفید' ), array( 'sku' => 'SHIRT-' . $run . '-1', 'label' => 'مشکی' ) ) ) );
$b_lone  = slh_t_booth( $state_file, array( 'title' => 'محصول فقط در باسلام ' . $run ) );
// Fill the booth past two pages (50 per page) to exercise pagination.
for ( $i = 0; $i < 95; $i++ ) {
	slh_t_booth( $state_file, array( 'title' => 'پرکننده ' . $i . ' ' . wp_generate_password( 6, false ) ) );
}
$links_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SLH_Links::table() . ' WHERE basalam_id IS NOT NULL' );

echo "\n[L1] Fetch the whole booth in the background (paginated)\n";
$r = SLH_Linker::start();
slh_t_ok( true === $r && SLH_Linker::is_running(), 'job started' );
slh_t_ok( is_wp_error( SLH_Bulk::start( array( 'scope' => 'all' ) ) ), 'bulk send refused while linking runs (one heavy job at a time)' );
slh_t_run_linker();
$st = SLH_Linker::state();
slh_t_ok( 'ready' === $st['status'] && 105 === $st['fetched'] && 3 === $st['total_pages'], '105 booth products fetched over 3 pages' );
$fetches = slh_t_requests( $state_file, 'GET', '#/vendors/\d+/products$#' );
slh_t_ok( 3 === count( $fetches ), 'exactly 3 list requests' );

echo "\n[L2] Matching: certain / suspect / none\n";
$row = slh_t_row( $b_honey );
slh_t_ok( 'certain' === $row->match_status && (int) $row->match_wc_id === $w_honey->get_id(), 'same SKU → «قطعی» even with a different name' );
$row = slh_t_row( $b_synth );
slh_t_ok( 'certain' === $row->match_status && (int) $row->match_wc_id === $w_synth->get_id(), 'our own SLH-{id} SKU → «قطعی»' );
$row = slh_t_row( $b_var );
slh_t_ok( 'certain' === $row->match_status && (int) $row->match_wc_id === $w_var->get_id(), 'variant SKUs match a variable product → «قطعی»' );
$row = slh_t_row( $b_exact );
slh_t_ok( 'suspect' === $row->match_status && 100 === (int) $row->match_score && (int) $row->match_wc_id === $w_exact->get_id(), 'same name without SKU → «مشکوک» (never auto-linked by name)' );
$row = slh_t_row( $b_fuzzy );
slh_t_ok( 'suspect' === $row->match_status && (int) $row->match_wc_id === $w_fuzzy->get_id() && $row->match_score >= 70 && $row->match_score < 100, 'similar name → «مشکوک» with a score' );
$row = slh_t_row( $b_kilo );
slh_t_ok( 'none' === $row->match_status, '«۲ کیلویی» is not suggested for «۱ کیلویی»' );
slh_t_ok( 'suspect' === slh_t_row( $b_dup1 )->match_status && 'suspect' === slh_t_row( $b_dup2 )->match_status, 'two booth products with one SKU → both «مشکوک»' );
$row = slh_t_row( $b_taken );
slh_t_ok( 'suspect' === $row->match_status && false !== strpos( $row->match_reason, 'متصل است' ), 'site product already linked elsewhere → «مشکوک» with the reason' );
slh_t_ok( 'none' === slh_t_row( $b_lone )->match_status, 'booth-only product → «بدون جفت»' );
slh_t_ok( $links_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SLH_Links::table() . ' WHERE basalam_id IS NOT NULL' ), 'nothing was linked before approval' );

echo "\n[L3] Approve without sending: links only, Basalam untouched\n";
$writes_before = count( array_filter( slh_t_mock( $state_file )['requests'], function ( $r ) {
	return 'GET' !== $r['method'];
} ) );
$res = SLH_Linker::approve( array( $b_honey => $w_honey->get_id(), $b_var => $w_var->get_id() ), false );
slh_t_ok( 2 === $res['linked'] && ! $res['errors'], '2 linked' );
$link = SLH_Links::get( 'product', $w_honey->get_id() );
slh_t_ok( (int) $link->basalam_id === $b_honey && 'stale' === $link->sync_status, 'link written, status «همگام نیست»' );
slh_t_ok( 'linked' === slh_t_row( $b_honey )->match_status, 'row moved to «متصل‌شده»' );
$map = SLH_Product_Sync::variant_map( wc_get_product( $w_var->get_id() ) );
slh_t_ok( 2 === count( $map ) && ! array_diff( wp_list_pluck( $map, 'id' ), wp_list_pluck( slh_t_mock( $state_file )['products'][ $b_var ]['variants'], 'id' ) ), 'variations mapped to the booth\'s existing variant IDs' );
slh_t_ok( count( array_filter( slh_t_mock( $state_file )['requests'], function ( $r ) {
	return 'GET' !== $r['method'];
} ) ) === $writes_before, 'no write request to Basalam' );

echo "\n[L4] First sync of a linked product updates it in place (no new product, no new variants)\n";
SLH_Queue::enqueue_product( $w_var->get_id() );
slh_t_run_queue();
$posts = slh_t_requests( $state_file, 'POST', '#/products$#' );
slh_t_ok( 0 === count( $posts ), 'no product created' );
$vpatches = slh_t_requests( $state_file, 'PATCH', '#/variations/#' );
slh_t_ok( 2 === count( $vpatches ), 'both existing variants updated (price and stock)' );
slh_t_ok( 2 === count( slh_t_mock( $state_file )['products'][ $b_var ]['variants'] ), 'still 2 variants on Basalam' );
slh_t_ok( 'synced' === SLH_Links::get( 'product', $w_var->get_id() )->sync_status, 'now «همگام»' );

echo "\n[L5] Approve a suspect pair with «send after linking»\n";
$res = SLH_Linker::approve( array( $b_fuzzy => $w_fuzzy->get_id() ), true );
slh_t_ok( 1 === $res['linked'] && 1 === slh_t_pending_product_actions(), 'linked and queued' );
slh_t_run_queue();
$p = slh_t_requests( $state_file, 'PATCH', '#/v1/products/' . $b_fuzzy . '$#' );
slh_t_ok( 1 === count( $p ) && 0 === count( slh_t_requests( $state_file, 'POST', '#/products$#' ) ), 'site data sent to the existing booth product (PATCH, not create)' );

echo "\n[L6] Approval re-validates every pair\n";
$res = SLH_Linker::approve( array( $b_dup1 => $w_dupe->get_id() ) );
slh_t_ok( 1 === $res['linked'], 'first of the duplicate pair linked' );
$res = SLH_Linker::approve( array( $b_dup2 => $w_dupe->get_id() ) );
slh_t_ok( 0 === $res['linked'] && $res['errors'], 'second one refused: that site product is already linked' );
$res = SLH_Linker::approve( array( $b_honey => $w_exact->get_id() ) );
slh_t_ok( 0 === $res['linked'] && $res['errors'], 'a Basalam product already linked elsewhere is refused' );

echo "\n[L7] Re-run: booth products deleted on Basalam leave the preview; links stay\n";
$s = slh_t_mock( $state_file );
unset( $s['products'][ $b_lone ] );
file_put_contents( $state_file, json_encode( $s ) );
SLH_Linker::start();
slh_t_run_linker();
slh_t_ok( null === slh_t_row( $b_lone ), 'deleted booth product removed from the preview' );
slh_t_ok( 'linked' === slh_t_row( $b_honey )->match_status, 'linked products show as «متصل‌شده»' );
$c = SLH_Linker::counts();
slh_t_ok( 4 === $c['linked'] && $c['certain'] >= 1, '4 linked, synthetic-SKU pair still «قطعی»' );
