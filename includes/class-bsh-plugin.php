<?php
/**
 * Wires everything together.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Plugin {

	/** @var bool Set while BasalamHub itself saves products, so it doesn't re-queue them. */
	public static $suspend_hooks = false;

	/** @var BSH_Api_Client|null */
	private static $api;

	/**
	 * plugins_loaded.
	 */
	public static function boot() {
		load_plugin_textdomain( 'basalamhub', false, dirname( plugin_basename( BSH_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing_woocommerce' ) );
			return;
		}

		BSH_Migrate::maybe_run();
		BSH_Installer::maybe_upgrade();
		BSH_Queue::init();
		BSH_Bulk::init();
		BSH_Linker::init();
		BSH_Categories::init();
		BSH_Price_Rules::init();
		BSH_Inventory::init();
		BSH_Order_Sync::init();
		BSH_Reconcile::init();
		BSH_Importer::init();
		BSH_Notifier::init();

		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_changed' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_changed' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );
		add_action( 'woocommerce_before_delete_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );
		add_action( 'woocommerce_trash_product_variation', array( __CLASS__, 'on_variation_changed' ), 20, 1 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( 'BSH_Migrate', 'notice' ) );
			// The old SalamHub plugin must never run next to this one (everything would sync twice).
			add_action( 'admin_init', array( 'BSH_Migrate', 'deactivate_old' ) );
			BSH_Admin::init();
			BSH_Admin_Tools::init();
			BSH_App::init();
			BSH_Product_UI::init();
			BSH_Order_UI::init();
			BSH_Import_UI::init();
		}
	}

	/**
	 * @return BSH_Api_Client
	 */
	public static function api() {
		if ( ! self::$api ) {
			self::$api = new BSH_Api_Client();
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

		$link = BSH_Links::get( 'product', $product_id );
		if ( $link && $link->basalam_id ) {
			if ( BSH_Settings::get( 'auto_update' ) ) {
				BSH_Queue::enqueue_product( $product_id );
			} elseif ( 'synced' === $link->sync_status ) {
				BSH_Links::set_status( 'product', $product_id, 'stale' );
			}
			return;
		}

		if ( BSH_Settings::get( 'auto_send_new' ) && 'publish' === $product->get_status() && BSH_Settings::is_connected() ) {
			BSH_Queue::enqueue_product( $product_id );
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
		$link = BSH_Links::get( 'product', $parent_id );
		if ( $link && $link->basalam_id && BSH_Settings::get( 'auto_update' ) ) {
			BSH_Queue::enqueue_product( $parent_id );
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
		$link = BSH_Links::get( 'product', $id );
		if ( $link && $link->basalam_id && BSH_Settings::get( 'auto_update' ) ) {
			BSH_Queue::enqueue_product( $id );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public static function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'باسلام‌هاب برای کار به ووکامرس نیاز دارد. اول ووکامرس را نصب و فعال کن.', 'basalamhub' ) . '</p></div>';
	}
}
