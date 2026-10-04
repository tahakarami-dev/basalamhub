<?php
/**
 * Imports the whole Basalam booth into WooCommerce («ایمپورت کل غرفه»).
 *
 * Built on the booth snapshot that «اتصال محصولات غرفه» downloads and matches:
 *   - «بدون جفت»  → created in WooCommerce (images, price, stock, category, variants),
 *   - «متصل»      → updated (stock, and price when no price rule is active),
 *   - «قطعی/مشکوک» → skipped: they look like products the site already has, so creating
 *                    them would make a duplicate. The user links them first.
 * Every created product is linked at once, so running the import again never duplicates.
 *
 * One product per background job: a product with ten photos stays far below any host's
 * time limit.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Importer {

	const STATE    = 'slh_import_state';
	const HOOK     = 'slh_import_next';
	const HOOK_ONE = 'slh_import_one';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle_next' ), 10, 2 );
		add_action( self::HOOK_ONE, array( __CLASS__, 'handle_one' ), 10, 1 );
	}

	/**
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::STATE, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'status'        => 'idle', // idle | running | done | cancelled
				'run_id'        => '',
				'total'         => 0,
				'offset'        => 0,
				'created'       => 0,
				'updated'       => 0,
				'skipped'       => 0,
				'failed'        => 0,
				'update_linked' => 1,
				'publish'       => 'publish',
				'started_at'    => '',
				'finished_at'   => '',
			)
		);
	}

	/**
	 * @param array $data Partial state.
	 */
	private static function set_state( array $data ) {
		update_option( self::STATE, array_merge( self::state(), $data ), false );
	}

	/**
	 * @return bool
	 */
	public static function is_running() {
		return 'running' === self::state()['status'];
	}

	/**
	 * What an import would do with the current snapshot.
	 *
	 * @return array{ready: bool, new: int, linked: int, review: int, fetched_at: string}
	 */
	public static function preview() {
		$linker = SLH_Linker::state();
		$counts = SLH_Linker::counts();
		return array(
			'ready'      => 'ready' === $linker['status'],
			'new'        => $counts['none'],
			'linked'     => $counts['linked'],
			'review'     => $counts['certain'] + $counts['suspect'],
			'fetched_at' => $linker['finished_at'],
		);
	}

	/**
	 * Starts the import.
	 *
	 * @param array $options update_linked (bool), publish (publish|draft).
	 * @return true|WP_Error
	 */
	public static function start( array $options ) {
		if ( ! SLH_Settings::is_connected() ) {
			return new WP_Error( 'not_connected', __( 'اول در باسلام‌هاب › تنظیمات به باسلام وصل شو.', 'salamhub' ) );
		}
		if ( self::is_running() || SLH_Linker::is_running() || SLH_Bulk::is_running() ) {
			return new WP_Error( 'busy', __( 'یک عملیات سنگین دیگر در حال اجراست. صبر کن تمام شود؛ دو عملیات سنگین هم‌زمان اجرا نمی‌شوند.', 'salamhub' ) );
		}
		$preview = self::preview();
		if ( ! $preview['ready'] ) {
			return new WP_Error( 'no_snapshot', __( 'اول «دریافت فهرست غرفه» را بزن تا محصولات غرفه خوانده و با سایت مقایسه شوند.', 'salamhub' ) );
		}
		$update = ! empty( $options['update_linked'] );
		$total  = $preview['new'] + ( $update ? $preview['linked'] : 0 );
		if ( ! $total ) {
			return new WP_Error( 'nothing', __( 'محصولی برای واردکردن نیست؛ همه‌ی محصولات غرفه یا متصل‌اند یا منتظر بررسی در «اتصال محصولات غرفه».', 'salamhub' ) );
		}
		$run = 'i' . time() . wp_rand( 100, 999 );
		update_option(
			self::STATE,
			array(
				'status'        => 'running',
				'run_id'        => $run,
				'total'         => $total,
				'offset'        => 0,
				'created'       => 0,
				'updated'       => 0,
				'skipped'       => 0,
				'failed'        => 0,
				'update_linked' => $update ? 1 : 0,
				'publish'       => isset( $options['publish'] ) && 'draft' === $options['publish'] ? 'draft' : 'publish',
				'started_at'    => slh_now(),
				'finished_at'   => '',
			),
			false
		);
		as_enqueue_async_action( self::HOOK, array( 'run_id' => $run, 'offset' => 0 ), SLH_Queue::GROUP );
		return true;
	}

	/**
	 * Stops after the product in progress. Products already imported stay.
	 */
	public static function cancel() {
		if ( self::is_running() ) {
			self::set_state( array( 'status' => 'cancelled', 'finished_at' => slh_now() ) );
		}
	}

	/**
	 * @return array{done:int, total:int, percent:int}
	 */
	public static function progress() {
		$s    = self::state();
		$done = $s['created'] + $s['updated'] + $s['skipped'] + $s['failed'];
		return array(
			'done'    => $done,
			'total'   => (int) $s['total'],
			'percent' => $s['total'] ? (int) min( 100, floor( 100 * $done / $s['total'] ) ) : 0,
		);
	}

	/**
	 * Queue callback: imports the product at $offset of the snapshot, then queues the next.
	 *
	 * @param string $run_id Run.
	 * @param int    $offset Offset among the rows to import.
	 */
	public static function handle_next( $run_id, $offset ) {
		$state = self::state();
		if ( $state['run_id'] !== $run_id || 'running' !== $state['status'] ) {
			return;
		}
		SLH_Queue::run_exclusive(
			self::HOOK,
			array( 'run_id' => $run_id, 'offset' => (int) $offset ),
			function () use ( $run_id, $offset ) {
				global $wpdb;
				$state    = self::state();
				$statuses = $state['update_linked'] ? "'none','linked'" : "'none'";
				$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT basalam_id, match_status FROM ' . SLH_Linker::table() . " WHERE match_status IN ({$statuses}) ORDER BY id ASC LIMIT 1 OFFSET %d", (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $row ) {
					self::finish();
					return;
				}
				$result = self::import_one( (int) $row->basalam_id, $state );
				if ( 'retry' === $result ) {
					// Temporary Basalam/network problem: try the same product again later.
					if ( false !== SLH_Queue::retry_later( self::HOOK, array( 'run_id' => $run_id, 'offset' => (int) $offset ), 'import_' . $row->basalam_id, 60 ) ) {
						return;
					}
					$result = 'failed';
				}
				$state = self::state();
				if ( 'running' !== $state['status'] ) {
					return; // Cancelled meanwhile.
				}
				self::set_state( array( $result => $state[ $result ] + 1, 'offset' => (int) $offset + 1 ) );
				as_enqueue_async_action( self::HOOK, array( 'run_id' => $run_id, 'offset' => (int) $offset + 1 ), SLH_Queue::GROUP );
			}
		);
	}

	/**
	 * Run finished: summary log entry.
	 */
	private static function finish() {
		self::set_state( array( 'status' => 'done', 'finished_at' => slh_now() ) );
		$s = self::state();
		SLH_Logger::log(
			array(
				'level'       => $s['failed'] ? 'warning' : 'success',
				'event'       => 'import_finished',
				'object_type' => 'system',
				'title'       => __( 'ایمپورت غرفه', 'salamhub' ),
				/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
				'message'     => sprintf( __( 'تمام شد: %1$s محصول ساخته شد، %2$s به‌روز شد، %3$s رد شد، %4$s خطا.', 'salamhub' ), slh_fa_number( $s['created'] ), slh_fa_number( $s['updated'] ), slh_fa_number( $s['skipped'] ), slh_fa_number( $s['failed'] ) ),
				'suggestion'  => $s['failed'] ? __( 'خطاها با دلیل در همین لاگ آمده‌اند و هرکدام «تلاش مجدد» دارد.', 'salamhub' ) : null,
			)
		);
	}

	/**
	 * Retry button on a failed product's log entry.
	 *
	 * @param int $basalam_id Basalam product.
	 */
	public static function handle_one( $basalam_id ) {
		SLH_Queue::run_exclusive(
			self::HOOK_ONE,
			array( 'basalam_id' => (int) $basalam_id ),
			function () use ( $basalam_id ) {
				$state = self::state();
				$res   = self::import_one( (int) $basalam_id, array( 'update_linked' => 1, 'publish' => $state['publish'] ) );
				if ( 'retry' === $res ) {
					SLH_Queue::retry_later( self::HOOK_ONE, array( 'basalam_id' => (int) $basalam_id ), 'import_' . $basalam_id, 60 );
				}
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * One product
	 * ------------------------------------------------------------------ */

	/**
	 * @param int   $basalam_id Basalam product.
	 * @param array $options    update_linked, publish.
	 * @return string created|updated|skipped|failed|retry
	 */
	public static function import_one( $basalam_id, array $options ) {
		$owner = SLH_Lock::acquire( 'import_' . $basalam_id, 300 );
		if ( ! $owner ) {
			return 'skipped';
		}
		try {
			$link    = SLH_Links::get_by_basalam( 'product', $basalam_id );
			$product = $link ? wc_get_product( (int) $link->wc_id ) : null;
			if ( $product && empty( $options['update_linked'] ) ) {
				return 'skipped';
			}
			$remote = SLH_Plugin::api()->get_product( $basalam_id );
			if ( $product ) {
				self::update_from_remote( $product, $remote );
				SLH_Queue::reset_attempts( 'import_' . $basalam_id );
				return 'updated';
			}

			// Last guard against duplicates: a site product with the same SKU.
			$sku = self::taken_sku( $remote );
			if ( $sku ) {
				SLH_Logger::log(
					array(
						'level'       => 'warning',
						'event'       => 'import_sku_exists',
						'object_type' => 'import',
						'object_id'   => $basalam_id,
						'title'       => isset( $remote['title'] ) ? (string) $remote['title'] : '#' . $basalam_id,
						'message'     => __( 'وارد نشد تا تکراری ساخته نشود.', 'salamhub' ),
						/* translators: %s: SKU */
						'reason'      => sprintf( __( 'محصولی با SKU «%s» در سایت هست.', 'salamhub' ), $sku ),
						'suggestion'  => __( 'این دو را در «اتصال محصولات غرفه» به هم وصل کن.', 'salamhub' ),
					)
				);
				return 'skipped';
			}

			$wc_id = self::create_from_remote( $remote, $options );
			SLH_Queue::reset_attempts( 'import_' . $basalam_id );
			SLH_Logger::resolve_for( 'import', $basalam_id );
			global $wpdb;
			$wpdb->update( SLH_Linker::table(), array( 'match_status' => 'linked', 'match_wc_id' => $wc_id ), array( 'basalam_id' => $basalam_id ) );
			return 'created';
		} catch ( SLH_Api_Error $e ) {
			if ( 'rate_limit' === $e->kind ) {
				SLH_Queue::pause( $e->retry_after );
			}
			if ( $e->retryable ) {
				return 'retry';
			}
			self::log_failure( $basalam_id, $e->to_log(), $e->getMessage() );
			return 'failed';
		} catch ( Throwable $e ) {
			self::log_failure(
				$basalam_id,
				array(
					'reason'     => __( 'ساخت محصول در ووکامرس با خطای داخلی متوقف شد.', 'salamhub' ),
					'suggestion' => __( '«تلاش مجدد» را بزن. اگر تکرار شد، جزئیات فنی را برای پشتیبانی بفرست.', 'salamhub' ),
					'context'    => array( 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ),
				),
				__( 'خطای داخلی.', 'salamhub' )
			);
			return 'failed';
		} finally {
			SLH_Plugin::$suspend_hooks = false;
			SLH_Lock::release( 'import_' . $basalam_id, $owner );
		}
	}

	/**
	 * @param int    $basalam_id Basalam product.
	 * @param array  $log        Reason/suggestion/context.
	 * @param string $message    Short message.
	 */
	private static function log_failure( $basalam_id, array $log, $message ) {
		SLH_Logger::log(
			array_merge(
				array(
					'level'       => 'error',
					'event'       => 'import_failed',
					'object_type' => 'import',
					'object_id'   => $basalam_id,
					/* translators: %s: Basalam product id */
					'title'       => sprintf( __( 'محصول باسلام #%s', 'salamhub' ), $basalam_id ),
					'retry_hook'  => self::HOOK_ONE,
					'retry_args'  => array( 'basalam_id' => (int) $basalam_id ),
				),
				$log,
				array( 'message' => __( 'در ووکامرس ساخته نشد.', 'salamhub' ) . ' ' . $message )
			)
		);
	}

	/**
	 * The first SKU of the remote product (or its variants) that already exists on the site.
	 *
	 * @param array $remote ReadProductResponse.
	 * @return string
	 */
	private static function taken_sku( array $remote ) {
		$skus = array();
		if ( ! empty( $remote['sku'] ) ) {
			$skus[] = (string) $remote['sku'];
		}
		foreach ( isset( $remote['variants'] ) && is_array( $remote['variants'] ) ? $remote['variants'] : array() as $v ) {
			if ( ! empty( $v['sku'] ) ) {
				$skus[] = (string) $v['sku'];
			}
		}
		foreach ( $skus as $sku ) {
			if ( wc_get_product_id_by_sku( $sku ) ) {
				return $sku;
			}
		}
		return '';
	}

	/**
	 * Rial → store currency.
	 *
	 * @param int|null $rial Price.
	 * @return string
	 * @throws SLH_Api_Error When the store currency is unknown.
	 */
	private static function to_store( $rial ) {
		$multiplier = SLH_Product_Mapper::rial_multiplier();
		if ( null === $multiplier ) {
			throw new SLH_Api_Error(
				__( 'واحد پول فروشگاه قابل تبدیل از ریال نیست.', 'salamhub' ),
				'validation',
				array(
					'reason'     => __( 'قیمت‌های باسلام به ریال است و باسلام‌هاب نمی‌داند به چه واحدی تبدیلش کند.', 'salamhub' ),
					'suggestion' => __( 'در باسلام‌هاب › تنظیمات واحد قیمت‌های سایت را روی «تومان» یا «ریال» بگذار و «تلاش مجدد» را بزن.', 'salamhub' ),
				)
			);
		}
		return wc_format_decimal( (float) $rial / $multiplier, wc_get_price_decimals(), true );
	}

	/**
	 * Regular and sale price from Basalam's primary_price (before discount) and price (paid).
	 *
	 * @param array $r Product or variant.
	 * @return array{0:string, 1:string} Regular, sale ('' when none).
	 */
	private static function prices( array $r ) {
		$primary = isset( $r['primary_price'] ) ? (int) $r['primary_price'] : ( isset( $r['price'] ) ? (int) $r['price'] : 0 );
		$paid    = isset( $r['price'] ) ? (int) $r['price'] : $primary;
		$regular = self::to_store( $primary );
		$sale    = $paid > 0 && $paid < $primary ? self::to_store( $paid ) : '';
		return array( $regular, $sale );
	}

	/**
	 * Stock to keep on the site: Basalam shows "site − safety", so the safety units are added.
	 *
	 * @param int $remote Basalam stock.
	 * @return int
	 */
	private static function local_stock( $remote ) {
		$remote = max( 0, (int) $remote );
		return $remote > 0 ? $remote + max( 0, (int) SLH_Settings::get( 'safety_stock', 0 ) ) : 0;
	}

	/**
	 * Creates the WooCommerce product, links it, and records what Basalam already has.
	 *
	 * @param array $r       ReadProductResponse.
	 * @param array $options publish.
	 * @return int Product ID.
	 * @throws SLH_Api_Error When prices can't be converted.
	 */
	public static function create_from_remote( array $r, array $options ) {
		$basalam_id = (int) $r['id'];
		$variants   = array_values(
			array_filter(
				isset( $r['variants'] ) && is_array( $r['variants'] ) ? $r['variants'] : array(),
				function ( $v ) {
					return ! empty( $v['id'] ) && ! empty( $v['properties'] );
				}
			)
		);
		SLH_Plugin::$suspend_hooks = true;

		$product = $variants ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( isset( $r['title'] ) ? (string) $r['title'] : ( isset( $r['name'] ) ? (string) $r['name'] : '#' . $basalam_id ) );
		$product->set_status( isset( $options['publish'] ) && 'draft' === $options['publish'] ? 'draft' : 'publish' );
		if ( ! empty( $r['description'] ) ) {
			$product->set_description( wpautop( esc_html( (string) $r['description'] ) ) );
		}
		if ( ! empty( $r['summary'] ) ) {
			$product->set_short_description( esc_html( (string) $r['summary'] ) );
		}
		if ( ! empty( $r['sku'] ) ) {
			$product->set_sku( (string) $r['sku'] );
		}
		$weight = ! empty( $r['net_weight'] ) ? (int) $r['net_weight'] : ( ! empty( $r['packaged_weight'] ) ? (int) $r['packaged_weight'] : 0 );
		if ( $weight ) {
			$product->set_weight( wc_format_decimal( wc_get_weight( $weight, get_option( 'woocommerce_weight_unit', 'kg' ), 'g' ) ) );
		}
		if ( ! empty( $r['packaging_dimensions'] ) && is_array( $r['packaging_dimensions'] ) ) {
			$unit = get_option( 'woocommerce_dimension_unit', 'cm' );
			foreach ( array( 'length', 'width', 'height' ) as $dim ) {
				if ( ! empty( $r['packaging_dimensions'][ $dim ] ) ) {
					$product->{'set_' . $dim}( wc_format_decimal( wc_get_dimension( (int) $r['packaging_dimensions'][ $dim ], $unit, 'cm' ) ) );
				}
			}
		}
		$term = self::category_term( isset( $r['category'] ) && is_array( $r['category'] ) ? $r['category'] : null );
		if ( $term ) {
			$product->set_category_ids( array( $term ) );
		}
		if ( isset( $r['preparation_day'] ) && (int) $r['preparation_day'] !== (int) SLH_Settings::get( 'preparation_days' ) ) {
			$product->update_meta_data( '_slh_preparation_days', (int) $r['preparation_day'] );
		}

		if ( ! $variants ) {
			list( $regular, $sale ) = self::prices( $r );
			$product->set_regular_price( $regular );
			$product->set_sale_price( $sale );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( self::local_stock( isset( $r['inventory'] ) ? $r['inventory'] : ( isset( $r['stock'] ) ? $r['stock'] : 0 ) ) );
		} else {
			$product->set_attributes( self::attributes( $variants ) );
		}

		// Images: best effort — a product without photos is still better than no product.
		$image_errors = array();
		$images       = self::import_images( $r, $image_errors );
		if ( $images ) {
			$product->set_image_id( array_shift( $images ) );
			$product->set_gallery_image_ids( $images );
		}
		$product->update_meta_data( '_slh_imported_from', $basalam_id );
		$wc_id = $product->save();

		// Link right away: from here on, nothing can import this product a second time.
		SLH_Links::upsert( 'product', $wc_id, array( 'basalam_id' => $basalam_id, 'sync_status' => 'synced', 'last_synced_at' => slh_now(), 'last_error' => null ) );

		if ( $variants ) {
			self::create_variations( $wc_id, $variants );
			WC_Product_Variable::sync( $wc_id );
		}
		$product = wc_get_product( $wc_id );
		if ( $variants ) {
			self::store_variant_map( $product, $variants );
		}
		$sync = new SLH_Product_Sync( SLH_Plugin::api() );
		SLH_Links::upsert( 'product', $wc_id, array( 'payload_hash' => $sync->fingerprint( wc_get_product( $wc_id ) ) ) );
		SLH_Plugin::$suspend_hooks = false;

		SLH_Logger::log(
			array(
				'level'       => $image_errors ? 'warning' : 'success',
				'event'       => 'product_imported',
				'object_type' => 'product',
				'object_id'   => $wc_id,
				'title'       => $product->get_name(),
				/* translators: %s: Basalam product id */
				'message'     => sprintf( __( 'از باسلام وارد شد (#%s).', 'salamhub' ), $basalam_id ),
				'reason'      => $image_errors ? implode( ' ', array_unique( $image_errors ) ) : null,
				'suggestion'  => $image_errors ? __( 'تصویرهای جاافتاده را دستی به محصول اضافه کن؛ بقیه‌ی اطلاعات کامل وارد شده.', 'salamhub' ) : null,
			)
		);
		return $wc_id;
	}

	/**
	 * Local WooCommerce attributes from the variants' properties, in Basalam's order.
	 *
	 * @param array $variants Variants.
	 * @return WC_Product_Attribute[]
	 */
	private static function attributes( array $variants ) {
		$options = array();
		foreach ( $variants as $v ) {
			foreach ( $v['properties'] as $p ) {
				$name  = self::prop_name( $p );
				$value = self::prop_value( $p );
				if ( '' === $name || '' === $value ) {
					continue;
				}
				$options[ $name ][ $value ] = true;
			}
		}
		$out = array();
		$pos = 0;
		foreach ( $options as $name => $values ) {
			$a = new WC_Product_Attribute();
			$a->set_name( $name );
			$a->set_options( array_keys( $values ) );
			$a->set_position( $pos++ );
			$a->set_visible( true );
			$a->set_variation( true );
			$out[] = $a;
		}
		return $out;
	}

	/**
	 * @param array $p Property entry.
	 * @return string
	 */
	private static function prop_name( array $p ) {
		return trim( (string) ( isset( $p['property']['title'] ) ? $p['property']['title'] : ( isset( $p['property'] ) && is_string( $p['property'] ) ? $p['property'] : '' ) ) );
	}

	/**
	 * @param array $p Property entry.
	 * @return string
	 */
	private static function prop_value( array $p ) {
		return trim( (string) ( isset( $p['value']['title'] ) ? $p['value']['title'] : ( isset( $p['value'] ) && is_string( $p['value'] ) ? $p['value'] : '' ) ) );
	}

	/**
	 * Order-independent identity of a property set.
	 *
	 * @param array<string,string> $pairs name => value.
	 * @return string
	 */
	private static function prop_key( array $pairs ) {
		ksort( $pairs );
		return md5( wp_json_encode( $pairs ) );
	}

	/**
	 * @param int   $parent_id Product.
	 * @param array $variants  Variants.
	 */
	private static function create_variations( $parent_id, array $variants ) {
		foreach ( $variants as $i => $v ) {
			$attrs = array();
			foreach ( $v['properties'] as $p ) {
				$attrs[ sanitize_title( self::prop_name( $p ) ) ] = self::prop_value( $p );
			}
			list( $regular, $sale ) = self::prices( $v );
			$var = new WC_Product_Variation();
			$var->set_parent_id( $parent_id );
			$var->set_attributes( $attrs );
			$var->set_regular_price( $regular );
			$var->set_sale_price( $sale );
			$var->set_manage_stock( true );
			$var->set_stock_quantity( self::local_stock( isset( $v['stock'] ) ? $v['stock'] : 0 ) );
			$var->set_menu_order( $i );
			if ( ! empty( $v['sku'] ) && ! wc_get_product_id_by_sku( (string) $v['sku'] ) ) {
				$var->set_sku( (string) $v['sku'] );
			}
			$var->set_status( 'publish' );
			$var->save();
		}
	}

	/**
	 * Records "this variation is that Basalam variant", with the values the next sync would
	 * send — so the first sync after an import doesn't re-create or re-send variants.
	 *
	 * @param WC_Product $product  Variable product.
	 * @param array      $variants Basalam variants.
	 */
	private static function store_variant_map( WC_Product $product, array $variants ) {
		$remote = array();
		foreach ( $variants as $v ) {
			$pairs = array();
			foreach ( $v['properties'] as $p ) {
				$pairs[ self::prop_name( $p ) ] = self::prop_value( $p );
			}
			$remote[ self::prop_key( $pairs ) ] = (int) $v['id'];
		}
		$problems = array();
		$map      = array();
		foreach ( ( new SLH_Product_Mapper() )->variations( $product, $problems ) as $vid => $mv ) {
			$pairs = array();
			foreach ( $mv['properties'] as $p ) {
				$pairs[ $p['property'] ] = $p['value'];
			}
			$key = self::prop_key( $pairs );
			if ( isset( $remote[ $key ] ) ) {
				$map[ $vid ] = array( 'id' => $remote[ $key ], 'sig' => $mv['sig'], 'price' => $mv['primary_price'], 'stock' => $mv['stock'], 'sku' => $mv['sku'] );
			}
		}
		update_post_meta( $product->get_id(), '_slh_variants', $map );
	}

	/**
	 * Basalam category → WooCommerce category: the mapped one, else a category with the same
	 * name (created if needed and added to «نگاشت دسته‌ها» so sending back uses the same one).
	 *
	 * @param array|null $category CategoryResponse.
	 * @return int Term ID or 0.
	 */
	public static function category_term( $category ) {
		if ( empty( $category['id'] ) ) {
			return 0;
		}
		$cat_id = (int) $category['id'];
		foreach ( SLH_Categories::map() as $term_id => $m ) {
			if ( (int) $m['category_id'] === $cat_id && term_exists( (int) $term_id, 'product_cat' ) ) {
				return (int) $term_id;
			}
		}
		$title = isset( $category['title'] ) ? trim( (string) $category['title'] ) : '';
		if ( '' === $title ) {
			return 0;
		}
		$term = get_term_by( 'name', $title, 'product_cat' );
		if ( ! $term ) {
			$made = wp_insert_term( $title, 'product_cat' );
			if ( is_wp_error( $made ) ) {
				return 0;
			}
			$term_id = (int) $made['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		$map = SLH_Categories::map();
		if ( ! isset( $map[ $term_id ] ) ) {
			$map[ $term_id ] = array( 'category_id' => $cat_id, 'attrs' => array() );
			update_option( SLH_Categories::MAP_OPTION, $map, false );
		}
		return $term_id;
	}

	/**
	 * Downloads the product's photos into the media library (each Basalam photo once).
	 *
	 * @param array    $r      Product.
	 * @param string[] $errors Errors (by reference).
	 * @return int[] Attachment IDs, main photo first.
	 */
	private static function import_images( array $r, array &$errors ) {
		$photos = array();
		if ( ! empty( $r['photo'] ) && is_array( $r['photo'] ) ) {
			$photos[] = $r['photo'];
		}
		foreach ( isset( $r['photos'] ) && is_array( $r['photos'] ) ? $r['photos'] : array() as $p ) {
			if ( is_array( $p ) ) {
				$photos[] = $p;
			}
		}
		$ids = array();
		foreach ( array_slice( $photos, 0, 10 ) as $photo ) {
			$id = self::import_image( $photo, $errors );
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * @param array    $photo  PhotoResponse.
	 * @param string[] $errors Errors (by reference).
	 * @return int Attachment ID or 0.
	 */
	private static function import_image( array $photo, array &$errors ) {
		$file_id = isset( $photo['id'] ) ? (int) $photo['id'] : 0;
		if ( $file_id ) {
			$existing = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => SLH_Image_Sync::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => (string) $file_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $existing ) {
				return (int) $existing[0];
			}
		}
		$url = '';
		foreach ( array( 'original', 'lg', 'md' ) as $size ) {
			if ( ! empty( $photo[ $size ] ) ) {
				$url = (string) $photo[ $size ];
				break;
			}
		}
		if ( ! $url ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$errors[] = __( 'دانلود بعضی تصویرها از باسلام انجام نشد.', 'salamhub' );
			return 0;
		}
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $name ) ) {
			$name = 'basalam-' . ( $file_id ? $file_id : wp_generate_password( 6, false ) ) . '.jpg';
		}
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			$errors[] = __( 'بعضی تصویرها در کتابخانه‌ی رسانه ذخیره نشدند.', 'salamhub' );
			return 0;
		}
		if ( $file_id ) {
			// Sending this product back later reuses Basalam's file instead of uploading it again.
			$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : '';
			$path = $path && file_exists( $path ) ? $path : get_attached_file( $id );
			update_post_meta( $id, SLH_Image_Sync::META_ID, $file_id );
			update_post_meta( $id, SLH_Image_Sync::META_SIG, SLH_Image_Sync::signature( $path ) );
		}
		return (int) $id;
	}

	/**
	 * An already-linked product: copy Basalam's stock (and price, unless a price rule is
	 * active — Basalam's price already contains the rule, copying it would apply it twice).
	 *
	 * @param WC_Product $product Product.
	 * @param array      $r       ReadProductResponse.
	 * @throws SLH_Api_Error When prices can't be converted.
	 */
	public static function update_from_remote( WC_Product $product, array $r ) {
		$prices = ! SLH_Price_Rules::is_active();
		SLH_Plugin::$suspend_hooks = true;
		if ( $product->is_type( 'variable' ) ) {
			$remote = array();
			foreach ( isset( $r['variants'] ) && is_array( $r['variants'] ) ? $r['variants'] : array() as $v ) {
				if ( ! empty( $v['id'] ) ) {
					$remote[ (int) $v['id'] ] = $v;
				}
			}
			foreach ( SLH_Product_Sync::variant_map( $product ) as $vid => $row ) {
				$variation = wc_get_product( (int) $vid );
				if ( ! $variation || ! isset( $remote[ (int) $row['id'] ] ) ) {
					continue;
				}
				$v = $remote[ (int) $row['id'] ];
				if ( $prices ) {
					list( $regular, $sale ) = self::prices( $v );
					$variation->set_regular_price( $regular );
					$variation->set_sale_price( $sale );
					$variation->save();
				}
				SLH_Inventory::set_local_stock( wc_get_product( (int) $vid ), isset( $v['stock'] ) ? (int) $v['stock'] : 0 );
			}
			WC_Product_Variable::sync( $product->get_id() );
		} else {
			if ( $prices ) {
				list( $regular, $sale ) = self::prices( $r );
				$product->set_regular_price( $regular );
				$product->set_sale_price( $sale );
				$product->save();
			}
			SLH_Inventory::set_local_stock( wc_get_product( $product->get_id() ), isset( $r['inventory'] ) ? (int) $r['inventory'] : ( isset( $r['stock'] ) ? (int) $r['stock'] : 0 ) );
		}
		$sync = new SLH_Product_Sync( SLH_Plugin::api() );
		SLH_Links::upsert( 'product', $product->get_id(), array( 'payload_hash' => $sync->fingerprint( wc_get_product( $product->get_id() ) ), 'sync_status' => 'synced', 'last_synced_at' => slh_now() ) );
		SLH_Plugin::$suspend_hooks = false;
	}
}
