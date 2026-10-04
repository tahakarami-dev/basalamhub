<?php
/**
 * Phase 3 scenarios: variable products (and, below, linking existing booth products).
 *
 * @package SalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
slh_t_fresh_start( $state_file );
SLH_Settings::set_token( 'good-token' );
SLH_Admin::test_connection();
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'default_category_id' => '1287' ) ) );

/**
 * Variable product with local attributes رنگ × سایز and one variation per combination.
 */
function slh_t_variable( $name, array $colors = array( 'قرمز', 'آبی' ), array $sizes = array( 'S', 'M' ), $price = '200000' ) {
	$p = new WC_Product_Variable();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$a1 = new WC_Product_Attribute();
	$a1->set_name( 'رنگ' );
	$a1->set_options( $colors );
	$a1->set_variation( true );
	$a1->set_visible( true );
	$a2 = new WC_Product_Attribute();
	$a2->set_name( 'سایز' );
	$a2->set_options( $sizes );
	$a2->set_variation( true );
	$a2->set_visible( true );
	$p->set_attributes( array( $a1, $a2 ) );
	$p->set_image_id( slh_t_image() );
	SLH_Plugin::$suspend_hooks = true;
	$id = $p->save();
	$i  = 0;
	foreach ( $colors as $c ) {
		foreach ( $sizes as $s ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $id );
			$v->set_attributes( array( sanitize_title( 'رنگ' ) => $c, sanitize_title( 'سایز' ) => $s ) );
			$v->set_regular_price( (string) ( (int) $price + 10000 * $i ) );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( 3 + $i );
			$v->set_status( 'publish' );
			$v->save();
			++$i;
		}
	}
	WC_Product_Variable::sync( $id );
	SLH_Plugin::$suspend_hooks = false;
	return wc_get_product( $id );
}

function slh_t_remote( $state_file, $product_id ) {
	$bid = (int) SLH_Links::get( 'product', $product_id )->basalam_id;
	$m   = slh_t_mock( $state_file );
	return isset( $m['products'][ $bid ] ) ? $m['products'][ $bid ] : null;
}

echo "\n[V1] Create a variable product with its variants\n";
$tee = slh_t_variable( 'تیشرت نخی ' . wp_generate_password( 4, false ) );
SLH_Queue::enqueue_product( $tee->get_id() );
slh_t_run_queue();
$remote = slh_t_remote( $state_file, $tee->get_id() );
slh_t_ok( $remote && 4 === count( $remote['variants'] ), '4 variants created on Basalam' );
$first = $remote['variants'][0];
slh_t_ok( 'رنگ' === $first['properties'][0]['property']['title'] && 'قرمز' === $first['properties'][0]['value']['title'], 'properties sent with Persian names and values' );
slh_t_ok( 2000000 === $first['primary_price'] && 3 === $first['stock'], 'variant price in Rial and own stock' );
slh_t_ok( 2000000 === $remote['primary_price'] && 3 + 4 + 5 + 6 === $remote['stock'], 'product price = cheapest variant, stock = sum' );
$map = SLH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
slh_t_ok( 4 === count( $map ) && ! array_diff( wp_list_pluck( $map, 'id' ), wp_list_pluck( $remote['variants'], 'id' ) ), 'every variation mapped to its Basalam variant ID' );

echo "\n[V2] Re-send without changes → nothing sent\n";
$before = count( slh_t_mock( $state_file )['requests'] );
SLH_Queue::enqueue_product( $tee->get_id() );
slh_t_run_queue();
slh_t_ok( count( slh_t_mock( $state_file )['requests'] ) === $before, 'zero requests' );

echo "\n[V3] One variation's price changes → only that variant is patched\n";
$children = $tee->get_children();
$v2       = wc_get_product( $children[1] );
$v2->set_regular_price( '333000' );
$v2->save(); // Fires woocommerce_update_product_variation → parent queued.
slh_t_ok( 1 === slh_t_pending_product_actions(), 'editing a variation queued the parent product' );
$req_before = count( slh_t_mock( $state_file )['requests'] );
slh_t_run_queue();
$new_reqs = array_slice( slh_t_mock( $state_file )['requests'], $req_before );
$var_patches = array_filter( $new_reqs, function ( $r ) {
	return 'PATCH' === $r['method'] && false !== strpos( $r['path'], '/variations/' );
} );
$prod_patches = array_filter( $new_reqs, function ( $r ) {
	return 'PATCH' === $r['method'] && preg_match( '#^/v1/products/\d+$#', $r['path'] );
} );
$vp = reset( $var_patches );
slh_t_ok( 1 === count( $var_patches ) && 3330000 === $vp['body']['primary_price'], 'exactly one variant PATCH with the new price' );
slh_t_ok( ! array_filter( $prod_patches, function ( $r ) {
	return isset( $r['body']['variants'] );
} ), 'the variant list was not re-sent' );
slh_t_ok( 4 === count( slh_t_remote( $state_file, $tee->get_id() )['variants'] ), 'still 4 variants on Basalam' );

echo "\n[V4] Variation stock change from an order → parent queued, only stock sent\n";
wc_update_product_stock( wc_get_product( $children[0] ), 1, 'set' );
slh_t_ok( 1 === slh_t_pending_product_actions(), 'variation stock change queued the parent' );
$req_before = count( slh_t_mock( $state_file )['requests'] );
slh_t_run_queue();
$vp = array_values( array_filter( array_slice( slh_t_mock( $state_file )['requests'], $req_before ), function ( $r ) {
	return false !== strpos( $r['path'], '/variations/' );
} ) );
slh_t_ok( 1 === count( $vp ) && array( 'stock' => 1 ) === $vp[0]['body'], 'only the stock of that variant was sent' );

echo "\n[V5] New variation → full list re-sent, mapped again, no duplicates (replace)\n";
$v = new WC_Product_Variation();
$v->set_parent_id( $tee->get_id() );
$v->set_attributes( array( sanitize_title( 'رنگ' ) => 'قرمز', sanitize_title( 'سایز' ) => 'XL' ) );
$v->set_regular_price( '250000' );
$v->set_status( 'publish' );
$attrs = $tee->get_attributes();
$attrs[ sanitize_title( 'سایز' ) ]->set_options( array( 'S', 'M', 'XL' ) );
$tee->set_attributes( $attrs );
SLH_Plugin::$suspend_hooks = true;
$tee->save();
SLH_Plugin::$suspend_hooks = false;
$v->save();
slh_t_run_queue();
$remote = slh_t_remote( $state_file, $tee->get_id() );
$map    = SLH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
slh_t_ok( 5 === count( $remote['variants'] ) && 5 === count( $map ), '5 variants on Basalam, 5 in the map' );
slh_t_ok( ! array_diff( wp_list_pluck( $map, 'id' ), wp_list_pluck( $remote['variants'], 'id' ) ), 'map points at the new variant IDs' );
slh_t_ok( 'variants_mismatch' !== slh_t_last_log( $tee->get_id() )->event, 'no mismatch warning' );

echo "\n[V6] If Basalam APPENDS instead of replacing → detected and reported, no silent duplicates\n";
$s                  = slh_t_mock( $state_file );
$s['variants_mode'] = 'append';
file_put_contents( $state_file, json_encode( $s ) );
$kids = $tee->get_children();
$last = wc_get_product( end( $kids ) );
$last->delete( true );
slh_t_run_queue();
$warn = SLH_Logger::query( array( 'object_id' => $tee->get_id(), 'level' => 'warning', 'per_page' => 1 ) )['items'];
slh_t_ok( $warn && 'variants_mismatch' === $warn[0]->event && false !== strpos( $warn[0]->reason, 'تکراری' ), 'warning: duplicate variants on Basalam, in Persian' );
$remote = slh_t_remote( $state_file, $tee->get_id() );
$map    = SLH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
slh_t_ok( 4 === count( $map ) && min( wp_list_pluck( $map, 'id' ) ) > max( array_slice( wp_list_pluck( $remote['variants'], 'id' ), 0, 5 ) ), 'map uses the newest variant of each row' );
$s                  = slh_t_mock( $state_file );
$s['variants_mode'] = 'replace';
file_put_contents( $state_file, json_encode( $s ) );

echo "\n[V7] Variation set to «any» → blocked with a clear reason\n";
$any = slh_t_variable( 'کلاه ' . wp_generate_password( 4, false ), array( 'سبز' ), array( 'L' ) );
$c   = wc_get_product( $any->get_children()[0] );
$c->set_attributes( array( sanitize_title( 'رنگ' ) => '', sanitize_title( 'سایز' ) => 'L' ) );
SLH_Plugin::$suspend_hooks = true;
$c->save();
SLH_Plugin::$suspend_hooks = false;
SLH_Queue::enqueue_product( $any->get_id() );
slh_t_run_queue();
$log = slh_t_last_log( $any->get_id() );
slh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'هرکدام' ), '«هرکدام» explained' );

echo "\n[V8] Disabled and zero-price variations\n";
$mix = slh_t_variable( 'جوراب ' . wp_generate_password( 4, false ), array( 'مشکی', 'سفید' ), array( 'M' ) );
$ch  = $mix->get_children();
$off = wc_get_product( $ch[1] );
$off->set_status( 'private' ); // "Enabled" unchecked in WooCommerce.
SLH_Plugin::$suspend_hooks = true;
$off->save();
SLH_Plugin::$suspend_hooks = false;
SLH_Queue::enqueue_product( $mix->get_id() );
slh_t_run_queue();
$remote = slh_t_remote( $state_file, $mix->get_id() );
slh_t_ok( $remote && 1 === count( $remote['variants'] ), 'disabled variation not sent' );
$zero = wc_get_product( $ch[0] );
$zero->set_regular_price( '' );
SLH_Plugin::$suspend_hooks = true;
$zero->save();
SLH_Plugin::$suspend_hooks = false;
SLH_Queue::enqueue_product( $mix->get_id(), true );
slh_t_run_queue();
$log = slh_t_last_log( $mix->get_id() );
slh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'مشکی' ), 'variation without price named in the error' );

echo "\n[V9] Variant deleted on Basalam → explained; retry re-sends the full list\n";
$s      = slh_t_mock( $state_file );
$bid    = (int) SLH_Links::get( 'product', $tee->get_id() )->basalam_id;
$map    = SLH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
$gone   = (int) reset( $map )['id'];
$s['products'][ $bid ]['variants'] = array_values( array_filter( $s['products'][ $bid ]['variants'], function ( $v ) use ( $gone ) {
	return (int) $v['id'] !== $gone;
} ) );
file_put_contents( $state_file, json_encode( $s ) );
SLH_Queue::enqueue_product( $tee->get_id(), true );
slh_t_run_queue();
$log = slh_t_last_log( $tee->get_id() );
slh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'حذف شده' ), '404 on a variant explained' );
SLH_Queue::retry_from_log( $log );
slh_t_run_queue();
$remote = slh_t_remote( $state_file, $tee->get_id() );
slh_t_ok( 4 === count( $remote['variants'] ) && 'synced' === SLH_Links::get( 'product', $tee->get_id() )->sync_status, 'retry restored all 4 variants' );

echo "\n[V10] Global attribute terms are sent by name, not slug\n";
$attr_id = wc_attribute_taxonomy_id_by_name( 'slhcolor' );
if ( ! $attr_id ) {
	$attr_id = wc_create_attribute( array( 'name' => 'رنگ‌بندی', 'slug' => 'slhcolor' ) );
}
delete_transient( 'wc_attribute_taxonomies' );
wp_cache_flush();
register_taxonomy( 'pa_slhcolor', 'product' );
$term = term_exists( 'سرمه‌ای', 'pa_slhcolor' ) ?: wp_insert_term( 'سرمه‌ای', 'pa_slhcolor', array( 'slug' => 'navy' ) );
$g    = new WC_Product_Variable();
$g->set_name( 'شال ' . wp_generate_password( 4, false ) );
$g->set_status( 'publish' );
$ga = new WC_Product_Attribute();
$ga->set_id( $attr_id );
$ga->set_name( 'pa_slhcolor' );
$ga->set_options( array( (int) $term['term_id'] ) );
$ga->set_variation( true );
$g->set_attributes( array( $ga ) );
$g->set_image_id( slh_t_image() );
SLH_Plugin::$suspend_hooks = true;
$gid = $g->save();
wp_set_object_terms( $gid, array( (int) $term['term_id'] ), 'pa_slhcolor' );
$gv = new WC_Product_Variation();
$gv->set_parent_id( $gid );
$gv->set_attributes( array( 'pa_slhcolor' => 'navy' ) );
$gv->set_regular_price( '90000' );
$gv->set_status( 'publish' );
$gv->save();
SLH_Plugin::$suspend_hooks = false;
$mapped = ( new SLH_Product_Mapper() )->map( wc_get_product( $gid ) );
$prop   = $mapped['payload']['variants'][0]['properties'][0];
slh_t_ok( 'رنگ‌بندی' === $prop['property'] && 'سرمه‌ای' === $prop['value'], 'attribute label and term name (not «navy»)' );

echo "\n[V11] Only «description» selected → variant prices untouched\n";
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'sync_fields' => array( 'description' ) ) ) );
$v0 = wc_get_product( wc_get_product( $tee->get_id() )->get_children()[0] );
$v0->set_regular_price( '999000' );
$v0->save();
$req_before = count( slh_t_mock( $state_file )['requests'] );
slh_t_run_queue();
slh_t_ok( ! array_filter( array_slice( slh_t_mock( $state_file )['requests'], $req_before ), function ( $r ) {
	return false !== strpos( $r['path'], '/variations/' ) || isset( $r['body']['variants'] ) || isset( $r['body']['primary_price'] );
} ), 'no price sent when «قیمت» is not selected' );
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'sync_fields' => array_keys( SLH_Settings::field_groups() ) ) ) );

if ( file_exists( __DIR__ . '/phase3-link.php' ) ) {
	require __DIR__ . '/phase3-link.php';
}

echo "\n" . $GLOBALS['slh_passes'] . ' passed, ' . $GLOBALS['slh_failures'] . " failed\n";
if ( $GLOBALS['slh_failures'] ) {
	exit( 1 );
}
