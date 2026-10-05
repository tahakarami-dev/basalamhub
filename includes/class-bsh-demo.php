<?php
/**
 * «حالت نمایشی»: fills the panel with sample products, 30 days of orders on both channels,
 * low stock and log events, so BasalamHub can be presented without a real booth. Everything
 * it creates carries a marker (meta _bsh_demo) and is removed by «پاک کردن داده‌ی نمایشی».
 *
 * Demo data never reaches Basalam: demo products are never queued or sent, and demo orders
 * never call Basalam's order API.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Demo {

	const OPTION = 'bsh_demo';
	const META   = '_bsh_demo';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_bsh_demo_fill', array( __CLASS__, 'ajax_fill' ) );
		add_action( 'wp_ajax_bsh_demo_clear', array( __CLASS__, 'ajax_clear' ) );
		add_action( 'wp_ajax_bsh_demo_order', array( __CLASS__, 'ajax_order' ) );
	}

	/**
	 * @return bool
	 */
	public static function active() {
		$d = get_option( self::OPTION );
		return is_array( $d ) && ! empty( $d['active'] );
	}

	/**
	 * Is this product or order part of the demo?
	 *
	 * @param int|WC_Data $object ID or object.
	 * @return bool
	 */
	public static function is_demo( $object ) {
		if ( ! self::active() ) {
			return false;
		}
		if ( $object instanceof WC_Data ) {
			return (bool) $object->get_meta( self::META );
		}
		return (bool) get_post_meta( (int) $object, self::META, true );
	}

	/**
	 * AJAX guard.
	 */
	private static function guard() {
		if ( ! current_user_can( BSH_Admin::CAP ) || ! check_ajax_referer( 'bsh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'basalamhub' ) ), 403 );
		}
	}

	/**
	 * «پر کردن با داده‌ی نمایشی».
	 */
	public static function ajax_fill() {
		self::guard();
		if ( self::active() ) {
			wp_send_json_error( array( 'message' => __( 'حالت نمایشی از قبل فعال است.', 'basalamhub' ) ) );
		}
		$r = self::fill();
		/* translators: 1: products, 2: orders */
		wp_send_json_success( array( 'message' => sprintf( __( '%1$s محصول و %2$s سفارش نمایشی ساخته شد.', 'basalamhub' ), bsh_fa_number( $r['products'] ), bsh_fa_number( $r['orders'] ) ) ) );
	}

	/**
	 * «پاک کردن داده‌ی نمایشی».
	 */
	public static function ajax_clear() {
		self::guard();
		self::clear();
		wp_send_json_success( array( 'message' => __( 'داده‌ی نمایشی پاک شد.', 'basalamhub' ) ) );
	}

	/**
	 * «شبیه‌سازی سفارش باسلام».
	 */
	public static function ajax_order() {
		self::guard();
		if ( ! self::active() ) {
			wp_send_json_error( array( 'message' => __( 'اول حالت نمایشی را روشن کن.', 'basalamhub' ) ) );
		}
		$order = self::simulate_order();
		/* translators: %s: order number */
		wp_send_json_success( array( 'message' => sprintf( __( 'سفارش نمایشی %s ثبت شد؛ تا چند ثانیه‌ی دیگر اعلانش را می‌بینی.', 'basalamhub' ), $order->get_order_number() ) ) );
	}

	/**
	 * Sample catalogue.
	 *
	 * @return array<int,array{0:string,1:int,2:int}> name, price (store currency), stock.
	 */
	private static function catalogue() {
		return array(
			array( __( 'عسل گون کوهپایه ۱ کیلویی', 'basalamhub' ), 450000, 38 ),
			array( __( 'زعفران سرگل قائنات ۴ گرمی', 'basalamhub' ), 380000, 2 ),
			array( __( 'گلاب دو آتشه کاشان', 'basalamhub' ), 120000, 64 ),
			array( __( 'خرمای مضافتی بم ۲ کیلویی', 'basalamhub' ), 160000, 0 ),
			array( __( 'روغن کنجد کنجدکوب', 'basalamhub' ), 210000, 25 ),
			array( __( 'کشک محلی کرمانشاه', 'basalamhub' ), 95000, 41 ),
			array( __( 'شال دست‌باف یزد', 'basalamhub' ), 690000, 12 ),
			array( __( 'سفال لالجین ست چهارتایی', 'basalamhub' ), 540000, 7 ),
		);
	}

	/**
	 * @return array{0:string,1:string} [name, city]
	 */
	private static function customer() {
		$names  = array( 'مریم احمدی', 'علی رضایی', 'زهرا کریمی', 'حسین محمدی', 'فاطمه موسوی', 'رضا حسینی', 'نرگس جعفری', 'امیر صادقی' );
		$cities = array( 'تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'کرج', 'اهواز', 'رشت', 'یزد', 'کرمان' );
		return array( $names[ wp_rand( 0, count( $names ) - 1 ) ], $cities[ wp_rand( 0, count( $cities ) - 1 ) ] );
	}

	/**
	 * While demo data is made or removed: no Bale/Telegram message, no WooCommerce e-mail
	 * («سفارش جدید» to the shop owner for 80 sample orders would be a nasty surprise).
	 *
	 * @param bool $on Start or stop.
	 */
	private static function quiet( $on ) {
		BSH_Notifier::$muted       = $on;
		BSH_Plugin::$suspend_hooks = $on;
		if ( $on ) {
			add_filter( 'pre_wp_mail', '__return_false', 999 );
		} else {
			remove_filter( 'pre_wp_mail', '__return_false', 999 );
		}
	}

	/**
	 * Creates the demo data.
	 *
	 * @return array{products:int, orders:int}
	 */
	public static function fill() {
		update_option(
			self::OPTION,
			array(
				'active'         => 1,
				'at'             => bsh_now(),
				'prev_reconcile' => get_option( BSH_Reconcile::OPTION, null ),
			),
			false
		);
		self::quiet( true );
		$ids = array();
		foreach ( self::catalogue() as $i => $row ) {
			$p = new WC_Product_Simple();
			$p->set_name( $row[0] );
			$p->set_status( 'publish' );
			$p->set_regular_price( (string) $row[1] );
			$p->set_manage_stock( true );
			$p->set_stock_quantity( 400 );
			$p->update_meta_data( self::META, 1 );
			$id    = $p->save();
			$ids[] = $id;
			BSH_Links::upsert(
				'product',
				$id,
				array(
					'basalam_id'     => 7000000 + $id,
					'sync_status'    => 'synced',
					'last_synced_at' => bsh_now(),
				)
			);
		}

		// 30 days of orders, a little more than half from Basalam, growing slightly.
		$orders = 0;
		for ( $day = 34; $day >= 0; $day-- ) {
			$n = wp_rand( 1, 3 ) + ( $day < 10 ? 1 : 0 );
			for ( $k = 0; $k < $n; $k++ ) {
				$basalam = wp_rand( 0, 100 ) < 58;
				$when    = time() - $day * DAY_IN_SECONDS - wp_rand( 600, 9 * HOUR_IN_SECONDS );
				self::make_order( $ids, $basalam, max( time() - 60, 0 ) > $when ? $when : time() - 120, wp_rand( 0, 10 ) > 2 ? 'completed' : 'processing' );
				++$orders;
			}
		}
		BSH_Plugin::$suspend_hooks = false; // Stock changes below run the real alert logic (still muted).

		// Two products running low on Basalam: one almost gone, one out.
		$catalogue = self::catalogue();
		foreach ( $ids as $i => $id ) {
			wc_update_product_stock( wc_get_product( $id ), $catalogue[ $i ][2], 'set' );
		}

		// A healthy night of reconciliation and a few everyday log events.
		if ( ! BSH_Reconcile::last() ) {
			update_option(
				BSH_Reconcile::OPTION,
				array(
					'at'      => gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ),
					'checked' => 41,
					'missing' => 0,
					'ids'     => array(),
					'error'   => '',
				),
				false
			);
		}
		foreach ( array_slice( $ids, 0, 5 ) as $id ) {
			BSH_Logger::log(
				array(
					'level'       => 'success',
					'event'       => 'product_updated',
					'object_type' => 'product',
					'object_id'   => $id,
					'title'       => get_the_title( $id ),
					'message'     => __( 'تغییرات در باسلام اعمال شد.', 'basalamhub' ),
					'context'     => array( 'demo' => 1 ),
				)
			);
		}
		BSH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'demo_filled',
				'object_type' => 'system',
				'title'       => __( 'حالت نمایشی', 'basalamhub' ),
				'message'     => __( 'داده‌ی نمایشی ساخته شد؛ چیزی به باسلام فرستاده نمی‌شود.', 'basalamhub' ),
				'context'     => array( 'demo' => 1 ),
			)
		);
		self::quiet( false );
		BSH_Sales::bust();
		return array(
			'products' => count( $ids ),
			'orders'   => $orders,
		);
	}

	/**
	 * One demo order.
	 *
	 * @param int[]  $ids     Demo products.
	 * @param bool   $basalam From Basalam.
	 * @param int    $when    Timestamp.
	 * @param string $status  Status.
	 * @return WC_Order
	 */
	private static function make_order( array $ids, $basalam, $when, $status ) {
		list( $name, $city ) = self::customer();
		$order               = wc_create_order( array( 'created_via' => $basalam ? 'basalamhub' : 'checkout' ) );
		$lines               = wp_rand( 1, 10 ) > 8 ? 2 : 1;
		for ( $l = 0; $l < $lines; $l++ ) {
			$order->add_product( wc_get_product( $ids[ wp_rand( 0, count( $ids ) - 1 ) ] ), wp_rand( 1, 3 ) );
		}
		$parts = explode( ' ', $name, 2 );
		$addr  = array(
			'first_name' => $parts[0],
			'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
			'city'       => $city,
			'country'    => 'IR',
		);
		$order->set_address( $addr, 'billing' );
		$order->set_address( $addr, 'shipping' );
		$order->set_date_created( $when );
		$order->update_meta_data( self::META, 1 );
		if ( $basalam ) {
			$parcel = 8000000 + wp_rand( 1000, 999999 );
			$order->update_meta_data( BSH_Order_Sync::META_PARCEL, $parcel );
			$order->update_meta_data( '_bsh_parcel_status', 'completed' === $status ? BSH_Order_Sync::ST_SATISFIED : BSH_Order_Sync::ST_PREPARATION );
		}
		$order->calculate_totals( false );
		$order->set_status( $status );
		$order->save();
		if ( $basalam ) {
			BSH_Links::upsert(
				'order',
				$order->get_id(),
				array(
					'basalam_id'     => $parcel,
					'sync_status'    => 'synced',
					'last_synced_at' => bsh_now(),
				)
			);
		}
		return $order;
	}

	/**
	 * A Basalam order arriving now — the toast, the counters and (if notifications are on)
	 * a real Bale/Telegram message, exactly like a real one.
	 *
	 * @return WC_Order
	 */
	public static function simulate_order() {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'numberposts' => 20,
				'fields'      => 'ids',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$in_stock = array_values(
			array_filter(
				$ids,
				function ( $id ) {
					$p = wc_get_product( $id );
					return $p && $p->get_stock_quantity() > 3;
				}
			)
		);
		BSH_Plugin::$suspend_hooks = true;
		add_filter( 'pre_wp_mail', '__return_false', 999 ); // Only the Bale/Telegram alert, like a real Basalam order.
		$order = self::make_order( $in_stock ? $in_stock : $ids, true, time() - 5, 'processing' );
		remove_filter( 'pre_wp_mail', '__return_false', 999 );
		BSH_Plugin::$suspend_hooks = false;
		BSH_Logger::log(
			array(
				'level'       => 'success',
				'event'       => 'order_imported',
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				/* translators: 1: parcel id, 2: order number */
				'title'       => sprintf( __( 'سفارش باسلام #%1$s → سفارش %2$s', 'basalamhub' ), $order->get_meta( BSH_Order_Sync::META_PARCEL ), $order->get_order_number() ),
				'message'     => __( 'در ووکامرس ثبت شد (نمایشی).', 'basalamhub' ),
				'context'     => array( 'demo' => 1 ),
			)
		);
		BSH_Sales::bust();
		return $order;
	}

	/**
	 * Removes everything the demo created.
	 */
	public static function clear() {
		global $wpdb;
		$state = get_option( self::OPTION );
		self::quiet( true );
		foreach ( wc_get_orders(
			array(
				'limit'      => 2000,
				'status'     => 'any',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $order ) {
			$wpdb->delete(
				BSH_Links::table(),
				array(
					'object_type' => 'order',
					'wc_id'       => $order->get_id(),
				)
			);
			$order->delete( true );
		}
		foreach ( get_posts(
			array(
				'post_type'   => array( 'product', 'product_variation' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		) as $id ) {
			$wpdb->delete(
				BSH_Links::table(),
				array(
					'object_type' => 'product',
					'wc_id'       => (int) $id,
				)
			);
			$wpdb->delete(
				BSH_Logger::table(),
				array(
					'object_type' => 'product',
					'object_id'   => (int) $id,
				)
			);
			$p = wc_get_product( $id );
			if ( $p ) {
				$p->delete( true );
			}
		}
		$wpdb->query( 'DELETE FROM ' . BSH_Logger::table() . " WHERE context LIKE '%\"demo\": 1%' OR event = 'demo_filled'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( is_array( $state ) && array_key_exists( 'prev_reconcile', $state ) ) {
			if ( $state['prev_reconcile'] ) {
				update_option( BSH_Reconcile::OPTION, $state['prev_reconcile'], false );
			} else {
				delete_option( BSH_Reconcile::OPTION );
			}
		}
		self::quiet( false );
		delete_option( self::OPTION );
		BSH_Sales::bust();
	}
}
