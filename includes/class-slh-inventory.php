<?php
/**
 * Two-way stock and safety stock.
 *
 * Reference ("مرجع موجودی"):
 * - site (default): WooCommerce stock is the truth. Every change (site sale, manual edit,
 *   Basalam order imported into WooCommerce) is pushed to Basalam.
 * - basalam: Basalam's stock is the truth. Stock is no longer pushed on updates; every hour
 *   Basalam's stock is copied into WooCommerce, and a sale on the site subtracts from
 *   Basalam's current stock (read → subtract → write) instead of overwriting it.
 *
 * Safety stock ("موجودی اطمینان"): N units are hidden from Basalam so a simultaneous sale on
 * both channels can't oversell. Global value in settings, overridable per product/variation.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Inventory {

	const HOOK_PULL      = 'slh_stock_pull';
	const HOOK_DECREMENT = 'slh_stock_decrement';
	const META_SAFETY    = '_slh_safety_stock';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'slh_basalam_stock', array( __CLASS__, 'apply_safety' ), 5, 2 );
		add_action( self::HOOK_PULL, array( __CLASS__, 'handle_pull' ), 10, 1 );
		add_action( self::HOOK_DECREMENT, array( __CLASS__, 'handle_decrement' ), 10, 3 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'on_site_sale' ), 20, 1 );
	}

	/**
	 * @return bool Whether Basalam is the stock reference.
	 */
	public static function basalam_is_reference() {
		return 'basalam' === SLH_Settings::get( 'stock_reference', 'site' );
	}

	/**
	 * Field groups to send on product updates (stock is left out when Basalam is the reference).
	 *
	 * @return string[]
	 */
	public static function push_groups() {
		$groups = (array) SLH_Settings::get( 'sync_fields', array() );
		if ( self::basalam_is_reference() ) {
			$groups = array_values( array_diff( $groups, array( 'stock' ) ) );
		}
		return $groups;
	}

	/* ---------------------------------------------------------------------
	 * Safety stock
	 * ------------------------------------------------------------------ */

	/**
	 * Safety stock for a product: variation → parent product → global. "" means inherit.
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	public static function safety_for( WC_Product $product ) {
		$own = $product->get_meta( self::META_SAFETY );
		if ( '' !== (string) $own ) {
			return max( 0, (int) $own );
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$own = $parent->get_meta( self::META_SAFETY );
				if ( '' !== (string) $own ) {
					return max( 0, (int) $own );
				}
			}
		}
		return max( 0, (int) SLH_Settings::get( 'safety_stock', 0 ) );
	}

	/**
	 * slh_basalam_stock filter.
	 *
	 * @param int        $stock   Stock to publish.
	 * @param WC_Product $product Product.
	 * @return int
	 */
	public static function apply_safety( $stock, $product ) {
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return (int) $stock; // Unmanaged stock is already a chosen fixed number.
		}
		return max( 0, (int) $stock - self::safety_for( $product ) );
	}

	/* ---------------------------------------------------------------------
	 * Basalam → site (reference = basalam)
	 * ------------------------------------------------------------------ */

	/**
	 * Keeps the hourly pull scheduled only while Basalam is the reference.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( self::HOOK_PULL, array( 'page' => 1 ), SLH_Queue::GROUP );
		if ( ! self::basalam_is_reference() || ! SLH_Settings::is_connected() ) {
			if ( $next ) {
				as_unschedule_all_actions( self::HOOK_PULL, array( 'page' => 1 ), SLH_Queue::GROUP );
			}
			return;
		}
		if ( ! $next ) {
			as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, self::HOOK_PULL, array( 'page' => 1 ), SLH_Queue::GROUP );
		}
	}

	/**
	 * Starts a pull right away (button).
	 */
	public static function pull_now() {
		as_enqueue_async_action( self::HOOK_PULL, array( 'page' => 1, 'manual' => 1 ), SLH_Queue::GROUP );
	}

	/**
	 * Queue callback: one page of the booth per job, the next page is chained.
	 *
	 * @param int $page Page.
	 */
	public static function handle_pull( $page = 1 ) {
		if ( ! self::basalam_is_reference() || ! SLH_Settings::is_connected() ) {
			return;
		}
		$page = max( 1, (int) $page );
		SLH_Queue::run_exclusive(
			self::HOOK_PULL,
			array( 'page' => $page, 'manual' => 1 ),
			function () use ( $page ) {
				try {
					$res     = SLH_Plugin::api()->vendor_products( SLH_Settings::vendor_id(), $page, 50 );
					$changed = 0;
					foreach ( $res['data'] as $item ) {
						$changed += self::apply_remote_item( $item );
					}
					$total = (int) get_option( 'slh_stock_pull_changed', 0 ) + $changed;
					$more  = $res['data'] && ( null === $res['total_page'] ? count( $res['data'] ) >= 50 : $page < $res['total_page'] );
					if ( $more && $page < 400 ) {
						update_option( 'slh_stock_pull_changed', $total, false );
						as_enqueue_async_action( self::HOOK_PULL, array( 'page' => $page + 1, 'manual' => 1 ), SLH_Queue::GROUP );
						return;
					}
					delete_option( 'slh_stock_pull_changed' );
					update_option( 'slh_stock_pulled_at', slh_now(), false );
					SLH_Queue::reset_attempts( 'stock_pull' );
					if ( $total ) {
						SLH_Logger::log(
							array(
								'level'       => 'info',
								'event'       => 'stock_pulled',
								'object_type' => 'system',
								'title'       => __( 'موجودی از باسلام', 'salamhub' ),
								/* translators: %s: count */
								'message'     => sprintf( __( 'موجودی %s کالا در سایت با باسلام یکی شد.', 'salamhub' ), slh_fa_number( $total ) ),
							)
						);
					}
				} catch ( SLH_Api_Error $e ) {
					if ( 'rate_limit' === $e->kind ) {
						SLH_Queue::pause( $e->retry_after );
					}
					if ( $e->retryable && false !== SLH_Queue::retry_later( self::HOOK_PULL, array( 'page' => $page, 'manual' => 1 ), 'stock_pull', $e->retry_after ) ) {
						return;
					}
					delete_option( 'slh_stock_pull_changed' );
					SLH_Logger::log(
						array_merge(
							array(
								'level'       => 'error',
								'event'       => 'stock_pull_failed',
								'object_type' => 'system',
								'title'       => __( 'دریافت موجودی از باسلام', 'salamhub' ),
							),
							$e->to_log()
						)
					);
				}
			}
		);
	}

	/**
	 * Copies the stock of one Basalam product (VendorProductResponse) into WooCommerce.
	 *
	 * @param array $item Product.
	 * @return int Number of WooCommerce products/variations whose stock changed.
	 */
	public static function apply_remote_item( array $item ) {
		if ( empty( $item['id'] ) ) {
			return 0;
		}
		$link    = SLH_Links::get_by_basalam( 'product', (int) $item['id'] );
		$product = $link ? wc_get_product( (int) $link->wc_id ) : null;
		if ( ! $product ) {
			return 0;
		}
		$changed = 0;
		if ( $product->is_type( 'variable' ) ) {
			$remote = array();
			$list   = isset( $item['variants'] ) ? $item['variants'] : ( isset( $item['variant'] ) ? $item['variant'] : array() );
			foreach ( is_array( $list ) ? $list : array() as $v ) {
				if ( isset( $v['id'], $v['stock'] ) ) {
					$remote[ (int) $v['id'] ] = (int) $v['stock'];
				}
			}
			$map = SLH_Product_Sync::variant_map( $product );
			foreach ( $map as $vid => $row ) {
				if ( ! isset( $remote[ (int) $row['id'] ] ) ) {
					continue;
				}
				$variation = wc_get_product( (int) $vid );
				if ( $variation && self::set_local_stock( $variation, $remote[ (int) $row['id'] ] ) ) {
					++$changed;
				}
				$map[ $vid ]['stock'] = $remote[ (int) $row['id'] ];
			}
			update_post_meta( $product->get_id(), '_slh_variants', $map );
		} else {
			$stock = isset( $item['inventory'] ) ? (int) $item['inventory'] : ( isset( $item['stock'] ) ? (int) $item['stock'] : null );
			if ( null !== $stock && self::set_local_stock( $product, $stock ) ) {
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * Writes Basalam's stock into WooCommerce without echoing it back to Basalam.
	 * Basalam shows "site stock − safety", so the safety units are added back.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param int        $remote  Basalam stock.
	 * @return bool Changed.
	 */
	public static function set_local_stock( WC_Product $product, $remote ) {
		$remote = max( 0, (int) $remote );
		$was    = SLH_Plugin::$suspend_hooks;
		SLH_Plugin::$suspend_hooks = true;
		try {
			if ( $product->managing_stock() ) {
				$target = $remote + ( $remote > 0 ? self::safety_for( $product ) : (int) min( max( 0, (int) $product->get_stock_quantity() ), self::safety_for( $product ) ) );
				if ( (int) $product->get_stock_quantity() === $target ) {
					return false;
				}
				wc_update_product_stock( $product, $target, 'set' );
				return true;
			}
			$status = $remote > 0 ? 'instock' : 'outofstock';
			if ( $product->get_stock_status() === $status ) {
				return false;
			}
			$product->set_stock_status( $status );
			$product->save();
			return true;
		} finally {
			SLH_Plugin::$suspend_hooks = $was;
		}
	}

	/* ---------------------------------------------------------------------
	 * Site sale → Basalam (reference = basalam)
	 * ------------------------------------------------------------------ */

	/**
	 * A WooCommerce order reduced stock. In "site" mode the stock hook already pushes the new
	 * number; in "basalam" mode the sold quantity is subtracted from Basalam's own number.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_site_sale( $order ) {
		if ( ! self::basalam_is_reference() || ! $order instanceof WC_Order || 'salamhub' === $order->get_created_via() ) {
			return; // Basalam orders were already subtracted on Basalam.
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$pid  = (int) $item->get_product_id();
			$link = SLH_Links::get( 'product', $pid );
			if ( ! $link || ! $link->basalam_id ) {
				continue;
			}
			as_enqueue_async_action(
				self::HOOK_DECREMENT,
				array( 'product_id' => $pid, 'variation_id' => (int) $item->get_variation_id(), 'qty' => (int) $item->get_quantity() ),
				SLH_Queue::GROUP
			);
		}
	}

	/**
	 * Queue callback.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation (0 for simple).
	 * @param int $qty          Sold quantity.
	 */
	public static function handle_decrement( $product_id, $variation_id, $qty ) {
		$args = array( 'product_id' => (int) $product_id, 'variation_id' => (int) $variation_id, 'qty' => (int) $qty );
		SLH_Queue::run_exclusive(
			self::HOOK_DECREMENT,
			$args,
			function () use ( $args ) {
				self::decrement( $args['product_id'], $args['variation_id'], $args['qty'] );
			}
		);
	}

	/**
	 * Read Basalam's current stock, subtract, write back (and mirror into WooCommerce).
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 * @param int $qty          Quantity.
	 * @return int|false New Basalam stock.
	 */
	public static function decrement( $product_id, $variation_id, $qty ) {
		$product = wc_get_product( $product_id );
		$link    = SLH_Links::get( 'product', $product_id );
		if ( ! $product || ! $link || ! $link->basalam_id || $qty <= 0 ) {
			return false;
		}
		$args = array( 'product_id' => (int) $product_id, 'variation_id' => (int) $variation_id, 'qty' => (int) $qty );
		$key  = 'decrement_' . $product_id . '_' . $variation_id;
		try {
			$api    = SLH_Plugin::api();
			$remote = $api->get_product( (int) $link->basalam_id );
			if ( $variation_id ) {
				$map = SLH_Product_Sync::variant_map( $product );
				if ( empty( $map[ $variation_id ]['id'] ) ) {
					return false;
				}
				$bvid    = (int) $map[ $variation_id ]['id'];
				$current = null;
				foreach ( isset( $remote['variants'] ) && is_array( $remote['variants'] ) ? $remote['variants'] : array() as $v ) {
					if ( isset( $v['id'], $v['stock'] ) && (int) $v['id'] === $bvid ) {
						$current = (int) $v['stock'];
					}
				}
				if ( null === $current ) {
					return false;
				}
				$new = max( 0, $current - $qty );
				$api->update_variant( (int) $link->basalam_id, $bvid, array( 'stock' => $new ) );
				$map[ $variation_id ]['stock'] = $new;
				update_post_meta( $product_id, '_slh_variants', $map );
				$local = wc_get_product( $variation_id );
			} else {
				$current = isset( $remote['inventory'] ) ? (int) $remote['inventory'] : ( isset( $remote['stock'] ) ? (int) $remote['stock'] : 0 );
				$new     = max( 0, $current - $qty );
				$api->update_product( (int) $link->basalam_id, array( 'stock' => $new ) );
				$local = $product;
			}
			if ( $local ) {
				self::set_local_stock( $local, $new );
			}
			SLH_Queue::reset_attempts( $key );
			return $new;
		} catch ( SLH_Api_Error $e ) {
			if ( 'rate_limit' === $e->kind ) {
				SLH_Queue::pause( $e->retry_after );
			}
			if ( $e->retryable && false !== SLH_Queue::retry_later( self::HOOK_DECREMENT, $args, $key, $e->retry_after ) ) {
				return false;
			}
			SLH_Logger::log(
				array_merge(
					array(
						'level'       => 'error',
						'event'       => 'stock_decrement_failed',
						'object_type' => 'product',
						'object_id'   => $product_id,
						'title'       => $product->get_name(),
					),
					$e->to_log(),
					array(
						'message'    => __( 'فروش سایت از موجودی باسلام کم نشد.', 'salamhub' ) . ' ' . $e->getMessage(),
						'suggestion' => __( 'موجودی این محصول را در پنل باسلام دستی اصلاح کن تا بیش‌فروشی نشود.', 'salamhub' ),
					)
				)
			);
			return false;
		}
	}
}
