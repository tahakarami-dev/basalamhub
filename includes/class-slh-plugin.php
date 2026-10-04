<?php
/**
 * Wires everything together.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Plugin {

	/** @var bool Set while SalamHub itself saves products, so it doesn't re-queue them. */
	public static $suspend_hooks = false;

	/** @var SLH_Api_Client|null */
	private static $api;

	/**
	 * plugins_loaded.
	 */
	public static function boot() {
		load_plugin_textdomain( 'salamhub', false, dirname( plugin_basename( SLH_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing_woocommerce' ) );
			return;
		}

		SLH_Installer::maybe_upgrade();
		SLH_Queue::init();
		SLH_Bulk::init();
		SLH_Linker::init();
		SLH_Categories::init();
		SLH_Price_Rules::init();
		SLH_Inventory::init();
		SLH_Order_Sync::init();

		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_changed' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_changed' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );
		add_action( 'woocommerce_before_delete_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );
		add_action( 'woocommerce_trash_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );

		if ( is_admin() ) {
			SLH_Admin::init();
			SLH_Admin_Tools::init();
			SLH_App::init();
			SLH_Product_UI::init();
			SLH_Order_UI::init();
		}
	}

	/**
	 * @return SLH_Api_Client
	 */
	public static function api() {
		if ( ! self::$api ) {
			self::$api = new SLH_Api_Client();
		}
		return self::$api;
	}

	/**
	 * Queues a product after it is saved in WooCommerce — only that product.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function on_product_saved( $product_id ) {
		if ( self::$suspend_hooks || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $product_id ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return;
		}

		$link = SLH_Links::get( 'product', $product_id );
		if ( $link && $link->basalam_id ) {
			if ( SLH_Settings::get( 'auto_update' ) ) {
				SLH_Queue::enqueue_product( $product_id );
			} elseif ( 'synced' === $link->sync_status ) {
				SLH_Links::set_status( 'product', $product_id, 'stale' );
			}
			return;
		}

		if ( SLH_Settings::get( 'auto_send_new' ) && 'publish' === $product->get_status() && SLH_Settings::is_connected() ) {
			SLH_Queue::enqueue_product( $product_id );
		}
	}

	/**
	 * A variation was added, edited or removed: queue its (linked) parent product.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public static function on_variation_changed( $variation_id ) {
		if ( self::$suspend_hooks ) {
			return;
		}
		$parent_id = (int) wp_get_post_parent_id( $variation_id );
		if ( ! $parent_id ) {
			return;
		}
		$link = SLH_Links::get( 'product', $parent_id );
		if ( $link && $link->basalam_id && SLH_Settings::get( 'auto_update' ) ) {
			SLH_Queue::enqueue_product( $parent_id );
		}
	}

	/**
	 * Stock changes from orders don't always trigger a full product save.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function on_stock_changed( $product ) {
		if ( self::$suspend_hooks || ! $product instanceof WC_Product ) {
			return;
		}
		$id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$link = SLH_Links::get( 'product', $id );
		if ( $link && $link->basalam_id && SLH_Settings::get( 'auto_update' ) ) {
			SLH_Queue::enqueue_product( $id );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public static function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'سلام‌هاب برای کار به ووکامرس نیاز دارد. اول ووکامرس را نصب و فعال کن.', 'salamhub' ) . '</p></div>';
	}
}
