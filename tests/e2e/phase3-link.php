<?php
/**
 * Phase 3, part 2: linking existing booth products (included from phase3.php).
 *
 * @package BasalamHub
 */

if ( ! function_exists( 'bsh_t_ok' ) ) {
	exit( 1 );
}

global $wpdb;

/** Adds a product to the mock booth directly (as if the seller created it on Basalam). */
function bsh_t_booth( $state_file, array $p ) {
	$s = bsh_t_mock( $state_file );
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

function bsh_t_row( $basalam_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSH_Linker::table() . ' WHERE basalam_id = %d', $basalam_id ) );
}

function bsh_t_run_linker() {
	for ( $i = 0; $i < 40 && BSH_Linker::is_running(); $i++ ) {
		$ids = ActionScheduler::store()->query_actions( array( 'group' => 'basalamhub', 'status' => 'pending', 'per_page' => 20, 'date' => as_get_datetime_object( time() + 1 ), 'date_compare' => '<=' ) );
		$ids = array_filter( $ids, function ( $id ) {
			return in_array( ActionScheduler::store()->fetch_action( $id )->get_hook(), array( BSH_Linker::HOOK_FETCH, BSH_Linker::HOOK_MATCH ), true );
		} );
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, 'e2e' );
		}
	}
}

// Fresh booth and links for this part.
bsh_t_clear_queue();
bsh_t_reset_mock( $state_file );
$wpdb->query( 'DELETE FROM ' . BSH_Links::table() );
$wpdb->query( 'DELETE FROM ' . BSH_Linker::table() );
delete_option( BSH_Linker::STATE );
$run = wp_generate_password( 4, false );

// WooCommerce side.
$w_honey  = bsh_t_product( array( 'name' => 'عسل گون ' . $run, 'sku' => 'HONEY-' . $run ) );
$w_synth  = bsh_t_product( array( 'name' => 'شیره انگور ' . $run ) );
$w_exact  = bsh_t_product( array( 'name' => 'ارده کنجد ممتاز ' . $run ) );
$w_fuzzy  = bsh_t_product( array( 'name' => 'زعفران سرگل قائنات ' . $run ) );
$w_kilo   = bsh_t_product( array( 'name' => 'عسل کنار 1 کیلویی ' . $run ) );
$w_dupe   = bsh_t_product( array( 'name' => 'گلاب دوآتشه ' . $run, 'sku' => 'ROSE-' . $run ) );
$w_taken  = bsh_t_product( array( 'name' => 'نبات زعفرانی ' . $run, 'sku' => 'NABAT-' . $run ) );
BSH_Links::upsert( 'product', $w_taken->get_id(), array( 'basalam_id' => 424242, 'sync_status' => 'synced' ) );
$w_var    = bsh_t_variable( 'پیراهن ' . $run, array( 'سفید', 'مشکی' ), array( 'M' ) );
$vars     = $w_var->get_children();
foreach ( $vars as $i => $vid ) {
	$vv = wc_get_product( $vid );
	$vv->set_sku( 'SHIRT-' . $run . '-' . $i );
	BSH_Plugin::$suspend_hooks = true;
	$vv->save();
	BSH_Plugin::$suspend_hooks = false;
}

// Basalam booth side.
$b_honey = bsh_t_booth( $state_file, array( 'title' => 'عسل گون اعلا', 'sku' => 'HONEY-' . $run ) );
$b_synth = bsh_t_booth( $state_file, array( 'title' => 'شیره', 'sku' => 'BSH-' . $w_synth->get_id() ) );
$b_exact = bsh_t_booth( $state_file, array( 'title' => 'ارده کنجد ممتاز ' . $run ) );
$b_fuzzy = bsh_t_booth( $state_file, array( 'title' => 'زعفران سرگل قائن ' . $run ) );
$b_kilo  = bsh_t_booth( $state_file, array( 'title' => 'عسل کنار 2 کیلویی ' . $run ) );
$b_dup1  = bsh_t_booth( $state_file, array( 'title' => 'گلاب ۱', 'sku' => 'ROSE-' . $run ) );
$b_dup2  = bsh_t_booth( $state_file, array( 'title' => 'گلاب ۲', 'sku' => 'ROSE-' . $run ) );
$b_taken = bsh_t_booth( $state_file, array( 'title' => 'نبات', 'sku' => 'NABAT-' . $run ) );
$b_var   = bsh_t_booth( $state_file, array( 'title' => 'پیراهن مردانه', 'variants' => array( array( 'sku' => 'SHIRT-' . $run . '-0', 'label' => 'سفید' ), array( 'sku' => 'SHIRT-' . $run . '-1', 'label' => 'مشکی' ) ) ) );
$b_lone  = bsh_t_booth( $state_file, array( 'title' => 'محصول فقط در باسلام ' . $run ) );
// Fill the booth past two pages (50 per page) to exercise pagination.
for ( $i = 0; $i < 95; $i++ ) {
	bsh_t_booth( $state_file, array( 'title' => 'پرکننده ' . $i . ' ' . wp_generate_password( 6, false ) ) );
}
$links_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . ' WHERE basalam_id IS NOT NULL' );

echo "\n[L1] Fetch the whole booth in the background (paginated)\n";
$r = BSH_Linker::start();
bsh_t_ok( true === $r && BSH_Linker::is_running(), 'job started' );
bsh_t_ok( is_wp_error( BSH_Bulk::start( array( 'scope' => 'all' ) ) ), 'bulk send refused while linking runs (one heavy job at a time)' );
bsh_t_run_linker();
$st = BSH_Linker::state();
bsh_t_ok( 'ready' === $st['status'] && 105 === $st['fetched'] && 3 === $st['total_pages'], '105 booth products fetched over 3 pages' );
$fetches = bsh_t_requests( $state_file, 'GET', '#/vendors/\d+/products$#' );
bsh_t_ok( 3 === count( $fetches ), 'exactly 3 list requests' );

echo "\n[L2] Matching: certain / suspect / none\n";
$row = bsh_t_row( $b_honey );
bsh_t_ok( 'certain' === $row->match_status && (int) $row->match_wc_id === $w_honey->get_id(), 'same SKU → «قطعی» even with a different name' );
$row = bsh_t_row( $b_synth );
bsh_t_ok( 'certain' === $row->match_status && (int) $row->match_wc_id === $w_synth->get_id(), 'our own BSH-{id} SKU → «قطعی»' );
$row = bsh_t_row( $b_var );
bsh_t_ok( 'certain' === $row->match_status && (int) $row->match_wc_id === $w_var->get_id(), 'variant SKUs match a variable product → «قطعی»' );
$row = bsh_t_row( $b_exact );
bsh_t_ok( 'suspect' === $row->match_status && 100 === (int) $row->match_score && (int) $row->match_wc_id === $w_exact->get_id(), 'same name without SKU → «مشکوک» (never auto-linked by name)' );
$row = bsh_t_row( $b_fuzzy );
bsh_t_ok( 'suspect' === $row->match_status && (int) $row->match_wc_id === $w_fuzzy->get_id() && $row->match_score >= 70 && $row->match_score < 100, 'similar name → «مشکوک» with a score' );
$row = bsh_t_row( $b_kilo );
bsh_t_ok( 'none' === $row->match_status, '«۲ کیلویی» is not suggested for «۱ کیلویی»' );
bsh_t_ok( 'suspect' === bsh_t_row( $b_dup1 )->match_status && 'suspect' === bsh_t_row( $b_dup2 )->match_status, 'two booth products with one SKU → both «مشکوک»' );
$row = bsh_t_row( $b_taken );
bsh_t_ok( 'suspect' === $row->match_status && false !== strpos( $row->match_reason, 'متصل است' ), 'site product already linked elsewhere → «مشکوک» with the reason' );
bsh_t_ok( 'none' === bsh_t_row( $b_lone )->match_status, 'booth-only product → «بدون جفت»' );
bsh_t_ok( $links_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . ' WHERE basalam_id IS NOT NULL' ), 'nothing was linked before approval' );

echo "\n[L3] Approve without sending: links only, Basalam untouched\n";
$writes_before = count( array_filter( bsh_t_mock( $state_file )['requests'], function ( $r ) {
	return 'GET' !== $r['method'];
} ) );
$res = BSH_Linker::approve( array( $b_honey => $w_honey->get_id(), $b_var => $w_var->get_id() ), false );
bsh_t_ok( 2 === $res['linked'] && ! $res['errors'], '2 linked' );
$link = BSH_Links::get( 'product', $w_honey->get_id() );
bsh_t_ok( (int) $link->basalam_id === $b_honey && 'stale' === $link->sync_status, 'link written, status «همگام نیست»' );
bsh_t_ok( 'linked' === bsh_t_row( $b_honey )->match_status, 'row moved to «متصل‌شده»' );
$map = BSH_Product_Sync::variant_map( wc_get_product( $w_var->get_id() ) );
bsh_t_ok( 2 === count( $map ) && ! array_diff( wp_list_pluck( $map, 'id' ), wp_list_pluck( bsh_t_mock( $state_file )['products'][ $b_var ]['variants'], 'id' ) ), 'variations mapped to the booth\'s existing variant IDs' );
bsh_t_ok( count( array_filter( bsh_t_mock( $state_file )['requests'], function ( $r ) {
	return 'GET' !== $r['method'];
} ) ) === $writes_before, 'no write request to Basalam' );

echo "\n[L4] First sync of a linked product updates it in place (no new product, no new variants)\n";
BSH_Queue::enqueue_product( $w_var->get_id() );
bsh_t_run_queue();
$posts = bsh_t_requests( $state_file, 'POST', '#/products$#' );
bsh_t_ok( 0 === count( $posts ), 'no product created' );
$vpatches = bsh_t_requests( $state_file, 'PATCH', '#/variations/#' );
bsh_t_ok( 2 === count( $vpatches ), 'both existing variants updated (price and stock)' );
bsh_t_ok( 2 === count( bsh_t_mock( $state_file )['products'][ $b_var ]['variants'] ), 'still 2 variants on Basalam' );
bsh_t_ok( 'synced' === BSH_Links::get( 'product', $w_var->get_id() )->sync_status, 'now «همگام»' );

echo "\n[L5] Approve a suspect pair with «send after linking»\n";
$res = BSH_Linker::approve( array( $b_fuzzy => $w_fuzzy->get_id() ), true );
bsh_t_ok( 1 === $res['linked'] && 1 === bsh_t_pending_product_actions(), 'linked and queued' );
bsh_t_run_queue();
$p = bsh_t_requests( $state_file, 'PATCH', '#/v1/products/' . $b_fuzzy . '$#' );
bsh_t_ok( 1 === count( $p ) && 0 === count( bsh_t_requests( $state_file, 'POST', '#/products$#' ) ), 'site data sent to the existing booth product (PATCH, not create)' );

echo "\n[L6] Approval re-validates every pair\n";
$res = BSH_Linker::approve( array( $b_dup1 => $w_dupe->get_id() ) );
bsh_t_ok( 1 === $res['linked'], 'first of the duplicate pair linked' );
$res = BSH_Linker::approve( array( $b_dup2 => $w_dupe->get_id() ) );
bsh_t_ok( 0 === $res['linked'] && $res['errors'], 'second one refused: that site product is already linked' );
$res = BSH_Linker::approve( array( $b_honey => $w_exact->get_id() ) );
bsh_t_ok( 0 === $res['linked'] && $res['errors'], 'a Basalam product already linked elsewhere is refused' );

echo "\n[L7] Re-run: booth products deleted on Basalam leave the preview; links stay\n";
$s = bsh_t_mock( $state_file );
unset( $s['products'][ $b_lone ] );
file_put_contents( $state_file, json_encode( $s ) );
BSH_Linker::start();
bsh_t_run_linker();
bsh_t_ok( null === bsh_t_row( $b_lone ), 'deleted booth product removed from the preview' );
bsh_t_ok( 'linked' === bsh_t_row( $b_honey )->match_status, 'linked products show as «متصل‌شده»' );
$c = BSH_Linker::counts();
bsh_t_ok( 4 === $c['linked'] && $c['certain'] >= 1, '4 linked, synthetic-SKU pair still «قطعی»' );
