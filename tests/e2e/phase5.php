<?php
/**
 * Phase 5 scenarios: whole-booth import, nightly order reconciliation, Telegram/Bale alerts.
 *
 *   BSH_MOCK_STATE=… wp eval-file tests/e2e/phase5.php
 *
 * @package BasalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
add_filter( 'pre_wp_mail', '__return_true' ); // No mail server in the test box.
// The mock serves photos from 127.0.0.1:8099; real Basalam photos come from statics.basalam.com.
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter(
	'http_allowed_safe_ports',
	function ( $ports ) {
		$ports[] = 8099;
		return $ports;
	}
);
add_filter(
	'bsh_notify_api_base',
	function () {
		return 'http://127.0.0.1:8099';
	}
);

bsh_t_fresh_start( $state_file );
delete_option( BSH_Importer::STATE );
delete_option( BSH_Linker::STATE );
delete_option( BSH_Reconcile::OPTION );
delete_option( BSH_Notifier::OPTION );
$wpdb->query( 'DELETE FROM ' . BSH_Linker::table() ); // phpcs:ignore
// Products left by an earlier run would look like «مشکوک» matches of today's booth.
$bsh_old = array_unique(
	array_merge(
		$wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bsh_imported_from'" ),
		$wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'BS-%'" ),
		$wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE 'گلاب کاشان %'" )
	)
);
foreach ( $bsh_old as $bsh_id ) {
	$bsh_p = wc_get_product( $bsh_id );
	if ( $bsh_p ) {
		$bsh_p->delete( true );
	}
}
BSH_Settings::set_token( 'good-token' );
BSH_Admin::test_connection();
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'default_category_id' => '1287', 'orders_enabled' => 1, 'safety_stock' => '0', 'stock_reference' => 'site' ) ) );

/** Puts a product straight into the mock booth (as if made in the Basalam panel). */
function bsh_t_booth_add( $state_file, array $p ) {
	$s       = bsh_t_mock( $state_file );
	$id      = $s['next_id']++;
	$p['id'] = $id;
	if ( ! empty( $p['variants'] ) ) {
		foreach ( $p['variants'] as $i => $v ) {
			$p['variants'][ $i ]['id'] = $s['next_id']++;
		}
	}
	$s['products'][ $id ] = $p;
	file_put_contents( $state_file, json_encode( $s ) );
	return $id;
}

function bsh_t_booth_set( $state_file, $id, array $changes ) {
	$s                    = bsh_t_mock( $state_file );
	$s['products'][ $id ] = array_replace( $s['products'][ $id ], $changes );
	file_put_contents( $state_file, json_encode( $s ) );
}

function bsh_t_snapshot() {
	BSH_Linker::start();
	bsh_t_run_all();
	return BSH_Linker::state()['status'];
}

function bsh_t_import( array $options = array() ) {
	$r = BSH_Importer::start( array_merge( array( 'update_linked' => 1, 'publish' => 'publish' ), $options ) );
	bsh_t_run_all( 200 );
	return $r;
}

function bsh_t_prop( $name, $value ) {
	return array( 'property' => array( 'id' => crc32( $name ) % 100, 'title' => $name ), 'value' => array( 'id' => crc32( $value ) % 1000, 'title' => $value ) );
}

function bsh_t_imported( $bid ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bsh_imported_from' AND meta_value = %s", (string) $bid ) ) );
}

$sfx = wp_generate_password( 4, false );

// A booth with: a simple product, a variable product, one that the site already has (same
// SKU, not linked), and one that is already linked.
$bid_a = bsh_t_booth_add(
	$state_file,
	array(
		'name'                 => 'عسل کنار باسلام ' . $sfx,
		'sku'                  => 'BS-A-' . $sfx,
		'primary_price'        => 2500000,
		'price'                => 2000000,
		'stock'                => 5,
		'photo'                => 501,
		'photos'               => array( 502 ),
		'category'             => array( 'id' => 777, 'title' => 'عسل و ساکاب ' . $sfx ),
		'description'          => "خط اول توضیحات\nخط دوم",
		'net_weight'           => 1200,
		'packaging_dimensions' => array( 'length' => 10, 'width' => 20, 'height' => 30 ),
		'preparation_day'      => 5,
	)
);
$bid_b = bsh_t_booth_add(
	$state_file,
	array(
		'name'          => 'شال دست‌باف ' . $sfx,
		'primary_price' => 3000000,
		'stock'         => 0,
		'photo'         => 503,
		'category'      => array( 'id' => 778, 'title' => 'پوشاک ' . $sfx ),
		'variants'      => array(
			array( 'primary_price' => 3000000, 'stock' => 2, 'sku' => 'BS-B1-' . $sfx, 'properties' => array( bsh_t_prop( 'رنگ', 'قرمز' ), bsh_t_prop( 'سایز', 'بزرگ' ) ) ),
			array( 'primary_price' => 3200000, 'stock' => 4, 'sku' => 'BS-B2-' . $sfx, 'properties' => array( bsh_t_prop( 'رنگ', 'آبی' ), bsh_t_prop( 'سایز', 'بزرگ' ) ) ),
		),
	)
);
$site_c = bsh_t_product( array( 'name' => 'زعفران سایت ' . $sfx, 'sku' => 'BS-C-' . $sfx, 'no_image' => 1 ) );
$bid_c  = bsh_t_booth_add( $state_file, array( 'name' => 'زعفران قائنات ' . $sfx, 'sku' => 'BS-C-' . $sfx, 'primary_price' => 900000, 'stock' => 3 ) );
$site_d = bsh_t_product( array( 'name' => 'گلاب کاشان ' . $sfx, 'stock' => 6 ) );
BSH_Queue::enqueue_product( $site_d->get_id() );
bsh_t_run_all();
$bid_d = (int) BSH_Links::get( 'product', $site_d->get_id() )->basalam_id;

echo "I1 Preview before anything is created\n";
bsh_t_ok( ! BSH_Importer::preview()['ready'], 'no snapshot yet → not ready' );
bsh_t_ok( is_wp_error( BSH_Importer::start( array() ) ), 'start refused without a snapshot' );
bsh_t_ok( 'ready' === bsh_t_snapshot(), 'booth fetched and matched' );
$pv = BSH_Importer::preview();
bsh_t_ok( 2 === $pv['new'] && 1 === $pv['linked'] && 1 === $pv['review'], 'new 2 / linked 1 / needs review 1 (same SKU)' );

echo "I2 Import creates the products with everything\n";
$files_before = count( bsh_t_requests( $state_file, 'POST', '#^/v1/files#' ) );
$patch_before = count( bsh_t_requests( $state_file, 'PATCH', '#^/v1/products/#' ) ) + count( bsh_t_requests( $state_file, 'POST', '#^/v1/vendors/\d+/products$#' ) );
bsh_t_ok( true === bsh_t_import(), 'import started' );
$st = BSH_Importer::state();
bsh_t_ok( 'done' === $st['status'] && 2 === $st['created'] && 1 === $st['updated'] && 0 === $st['failed'], 'done: 2 created, 1 updated, 0 failed' );
$a_ids = bsh_t_imported( $bid_a );
bsh_t_ok( 1 === count( $a_ids ), 'product A exists once' );
$a = wc_get_product( $a_ids[0] );
bsh_t_ok( 'عسل کنار باسلام ' . $sfx === $a->get_name() && 'publish' === $a->get_status(), 'name and status' );
bsh_t_ok( '250000' === $a->get_regular_price() && '200000' === $a->get_sale_price(), 'Rial → Toman, Basalam discount kept as sale price' );
bsh_t_ok( 5 === $a->get_stock_quantity() && $a->managing_stock(), 'stock managed: 5' );
bsh_t_ok( 'BS-A-' . $sfx === $a->get_sku(), 'SKU kept' );
bsh_t_ok( 1.2 === (float) $a->get_weight() && 10.0 === (float) $a->get_length() && 30.0 === (float) $a->get_height(), 'weight g → kg, dimensions' );
bsh_t_ok( false !== strpos( $a->get_description(), '<p>' ) && false !== mb_strpos( $a->get_description(), 'خط دوم' ), 'description as paragraphs' );
bsh_t_ok( 5 === (int) $a->get_meta( '_bsh_preparation_days' ), 'preparation days stored (differs from default)' );
bsh_t_ok( $a->get_image_id() && 1 === count( $a->get_gallery_image_ids() ), 'main photo + gallery' );
bsh_t_ok( 501 === (int) get_post_meta( $a->get_image_id(), BSH_Image_Sync::META_ID, true ), 'photo remembers its Basalam file id' );
$terms = $a->get_category_ids();
$term  = $terms ? get_term( $terms[0], 'product_cat' ) : null;
bsh_t_ok( $term && 'عسل و ساکاب ' . $sfx === $term->name, 'category created with Basalam’s name' );
bsh_t_ok( 777 === (int) BSH_Categories::map()[ $term->term_id ]['category_id'], '…and added to the category mapping' );
$link_a = BSH_Links::get( 'product', $a->get_id() );
bsh_t_ok( $link_a && $bid_a === (int) $link_a->basalam_id && 'synced' === $link_a->sync_status && $link_a->payload_hash, 'linked at once, with a change hash' );

$b_ids = bsh_t_imported( $bid_b );
$b     = wc_get_product( $b_ids[0] );
bsh_t_ok( $b && $b->is_type( 'variable' ) && 2 === count( $b->get_children() ), 'variable product with 2 variations' );
$attrs = $b->get_attributes();
bsh_t_ok( 2 === count( $attrs ) && array( 'قرمز', 'آبی' ) === array_values( reset( $attrs )->get_options() ), 'attributes رنگ/سایز with values' );
$map = BSH_Product_Sync::variant_map( $b );
$remote_b = bsh_t_mock( $state_file )['products'][ $bid_b ];
$ok = 2 === count( $map );
foreach ( $map as $vid => $row ) {
	$v = wc_get_product( $vid );
	foreach ( $remote_b['variants'] as $rv ) {
		if ( (int) $rv['id'] === (int) $row['id'] ) {
			$ok = $ok && (int) $rv['stock'] === $v->get_stock_quantity() && $rv['sku'] === $v->get_sku() && (float) ( $rv['primary_price'] / 10 ) === (float) $v->get_regular_price();
		}
	}
}
bsh_t_ok( $ok, 'each variation mapped to its Basalam variant (price, stock, SKU)' );
bsh_t_ok( ! bsh_t_imported( $bid_c ) && 1 === count( wc_get_products( array( 'sku' => 'BS-C-' . $sfx, 'return' => 'ids' ) ) ), 'same-SKU product not duplicated' );

echo "I3 Nothing is pushed back to Basalam after an import\n";
bsh_t_run_all();
$after = count( bsh_t_requests( $state_file, 'PATCH', '#^/v1/products/#' ) ) + count( bsh_t_requests( $state_file, 'POST', '#^/v1/vendors/\d+/products$#' ) );
bsh_t_ok( $after === $patch_before, 'no product created or updated on Basalam' );
bsh_t_ok( count( bsh_t_requests( $state_file, 'POST', '#^/v1/files#' ) ) === $files_before, 'no photo uploaded' );
BSH_Queue::enqueue_product( $a->get_id() );
bsh_t_run_all();
bsh_t_ok( ( count( bsh_t_requests( $state_file, 'PATCH', '#^/v1/products/#' ) ) + count( bsh_t_requests( $state_file, 'POST', '#^/v1/vendors/\d+/products$#' ) ) ) === $patch_before, 'a sync with no change sends nothing' );
BSH_Queue::enqueue_product( $a->get_id(), true );
bsh_t_run_all();
bsh_t_ok( count( bsh_t_requests( $state_file, 'POST', '#^/v1/files#' ) ) === $files_before, 'forced sync reuses Basalam’s photos (no re-upload)' );
bsh_t_ok( 1 === count( bsh_t_requests( $state_file, 'PATCH', '#^/v1/products/' . $bid_a . '$#' ) ), 'forced sync updates the same Basalam product' );
BSH_Queue::enqueue_product( $b->get_id(), true );
bsh_t_run_all();
bsh_t_ok( 2 === count( bsh_t_mock( $state_file )['products'][ $bid_b ]['variants'] ), 'variable product: no duplicate variants after a sync' );

echo "I4 Running it again updates, never duplicates\n";
bsh_t_booth_set( $state_file, $bid_a, array( 'stock' => 9, 'primary_price' => 2600000, 'price' => 2600000 ) );
bsh_t_snapshot();
$pv = BSH_Importer::preview();
bsh_t_ok( 0 === $pv['new'] && 3 === $pv['linked'], 'second preview: nothing new, 3 linked' );
bsh_t_import();
$st = BSH_Importer::state();
bsh_t_ok( 0 === $st['created'] && 3 === $st['updated'], '0 created, 3 updated' );
$a = wc_get_product( $a->get_id() );
bsh_t_ok( 9 === $a->get_stock_quantity() && '260000' === $a->get_regular_price() && '' === $a->get_sale_price(), 'stock and price updated, sale removed' );
bsh_t_ok( 1 === count( bsh_t_imported( $bid_a ) ) && 1 === count( bsh_t_imported( $bid_b ) ), 'still one product each' );

echo "I5 With a price rule, imported prices are not touched on update\n";
BSH_Price_Rules::save( array( 'global' => array( 'type' => 'percent', 'direction' => 'up', 'value' => '10' ) ) );
bsh_t_booth_set( $state_file, $bid_a, array( 'stock' => 4, 'primary_price' => 2860000, 'price' => 2860000 ) );
bsh_t_snapshot();
bsh_t_import();
$a = wc_get_product( $a->get_id() );
bsh_t_ok( 4 === $a->get_stock_quantity() && '260000' === $a->get_regular_price(), 'stock updated, price kept (rule would apply twice)' );
BSH_Price_Rules::save( array() );

echo "I6 Safety stock is added back on import\n";
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'safety_stock' => '2' ) ) );
$bid_s = bsh_t_booth_add( $state_file, array( 'name' => 'روغن کنجد ' . $sfx, 'primary_price' => 1000000, 'stock' => 3 ) );
bsh_t_snapshot();
bsh_t_import( array( 'update_linked' => 0 ) );
$sp = wc_get_product( bsh_t_imported( $bid_s )[0] );
bsh_t_ok( 5 === $sp->get_stock_quantity(), 'Basalam shows 3 with safety 2 → site 5' );
bsh_t_ok( 3 === ( new BSH_Product_Mapper() )->map( $sp )['payload']['stock'], 'and it maps back to 3 for Basalam' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'safety_stock' => '0' ) ) );

echo "I6b Import of new products only: every one is created (no skipping)\n";
$bid_r = array();
for ( $i = 0; $i < 5; $i++ ) {
	$bid_r[] = bsh_t_booth_add( $state_file, array( 'name' => 'ردیف ' . $i . ' ' . $sfx, 'primary_price' => 1000000 + $i, 'stock' => 1 ) );
}
bsh_t_snapshot();
bsh_t_import( array( 'update_linked' => 0 ) );
$made = 0;
foreach ( $bid_r as $b ) {
	$made += count( bsh_t_imported( $b ) );
}
bsh_t_ok( 5 === $made && 5 === BSH_Importer::state()['created'], 'all 5 created in one run (regression: the old offset walk skipped every other one)' );

echo "I7 Last guard: a SKU that appeared on the site meanwhile is not duplicated\n";
$bid_e = bsh_t_booth_add( $state_file, array( 'name' => 'پسته اکبری ' . $sfx, 'sku' => 'BS-E-' . $sfx, 'primary_price' => 1000000, 'stock' => 1 ) );
bsh_t_product( array( 'name' => 'محصول دیگر ' . $sfx, 'sku' => 'BS-E-' . $sfx, 'no_image' => 1 ) );
bsh_t_ok( 'skipped' === BSH_Importer::import_one( $bid_e, array( 'update_linked' => 1, 'publish' => 'publish' ) ), 'skipped' );
$log = BSH_Logger::query( array( 'object_type' => 'import', 'object_id' => $bid_e, 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'import_sku_exists' === $log->event && $log->suggestion, 'warning tells you to link them' );

echo "I8 A broken photo doesn't block the product\n";
$bid_f = bsh_t_booth_add( $state_file, array( 'name' => 'کشک محلی ' . $sfx, 'primary_price' => 500000, 'stock' => 2, 'photo' => array( 'id' => 9999, 'original' => 'http://127.0.0.1:8099/mock-files/broken-1.jpg' ) ) );
bsh_t_ok( 'created' === BSH_Importer::import_one( $bid_f, array( 'update_linked' => 1, 'publish' => 'draft' ) ), 'created' );
$f = wc_get_product( bsh_t_imported( $bid_f )[0] );
bsh_t_ok( ! $f->get_image_id() && 'draft' === $f->get_status(), 'no photo, saved as draft (option)' );
$log = BSH_Logger::query( array( 'object_type' => 'product', 'object_id' => $f->get_id(), 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'warning' === $log->level && $log->suggestion, 'warning about the missing photo' );

echo "I9 Failure → error with retry, retry works\n";
$bid_g = bsh_t_booth_add( $state_file, array( 'name' => 'گردو ' . $sfx, 'primary_price' => 700000, 'stock' => 2 ) );
bsh_t_set_fail( $state_file, array( 'GET /v1/products/*' => array( 'status' => 403, 'times' => 1 ) ) );
bsh_t_ok( 'failed' === BSH_Importer::import_one( $bid_g, array( 'update_linked' => 1, 'publish' => 'publish' ) ), 'failed on 403' );
$log = BSH_Logger::query( array( 'object_type' => 'import', 'object_id' => $bid_g, 'per_page' => 1 ) )['items'][0];
bsh_t_ok( 'error' === $log->level && 'bsh_import_one' === $log->retry_hook, 'error log with retry' );
bsh_t_ok( BSH_Queue::retry_from_log( $log ), 'retry queued' );
bsh_t_run_all();
bsh_t_ok( 1 === count( bsh_t_imported( $bid_g ) ), 'imported on retry' );
bsh_t_ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE object_type = 'import' AND object_id = %d AND level = 'error' AND resolved = 0", $bid_g ) ), 'error resolved' ); // phpcs:ignore

echo "I10 Temporary failure retries the same product\n";
$bid_h = bsh_t_booth_add( $state_file, array( 'name' => 'بادام ' . $sfx, 'primary_price' => 700000, 'stock' => 2 ) );
bsh_t_snapshot();
bsh_t_set_fail( $state_file, array( 'GET /v1/products/*' => array( 'status' => 503, 'times' => 1 ) ) );
BSH_Importer::start( array( 'update_linked' => 0 ) );
bsh_t_run_all();
$retry = as_get_scheduled_actions( array( 'hook' => BSH_Importer::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
bsh_t_ok( 1 === count( $retry ) && BSH_Importer::is_running(), 'retry scheduled, import still running' );
ActionScheduler::runner()->process_action( reset( $retry ), 'e2e' );
bsh_t_run_all();
bsh_t_ok( 1 === count( bsh_t_imported( $bid_h ) ) && 'done' === BSH_Importer::state()['status'], 'imported on the retry, run finished' );

echo "I11 One heavy job at a time; stop works\n";
bsh_t_booth_add( $state_file, array( 'name' => 'خرما ' . $sfx, 'primary_price' => 700000, 'stock' => 2 ) );
bsh_t_booth_add( $state_file, array( 'name' => 'کشمش ' . $sfx, 'primary_price' => 700000, 'stock' => 2 ) );
bsh_t_snapshot();
BSH_Importer::start( array( 'update_linked' => 0 ) );
bsh_t_ok( is_wp_error( BSH_Linker::start() ) && is_wp_error( BSH_Bulk::start( array( 'scope' => 'all' ) ) ), 'linker and bulk refuse while importing' );
bsh_t_ok( is_wp_error( BSH_Importer::start( array() ) ), 'a second import refuses' );
BSH_Importer::cancel();
bsh_t_run_all();
bsh_t_ok( 'cancelled' === BSH_Importer::state()['status'] && 0 === BSH_Importer::state()['created'], 'cancelled before creating anything' );

/* ---------------------------------------------------------------------
 * Nightly reconciliation
 * ------------------------------------------------------------------ */

function bsh_t_parcel5( $state_file, array $over = array() ) {
	static $n = 0;
	++$n;
	$id = 90000 + $n * 7 + wp_rand( 0, 999 ) * 100;
	$p  = array_replace_recursive(
		array(
			'id'                => $id,
			'created_at'        => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
			'total_items_price' => 1000000,
			'shipping_cost'     => 0,
			'status'            => array( 'id' => 3739, 'title' => 'سفارش جدید' ),
			'items'             => array( array( 'id' => 1, 'title' => 'کالا', 'quantity' => 1, 'weight' => 1, 'price' => 1000000, 'product' => array( 'id' => 1 ), 'variation' => null ) ),
			'order'             => array( 'id' => 1, 'customer' => array( 'recipient' => array( 'name' => 'علی رضایی', 'mobile' => '0912' ), 'city' => array( 'title' => 'شیراز' ) ) ),
		),
		$over
	);
	$s = bsh_t_mock( $state_file );
	$s['parcels'][ (string) $id ] = $p;
	file_put_contents( $state_file, json_encode( $s ) );
	return $id;
}

function bsh_t_order_of( $parcel_id ) {
	return wc_get_orders( array( 'limit' => 5, 'return' => 'ids', 'status' => 'any', 'meta_key' => BSH_Order_Sync::META_PARCEL, 'meta_value' => (string) $parcel_id ) ); // phpcs:ignore
}

echo "R1 Finds orders that never reached the site\n";
$p1 = bsh_t_parcel5( $state_file );
$p2 = bsh_t_parcel5( $state_file, array( 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 5 * DAY_IN_SECONDS ) ) );
$p3 = bsh_t_parcel5( $state_file );
BSH_Order_Sync::import( $p3 ); // This one arrived normally.
$old = bsh_t_parcel5( $state_file, array( 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 9 * DAY_IN_SECONDS ) ) );
$cxl = bsh_t_parcel5( $state_file, array( 'status' => array( 'id' => 3067, 'title' => 'لغو' ) ) );
$res = BSH_Reconcile::run();
bsh_t_ok( 2 === $res['missing'] && in_array( $p1, $res['ids'], true ) && in_array( $p2, $res['ids'], true ), '2 missing found (yesterday and 5 days ago)' );
bsh_t_ok( ! in_array( $old, $res['ids'], true ) && ! in_array( $cxl, $res['ids'], true ) && ! in_array( $p3, $res['ids'], true ), 'older than 7 days, cancelled and already-imported ones are not counted' );
bsh_t_run_all();
bsh_t_ok( 1 === count( bsh_t_order_of( $p1 ) ) && 1 === count( bsh_t_order_of( $p2 ) ), 'both are now WooCommerce orders' );
$log = BSH_Logger::query( array( 'search' => 'سفارش باسلام در سایت نبود', 'per_page' => 1 ) )['items'][0];
bsh_t_ok( $log && 'warning' === $log->level && 'reconcile_missing' === $log->event, 'reported in the log' );
bsh_t_ok( 2 === BSH_Reconcile::last()['missing'], 'last result stored for the orders page' );

echo "R2 Second night: nothing missing\n";
$res = BSH_Reconcile::run();
bsh_t_ok( 0 === $res['missing'] && $res['checked'] >= 4, 'all orders present' );
bsh_t_ok( 1 === count( bsh_t_order_of( $p1 ) ), 'no duplicate order' );

echo "R3 Scheduled every night while orders are on\n";
BSH_Reconcile::schedule();
$next = as_next_scheduled_action( BSH_Reconcile::HOOK, array( 'manual' => 0 ), 'basalamhub' );
$local = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
bsh_t_ok( $next && '03' === $local->format( 'H' ) && $next - time() <= DAY_IN_SECONDS + HOUR_IN_SECONDS, 'next run tonight around 03:00 site time' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'orders_enabled' => 0 ) ) );
bsh_t_ok( ! as_next_scheduled_action( BSH_Reconcile::HOOK, array( 'manual' => 0 ), 'basalamhub' ), 'removed when orders are off' );
BSH_Settings::save( array_merge( BSH_Settings::all(), array( 'orders_enabled' => 1 ) ) );

echo "R4 Failure is visible on the health page\n";
bsh_t_set_fail( $state_file, array( 'GET /v1/vendor-parcels' => array( 'status' => 403, 'times' => 1 ) ) );
BSH_Reconcile::handle( 1 );
bsh_t_ok( BSH_Reconcile::last()['error'], 'error stored' );
$bad = array_filter( BSH_App::health_checks(), function ( $c ) { return 'تطبیق شبانه‌ی سفارش‌ها' === $c[1]; } );
bsh_t_ok( $bad && 'bad' === reset( $bad )[0], 'health check is red' );
BSH_Reconcile::handle( 1 );
$ok = array_filter( BSH_App::health_checks(), function ( $c ) { return 'تطبیق شبانه‌ی سفارش‌ها' === $c[1]; } );
bsh_t_ok( 'ok' === reset( $ok )[0], 'green again after a good run' );

/* ---------------------------------------------------------------------
 * Notifications
 * ------------------------------------------------------------------ */

function bsh_t_bot( $state_file ) {
	$m = bsh_t_mock( $state_file );
	return isset( $m['bot_messages'] ) ? $m['bot_messages'] : array();
}

echo "N1 Settings are validated and the token is encrypted\n";
$errors = BSH_Notifier::save( array( 'bale' => array( 'enabled' => 1, 'token' => 'not-a-token', 'chat_id' => 'abc' ) ) );
bsh_t_ok( isset( $errors['bale_token'], $errors['bale_chat_id'] ), 'bad token and chat id rejected with Persian messages' );
$token = '123456789:AAHgoodTokenForBaleBot_0123456789';
BSH_Notifier::save( array( 'bale' => array( 'enabled' => 1, 'token' => $token, 'chat_id' => '' ), 'events' => array( 'new_order' => 1, 'errors' => 1, 'reconcile' => 1 ) ) );
bsh_t_ok( false === strpos( (string) wp_json_encode( get_option( BSH_Notifier::OPTION ) ), 'AAHgood' ) && $token === BSH_Notifier::token( 'bale' ), 'token stored encrypted, readable by the plugin' );
bsh_t_ok( ! BSH_Notifier::active_channels(), 'not active without a chat id' );

echo "N2 Find chat id, test message\n";
$found = BSH_Notifier::find_chat( 'bale' );
bsh_t_ok( isset( $found['id'] ) && '4242' === $found['id'] && 'طاها' === $found['title'], 'chat found from the bot’s updates' );
bsh_t_ok( array( 'bale' ) === BSH_Notifier::active_channels(), 'channel active' );
bsh_t_ok( true === BSH_Notifier::send( 'bale', 'تست' ), 'test message sent' );
$msgs = bsh_t_bot( $state_file );
bsh_t_ok( 'تست' === end( $msgs )['text'] && '4242' === (string) end( $msgs )['chat_id'], 'arrived at the bot with the right chat' );

echo "N3 New Basalam order → message with details\n";
$count = count( bsh_t_bot( $state_file ) );
$p9    = bsh_t_parcel5( $state_file );
BSH_Order_Sync::import( $p9 );
bsh_t_run_all();
$msgs = array_slice( bsh_t_bot( $state_file ), $count );
$text = $msgs ? end( $msgs )['text'] : '';
$o9   = wc_get_order( bsh_t_order_of( $p9 )[0] );
bsh_t_ok( 1 === count( $msgs ) && false !== mb_strpos( $text, 'سفارش جدید باسلام' ) && false !== mb_strpos( $text, (string) $p9 ) && false !== mb_strpos( $text, 'شیراز' ), 'one message: title, Basalam number, city' );
bsh_t_ok( false !== mb_strpos( $text, $o9->get_order_number() ) && false !== strpos( $text, 'wc-orders' ), 'WooCommerce order number and edit link' );

echo "N4 Errors: sent once, not flooded\n";
$count = count( bsh_t_bot( $state_file ) );
$entry = array( 'level' => 'error', 'event' => 'test_error', 'object_type' => 'product', 'object_id' => 77, 'title' => 'محصول تست', 'message' => 'خطا رخ داد.', 'suggestion' => 'کاری کن.' );
BSH_Logger::log( $entry );
BSH_Logger::log( $entry );
bsh_t_run_all();
$msgs = array_slice( bsh_t_bot( $state_file ), $count );
bsh_t_ok( 1 === count( $msgs ) && false !== mb_strpos( $msgs[0]['text'], 'راه‌حل' ), 'same error twice → one message with the fix' );
for ( $i = 0; $i < 15; $i++ ) {
	BSH_Logger::log( array_merge( $entry, array( 'object_id' => 1000 + $i ) ) );
}
bsh_t_run_all();
bsh_t_ok( count( bsh_t_bot( $state_file ) ) - $count <= BSH_Notifier::ERROR_CAP, 'at most 10 error messages per hour' );

echo "N5 Event switches and channel switch\n";
$count = count( bsh_t_bot( $state_file ) );
$s     = BSH_Notifier::settings();
BSH_Notifier::save( array( 'bale' => array( 'enabled' => 1, 'chat_id' => '4242' ), 'events' => array( 'new_order' => 0, 'errors' => 1, 'reconcile' => 1 ) ) );
$p10 = bsh_t_parcel5( $state_file );
BSH_Order_Sync::import( $p10 );
bsh_t_run_all();
bsh_t_ok( count( bsh_t_bot( $state_file ) ) === $count, 'new-order alerts off → no message' );
BSH_Notifier::save( array( 'bale' => array( 'enabled' => 0, 'chat_id' => '4242' ), 'events' => array( 'new_order' => 1, 'errors' => 1, 'reconcile' => 1 ) ) );
BSH_Logger::log( array_merge( $entry, array( 'object_id' => 5555 ) ) );
bsh_t_run_all();
bsh_t_ok( count( bsh_t_bot( $state_file ) ) === $count, 'channel off → no message' );
BSH_Notifier::save( array( 'bale' => array( 'enabled' => 1, 'chat_id' => '4242' ), 'events' => array( 'new_order' => 1, 'errors' => 1, 'reconcile' => 1 ) ) );

echo "N6 Reconciliation result is announced\n";
$count = count( bsh_t_bot( $state_file ) );
bsh_t_parcel5( $state_file );
BSH_Reconcile::run();
bsh_t_run_all();
$msgs = array_slice( bsh_t_bot( $state_file ), $count );
bsh_t_ok( (bool) array_filter( $msgs, function ( $m ) { return false !== mb_strpos( $m['text'], 'تطبیق شبانه' ); } ), 'message about the missing order' );

echo "N7 A failing channel is explained and never loops\n";
BSH_Notifier::save( array( 'bale' => array( 'enabled' => 1, 'chat_id' => '-999' ), 'events' => array( 'new_order' => 1, 'errors' => 1, 'reconcile' => 1 ) ) );
$res = BSH_Notifier::send( 'bale', 'x' );
bsh_t_ok( is_array( $res ) && false !== mb_strpos( $res['message'], 'نمی‌تواند به این گفتگو' ) && ! $res['retryable'], 'chat not found → Persian reason, no retry' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%bsh_ntf_count_%'" ); // New hour: N4 used up the error-alert cap.
wp_cache_flush();
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE event = 'notify_failed'" ); // phpcs:ignore
BSH_Logger::log( array_merge( $entry, array( 'object_id' => 6666 ) ) );
bsh_t_run_all();
$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE event = 'notify_failed'" ); // phpcs:ignore
bsh_t_ok( 1 === $after - $before, 'one notify_failed warning, no alert about the alert' );
BSH_Notifier::save( array( 'bale' => array( 'enabled' => 1, 'token' => '123456789:AAHbadTokenForBaleBot_0123456789', 'chat_id' => '4242' ) ) );
$res = BSH_Notifier::send( 'bale', 'x' );
bsh_t_ok( is_array( $res ) && false !== mb_strpos( $res['message'], 'توکن ربات درست نیست' ), 'wrong token → clear message' );
remove_all_filters( 'bsh_notify_api_base' );
add_filter(
	'bsh_notify_api_base',
	function () {
		return 'http://127.0.0.1:1';
	}
);
BSH_Notifier::save( array( 'telegram' => array( 'enabled' => 1, 'token' => $token, 'chat_id' => '4242' ) ) );
$res = BSH_Notifier::send( 'telegram', 'x' );
bsh_t_ok( is_array( $res ) && $res['retryable'] && false !== mb_strpos( $res['suggestion'], 'بله' ), 'Telegram unreachable → suggests Bale, retries' );

echo "\n" . $GLOBALS['bsh_passes'] . ' passed, ' . $GLOBALS['bsh_failures'] . " failed\n";
