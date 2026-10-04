<?php
/**
 * Phase 3 scenarios: variable products (and, below, linking existing booth products).
 *
 * @package BasalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
bsh_t_fresh_start( $state_file );
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287' ) ) );

function bsh_t_remote( $state_file, $product_id ) {
	$bid = (int) BSH_Links::get( 'product', $product_id )->basalam_id;
	$m   = bsh_t_mock( $state_file );
	return isset( $m['products'][ $bid ] ) ? $m['products'][ $bid ] : null;
}

echo "\n[V1] Create a variable product with its variants\n";
$tee = bsh_t_variable( 'تیشرت نخی ' . wp_generate_password( 4, false ) );
BSH_Queue::enqueue_product( $tee->get_id() );
bsh_t_run_queue();
$remote = bsh_t_remote( $state_file, $tee->get_id() );
bsh_t_ok( $remote && 4 === count( $remote['variants'] ), '4 variants created on Basalam' );
$first = $remote['variants'][0];
bsh_t_ok( 'رنگ' === $first['properties'][0]['property']['title'] && 'قرمز' === $first['properties'][0]['value']['title'], 'properties sent with Persian names and values' );
bsh_t_ok( 2000000 === $first['primary_price'] && 3 === $first['stock'], 'variant price in Rial and own stock' );
bsh_t_ok( 2000000 === $remote['primary_price'] && 3 + 4 + 5 + 6 === $remote['stock'], 'product price = cheapest variant, stock = sum' );
$map = BSH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
bsh_t_ok( 4 === count( $map ) && ! array_diff( wp_list_pluck( $map, 'id' ), wp_list_pluck( $remote['variants'], 'id' ) ), 'every variation mapped to its Basalam variant ID' );

echo "\n[V2] Re-send without changes → nothing sent\n";
$before = count( bsh_t_mock( $state_file )['requests'] );
BSH_Queue::enqueue_product( $tee->get_id() );
bsh_t_run_queue();
bsh_t_ok( count( bsh_t_mock( $state_file )['requests'] ) === $before, 'zero requests' );

echo "\n[V3] One variation's price changes → only that variant is patched\n";
$children = $tee->get_children();
$v2       = wc_get_product( $children[1] );
$v2->set_regular_price( '333000' );
$v2->save(); // Fires woocommerce_update_product_variation → parent queued.
bsh_t_ok( 1 === bsh_t_pending_product_actions(), 'editing a variation queued the parent product' );
$req_before = count( bsh_t_mock( $state_file )['requests'] );
bsh_t_run_queue();
$new_reqs = array_slice( bsh_t_mock( $state_file )['requests'], $req_before );
$var_patches = array_filter( $new_reqs, function ( $r ) {
	return 'PATCH' === $r['method'] && false !== strpos( $r['path'], '/variations/' );
} );
$prod_patches = array_filter( $new_reqs, function ( $r ) {
	return 'PATCH' === $r['method'] && preg_match( '#^/v1/products/\d+$#', $r['path'] );
} );
$vp = reset( $var_patches );
bsh_t_ok( 1 === count( $var_patches ) && 3330000 === $vp['body']['primary_price'], 'exactly one variant PATCH with the new price' );
bsh_t_ok( ! array_filter( $prod_patches, function ( $r ) {
	return isset( $r['body']['variants'] );
} ), 'the variant list was not re-sent' );
bsh_t_ok( 4 === count( bsh_t_remote( $state_file, $tee->get_id() )['variants'] ), 'still 4 variants on Basalam' );

echo "\n[V4] Variation stock change from an order → parent queued, only stock sent\n";
wc_update_product_stock( wc_get_product( $children[0] ), 1, 'set' );
bsh_t_ok( 1 === bsh_t_pending_product_actions(), 'variation stock change queued the parent' );
$req_before = count( bsh_t_mock( $state_file )['requests'] );
bsh_t_run_queue();
$vp = array_values( array_filter( array_slice( bsh_t_mock( $state_file )['requests'], $req_before ), function ( $r ) {
	return false !== strpos( $r['path'], '/variations/' );
} ) );
bsh_t_ok( 1 === count( $vp ) && array( 'stock' => 1 ) === $vp[0]['body'], 'only the stock of that variant was sent' );

echo "\n[V5] New variation → full list re-sent, mapped again, no duplicates (replace)\n";
$v = new WC_Product_Variation();
$v->set_parent_id( $tee->get_id() );
$v->set_attributes( array( sanitize_title( 'رنگ' ) => 'قرمز', sanitize_title( 'سایز' ) => 'XL' ) );
$v->set_regular_price( '250000' );
$v->set_status( 'publish' );
$attrs = $tee->get_attributes();
$attrs[ sanitize_title( 'سایز' ) ]->set_options( array( 'S', 'M', 'XL' ) );
$tee->set_attributes( $attrs );
BSH_Plugin::$suspend_hooks = true;
$tee->save();
BSH_Plugin::$suspend_hooks = false;
$v->save();
bsh_t_run_queue();
$remote = bsh_t_remote( $state_file, $tee->get_id() );
$map    = BSH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
bsh_t_ok( 5 === count( $remote['variants'] ) && 5 === count( $map ), '5 variants on Basalam, 5 in the map' );
bsh_t_ok( ! array_diff( wp_list_pluck( $map, 'id' ), wp_list_pluck( $remote['variants'], 'id' ) ), 'map points at the new variant IDs' );
bsh_t_ok( 'variants_mismatch' !== bsh_t_last_log( $tee->get_id() )->event, 'no mismatch warning' );

echo "\n[V6] If Basalam APPENDS instead of replacing → detected and reported, no silent duplicates\n";
$s                  = bsh_t_mock( $state_file );
$s['variants_mode'] = 'append';
file_put_contents( $state_file, json_encode( $s ) );
$kids = $tee->get_children();
$last = wc_get_product( end( $kids ) );
$last->delete( true );
bsh_t_run_queue();
$warn = BSH_Logger::query( array( 'object_id' => $tee->get_id(), 'level' => 'warning', 'per_page' => 1 ) )['items'];
bsh_t_ok( $warn && 'variants_mismatch' === $warn[0]->event && false !== strpos( $warn[0]->reason, 'تکراری' ), 'warning: duplicate variants on Basalam, in Persian' );
$remote = bsh_t_remote( $state_file, $tee->get_id() );
$map    = BSH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
bsh_t_ok( 4 === count( $map ) && min( wp_list_pluck( $map, 'id' ) ) > max( array_slice( wp_list_pluck( $remote['variants'], 'id' ), 0, 5 ) ), 'map uses the newest variant of each row' );
$s                  = bsh_t_mock( $state_file );
$s['variants_mode'] = 'replace';
file_put_contents( $state_file, json_encode( $s ) );

echo "\n[V7] Variation set to «any» → blocked with a clear reason\n";
$any = bsh_t_variable( 'کلاه ' . wp_generate_password( 4, false ), array( 'سبز' ), array( 'L' ) );
$c   = wc_get_product( $any->get_children()[0] );
$c->set_attributes( array( sanitize_title( 'رنگ' ) => '', sanitize_title( 'سایز' ) => 'L' ) );
BSH_Plugin::$suspend_hooks = true;
$c->save();
BSH_Plugin::$suspend_hooks = false;
BSH_Queue::enqueue_product( $any->get_id() );
bsh_t_run_queue();
$log = bsh_t_last_log( $any->get_id() );
bsh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'هرکدام' ), '«هرکدام» explained' );

echo "\n[V8] Disabled and zero-price variations\n";
$mix = bsh_t_variable( 'جوراب ' . wp_generate_password( 4, false ), array( 'مشکی', 'سفید' ), array( 'M' ) );
$ch  = $mix->get_children();
$off = wc_get_product( $ch[1] );
$off->set_status( 'private' ); // "Enabled" unchecked in WooCommerce.
BSH_Plugin::$suspend_hooks = true;
$off->save();
BSH_Plugin::$suspend_hooks = false;
BSH_Queue::enqueue_product( $mix->get_id() );
bsh_t_run_queue();
$remote = bsh_t_remote( $state_file, $mix->get_id() );
bsh_t_ok( $remote && 1 === count( $remote['variants'] ), 'disabled variation not sent' );
$zero = wc_get_product( $ch[0] );
$zero->set_regular_price( '' );
BSH_Plugin::$suspend_hooks = true;
$zero->save();
BSH_Plugin::$suspend_hooks = false;
BSH_Queue::enqueue_product( $mix->get_id(), true );
bsh_t_run_queue();
$log = bsh_t_last_log( $mix->get_id() );
bsh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'مشکی' ), 'variation without price named in the error' );

echo "\n[V9] Variant deleted on Basalam → explained; retry re-sends the full list\n";
$s      = bsh_t_mock( $state_file );
$bid    = (int) BSH_Links::get( 'product', $tee->get_id() )->basalam_id;
$map    = BSH_Product_Sync::variant_map( wc_get_product( $tee->get_id() ) );
$gone   = (int) reset( $map )['id'];
$s['products'][ $bid ]['variants'] = array_values( array_filter( $s['products'][ $bid ]['variants'], function ( $v ) use ( $gone ) {
	return (int) $v['id'] !== $gone;
} ) );
file_put_contents( $state_file, json_encode( $s ) );
BSH_Queue::enqueue_product( $tee->get_id(), true );
bsh_t_run_queue();
$log = bsh_t_last_log( $tee->get_id() );
bsh_t_ok( $log && 'error' === $log->level && false !== strpos( $log->reason, 'حذف شده' ), '404 on a variant explained' );
BSH_Queue::retry_from_log( $log );
bsh_t_run_queue();
$remote = bsh_t_remote( $state_file, $tee->get_id() );
bsh_t_ok( 4 === count( $remote['variants'] ) && 'synced' === BSH_Links::get( 'product', $tee->get_id() )->sync_status, 'retry restored all 4 variants' );

echo "\n[V10] Global attribute terms are sent by name, not slug\n";
$attr_id = wc_attribute_taxonomy_id_by_name( 'bshcolor' );
if ( ! $attr_id ) {
	$attr_id = wc_create_attribute( array( 'name' => 'رنگ‌بندی', 'slug' => 'bshcolor' ) );
}
delete_transient( 'wc_attribute_taxonomies' );
if ( class_exists( 'WC_Cache_Helper' ) ) {
	WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
}
wp_cache_flush();
// A freshly created attribute is registered on the next request; do it now, with its label.
register_taxonomy( 'pa_bshcolor', 'product', array( 'labels' => array( 'name' => 'رنگ‌بندی', 'singular_name' => 'رنگ‌بندی' ) ) );
$term = term_exists( 'سرمه‌ای', 'pa_bshcolor' ) ?: wp_insert_term( 'سرمه‌ای', 'pa_bshcolor', array( 'slug' => 'navy' ) );
$g    = new WC_Product_Variable();
$g->set_name( 'شال ' . wp_generate_password( 4, false ) );
$g->set_status( 'publish' );
$ga = new WC_Product_Attribute();
$ga->set_id( $attr_id );
$ga->set_name( 'pa_bshcolor' );
$ga->set_options( array( (int) $term['term_id'] ) );
$ga->set_variation( true );
$g->set_attributes( array( $ga ) );
$g->set_image_id( bsh_t_image() );
BSH_Plugin::$suspend_hooks = true;
$gid = $g->save();
wp_set_object_terms( $gid, array( (int) $term['term_id'] ), 'pa_bshcolor' );
$gv = new WC_Product_Variation();
$gv->set_parent_id( $gid );
$gv->set_attributes( array( 'pa_bshcolor' => 'navy' ) );
$gv->set_regular_price( '90000' );
$gv->set_status( 'publish' );
$gv->save();
BSH_Plugin::$suspend_hooks = false;
$mapped = ( new BSH_Product_Mapper() )->map( wc_get_product( $gid ) );
$prop   = $mapped['payload']['variants'][0]['properties'][0];
bsh_t_ok( 'رنگ‌بندی' === $prop['property'] && 'سرمه‌ای' === $prop['value'], 'attribute label and term name (not «navy»)' );

echo "\n[V11] Only «description» selected → variant prices untouched\n";
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'sync_fields' => array( 'description' ) ) ) );
$v0 = wc_get_product( wc_get_product( $tee->get_id() )->get_children()[0] );
$v0->set_regular_price( '999000' );
$v0->save();
$req_before = count( bsh_t_mock( $state_file )['requests'] );
bsh_t_run_queue();
bsh_t_ok( ! array_filter( array_slice( bsh_t_mock( $state_file )['requests'], $req_before ), function ( $r ) {
	return false !== strpos( $r['path'], '/variations/' ) || isset( $r['body']['variants'] ) || isset( $r['body']['primary_price'] );
} ), 'no price sent when «قیمت» is not selected' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'sync_fields' => array_keys( BSH_Settings::field_groups() ) ) ) );

if ( file_exists( __DIR__ . '/phase3-link.php' ) ) {
	require __DIR__ . '/phase3-link.php';
}

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
if ( $GLOBALS['bsh_failures'] ) {
	exit( 1 );
}
