<?php
/**
 * Low-stock alerts for products sold on Basalam («هشدار کمبود موجودی»).
 *
 * When a linked product (or one of its variations) drops to the alert threshold or runs
 * out, a warning is logged — and, with notifications on, sent to Bale/Telegram — so the
 * seller restocks before Basalam takes an order that can't be shipped (and fines the booth).
 * Each product alerts once per drop: only crossing the threshold triggers it, and the
 * flag clears when the stock goes back above it.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Stock_Alerts {

	const META = '_bsh_low_alert';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'check' ), 30 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'check' ), 30 );
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return (bool) BSH_Settings::get( 'low_stock_alert', 1 );
	}

	/**
	 * Alert threshold for a product: its own WooCommerce «low stock threshold», else the
	 * BasalamHub setting.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return int
	 */
	public static function threshold( WC_Product $product ) {
		$own = method_exists( $product, 'get_low_stock_amount' ) ? $product->get_low_stock_amount( 'edit' ) : '';
		if ( '' !== (string) $own && null !== $own ) {
			return max( 0, (int) $own );
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$own    = $parent && method_exists( $parent, 'get_low_stock_amount' ) ? $parent->get_low_stock_amount( 'edit' ) : '';
			if ( '' !== (string) $own && null !== $own ) {
				return max( 0, (int) $own );
			}
		}
		return max( 0, (int) BSH_Settings::get( 'low_stock_threshold', 3 ) );
	}

	/**
	 * Stock changed (any reason: order, manual edit, pull from Basalam).
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public static function check( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() || ! self::enabled() ) {
			return;
		}
		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$link      = BSH_Links::get( 'product', $parent_id );
		if ( ! $link || ! $link->basalam_id ) {
			return; // Only products that are on Basalam.
		}
		$qty       = (int) $product->get_stock_quantity();
		$threshold = self::threshold( $product );
		$level     = $qty <= 0 ? 'out' : ( $qty <= $threshold ? 'low' : '' );
		$previous  = (string) get_post_meta( $product->get_id(), self::META, true );

		if ( '' === $level ) {
			if ( '' !== $previous ) {
				delete_post_meta( $product->get_id(), self::META );
			}
			return;
		}
		// Only a new drop (or low → out) alerts; staying low doesn't repeat it.
		if ( $previous === $level || ( 'out' === $previous && 'low' === $level ) ) {
			if ( 'out' === $previous && 'low' === $level ) {
				update_post_meta( $product->get_id(), self::META, 'low' );
			}
			return;
		}
		update_post_meta( $product->get_id(), self::META, $level );

		$name       = self::name( $product );
		$on_basalam = max( 0, $qty - BSH_Inventory::safety_for( $product ) );
		BSH_Logger::log(
			array(
				'level'       => 'warning',
				'event'       => 'out' === $level ? 'stock_out' : 'stock_low',
				'object_type' => 'product',
				'object_id'   => $parent_id,
				'title'       => $name,
				'message'     => 'out' === $level
					? __( 'موجودی تمام شد؛ در باسلام «ناموجود» نمایش داده می‌شود.', 'basalamhub' )
					/* translators: 1: stock, 2: threshold, 3: stock shown on Basalam */
					: sprintf( __( 'موجودی به %1$s رسید (حد هشدار %2$s). در باسلام %3$s عدد نمایش داده می‌شود.', 'basalamhub' ), bsh_fa_number( $qty ), bsh_fa_number( $threshold ), bsh_fa_number( $on_basalam ) ),
				'suggestion'  => 'out' === $level
					? __( 'اگر کالا را داری، موجودی را در ووکامرس به‌روز کن تا دوباره در باسلام فروخته شود.', 'basalamhub' )
					: __( 'موجودی را شارژ کن تا سفارشی که نتوانی بفرستی (و جریمه‌ی باسلام) پیش نیاید.', 'basalamhub' ),
				'context'     => array(
					'stock'     => $qty,
					'threshold' => $threshold,
					'product'   => $product->get_id(),
				),
			)
		);
	}

	/**
	 * «نام محصول — ویژگی‌ها» for variations.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function name( WC_Product $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$attrs  = array_filter( array_map( 'strval', $product->get_variation_attributes( false ) ) );
			return ( $parent ? $parent->get_name() : $product->get_name() ) . ( $attrs ? ' — ' . implode( '، ', $attrs ) : '' );
		}
		return $product->get_name();
	}

	/**
	 * Linked products/variations at or below the threshold right now (for the sales page,
	 * the health check and the weekly report). Uses WooCommerce's product lookup table.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array{id:int, name:string, stock:int, threshold:int, edit:string}>
	 */
	public static function low_items( $limit = 50 ) {
		global $wpdb;
		$max   = max( 50, (int) BSH_Settings::get( 'low_stock_threshold', 3 ) );
		$links = BSH_Links::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id, l.stock_quantity FROM {$wpdb->prefix}wc_product_meta_lookup l
				JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_status IN ('publish','private')
				JOIN {$links} k ON k.object_type = 'product' AND k.basalam_id IS NOT NULL AND k.wc_id = IF(p.post_type = 'product_variation', p.post_parent, p.ID)
				WHERE l.stock_quantity IS NOT NULL AND l.stock_quantity <= %d
				ORDER BY l.stock_quantity ASC, l.product_id ASC LIMIT 500",
				$max
			)
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$product = wc_get_product( (int) $r->product_id );
			if ( ! $product || ! $product->managing_stock() || $product->is_type( 'variable' ) ) {
				continue;
			}
			$threshold = self::threshold( $product );
			if ( (int) $r->stock_quantity > $threshold ) {
				continue;
			}
			$edit_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$out[]   = array(
				'id'        => $product->get_id(),
				'name'      => self::name( $product ),
				'stock'     => (int) $r->stock_quantity,
				'threshold' => $threshold,
				'edit'      => (string) get_edit_post_link( $edit_id, 'raw' ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
