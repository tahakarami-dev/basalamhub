<?php
/**
 * Phase 2 scenarios: category mapping and attributes, price rules, image processing,
 * bulk send. Same setup as run.php.
 *
 * @package SalamHub
 */

require_once __DIR__ . '/helpers.php';

global $wpdb;
slh_t_fresh_start( $state_file );
SLH_Settings::set_token( 'good-token' );
SLH_Admin::test_connection();

function slh_t_term( $name, $parent = 0 ) {
	$t = term_exists( $name, 'product_cat', $parent );
	if ( ! $t ) {
		$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	}
	return (int) $t['term_id'];
}

function slh_t_product_in( $term_id, array $props = array() ) {
	$p = slh_t_product( $props );
	$p->set_category_ids( array( $term_id ) );
	SLH_Plugin::$suspend_hooks = true;
	$p->save();
	SLH_Plugin::$suspend_hooks = false;
	return wc_get_product( $p->get_id() );
}

function slh_t_sent( $state_file, $product_id ) {
	$link = SLH_Links::get( 'product', $product_id );
	$mock = slh_t_mock( $state_file );
	return $link && $link->basalam_id && isset( $mock['products'][ $link->basalam_id ] ) ? $mock['products'][ $link->basalam_id ] : null;
}

// Fresh WooCommerce categories for this run.
$run   = wp_generate_password( 4, false );
$food  = slh_t_term( 'خوراکی ' . $run );
$honey = slh_t_term( 'عسل ' . $run, $food );
$mount = slh_t_term( 'عسل کوهی ' . $run, $honey );
$spice = slh_t_term( 'ادویه ' . $run, $food );
$shirt = slh_t_term( 'تیشرت ' . $run );
$misc  = slh_t_term( 'متفرقه ' . $run );

echo "\n[P2-1] Basalam category tree\n";
slh_t_ok( 6 === SLH_Categories::refresh(), 'tree downloaded and flattened (6 categories)' );
$c = SLH_Categories::find( 1301 );
slh_t_ok( $c && 'خوراکی › ادویه › زعفران' === $c['path'] && $c['leaf'], 'path and leaf flag' );
slh_t_ok( ! SLH_Categories::find( 1300 )['leaf'], 'category with children is not a leaf' );

echo "\n[P2-2] Mapping validation\n";
$errors = SLH_Categories::save_map(
	array(
		$honey => array( 'category_id' => 'خوراکی › عسل (1287)' ),
		$spice => array( 'category_id' => '1300' ),
		$misc  => array( 'category_id' => '9999' ),
		$shirt => array( 'category_id' => '۲۰۰۰' ),
	)
);
slh_t_ok( isset( $errors[ $spice ] ) && false !== strpos( $errors[ $spice ], 'زیرمجموعه' ), 'non-leaf category rejected with reason' );
slh_t_ok( isset( $errors[ $misc ] ), 'unknown category ID rejected' );
$map = SLH_Categories::map();
slh_t_ok( 1287 === $map[ $honey ]['category_id'], '"path (id)" from the search box accepted' );
slh_t_ok( 2000 === $map[ $shirt ]['category_id'], 'Persian digits accepted' );

echo "\n[P2-3] Resolution order\n";
$p_mount = slh_t_product_in( $mount, array( 'name' => 'عسل کوهی سبلان' ) );
slh_t_ok( 1287 === SLH_Categories::resolve( $p_mount )['category_id'], 'child category inherits the parent mapping' );
SLH_Categories::save_map( array_replace( $map, array( $mount => array( 'category_id' => '1301' ) ) ) );
$p_mount = wc_get_product( $p_mount->get_id() );
slh_t_ok( 1301 === SLH_Categories::resolve( $p_mount )['category_id'], 'own mapping beats the parent' );
update_post_meta( $p_mount->get_id(), '_slh_category_id', 1287 );
$mapped = ( new SLH_Product_Mapper() )->map( wc_get_product( $p_mount->get_id() ) );
slh_t_ok( 1287 === $mapped['payload']['category_id'], 'per-product override beats the mapping' );
delete_post_meta( $p_mount->get_id(), '_slh_category_id' );
$p_misc = slh_t_product_in( $misc, array( 'name' => 'کالای متفرقه' ) );
$mapped = ( new SLH_Product_Mapper() )->map( $p_misc );
$msg    = implode( ' ', wp_list_pluck( $mapped['problems'], 'message' ) );
slh_t_ok( false !== strpos( $msg, 'متفرقه' ) && false !== strpos( $msg, 'نگاشت نشده' ), 'unmapped product: error names the WooCommerce category' );
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'default_category_id' => '1287' ) ) );
$mapped = ( new SLH_Product_Mapper() )->map( wc_get_product( $p_misc->get_id() ) );
slh_t_ok( 1287 === $mapped['payload']['category_id'] && ! $mapped['problems'], 'default category is the last fallback' );
SLH_Settings::save( array_merge( SLH_Settings::all(), array( 'default_category_id' => '' ) ) );
$unmapped = wp_list_pluck( SLH_Categories::unmapped_terms(), 'term_id' );
slh_t_ok( in_array( $misc, $unmapped, true ) && ! in_array( $mount, $unmapped, true ), 'unmapped list contains only categories without mapping' );

echo "\n[P2-4] Required category attributes\n";
$tee = slh_t_product_in( $shirt, array( 'name' => 'تیشرت نخی' ) );
SLH_Queue::enqueue_product( $tee->get_id() );
slh_t_run_queue();
$log = slh_t_last_log( $tee->get_id() );
slh_t_ok( $log && false !== strpos( $log->reason, 'جنس' ) && false !== strpos( $log->reason, 'یقه' ), 'missing required attributes named in Persian' );
slh_t_ok( 0 === count( slh_t_requests( $state_file, 'POST', '#/products$#' ) ), 'blocked before calling Basalam' );
$map = SLH_Categories::map();
$map[ $shirt ]['attrs'] = array( 501 => 'پلی‌استر', 502 => '7001' );
SLH_Categories::save_map( array_map( function ( $r ) { return array( 'category_id' => (string) $r['category_id'], 'attrs' => $r['attrs'] ); }, $map ) );
// The product's own WooCommerce attribute with the same name wins over the default.
$attr = new WC_Product_Attribute();
$attr->set_name( 'جنس' );
$attr->set_options( array( 'پنبه' ) );
$attr->set_visible( true );
$tee = wc_get_product( $tee->get_id() );
$tee->set_attributes( array( $attr ) );
SLH_Plugin::$suspend_hooks = true;
$tee->save();
SLH_Plugin::$suspend_hooks = false;
SLH_Queue::retry_from_log( $log );
slh_t_run_queue();
$sent = slh_t_sent( $state_file, $tee->get_id() );
$pa   = $sent ? array_column( $sent['product_attribute'], null, 'attribute_id' ) : array();
slh_t_ok( $sent && 'پنبه' === $pa[501]['value'], 'product attribute «جنس: پنبه» used instead of the default' );
slh_t_ok( $sent && array( 7001 ) === $pa[502]['selected_values'], 'select attribute sent as option ID' );

echo "\n[P2-5] Price rules\n";
$errors = SLH_Price_Rules::save( array( 'global' => array( 'type' => 'percent', 'direction' => 'down', 'value' => '100' ) ) );
slh_t_ok( isset( $errors['global'] ), '100% decrease rejected' );
SLH_Price_Rules::save(
	array(
		'global'     => array( 'type' => 'percent', 'direction' => 'up', 'value' => '۱۰' ),
		'categories' => array( $spice => array( 'type' => 'fixed', 'direction' => 'down', 'value' => '5,000' ) ),
		'rounding'   => array( 'unit' => '1000', 'mode' => 'up' ),
	)
);
$p_honey = slh_t_product_in( $honey, array( 'price' => '149300', 'name' => 'عسل چهل‌گیاه' ) );
$mapped  = ( new SLH_Product_Mapper() )->map( $p_honey );
// 149,300 * 1.1 = 164,230 Toman → up to 1,000 → 165,000 Toman = 1,650,000 Rial.
slh_t_ok( 1650000 === $mapped['payload']['primary_price'], 'global +10% then round up to 1,000 Toman = 1,650,000 Rial' );
SLH_Categories::save_map( array( $spice => array( 'category_id' => '1301' ), $honey => array( 'category_id' => '1287' ), $shirt => array( 'category_id' => '2000', 'attrs' => array( 501 => 'پلی‌استر', 502 => '7001' ) ) ) );
$saffron_cat = slh_t_term( 'زعفران قائنات ' . $run, $spice );
$p_saffron   = slh_t_product_in( $saffron_cat, array( 'price' => '320000', 'name' => 'زعفران نیم مثقال' ) );
$mapped      = ( new SLH_Product_Mapper() )->map( $p_saffron );
slh_t_ok( 3150000 === $mapped['payload']['primary_price'], 'child category inherits the category rule (320,000 − 5,000 = 315,000 T)' );
$for = SLH_Price_Rules::rule_for( $p_saffron );
slh_t_ok( 'category' === $for['source'] && $spice === $for['term_id'], 'category rule beats the global rule' );
SLH_Price_Rules::save(
	array(
		'global'   => array( 'type' => 'fixed', 'direction' => 'down', 'value' => '500000' ),
		'rounding' => array( 'unit' => '0', 'mode' => 'up' ),
	)
);
$mapped = ( new SLH_Product_Mapper() )->map( wc_get_product( $p_honey->get_id() ) );
slh_t_ok( 0 === $mapped['payload']['primary_price'] && in_array( 'primary_price', wp_list_pluck( $mapped['problems'], 'field' ), true ), 'rule that makes the price ≤ 0 is blocked by the guard' );
slh_t_ok( SLH_Price_Rules::apply( 1234567, array( 'type' => 'none', 'direction' => 'up', 'value' => 0 ), array( 'unit' => 100, 'mode' => 'nearest' ) ) === 1235000, 'nearest rounding to 100 Toman' );
slh_t_ok( SLH_Price_Rules::apply( 1239999, array( 'type' => 'none', 'direction' => 'up', 'value' => 0 ), array( 'unit' => 10000, 'mode' => 'down' ) ) === 1200000, 'down rounding to 10,000 Toman' );
delete_option( SLH_Price_Rules::OPTION );

echo "\n[P2-6] Image processing on a copy\n";
$upload = wp_upload_dir();
$big    = $upload['path'] . '/slh-big-' . $run . '.jpg';
$img    = imagecreatetruecolor( 3000, 2000 );
for ( $i = 0; $i < 3000; $i += 7 ) {
	imageline( $img, $i, 0, 3000 - $i, 2000, imagecolorallocate( $img, $i % 255, ( $i * 3 ) % 255, ( $i * 7 ) % 255 ) );
}
imagejpeg( $img, $big, 100 );
imagedestroy( $img );
$orig_size = filesize( $big );
$orig_md5  = md5_file( $big );
$att       = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'big', 'post_status' => 'inherit' ), $big );
$p_img     = slh_t_product_in( $honey, array( 'name' => 'عسل با عکس بزرگ', 'no_image' => true ) );
$p_img->set_image_id( $att );
SLH_Plugin::$suspend_hooks = true;
$p_img->save();
SLH_Plugin::$suspend_hooks = false;
SLH_Queue::enqueue_product( $p_img->get_id() );
slh_t_run_queue();
$sent = slh_t_sent( $state_file, $p_img->get_id() );
$file = $sent ? slh_t_mock( $state_file )['files'][ $sent['photo'] ] : null;
slh_t_ok( $file && 2048 === max( $file['width'], $file['height'] ), 'uploaded copy resized to 2048px (was 3000px)' );
slh_t_ok( $file && $file['size'] <= 2 * MB_IN_BYTES, 'uploaded copy is under 2MB' );
slh_t_ok( md5_file( $big ) === $orig_md5 && filesize( $big ) === $orig_size, 'original file on the site is untouched' );
$tmp = glob( $upload['basedir'] . '/salamhub-tmp/slh-*' );
slh_t_ok( 0 === count( $tmp ), 'temporary copy deleted after upload' );
if ( function_exists( 'imagewebp' ) ) {
	$webp = $upload['path'] . '/slh-' . $run . '.webp';
	$img  = imagecreatetruecolor( 800, 800 );
	imagewebp( $img, $webp );
	imagedestroy( $img );
	$prepared = ( new SLH_Image_Sync( SLH_Plugin::api() ) )->prepare( $webp, 1 );
	$info     = getimagesize( $prepared['path'] );
	slh_t_ok( $prepared['temp'] && 'image/jpeg' === $info['mime'], 'WebP converted to JPEG' );
	wp_delete_file( $prepared['path'] );
}
$small    = slh_t_image();
$prepared = ( new SLH_Image_Sync( SLH_Plugin::api() ) )->prepare( get_attached_file( $small ), $small );
slh_t_ok( ! $prepared['temp'], 'image that already fits is uploaded as-is' );

echo "\n[P2-7] Bulk send in the background\n";
slh_t_clear_queue();
$bulk_cat = slh_t_term( 'بالک ' . $run, $honey );
$ids      = array();
for ( $i = 0; $i < 23; $i++ ) {
	$ids[] = slh_t_product_in( $bulk_cat, array( 'name' => 'محصول گروهی ' . $i ) )->get_id();
}
$var = new WC_Product_Variable();
$var->set_name( 'متغیر گروهی' );
$var->set_status( 'publish' );
$var->set_category_ids( array( $bulk_cat ) );
SLH_Plugin::$suspend_hooks = true;
$var->save();
SLH_Plugin::$suspend_hooks = false;
$counts = SLH_Bulk::count_candidates( $bulk_cat );
slh_t_ok( 24 === $counts['all'] && 24 === $counts['unsent'], 'count: 23 simple + 1 variable product' );
add_filter( 'slh_bulk_chunk', function () {
	return 10;
} );
$batch = SLH_Bulk::start( array( 'scope' => 'unsent', 'term_id' => $bulk_cat ) );
slh_t_ok( is_array( $batch ) && 24 === $batch['total'], 'batch started with 24 products' );
slh_t_ok( 0 === slh_t_pending_product_actions(), 'the browser request itself queued nothing heavy (only the planner)' );
$second = SLH_Bulk::start( array( 'scope' => 'all' ) );
slh_t_ok( is_wp_error( $second ) && 'busy' === $second->get_error_code(), 'a second batch is refused while one runs' );
slh_t_run_all();
$p = SLH_Bulk::progress();
slh_t_ok( 23 === $p['done'] && 1 === $p['failed'] && 0 === $p['waiting'] && 100 === $p['percent'], '23 sent, the variable product without variations failed with a reason, progress 100%' );
slh_t_ok( 'done' === SLH_Bulk::current()['status'], 'batch marked done' );
$sys = SLH_Logger::query( array( 'object_type' => 'system', 'per_page' => 1 ) )['items'][0];
slh_t_ok( 'bulk_done' === $sys->event && false !== strpos( $sys->message, '۲۳ موفق، ۱ خطا' ), 'summary logged: «۲۳ موفق، ۱ خطا»' );
$counts = SLH_Bulk::count_candidates( $bulk_cat );
slh_t_ok( 1 === $counts['unsent'], 'only the failed variable product is left unsent' );

echo "\n[P2-8] Bulk with failures and cancel\n";
$bad = slh_t_product_in( $misc, array( 'name' => 'بی‌دسته' ) );
$batch = SLH_Bulk::start( array( 'scope' => 'ids', 'ids' => array_merge( array( $bad->get_id() ), array_slice( $ids, 0, 4 ) ) ) );
slh_t_run_all();
$p = SLH_Bulk::progress();
slh_t_ok( 1 === $p['failed'] && 4 === $p['done'], 'one failure counted, the rest updated' );
slh_t_ok( 'done' === SLH_Bulk::current()['status'], 'batch still finishes when some fail' );
$batch = SLH_Bulk::start( array( 'scope' => 'all', 'term_id' => $bulk_cat ) );
// Run only the planner once (first 10 products queued), then stop.
$plan_ids = ActionScheduler::store()->query_actions( array( 'hook' => SLH_Bulk::HOOK_PLAN, 'status' => ActionScheduler_Store::STATUS_PENDING ) );
ActionScheduler::runner()->process_action( reset( $plan_ids ), 'e2e' );
slh_t_ok( 10 === slh_t_pending_product_actions(), 'planner queued the first chunk of 10' );
$removed = SLH_Bulk::cancel();
slh_t_ok( 10 === $removed && 0 === slh_t_pending_product_actions(), 'cancel took the waiting products out of the queue' );
slh_t_ok( 0 === (int) ActionScheduler::store()->query_actions( array( 'hook' => SLH_Bulk::HOOK_PLAN, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' ), 'planner stopped' );
slh_t_ok( 'stale' === SLH_Links::get( 'product', $ids[0] )->sync_status, 'cancelled products show «همگام نیست»' );
slh_t_ok( 'cancelled' === SLH_Bulk::current()['status'] && ! SLH_Bulk::is_running(), 'batch cancelled; a new one can start' );

echo "\n[P2-9] Rate limit during bulk: one pause, nothing lost\n";
slh_t_clear_queue();
$fresh = array();
for ( $i = 0; $i < 5; $i++ ) {
	$fresh[] = slh_t_product_in( $bulk_cat, array( 'name' => 'محصول تازه ' . $i ) )->get_id();
}
slh_t_set_fail( $state_file, array( 'POST /v1/vendors/*/products' => array( 'status' => 429, 'times' => 1 ) ) );
SLH_Bulk::start( array( 'scope' => 'ids', 'ids' => $fresh ) );
slh_t_run_all( 3 );
slh_t_ok( (int) get_option( 'slh_pause_until' ) > time(), 'queue paused on 429' );
slh_t_ok( 1 === count( array_filter( SLH_Logger::query( array( 'object_type' => 'system', 'per_page' => 20 ) )['items'], function ( $l ) {
	return 'rate_limited' === $l->event;
} ) ), 'exactly one warning for the pause' );
delete_option( 'slh_pause_until' );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = %s WHERE hook = %s AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 5 ), SLH_Queue::HOOK_PRODUCT ) );
slh_t_run_all();
$p = SLH_Bulk::progress();
slh_t_ok( 5 === $p['done'] && 'done' === SLH_Bulk::current()['status'], 'after the pause all 5 were sent' );
$creates = array_filter( slh_t_mock( $state_file )['products'], function ( $x ) use ( $fresh ) {
	return in_array( (int) substr( $x['sku'], 4 ), $fresh, true );
} );
slh_t_ok( 5 === count( $creates ), 'no duplicates on Basalam' );

echo "\n" . $GLOBALS['slh_passes'] . ' passed, ' . $GLOBALS['slh_failures'] . " failed\n";
if ( $GLOBALS['slh_failures'] ) {
	exit( 1 );
}
