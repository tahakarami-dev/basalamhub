<?php
/**
 * Order screens (HPOS and legacy): the SalamHub box on Basalam orders, the list column,
 * the AJAX actions behind «تأیید سفارش» / «ثبت ارسال», and the safety-stock fields on products.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Order_UI {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 30 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_box' ), 5, 1 );

		// List column: HPOS screen and the legacy posts screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );

		add_action( 'wp_ajax_slh_order_action', array( __CLASS__, 'ajax_order_action' ) );
		add_action( 'wp_ajax_slh_orders_poll', array( __CLASS__, 'ajax_poll' ) );
		add_action( 'wp_ajax_slh_stock_pull', array( __CLASS__, 'ajax_stock_pull' ) );

		// Safety stock per product / variation.
		add_action( 'woocommerce_product_options_stock_fields', array( __CLASS__, 'product_safety_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_safety' ) );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'variation_safety_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_safety' ), 10, 2 );
	}

	/**
	 * Is the current admin screen an order screen (either storage)?
	 *
	 * @return bool
	 */
	public static function is_order_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( self::screen_id(), 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * @return string The order edit screen ID for the active storage.
	 */
	private static function screen_id() {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * @param WP_Post|WC_Order|mixed $object Post (legacy) or order (HPOS).
	 * @return WC_Order|null
	 */
	private static function order_from( $object ) {
		if ( $object instanceof WC_Order ) {
			return $object;
		}
		if ( $object instanceof WP_Post ) {
			$order = wc_get_order( $object->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Meta box
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the box only on orders that came from Basalam.
	 *
	 * @param string $screen_id Screen.
	 */
	public static function add_box( $screen_id ) {
		if ( ! in_array( $screen_id, array( self::screen_id(), 'shop_order' ), true ) ) {
			return;
		}
		add_meta_box( 'slh-order-box', __( 'باسلام‌هاب · سفارش باسلام', 'salamhub' ), array( __CLASS__, 'render_box' ), $screen_id, 'side', 'high' );
	}

	/**
	 * @param WP_Post|WC_Order $object Order.
	 */
	public static function render_box( $object ) {
		$order     = self::order_from( $object );
		$parcel_id = $order ? (int) $order->get_meta( SLH_Order_Sync::META_PARCEL ) : 0;
		echo '<div class="slh-root slh-order-box" data-slh-order="' . esc_attr( $order ? $order->get_id() : 0 ) . '">';
		if ( ! $parcel_id ) {
			echo '<p class="slh-card__meta">' . esc_html__( 'این سفارش از باسلام نیامده است.', 'salamhub' ) . '</p></div>';
			return;
		}
		$status   = (int) $order->get_meta( '_slh_parcel_status' );
		$method   = (int) $order->get_meta( '_slh_shipping_method' );
		$tracking = (string) $order->get_meta( '_slh_tracking_code' );
		$link     = SLH_Links::get( 'order', $order->get_id() );
		wp_nonce_field( 'slh_order_box', 'slh_order_box_nonce' );
		?>
		<p><?php echo self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
		<?php /* translators: %s: parcel id */ ?>
		<p class="slh-card__meta"><?php echo esc_html( sprintf( __( 'شماره‌ی محموله در باسلام: %s', 'salamhub' ), $parcel_id ) ); ?></p>
		<?php if ( $link && $link->last_synced_at ) : ?>
			<?php /* translators: %s: relative time */ ?>
			<p class="slh-card__meta"><?php echo esc_html( sprintf( __( 'آخرین بررسی: %s', 'salamhub' ), slh_time_ago( $link->last_synced_at ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( $link && 'error' === $link->sync_status && $link->last_error ) : ?>
			<p class="slh-product-box__error"><?php echo esc_html( $link->last_error ); ?></p>
		<?php endif; ?>

		<?php if ( SLH_Order_Sync::ST_NEW === $status ) : ?>
			<p>
				<button type="button" class="slh-btn slh-btn--primary" data-slh-order-action="confirm"><?php esc_html_e( 'تأیید سفارش در باسلام', 'salamhub' ); ?></button>
			</p>
			<p class="slh-field__hint"><?php esc_html_e( 'وضعیت در باسلام «در حال آماده‌سازی» می‌شود و مشتری مطلع می‌شود.', 'salamhub' ); ?></p>
		<?php endif; ?>

		<?php if ( in_array( $status, array( SLH_Order_Sync::ST_NEW, SLH_Order_Sync::ST_PREPARATION, SLH_Order_Sync::ST_WRONG_TRACKING ), true ) ) : ?>
			<label class="slh-field">
				<span class="slh-field__label"><?php esc_html_e( 'روش ارسال', 'salamhub' ); ?></span>
				<select class="slh-field__input" name="slh_shipping_method">
					<option value=""><?php esc_html_e( 'انتخاب کن…', 'salamhub' ); ?></option>
					<?php foreach ( SLH_Order_Sync::shipping_methods() as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $method, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="slh-field">
				<span class="slh-field__label"><?php esc_html_e( 'کد رهگیری', 'salamhub' ); ?></span>
				<input class="slh-field__input slh-field__ltr" type="text" name="slh_tracking_code" value="<?php echo esc_attr( $tracking ); ?>" autocomplete="off">
				<span class="slh-field__hint"><?php esc_html_e( 'برای پیک می‌تواند خالی بماند.', 'salamhub' ); ?></span>
			</label>
			<p>
				<button type="button" class="slh-btn" data-slh-order-action="posted"><?php esc_html_e( 'ثبت ارسال در باسلام', 'salamhub' ); ?></button>
			</p>
			<p class="slh-field__hint"><?php esc_html_e( 'یا سفارش را «تکمیل‌شده» کن؛ ارسال با همین روش و کد خودکار ثبت می‌شود.', 'salamhub' ); ?></p>
		<?php elseif ( $tracking ) : ?>
			<?php /* translators: %s: tracking code */ ?>
			<p class="slh-card__meta"><?php echo esc_html( sprintf( __( 'کد رهگیری: %s', 'salamhub' ), $tracking ) ); ?></p>
		<?php endif; ?>
		<p class="slh-field__hint" data-slh-message aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Persian badge for a Basalam parcel status.
	 *
	 * @param int $status Status.
	 * @return string HTML.
	 */
	public static function status_badge( $status ) {
		$labels = self::status_labels();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'نامشخص', 'salamhub' );
		$kind   = 'queued';
		if ( in_array( $status, array( SLH_Order_Sync::ST_POSTED, SLH_Order_Sync::ST_SATISFIED ), true ) ) {
			$kind = 'synced';
		} elseif ( in_array( $status, array( SLH_Order_Sync::ST_CANCEL, SLH_Order_Sync::ST_REFUNDED, SLH_Order_Sync::ST_PROBLEM, SLH_Order_Sync::ST_CUSTOMER_CANCEL, SLH_Order_Sync::ST_NOT_DELIVERED, SLH_Order_Sync::ST_WRONG_TRACKING ), true ) ) {
			$kind = 'error';
		} elseif ( SLH_Order_Sync::ST_NEW === $status ) {
			$kind = 'stale';
		}
		return '<span class="slh-badge slh-badge--' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * @return array<int,string>
	 */
	public static function status_labels() {
		return array(
			SLH_Order_Sync::ST_NEW             => __( 'سفارش جدید', 'salamhub' ),
			SLH_Order_Sync::ST_PREPARATION     => __( 'در حال آماده‌سازی', 'salamhub' ),
			SLH_Order_Sync::ST_POSTED          => __( 'ارسال‌شده', 'salamhub' ),
			SLH_Order_Sync::ST_WRONG_TRACKING  => __( 'کد رهگیری نادرست', 'salamhub' ),
			SLH_Order_Sync::ST_NOT_DELIVERED   => __( 'تحویل نشده', 'salamhub' ),
			SLH_Order_Sync::ST_PROBLEM         => __( 'مشکل گزارش‌شده', 'salamhub' ),
			SLH_Order_Sync::ST_CUSTOMER_CANCEL => __( 'درخواست لغو مشتری', 'salamhub' ),
			SLH_Order_Sync::ST_OVERDUE_REQUEST => __( 'درخواست تمدید زمان ارسال', 'salamhub' ),
			SLH_Order_Sync::ST_SATISFIED       => __( 'تحویل و رضایت', 'salamhub' ),
			SLH_Order_Sync::ST_REFUNDED        => __( 'مرجوع و بازپرداخت', 'salamhub' ),
			SLH_Order_Sync::ST_CANCEL          => __( 'لغوشده', 'salamhub' ),
			SLH_Order_Sync::ST_VENDOR_CANCEL   => __( 'درخواست لغو غرفه‌دار', 'salamhub' ),
		);
	}

	/**
	 * Stores shipping method and tracking code when the order is saved (before the status
	 * change is processed, so «تکمیل‌شده» can use them).
	 *
	 * @param int $order_id Order.
	 */
	public static function save_box( $order_id ) {
		if ( ! isset( $_POST['slh_order_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slh_order_box_nonce'] ) ), 'slh_order_box' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		self::store_shipping(
			$order,
			isset( $_POST['slh_shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['slh_shipping_method'] ) ) : '',
			isset( $_POST['slh_tracking_code'] ) ? sanitize_text_field( wp_unslash( $_POST['slh_tracking_code'] ) ) : ''
		);
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param string   $method   Method code.
	 * @param string   $tracking Tracking code.
	 */
	private static function store_shipping( WC_Order $order, $method, $tracking ) {
		$method   = (int) $method;
		$tracking = trim( strtr( (string) $tracking, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) );
		$order->update_meta_data( '_slh_shipping_method', isset( SLH_Order_Sync::shipping_methods()[ $method ] ) ? $method : 0 );
		$order->update_meta_data( '_slh_tracking_code', mb_substr( $tracking, 0, 60 ) );
		$order->save();
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * «تأیید سفارش» / «ثبت ارسال» — runs right away so the seller sees the result.
	 */
	public static function ajax_order_action() {
		check_ajax_referer( 'slh_admin', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی کافی نداری.', 'salamhub' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$action   = isset( $_POST['todo'] ) ? sanitize_key( wp_unslash( $_POST['todo'] ) ) : '';
		$order    = wc_get_order( $order_id );
		$parcel   = $order ? (int) $order->get_meta( SLH_Order_Sync::META_PARCEL ) : 0;
		if ( ! $parcel || ! in_array( $action, array( 'confirm', 'posted' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'این سفارش از باسلام نیامده است.', 'salamhub' ) ) );
		}
		$data = array();
		if ( 'posted' === $action ) {
			self::store_shipping(
				$order,
				isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : '',
				isset( $_POST['tracking_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_code'] ) ) : ''
			);
			$data = array(
				'shipping_method' => (int) $order->get_meta( '_slh_shipping_method' ),
				'tracking_code'   => (string) $order->get_meta( '_slh_tracking_code' ),
			);
			if ( ! $data['shipping_method'] ) {
				wp_send_json_error( array( 'message' => __( 'روش ارسال را انتخاب کن.', 'salamhub' ) ) );
			}
			if ( '' === $data['tracking_code'] && 3259 !== $data['shipping_method'] ) {
				wp_send_json_error( array( 'message' => __( 'برای این روش ارسال، کد رهگیری لازم است.', 'salamhub' ) ) );
			}
		}
		if ( SLH_Order_Sync::do_action( $parcel, $action, $data ) ) {
			if ( 'posted' === $action && 'completed' !== $order->get_status() ) {
				SLH_Plugin::$suspend_hooks = true;
				try {
					wc_get_order( $order_id )->update_status( 'completed', __( 'ارسال در باسلام ثبت شد.', 'salamhub' ) );
				} finally {
					SLH_Plugin::$suspend_hooks = false;
				}
			}
			wp_send_json_success( array( 'message' => __( 'در باسلام ثبت شد.', 'salamhub' ), 'reload' => true ) );
		}
		$log = SLH_Logger::query( array( 'object_type' => 'order', 'object_id' => $order_id, 'per_page' => 1 ) );
		$row = ! empty( $log['items'] ) ? $log['items'][0] : null;
		wp_send_json_error(
			array(
				'message' => $row ? trim( $row->message . ' ' . $row->suggestion ) : __( 'در باسلام ثبت نشد؛ جزئیات در لاگ.', 'salamhub' ),
			)
		);
	}

	/**
	 * «دریافت سفارش‌ها الان».
	 */
	public static function ajax_poll() {
		check_ajax_referer( 'slh_admin', 'nonce' );
		if ( ! current_user_can( SLH_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی کافی نداری.', 'salamhub' ) ), 403 );
		}
		if ( ! SLH_Order_Sync::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'دریافت سفارش‌ها در تنظیمات خاموش است یا به باسلام وصل نیستی.', 'salamhub' ) ) );
		}
		SLH_Order_Sync::poll_now();
		wp_send_json_success( array( 'message' => __( 'در صف قرار گرفت؛ سفارش‌های تازه تا چند ثانیه‌ی دیگر اینجا می‌آیند.', 'salamhub' ) ) );
	}

	/**
	 * «دریافت موجودی از باسلام الان».
	 */
	public static function ajax_stock_pull() {
		check_ajax_referer( 'slh_admin', 'nonce' );
		if ( ! current_user_can( SLH_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی کافی نداری.', 'salamhub' ) ), 403 );
		}
		if ( ! SLH_Inventory::basalam_is_reference() || ! SLH_Settings::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'مرجع موجودی «سایت» است؛ دریافت موجودی از باسلام فقط وقتی مرجع «باسلام» باشد انجام می‌شود.', 'salamhub' ) ) );
		}
		SLH_Inventory::pull_now();
		wp_send_json_success( array( 'message' => __( 'در صف قرار گرفت؛ نتیجه در لاگ ثبت می‌شود.', 'salamhub' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * List column
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['slh_basalam'] = __( 'باسلام', 'salamhub' );
			}
		}
		if ( ! isset( $out['slh_basalam'] ) ) {
			$out['slh_basalam'] = __( 'باسلام', 'salamhub' );
		}
		return $out;
	}

	/**
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function render_column_hpos( $column, $order ) {
		if ( 'slh_basalam' === $column && $order instanceof WC_Order ) {
			self::column_html( $order );
		}
	}

	/**
	 * @param string $column  Column.
	 * @param int    $post_id Order ID.
	 */
	public static function render_column_legacy( $column, $post_id ) {
		if ( 'slh_basalam' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				self::column_html( $order );
			}
		}
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private static function column_html( WC_Order $order ) {
		$parcel = (int) $order->get_meta( SLH_Order_Sync::META_PARCEL );
		if ( ! $parcel ) {
			echo '<span class="slh-muted">—</span>';
			return;
		}
		echo self::status_badge( (int) $order->get_meta( '_slh_parcel_status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<br><small>#' . esc_html( (string) $parcel ) . '</small>';
	}

	/* ---------------------------------------------------------------------
	 * Safety stock fields
	 * ------------------------------------------------------------------ */

	/**
	 * Inventory tab of a simple/variable product.
	 */
	public static function product_safety_field() {
		global $product_object;
		woocommerce_wp_text_input(
			array(
				'id'                => 'slh_safety_stock',
				'value'             => $product_object ? $product_object->get_meta( SLH_Inventory::META_SAFETY ) : '',
				'label'             => __( 'موجودی اطمینان باسلام', 'salamhub' ),
				/* translators: %s: global safety stock */
				'placeholder'       => sprintf( __( 'پیش‌فرض: %s', 'salamhub' ), SLH_Settings::get( 'safety_stock', 0 ) ),
				'desc_tip'          => true,
				'description'       => __( 'این تعداد از موجودی در باسلام نمایش داده نمی‌شود تا فروش هم‌زمان در دو جا باعث بیش‌فروشی نشود. خالی = مقدار تنظیمات باسلام‌هاب.', 'salamhub' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			)
		);
	}

	/**
	 * @param WC_Product $product Product.
	 */
	public static function save_product_safety( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		$raw = isset( $_POST['slh_safety_stock'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['slh_safety_stock'] ) ) ) : '';
		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			$product->delete_meta_data( SLH_Inventory::META_SAFETY );
		} else {
			$product->update_meta_data( SLH_Inventory::META_SAFETY, (int) $raw );
		}
	}

	/**
	 * @param int     $loop           Index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_safety_field( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'                => 'slh_safety_stock_' . $loop,
				'name'              => 'slh_safety_stock[' . $loop . ']',
				'value'             => get_post_meta( $variation->ID, SLH_Inventory::META_SAFETY, true ),
				'label'             => __( 'موجودی اطمینان باسلام', 'salamhub' ),
				'placeholder'       => __( 'مثل محصول اصلی', 'salamhub' ),
				'wrapper_class'     => 'form-row form-row-first',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			)
		);
	}

	/**
	 * @param int $variation_id Variation.
	 * @param int $loop         Index.
	 */
	public static function save_variation_safety( $variation_id, $loop ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the variations nonce.
		$raw = isset( $_POST['slh_safety_stock'][ $loop ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['slh_safety_stock'][ $loop ] ) ) ) : '';
		if ( '' === $raw || ! ctype_digit( $raw ) ) {
			delete_post_meta( $variation_id, SLH_Inventory::META_SAFETY );
		} else {
			update_post_meta( $variation_id, SLH_Inventory::META_SAFETY, (int) $raw );
		}
	}
}
