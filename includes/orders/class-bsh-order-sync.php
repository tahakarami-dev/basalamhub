<?php
/**
 * Basalam orders (vendor parcels) ⇄ WooCommerce orders.
 *
 * Guarantees ("سفارش گم‌نشدنی"):
 * - Polling is the source of truth: every few minutes the newest parcels are read again
 *   with an overlap, so a parcel missed during a host outage is picked up next time.
 *   A webhook (optional) only triggers an earlier poll; its payload is never trusted.
 * - A parcel is imported exactly once: links table (object_type "order") + order meta,
 *   checked under a per-parcel lock inside the single-worker queue.
 * - Every failure is logged in Persian with a retry; the dashboard counts parcels that
 *   could not be imported as «سفارش جاافتاده».
 *
 * Status, two ways:
 * - Basalam → site: status changes of imported parcels are mapped to WooCommerce statuses.
 * - Site → Basalam: «تأیید سفارش» and «ثبت ارسال» (courier + tracking code) — the only
 *   transitions Basalam's API allows a seller to make.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Order_Sync {

	const HOOK_POLL    = 'bsh_poll_orders';
	const HOOK_IMPORT  = 'bsh_import_parcel';
	const HOOK_ACTION  = 'bsh_parcel_action';
	const HOOK_REFRESH = 'bsh_refresh_parcels';
	const META_PARCEL  = '_bsh_parcel_id';

	/** Basalam parcel statuses (from the official API spec). */
	const ST_NEW              = 3739;
	const ST_PREPARATION      = 3237;
	const ST_POSTED           = 3238;
	const ST_WRONG_TRACKING   = 5017;
	const ST_NOT_DELIVERED    = 3572;
	const ST_PROBLEM          = 3740;
	const ST_CUSTOMER_CANCEL  = 4633;
	const ST_OVERDUE_REQUEST  = 5075;
	const ST_SATISFIED        = 3195;
	const ST_REFUNDED         = 3233;
	const ST_CANCEL           = 3067;
	const ST_VENDOR_CANCEL    = 6440;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_POLL, array( __CLASS__, 'handle_poll' ) );
		add_action( self::HOOK_IMPORT, array( __CLASS__, 'handle_import' ), 10, 1 );
		add_action( self::HOOK_ACTION, array( __CLASS__, 'handle_action' ), 10, 3 );
		add_action( self::HOOK_REFRESH, array( __CLASS__, 'handle_refresh' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_route' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_wc_status_changed' ), 20, 4 );
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return (bool) BSH_Settings::get( 'orders_enabled' ) && BSH_Settings::is_connected();
	}

	/**
	 * Keeps the recurring poll in line with the setting.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$interval = max( 2, (int) BSH_Settings::get( 'orders_interval', 5 ) ) * MINUTE_IN_SECONDS;
		$next     = as_next_scheduled_action( self::HOOK_POLL, array(), BSH_Queue::GROUP );
		if ( ! self::enabled() ) {
			if ( $next ) {
				as_unschedule_all_actions( self::HOOK_POLL, array(), BSH_Queue::GROUP );
			}
			return;
		}
		if ( ! $next ) {
			as_schedule_recurring_action( time() + 30, $interval, self::HOOK_POLL, array(), BSH_Queue::GROUP );
		}
	}

	/**
	 * Re-creates the schedule after the interval setting changes.
	 */
	public static function reschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_POLL, array(), BSH_Queue::GROUP );
		}
		self::schedule();
	}

	/**
	 * Asks for a poll as soon as possible (button, webhook).
	 */
	public static function poll_now() {
		if ( ! as_has_scheduled_action( self::HOOK_POLL, array( 'now' => 1 ), BSH_Queue::GROUP ) ) {
			as_enqueue_async_action( self::HOOK_POLL, array( 'now' => 1 ), BSH_Queue::GROUP );
		}
	}

	/* ---------------------------------------------------------------------
	 * Polling
	 * ------------------------------------------------------------------ */

	/**
	 * Recurring job: list the newest parcels, queue the ones not imported yet, and refresh
	 * the statuses of open imported orders.
	 */
	public static function handle_poll() {
		if ( ! self::enabled() ) {
			return;
		}
		BSH_Queue::run_exclusive(
			self::HOOK_POLL,
			array( 'now' => 1 ),
			function () {
				try {
					$queued = self::poll();
					update_option( 'bsh_orders_polled_at', bsh_now(), false );
					delete_option( 'bsh_orders_poll_error' );
					BSH_Queue::reset_attempts( 'orders_poll' );
					if ( $queued ) {
						BSH_Logger::log(
							array(
								'level'       => 'info',
								'event'       => 'orders_found',
								'object_type' => 'system',
								'title'       => __( 'سفارش‌های باسلام', 'basalamhub' ),
								/* translators: %s: count */
								'message'     => sprintf( __( '%s سفارش تازه پیدا شد و در صف ثبت قرار گرفت.', 'basalamhub' ), bsh_fa_number( $queued ) ),
							)
						);
					}
				} catch ( BSH_Api_Error $e ) {
					if ( 'rate_limit' === $e->kind ) {
						BSH_Queue::pause( $e->retry_after );
					}
					// The recurring schedule tries again by itself; record it once for the health page.
					if ( get_option( 'bsh_orders_poll_error' ) !== $e->getMessage() ) {
						BSH_Logger::log(
							array_merge(
								array(
									'level'       => $e->retryable ? 'warning' : 'error',
									'event'       => 'orders_poll_failed',
									'object_type' => 'system',
									'title'       => __( 'دریافت سفارش‌های باسلام', 'basalamhub' ),
								),
								$e->to_log()
							)
						);
					}
					update_option( 'bsh_orders_poll_error', $e->getMessage(), false );
					if ( 'auth' === $e->kind ) {
						BSH_Settings::update_connection( array( 'status' => 'invalid', 'message' => $e->getMessage() ) );
					}
				}
			}
		);
	}

	/**
	 * One poll pass.
	 *
	 * @return int Parcels queued for import.
	 * @throws BSH_Api_Error On API failure.
	 */
	public static function poll() {
		$api       = BSH_Plugin::api();
		$first_run = ! get_option( 'bsh_orders_polled_at' );
		$since     = $first_run
			? time() - max( 0, (int) BSH_Settings::get( 'orders_import_days', 3 ) ) * DAY_IN_SECONDS
			: strtotime( get_option( 'bsh_orders_polled_at' ) . ' UTC' ) - DAY_IN_SECONDS; // A day of overlap.
		$queued = 0;
		$cursor = null;
		for ( $page = 0; $page < 20; $page++ ) {
			$res   = $api->vendor_parcels( array( 'cursor' => $cursor, 'per_page' => 30 ) );
			$older = false;
			foreach ( $res['data'] as $parcel ) {
				if ( empty( $parcel['id'] ) ) {
					continue;
				}
				$created = isset( $parcel['created_at'] ) ? strtotime( (string) $parcel['created_at'] ) : time();
				if ( $created && $created < $since ) {
					$older = true;
					continue;
				}
				if ( self::find_order( (int) $parcel['id'] ) || self::is_skipped( (int) $parcel['id'] ) ) {
					continue;
				}
				if ( self::queue_import( (int) $parcel['id'] ) ) {
					++$queued;
				}
			}
			// The list is newest first: once we reach parcels older than the window, stop.
			if ( $older || ! $res['next_cursor'] || ! $res['data'] ) {
				break;
			}
			$cursor = $res['next_cursor'];
		}
		self::refresh_open();
		return $queued;
	}

	/**
	 * @param int $parcel_id Parcel.
	 * @return bool Whether it was newly queued.
	 */
	public static function queue_import( $parcel_id ) {
		$args = array( 'parcel_id' => (int) $parcel_id );
		if ( as_has_scheduled_action( self::HOOK_IMPORT, $args, BSH_Queue::GROUP ) ) {
			return false;
		}
		as_enqueue_async_action( self::HOOK_IMPORT, $args, BSH_Queue::GROUP );
		return true;
	}

	/**
	 * The WooCommerce order already created for a parcel, if any.
	 *
	 * @param int $parcel_id Parcel.
	 * @return int Order ID or 0.
	 */
	public static function find_order( $parcel_id ) {
		$link = BSH_Links::get_by_basalam( 'order', $parcel_id );
		if ( $link && $link->wc_id && wc_get_order( (int) $link->wc_id ) ) {
			return (int) $link->wc_id;
		}
		// Belt and braces: the order meta survives even if the links row was lost.
		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => self::META_PARCEL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) (int) $parcel_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'     => 'any',
			)
		);
		if ( $ids ) {
			BSH_Links::upsert( 'order', (int) $ids[0], array( 'basalam_id' => (int) $parcel_id ) );
			return (int) $ids[0];
		}
		return 0;
	}

	/**
	 * A parcel that was already cancelled when first seen (never becomes an order).
	 *
	 * @param int $parcel_id Parcel.
	 * @return bool
	 */
	public static function is_skipped( $parcel_id ) {
		return (bool) BSH_Links::get( 'parcel_skip', (int) $parcel_id );
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Queue callback.
	 *
	 * @param int $parcel_id Parcel.
	 */
	public static function handle_import( $parcel_id ) {
		BSH_Queue::run_exclusive(
			self::HOOK_IMPORT,
			array( 'parcel_id' => (int) $parcel_id ),
			function () use ( $parcel_id ) {
				self::import( (int) $parcel_id );
			}
		);
	}

	/**
	 * Creates the WooCommerce order for a parcel (once).
	 *
	 * @param int $parcel_id Parcel.
	 * @return int|string Order ID, "exists", "skipped" or "failed".
	 */
	public static function import( $parcel_id ) {
		if ( self::find_order( $parcel_id ) ) {
			return 'exists';
		}
		if ( self::is_skipped( $parcel_id ) ) {
			return 'skipped';
		}
		$owner = BSH_Lock::acquire( 'parcel_' . $parcel_id, 120 );
		if ( ! $owner ) {
			return 'exists'; // Another process is importing it right now.
		}
		try {
			$parcel = BSH_Plugin::api()->get_parcel( $parcel_id );
			$status = isset( $parcel['status']['id'] ) ? (int) $parcel['status']['id'] : 0;
			if ( in_array( $status, array( self::ST_CANCEL, self::ST_VENDOR_CANCEL ), true ) ) {
				// Cancelled before we ever saw it: nothing to fulfil, remember so we don't fetch it again.
				BSH_Links::upsert( 'parcel_skip', (int) $parcel_id, array( 'basalam_id' => (int) $parcel_id, 'sync_status' => 'synced' ) );
				BSH_Logger::resolve_for( 'parcel', $parcel_id );
				return 'skipped';
			}
			$order_id = self::create_order( $parcel );
			BSH_Queue::reset_attempts( 'parcel_' . $parcel_id );
			if ( BSH_Settings::get( 'orders_auto_confirm' ) && self::ST_NEW === $status ) {
				self::queue_action( $parcel_id, 'confirm' );
			}
			return $order_id;
		} catch ( BSH_Api_Error $e ) {
			self::import_failed( $parcel_id, $e );
			return 'failed';
		} catch ( Throwable $e ) {
			self::import_failed(
				$parcel_id,
				new BSH_Api_Error(
					__( 'ساخت سفارش در ووکامرس با خطای داخلی متوقف شد.', 'basalamhub' ),
					'unknown',
					array(
						'reason'     => __( 'یک خطای پیش‌بینی‌نشده در سایت رخ داد (احتمالاً تداخل با افزونه‌ی دیگر).', 'basalamhub' ),
						'suggestion' => __( '«تلاش مجدد» را بزن. اگر تکرار شد، جزئیات فنی را برای پشتیبانی بفرست. سفارش در باسلام سالم است.', 'basalamhub' ),
						'details'    => array( 'exception' => get_class( $e ), 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ),
					)
				)
			);
			return 'failed';
		} finally {
			BSH_Lock::release( 'parcel_' . $parcel_id, $owner );
		}
	}

	/**
	 * @param int           $parcel_id Parcel.
	 * @param BSH_Api_Error $e         Error.
	 */
	private static function import_failed( $parcel_id, BSH_Api_Error $e ) {
		if ( 'rate_limit' === $e->kind ) {
			BSH_Queue::pause( $e->retry_after );
		}
		if ( $e->retryable && false !== BSH_Queue::retry_later( self::HOOK_IMPORT, array( 'parcel_id' => (int) $parcel_id ), 'parcel_' . $parcel_id, $e->retry_after ) ) {
			return;
		}
		BSH_Logger::log(
			array_merge(
				array(
					'level'       => 'error',
					'event'       => 'order_import_failed',
					'object_type' => 'parcel',
					'object_id'   => (int) $parcel_id,
					/* translators: %s: parcel id */
					'title'       => sprintf( __( 'سفارش باسلام #%s', 'basalamhub' ), $parcel_id ),
					'retry_hook'  => self::HOOK_IMPORT,
					'retry_args'  => array( 'parcel_id' => (int) $parcel_id ),
				),
				$e->to_log(),
				array( 'message' => __( 'در ووکامرس ثبت نشد.', 'basalamhub' ) . ' ' . $e->getMessage() )
			)
		);
	}

	/**
	 * Builds the WooCommerce order from a ParcelResponse.
	 *
	 * @param array $parcel Parcel.
	 * @return int Order ID.
	 * @throws BSH_Api_Error When the currency can't be converted.
	 */
	public static function create_order( array $parcel ) {
		$parcel_id  = (int) $parcel['id'];
		$multiplier = BSH_Product_Mapper::rial_multiplier();
		if ( null === $multiplier ) {
			throw new BSH_Api_Error(
				__( 'واحد پول فروشگاه قابل تبدیل از ریال نیست.', 'basalamhub' ),
				'validation',
				array(
					'reason'     => __( 'مبلغ سفارش‌های باسلام به ریال است و باسلام‌هاب نمی‌داند به چه واحدی تبدیلش کند.', 'basalamhub' ),
					'suggestion' => __( 'در باسلام‌هاب › تنظیمات واحد قیمت‌های سایت را روی «تومان» یا «ریال» بگذار و «تلاش مجدد» را بزن.', 'basalamhub' ),
				)
			);
		}
		$to_store = function ( $rial ) use ( $multiplier ) {
			return wc_format_decimal( (float) $rial / $multiplier, wc_get_price_decimals() );
		};

		BSH_Plugin::$suspend_hooks = true;
		try {
			$order = wc_create_order( array( 'created_via' => 'basalamhub', 'status' => 'pending' ) );
			if ( is_wp_error( $order ) ) {
				throw new BSH_Api_Error( __( 'ووکامرس سفارش را نساخت.', 'basalamhub' ), 'unknown', array( 'reason' => $order->get_error_message() ) );
			}

			// Items. Basalam's "price" may be per unit or per line; decide from the parcel total.
			$items       = isset( $parcel['items'] ) && is_array( $parcel['items'] ) ? $parcel['items'] : array();
			$unit_sum    = 0;
			$line_sum    = 0;
			foreach ( $items as $item ) {
				$unit_sum += (int) $item['price'] * max( 1, (int) $item['quantity'] );
				$line_sum += (int) $item['price'];
			}
			$total_items = isset( $parcel['total_items_price'] ) ? (int) $parcel['total_items_price'] : $unit_sum;
			$per_line    = $line_sum === $total_items && $unit_sum !== $total_items;

			$missing = array();
			foreach ( $items as $item ) {
				$qty   = max( 1, (int) $item['quantity'] );
				$line  = $per_line ? (int) $item['price'] : (int) $item['price'] * $qty;
				$total = $to_store( $line );
				$wc    = self::resolve_product( $item );
				if ( $wc ) {
					$order->add_product( $wc, $qty, array( 'subtotal' => $total, 'total' => $total ) );
				} else {
					$li = new WC_Order_Item_Product();
					$li->set_name( isset( $item['title'] ) ? (string) $item['title'] : __( 'محصول باسلام', 'basalamhub' ) );
					$li->set_quantity( $qty );
					$li->set_subtotal( $total );
					$li->set_total( $total );
					$order->add_item( $li );
					$missing[] = isset( $item['title'] ) ? $item['title'] : '#' . ( isset( $item['product']['id'] ) ? $item['product']['id'] : '?' );
				}
			}

			// Shipping.
			$shipping_cost = isset( $parcel['shipping_cost'] ) ? (int) $parcel['shipping_cost'] : 0;
			$method        = isset( $parcel['shipping_method']['current']['title'] ) ? (string) $parcel['shipping_method']['current']['title'] : '';
			$ship          = new WC_Order_Item_Shipping();
			/* translators: %s: shipping method */
			$ship->set_method_title( $method ? sprintf( __( 'ارسال باسلام (%s)', 'basalamhub' ), $method ) : __( 'ارسال باسلام', 'basalamhub' ) );
			$ship->set_method_id( 'basalamhub_basalam' );
			$ship->set_total( $to_store( $shipping_cost ) );
			$order->add_item( $ship );

			// Customer and address.
			$customer  = isset( $parcel['order']['customer'] ) ? $parcel['order']['customer'] : array();
			$recipient = isset( $customer['recipient'] ) ? $customer['recipient'] : array();
			$name      = trim( isset( $recipient['name'] ) ? (string) $recipient['name'] : ( isset( $customer['user']['name'] ) ? (string) $customer['user']['name'] : '' ) );
			$parts     = preg_split( '/\s+/u', $name, 2 );
			$address   = trim( ( isset( $recipient['postal_address'] ) ? $recipient['postal_address'] : '' ) );
			$unit      = trim( implode( '، ', array_filter( array(
				! empty( $recipient['house_number'] ) ? sprintf( /* translators: %s: plate */ __( 'پلاک %s', 'basalamhub' ), $recipient['house_number'] ) : '',
				! empty( $recipient['house_unit'] ) ? sprintf( /* translators: %s: unit */ __( 'واحد %s', 'basalamhub' ), $recipient['house_unit'] ) : '',
			) ) ) );
			$city      = isset( $customer['city']['title'] ) ? (string) $customer['city']['title'] : '';
			$province  = isset( $customer['city']['parent']['title'] ) ? (string) $customer['city']['parent']['title'] : '';
			$addr      = array(
				'first_name' => isset( $parts[0] ) ? $parts[0] : '',
				'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
				'address_1'  => $address,
				'address_2'  => $unit,
				'city'       => $city,
				'state'      => $province,
				'postcode'   => isset( $recipient['postal_code'] ) ? (string) $recipient['postal_code'] : '',
				'country'    => 'IR',
				'phone'      => isset( $recipient['mobile'] ) ? (string) $recipient['mobile'] : '',
			);
			$order->set_address( $addr, 'billing' );
			$order->set_address( $addr, 'shipping' );

			// "other": WooCommerce has no gateway for it, so the edit screen doesn't print a raw ID.
			$order->set_payment_method( 'other' );
			$order->set_payment_method_title( __( 'پرداخت در باسلام', 'basalamhub' ) );
			if ( ! empty( $parcel['order']['id'] ) ) {
				$order->set_transaction_id( (string) $parcel['order']['id'] );
			}
			// Shows as the order's origin in WooCommerce's "Order attribution" box and reports.
			$order->update_meta_data( '_wc_order_attribution_source_type', 'utm' );
			$order->update_meta_data( '_wc_order_attribution_utm_source', 'basalam' );
			$order->update_meta_data( '_wc_order_attribution_utm_medium', 'marketplace' );
			if ( ! empty( $parcel['order']['paid_at'] ) ) {
				$order->set_date_paid( strtotime( (string) $parcel['order']['paid_at'] ) );
			}
			if ( ! empty( $parcel['order']['created_at'] ) ) {
				$order->set_date_created( strtotime( (string) $parcel['order']['created_at'] ) );
			}
			$order->update_meta_data( self::META_PARCEL, $parcel_id );
			$order->update_meta_data( '_bsh_basalam_order_id', isset( $parcel['order']['id'] ) ? (int) $parcel['order']['id'] : 0 );
			$order->update_meta_data( '_bsh_parcel_status', isset( $parcel['status']['id'] ) ? (int) $parcel['status']['id'] : 0 );
			$order->calculate_totals( false );
			$order->save();

			// Link first, so nothing can import this parcel a second time from here on.
			BSH_Links::upsert(
				'order',
				$order->get_id(),
				array(
					'basalam_id'     => $parcel_id,
					'sync_status'    => 'synced',
					'last_synced_at' => bsh_now(),
					'last_error'     => null,
				)
			);

			/* translators: 1: parcel id, 2: Basalam status */
			$note = sprintf( __( 'سفارش باسلام #%1$s (وضعیت در باسلام: %2$s) توسط باسلام‌هاب ثبت شد.', 'basalamhub' ), $parcel_id, isset( $parcel['status']['title'] ) ? $parcel['status']['title'] : '—' );
			if ( $missing ) {
				/* translators: %s: product names */
				$note .= ' ' . sprintf( __( 'این محصولات در سایت پیدا نشدند و بدون اتصال ثبت شدند (موجودی‌شان کم نشد): %s', 'basalamhub' ), implode( '، ', $missing ) );
			}
			$order->add_order_note( $note );

			// Status last: moving to processing reduces WooCommerce stock (once).
			$target = self::wc_status_for( isset( $parcel['status']['id'] ) ? (int) $parcel['status']['id'] : self::ST_NEW );
			$order->update_status( $target, __( 'وضعیت از باسلام.', 'basalamhub' ) );
			if ( in_array( $target, array( 'processing', 'completed', 'on-hold' ), true ) ) {
				wc_maybe_reduce_stock_levels( $order->get_id() );
			}
		} finally {
			BSH_Plugin::$suspend_hooks = false;
		}

		// Stock changed through the order: queue the affected linked products (site is the reference).
		foreach ( $order->get_items() as $li ) {
			$pid = $li instanceof WC_Order_Item_Product ? (int) $li->get_product_id() : 0;
			if ( $pid ) {
				$link = BSH_Links::get( 'product', $pid );
				if ( $link && $link->basalam_id && BSH_Settings::get( 'auto_update' ) ) {
					BSH_Queue::enqueue_product( $pid );
				}
			}
		}

		BSH_Logger::resolve_for( 'parcel', $parcel_id );
		BSH_Logger::log(
			array(
				'level'       => $missing ? 'warning' : 'success',
				'event'       => 'order_imported',
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				/* translators: 1: parcel id, 2: order number */
				'title'       => sprintf( __( 'سفارش باسلام #%1$s → سفارش %2$s', 'basalamhub' ), $parcel_id, $order->get_order_number() ),
				'message'     => __( 'در ووکامرس ثبت شد.', 'basalamhub' ),
				/* translators: %s: names */
				'reason'      => $missing ? sprintf( __( 'این محصولات در سایت پیدا نشدند: %s', 'basalamhub' ), implode( '، ', $missing ) ) : null,
				'suggestion'  => $missing ? __( 'این محصولات را از «اتصال محصولات غرفه» به محصولات سایت وصل کن تا سفارش‌های بعدی موجودی را درست کم کنند.', 'basalamhub' ) : null,
				'context'     => array( 'parcel_id' => $parcel_id ),
			)
		);
		return $order->get_id();
	}

	/**
	 * The WooCommerce product (or variation) for a parcel item, through the links table.
	 *
	 * @param array $item ItemOfParcelResponse.
	 * @return WC_Product|null
	 */
	public static function resolve_product( array $item ) {
		$bid = isset( $item['product']['id'] ) ? (int) $item['product']['id'] : 0;
		if ( ! $bid ) {
			return null;
		}
		$link = BSH_Links::get_by_basalam( 'product', $bid );
		if ( ! $link ) {
			return null;
		}
		$product = wc_get_product( (int) $link->wc_id );
		if ( ! $product ) {
			return null;
		}
		$variant = isset( $item['variation']['id'] ) ? (int) $item['variation']['id'] : 0;
		if ( $variant && $product->is_type( 'variable' ) ) {
			foreach ( BSH_Product_Sync::variant_map( $product ) as $vid => $row ) {
				if ( (int) $row['id'] === $variant ) {
					$v = wc_get_product( (int) $vid );
					return $v ? $v : $product;
				}
			}
		}
		return $product;
	}

	/**
	 * Basalam parcel status → WooCommerce order status.
	 *
	 * @param int $status Basalam status ID.
	 * @return string
	 */
	public static function wc_status_for( $status ) {
		$map = array(
			self::ST_NEW             => 'processing',
			self::ST_PREPARATION     => 'processing',
			self::ST_POSTED          => 'completed',
			self::ST_SATISFIED       => 'completed',
			self::ST_NOT_DELIVERED   => 'on-hold',
			self::ST_WRONG_TRACKING  => 'on-hold',
			self::ST_PROBLEM         => 'on-hold',
			self::ST_CUSTOMER_CANCEL => 'on-hold',
			self::ST_OVERDUE_REQUEST => 'on-hold',
			self::ST_VENDOR_CANCEL   => 'on-hold',
			self::ST_CANCEL          => 'cancelled',
			self::ST_REFUNDED        => 'refunded',
		);
		/**
		 * Lets a store map Basalam statuses to its own (custom) order statuses.
		 *
		 * @param string $wc_status WooCommerce status.
		 * @param int    $status    Basalam status ID.
		 */
		return (string) apply_filters( 'bsh_order_status', isset( $map[ $status ] ) ? $map[ $status ] : 'on-hold', $status );
	}

	/* ---------------------------------------------------------------------
	 * Basalam → site: status refresh
	 * ------------------------------------------------------------------ */

	/**
	 * Reads the current status of imported orders that are not final yet.
	 *
	 * @throws BSH_Api_Error On API failure.
	 */
	public static function refresh_open() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT wc_id, basalam_id FROM ' . BSH_Links::table() . " WHERE object_type = 'order' AND wc_id > 0 AND basalam_id IS NOT NULL ORDER BY updated_at ASC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$open = array();
		foreach ( (array) $rows as $r ) {
			$order = wc_get_order( (int) $r->wc_id );
			if ( ! $order ) {
				continue;
			}
			$st = (int) $order->get_meta( '_bsh_parcel_status' );
			if ( ! in_array( $st, array( self::ST_SATISFIED, self::ST_REFUNDED, self::ST_CANCEL ), true ) ) {
				$open[ (int) $r->basalam_id ] = $order;
			}
		}
		foreach ( array_chunk( array_keys( $open ), 30, true ) as $ids ) {
			$res = BSH_Plugin::api()->vendor_parcels( array( 'ids' => $ids, 'per_page' => 30 ) );
			foreach ( $res['data'] as $parcel ) {
				if ( isset( $parcel['id'], $open[ (int) $parcel['id'] ], $parcel['status']['id'] ) ) {
					self::apply_remote_status( $open[ (int) $parcel['id'] ], (int) $parcel['status']['id'], isset( $parcel['status']['title'] ) ? (string) $parcel['status']['title'] : '' );
				}
			}
		}
	}

	/**
	 * Mirrors a Basalam status change onto the WooCommerce order.
	 *
	 * @param WC_Order $order  Order.
	 * @param int      $status Basalam status ID.
	 * @param string   $title  Persian status title from Basalam.
	 */
	public static function apply_remote_status( WC_Order $order, $status, $title = '' ) {
		$previous = (int) $order->get_meta( '_bsh_parcel_status' );
		if ( $previous === $status ) {
			return;
		}
		$order->update_meta_data( '_bsh_parcel_status', $status );
		$order->save();
		$target = self::wc_status_for( $status );
		BSH_Plugin::$suspend_hooks = true;
		try {
			if ( $order->get_status() !== $target ) {
				/* translators: %s: Basalam status */
				$order->update_status( $target, sprintf( __( 'وضعیت در باسلام عوض شد: %s', 'basalamhub' ), $title ? $title : $status ) );
			} else {
				/* translators: %s: Basalam status */
				$order->add_order_note( sprintf( __( 'وضعیت در باسلام عوض شد: %s', 'basalamhub' ), $title ? $title : $status ) );
			}
		} finally {
			BSH_Plugin::$suspend_hooks = false;
		}
		// The status moved on in Basalam, so an earlier failed confirm/posted is moot now.
		BSH_Links::upsert( 'order', $order->get_id(), array( 'last_synced_at' => bsh_now(), 'sync_status' => 'synced', 'last_error' => null ) );
		BSH_Logger::resolve_for( 'order', $order->get_id() );
		$attention = in_array( $status, array( self::ST_PROBLEM, self::ST_CUSTOMER_CANCEL, self::ST_NOT_DELIVERED, self::ST_WRONG_TRACKING, self::ST_OVERDUE_REQUEST ), true );
		BSH_Logger::log(
			array(
				'level'       => $attention ? 'warning' : 'info',
				'event'       => 'order_status_remote',
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				/* translators: %s: order number */
				'title'       => sprintf( __( 'سفارش %s', 'basalamhub' ), $order->get_order_number() ),
				/* translators: %s: status */
				'message'     => sprintf( __( 'وضعیت در باسلام: %s', 'basalamhub' ), $title ? $title : $status ),
				'suggestion'  => $attention ? __( 'این وضعیت به رسیدگی تو در پنل باسلام نیاز دارد.', 'basalamhub' ) : null,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Site → Basalam: confirm / posted
	 * ------------------------------------------------------------------ */

	/**
	 * @param int    $parcel_id Parcel.
	 * @param string $action    confirm|posted.
	 * @param array  $data      shipping_method, tracking_code (for posted).
	 */
	public static function queue_action( $parcel_id, $action, array $data = array() ) {
		as_enqueue_async_action( self::HOOK_ACTION, array( 'parcel_id' => (int) $parcel_id, 'action' => $action, 'data' => $data ), BSH_Queue::GROUP );
	}

	/**
	 * Queue callback.
	 *
	 * @param int    $parcel_id Parcel.
	 * @param string $action    Action.
	 * @param array  $data      Data.
	 */
	public static function handle_action( $parcel_id, $action, $data = array() ) {
		BSH_Queue::run_exclusive(
			self::HOOK_ACTION,
			array( 'parcel_id' => (int) $parcel_id, 'action' => $action, 'data' => $data ),
			function () use ( $parcel_id, $action, $data ) {
				self::do_action( (int) $parcel_id, (string) $action, (array) $data );
			}
		);
	}

	/**
	 * @param int    $parcel_id Parcel.
	 * @param string $action    confirm|posted.
	 * @param array  $data      Data.
	 * @return bool
	 */
	public static function do_action( $parcel_id, $action, array $data ) {
		$order_id = self::find_order( $parcel_id );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$labels   = array( 'confirm' => __( 'تأیید سفارش', 'basalamhub' ), 'posted' => __( 'ثبت ارسال', 'basalamhub' ) );
		$label    = isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
		try {
			if ( 'confirm' === $action ) {
				BSH_Plugin::api()->set_parcel_preparation( $parcel_id );
				$new = self::ST_PREPARATION;
			} elseif ( 'posted' === $action ) {
				BSH_Plugin::api()->set_parcel_posted( $parcel_id, isset( $data['shipping_method'] ) ? (int) $data['shipping_method'] : 0, isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : '' );
				$new = self::ST_POSTED;
			} else {
				return false;
			}
			BSH_Queue::reset_attempts( 'action_' . $parcel_id . $action );
			if ( $order ) {
				$order->update_meta_data( '_bsh_parcel_status', $new );
				$order->save();
				/* translators: %s: action */
				$order->add_order_note( sprintf( __( '«%s» در باسلام ثبت شد.', 'basalamhub' ), $label ) );
				BSH_Links::upsert( 'order', $order->get_id(), array( 'sync_status' => 'synced', 'last_error' => null, 'last_synced_at' => bsh_now() ) );
				BSH_Logger::resolve_for( 'order', $order->get_id() );
			}
			BSH_Logger::log(
				array(
					'level'       => 'success',
					'event'       => 'order_status_pushed',
					'object_type' => 'order',
					'object_id'   => $order ? $order->get_id() : null,
					/* translators: %s: parcel */
					'title'       => sprintf( __( 'سفارش باسلام #%s', 'basalamhub' ), $parcel_id ),
					/* translators: %s: action */
					'message'     => sprintf( __( '«%s» در باسلام ثبت شد.', 'basalamhub' ), $label ),
				)
			);
			return true;
		} catch ( BSH_Api_Error $e ) {
			if ( $e->retryable && false !== BSH_Queue::retry_later( self::HOOK_ACTION, array( 'parcel_id' => (int) $parcel_id, 'action' => $action, 'data' => $data ), 'action_' . $parcel_id . $action, $e->retry_after ) ) {
				return false;
			}
			$message = $e->getMessage();
			if ( 'validation' === $e->kind ) {
				// The generic 422 text talks about product data; say what it means for an order.
				$message       = __( 'باسلام این تغییر وضعیت را نپذیرفت.', 'basalamhub' );
				$e->reason     = trim( __( 'احتمالاً وضعیت فعلی سفارش در باسلام اجازه‌ی این تغییر را نمی‌دهد (مثلاً قبلاً ارسال یا لغو شده) یا کد رهگیری معتبر نیست.', 'basalamhub' ) . ' ' . $e->reason );
				$e->suggestion = __( 'وضعیت سفارش را در پنل باسلام ببین؛ باسلام‌هاب در دریافت بعدی وضعیت را خودش به‌روز می‌کند.', 'basalamhub' );
			}
			if ( $order ) {
				BSH_Links::upsert( 'order', $order->get_id(), array( 'sync_status' => 'error', 'last_error' => $message ) );
				/* translators: 1: action, 2: error */
				$order->add_order_note( sprintf( __( '«%1$s» در باسلام ثبت نشد: %2$s', 'basalamhub' ), $label, trim( $message . ' ' . $e->reason ) ) );
			}
			BSH_Logger::log(
				array_merge(
					array(
						'level'       => 'error',
						'event'       => 'order_status_failed',
						'object_type' => 'order',
						'object_id'   => $order ? $order->get_id() : null,
						/* translators: %s: parcel */
						'title'       => sprintf( __( 'سفارش باسلام #%s', 'basalamhub' ), $parcel_id ),
						'retry_hook'  => self::HOOK_ACTION,
						'retry_args'  => array( 'parcel_id' => (int) $parcel_id, 'action' => $action, 'data' => $data ),
					),
					$e->to_log(),
					/* translators: %s: action */
					array( 'message' => sprintf( __( '«%s» در باسلام ثبت نشد.', 'basalamhub' ), $label ) . ' ' . $message )
				)
			);
			return false;
		}
	}

	/**
	 * WooCommerce status changed by the seller → tell Basalam where the API allows it.
	 *
	 * @param int      $order_id Order.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function on_wc_status_changed( $order_id, $from, $to, $order ) {
		if ( BSH_Plugin::$suspend_hooks || ! $order instanceof WC_Order ) {
			return;
		}
		$parcel_id = (int) $order->get_meta( self::META_PARCEL );
		if ( ! $parcel_id ) {
			return;
		}
		$remote = (int) $order->get_meta( '_bsh_parcel_status' );
		if ( 'completed' === $to && self::ST_POSTED !== $remote ) {
			$method   = (int) $order->get_meta( '_bsh_shipping_method' );
			$tracking = (string) $order->get_meta( '_bsh_tracking_code' );
			if ( ! $method ) {
				$order->add_order_note( __( 'سفارش «تکمیل‌شده» شد ولی روش ارسال در کادر باسلام‌هاب انتخاب نشده؛ ارسال در باسلام ثبت نشد. روش ارسال و کد رهگیری را وارد کن و «ثبت ارسال در باسلام» را بزن.', 'basalamhub' ) );
				return;
			}
			self::queue_action( $parcel_id, 'posted', array( 'shipping_method' => $method, 'tracking_code' => $tracking ) );
		} elseif ( 'cancelled' === $to && ! in_array( $remote, array( self::ST_CANCEL, self::ST_VENDOR_CANCEL ), true ) ) {
			$order->add_order_note( __( 'این سفارش در سایت لغو شد، ولی API باسلام اجازه‌ی لغو از سمت غرفه‌دار را نمی‌دهد. درخواست لغو را در پنل باسلام ثبت کن؛ وضعیت بعد از آن خودکار همگام می‌شود.', 'basalamhub' ) );
			BSH_Logger::log(
				array(
					'level'       => 'warning',
					'event'       => 'order_cancel_manual',
					'object_type' => 'order',
					'object_id'   => $order->get_id(),
					/* translators: %s: order number */
					'title'       => sprintf( __( 'سفارش %s', 'basalamhub' ), $order->get_order_number() ),
					'message'     => __( 'در سایت لغو شد؛ در باسلام هنوز لغو نشده.', 'basalamhub' ),
					'reason'      => __( 'API باسلام لغو سفارش از سمت غرفه‌دار را پشتیبانی نمی‌کند.', 'basalamhub' ),
					'suggestion'  => __( 'در پنل غرفه‌ی باسلام «درخواست لغو» را ثبت کن.', 'basalamhub' ),
				)
			);
		}
	}

	/**
	 * Basalam's shipping methods for «ثبت ارسال» (codes from the official API spec).
	 *
	 * @return array<int,string>
	 */
	public static function shipping_methods() {
		return array(
			3259 => __( 'پیک', 'basalamhub' ),
			3197 => __( 'پست پیشتاز', 'basalamhub' ),
			3198 => __( 'پست سفارشی', 'basalamhub' ),
			5137 => __( 'باربری', 'basalamhub' ),
			4040 => __( 'تیپاکس', 'basalamhub' ),
			6102 => __( 'ماهکس', 'basalamhub' ),
			6101 => __( 'چاپار', 'basalamhub' ),
			6110 => __( 'امداد پست', 'basalamhub' ),
			6111 => __( 'دکا', 'basalamhub' ),
			6112 => __( 'چیتا', 'basalamhub' ),
			6113 => __( 'باکسیت', 'basalamhub' ),
			6114 => __( 'سلام‌رسان', 'basalamhub' ),
			7058 => __( 'دیجی‌اکسپرس', 'basalamhub' ),
			7059 => __( 'تاپین', 'basalamhub' ),
			7060 => __( 'پارسی', 'basalamhub' ),
			7061 => __( 'پینکس', 'basalamhub' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Webhook (optional, faster)
	 * ------------------------------------------------------------------ */

	/**
	 * POST /wp-json/basalamhub/v1/basalam-webhook?key=… — only triggers a poll.
	 */
	public static function register_webhook_route() {
		// The old namespace stays, so a webhook registered before the rename keeps working.
		foreach ( array( 'basalamhub/v1', 'salamhub/v1' ) as $namespace ) {
			register_rest_route(
				$namespace,
				'/basalam-webhook',
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true', // Checked with the secret below.
					'callback'            => array( __CLASS__, 'handle_webhook' ),
				)
			);
		}
	}

	/**
	 * The secret for the webhook URL (generated once).
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		$secret = (string) get_option( 'bsh_webhook_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false );
			update_option( 'bsh_webhook_secret', $secret, false );
		}
		return $secret;
	}

	/**
	 * @return string
	 */
	public static function webhook_url() {
		return add_query_arg( 'key', self::webhook_secret(), rest_url( 'basalamhub/v1/basalam-webhook' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$key = (string) ( $request->get_param( 'key' ) ? $request->get_param( 'key' ) : $request->get_header( 'x-basalamhub-key' ) );
		if ( ! hash_equals( self::webhook_secret(), $key ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		if ( self::enabled() ) {
			self::poll_now();
		}
		update_option( 'bsh_webhook_last', bsh_now(), false );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Numbers for the dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Parcels seen on Basalam that are not in WooCommerce because import failed.
	 *
	 * @return int
	 */
	public static function missing_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT object_id) FROM ' . BSH_Logger::table() . " WHERE object_type = 'parcel' AND level = 'error' AND resolved = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return int Orders imported from Basalam.
	 */
	public static function imported_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSH_Links::table() . " WHERE object_type = 'order' AND wc_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
