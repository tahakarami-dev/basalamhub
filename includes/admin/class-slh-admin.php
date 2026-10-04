<?php
/**
 * Admin pages (dashboard/health, settings, log) and their AJAX endpoints.
 *
 * Principle: nothing heavy runs on page load. Pages read a few cheap counters;
 * the only synchronous API call is the explicit "test connection" button.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_slh_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_slh_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'wp_ajax_slh_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_slh_retry_log', array( __CLASS__, 'ajax_retry_log' ) );
		add_action( 'wp_ajax_slh_retry_all', array( __CLASS__, 'ajax_retry_all' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SLH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 2a8 8 0 0 0-7.4 5h2.3A6 6 0 0 1 15.2 7H13l3 4 3-4h-1.6A8 8 0 0 0 10 2Zm-7 7-3 4h1.6A8 8 0 0 0 17.4 13h-2.3A6 6 0 0 1 4.8 13H7L4 9Z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page( __( 'باسلام‌هاب', 'salamhub' ), __( 'باسلام‌هاب', 'salamhub' ), self::CAP, 'salamhub', array( __CLASS__, 'page_dashboard' ), $icon, 56 );
		add_submenu_page( 'salamhub', __( 'داشبورد و سلامت', 'salamhub' ), __( 'داشبورد', 'salamhub' ), self::CAP, 'salamhub', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'salamhub', __( 'محصولات', 'salamhub' ), __( 'محصولات', 'salamhub' ), self::CAP, 'salamhub-products', array( __CLASS__, 'page_products' ) );
		$missing = SLH_Order_Sync::missing_count();
		$orders  = $missing ? ' <span class="awaiting-mod">' . esc_html( slh_fa_digits( $missing ) ) . '</span>' : '';
		add_submenu_page( 'salamhub', __( 'سفارش‌های باسلام', 'salamhub' ), __( 'سفارش‌ها', 'salamhub' ) . $orders, self::CAP, 'salamhub-orders', array( __CLASS__, 'page_orders' ) );
		SLH_Admin_Tools::add_pages();
		SLH_Import_UI::add_import_page();

		$errors = SLH_Logger::count_open_errors( 24 * 7 );
		$badge  = $errors ? ' <span class="awaiting-mod">' . esc_html( slh_fa_digits( $errors ) ) . '</span>' : '';
		add_submenu_page( 'salamhub', __( 'لاگ همگام‌سازی', 'salamhub' ), __( 'لاگ', 'salamhub' ) . $badge, self::CAP, 'salamhub-logs', array( __CLASS__, 'page_logs' ) );
		SLH_Import_UI::add_notify_page();
		add_submenu_page( 'salamhub', __( 'تنظیمات باسلام‌هاب', 'salamhub' ), __( 'تنظیمات', 'salamhub' ), self::CAP, 'salamhub-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=salamhub-settings' ) ) . '">' . esc_html__( 'تنظیمات', 'salamhub' ) . '</a>' );
		return $links;
	}

	/**
	 * Loads CSS/JS only on SalamHub pages and the product screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$ours    = false !== strpos( (string) $hook, 'salamhub' );
		$product = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true );
		if ( ! $ours && ! $product && ! SLH_Order_UI::is_order_screen() ) {
			return;
		}
		wp_enqueue_style( 'salamhub-admin', SLH_URL . 'assets/css/admin.css', array(), SLH_VERSION );
		if ( $ours ) {
			wp_enqueue_style( 'salamhub-app', SLH_URL . 'assets/css/app.css', array( 'salamhub-admin', 'dashicons' ), SLH_VERSION );
		}
		wp_enqueue_script( 'salamhub-admin', SLH_URL . 'assets/js/admin.js', array(), SLH_VERSION, true );
		wp_localize_script(
			'salamhub-admin',
			'SalamHub',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'slh_admin' ),
				'i18n'    => array(
					'testing'        => __( 'در حال تست اتصال…', 'salamhub' ),
					'sending'        => __( 'در حال افزودن به صف…', 'salamhub' ),
					'retrying'       => __( 'در حال افزودن به صف…', 'salamhub' ),
					'queued'         => __( 'در صف قرار گرفت. نتیجه همین‌جا نمایش داده می‌شود.', 'salamhub' ),
					'networkError'   => __( 'درخواست به سایت خودت نرسید. اینترنت یا ورودت به پیشخوان را بررسی کن و دوباره امتحان کن.', 'salamhub' ),
					'starting'       => __( 'در حال شروع…', 'salamhub' ),
					'refreshing'     => __( 'در حال دریافت از باسلام…', 'salamhub' ),
					'loading'        => __( 'در حال دریافت…', 'salamhub' ),
					'checkAttrs'     => __( 'بررسی ویژگی‌های اجباری', 'salamhub' ),
					'themeAuto'      => __( 'پوسته: خودکار (مطابق سیستم)', 'salamhub' ),
					'themeLight'     => __( 'پوسته: روشن', 'salamhub' ),
					'themeDark'      => __( 'پوسته: تیره', 'salamhub' ),
					'chartOk'        => __( 'ارسال موفق', 'salamhub' ),
					'chartErr'       => __( 'خطا', 'salamhub' ),
					'queuedShort'    => __( 'در صف', 'salamhub' ),
					'linking'        => __( 'در حال اتصال…', 'salamhub' ),
					'nothingSelected' => __( 'هیچ ردیفی انتخاب نشده.', 'salamhub' ),
					'confirmLinkAll' => __( 'همه‌ی جفت‌های قطعی متصل شوند؟ تا وقتی گزینه‌ی ارسال تیک نخورده، چیزی در باسلام تغییر نمی‌کند.', 'salamhub' ),
					/* translators: %s: number of selected products */
					'selected'       => __( '%s محصول انتخاب شده', 'salamhub' ),
					/* translators: %s: product count */
					'confirmBulk'    => __( '%s محصول در صف ارسال به باسلام قرار می‌گیرد. ادامه می‌دهی؟', 'salamhub' ),
					'confirmCancel'  => __( 'ارسال گروهی متوقف شود؟ محصولاتی که تا الان ارسال شده‌اند در باسلام می‌مانند و بقیه از صف خارج می‌شوند.', 'salamhub' ),
					'saving'         => __( 'در حال ثبت در باسلام…', 'salamhub' ),
					'confirmPosted'  => __( 'ارسال این سفارش در باسلام ثبت شود؟ بعد از ثبت، مشتری کد رهگیری را می‌بیند و این کار برگشت‌پذیر نیست.', 'salamhub' ),
					'copied'         => __( 'کپی شد.', 'salamhub' ),
					'sendingTest'    => __( 'در حال فرستادن…', 'salamhub' ),
					'searching'      => __( 'در حال جستجو…', 'salamhub' ),
					/* translators: %s: number of products */
					'confirmImport'  => __( '%s محصول از باسلام وارد سایت می‌شود. ادامه می‌دهی؟', 'salamhub' ),
					'confirmStopImport' => __( 'ایمپورت متوقف شود؟ محصولاتی که تا الان وارد شده‌اند در سایت می‌مانند.', 'salamhub' ),
					'confirmDisconn' => __( 'اتصال به باسلام قطع شود؟ توکن پاک می‌شود و همگام‌سازی تا اتصال دوباره متوقف می‌ماند. محصولات در باسلام دست نمی‌خورند.', 'salamhub' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard + health.
	 */
	public static function page_dashboard() {
		self::render( 'dashboard', 'salamhub' );
	}

	/**
	 * Settings.
	 */
	public static function page_settings() {
		self::render( 'settings', 'salamhub-settings' );
	}

	/**
	 * Products with their Basalam status.
	 */
	public static function page_products() {
		self::render( 'products', 'salamhub-products' );
	}

	/**
	 * Basalam orders.
	 */
	public static function page_orders() {
		self::render( 'orders', 'salamhub-orders' );
	}

	/**
	 * Log center.
	 */
	public static function page_logs() {
		self::render( 'logs', 'salamhub-logs' );
	}

	/**
	 * @param string $view View name.
	 * @param string $slug Page slug.
	 */
	private static function render( $view, $slug ) {
		SLH_App::render( $view, $slug );
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Saves settings (and the token, when a new one was typed).
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'salamhub' ) );
		}
		check_admin_referer( 'slh_save_settings' );

		$input   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in SLH_Settings::save().
		$section = isset( $input['slh_section'] ) ? sanitize_key( $input['slh_section'] ) : 'products';
		$notice  = array( 'type' => 'success', 'text' => __( 'تنظیمات ذخیره شد.', 'salamhub' ) );

		if ( 'connection' === $section ) {
			$token = isset( $input['slh_token'] ) ? trim( (string) $input['slh_token'] ) : '';
			if ( '' === $token ) {
				$notice = array( 'type' => 'error', 'text' => __( 'توکن خالی است. توکن را از پنل توسعه‌دهندگان باسلام کپی کن و اینجا بچسبان.', 'salamhub' ) );
			} elseif ( ! SLH_Settings::set_token( $token ) ) {
				$notice = array( 'type' => 'error', 'text' => __( 'این سرور امکان رمزنگاری ندارد، پس توکن ذخیره نشد. از هاستینگ بخواه افزونه‌ی sodium یا openssl در PHP را فعال کند.', 'salamhub' ) );
			} else {
				$result = self::test_connection();
				$notice = $result['ok']
					? array( 'type' => 'success', 'text' => $result['message'] )
					: array( 'type' => 'error', 'text' => $result['message'] . ' ' . $result['suggestion'] );
			}
		} else {
			$errors = SLH_Settings::save( $input );
			if ( $errors ) {
				set_transient( 'slh_settings_errors_' . get_current_user_id(), $errors, 300 );
				set_transient( 'slh_settings_input_' . get_current_user_id(), $input, 300 );
				$notice = array( 'type' => 'error', 'text' => __( 'بعضی فیلدها درست نبودند و ذخیره نشدند. پیام کنار هر فیلد را ببین.', 'salamhub' ) );
			}
		}

		set_transient( 'slh_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=salamhub-settings' ) );
		exit;
	}

	/**
	 * Removes the token and connection info.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'salamhub' ) );
		}
		check_admin_referer( 'slh_disconnect' );
		SLH_Settings::set_token( '' );
		delete_option( SLH_Settings::CONNECTION_OPTION );
		SLH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'disconnected',
				'object_type' => 'connection',
				'title'       => __( 'اتصال باسلام', 'salamhub' ),
				'message'     => __( 'اتصال قطع و توکن پاک شد.', 'salamhub' ),
			)
		);
		set_transient( 'slh_notice_' . get_current_user_id(), array( 'type' => 'success', 'text' => __( 'اتصال قطع شد. محصولات در باسلام دست نخوردند.', 'salamhub' ) ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=salamhub-settings' ) );
		exit;
	}

	/**
	 * Calls /users/me and stores the booth info.
	 *
	 * @return array{ok: bool, message: string, suggestion: string, vendor_title?: string, vendor_id?: int}
	 */
	public static function test_connection() {
		try {
			$me     = SLH_Plugin::api()->me();
			$vendor = isset( $me['vendor'] ) && is_array( $me['vendor'] ) ? $me['vendor'] : null;
			if ( ! $vendor || empty( $vendor['id'] ) ) {
				SLH_Settings::update_connection(
					array(
						'status'     => 'invalid',
						'user_name'  => isset( $me['name'] ) ? (string) $me['name'] : '',
						'checked_at' => slh_now(),
						'message'    => __( 'این حساب باسلام غرفه ندارد.', 'salamhub' ),
					)
				);
				return array(
					'ok'         => false,
					'message'    => __( 'توکن درست است، ولی این حساب باسلام غرفه ندارد.', 'salamhub' ),
					'suggestion' => __( 'با حسابی توکن بساز که صاحب غرفه است.', 'salamhub' ),
				);
			}
			SLH_Settings::update_connection(
				array(
					'status'            => 'ok',
					'vendor_id'         => (int) $vendor['id'],
					'vendor_title'      => isset( $vendor['title'] ) ? (string) $vendor['title'] : '',
					'vendor_identifier' => isset( $vendor['identifier'] ) ? (string) $vendor['identifier'] : '',
					'user_name'         => isset( $me['name'] ) ? (string) $me['name'] : '',
					'checked_at'        => slh_now(),
					'message'           => '',
				)
			);
			SLH_Logger::log(
				array(
					'level'       => 'success',
					'event'       => 'connected',
					'object_type' => 'connection',
					'title'       => __( 'اتصال باسلام', 'salamhub' ),
					/* translators: 1: booth title, 2: booth id */
					'message'     => sprintf( __( 'به غرفه‌ی «%1$s» (شناسه %2$s) وصل شد.', 'salamhub' ), $vendor['title'], $vendor['id'] ),
				)
			);
			return array(
				'ok'           => true,
				/* translators: 1: booth title, 2: booth id */
				'message'      => sprintf( __( 'وصل شدی! غرفه‌ی «%1$s» با شناسه‌ی %2$s.', 'salamhub' ), $vendor['title'], $vendor['id'] ),
				'suggestion'   => '',
				'vendor_title' => (string) $vendor['title'],
				'vendor_id'    => (int) $vendor['id'],
			);
		} catch ( SLH_Api_Error $e ) {
			SLH_Settings::update_connection(
				array(
					'status'     => 'auth' === $e->kind || 'forbidden' === $e->kind ? 'invalid' : SLH_Settings::connection()['status'],
					'checked_at' => slh_now(),
					'message'    => $e->getMessage(),
				)
			);
			SLH_Logger::log(
				array_merge(
					array(
						'level'       => 'error',
						'event'       => 'connection_failed',
						'object_type' => 'connection',
						'title'       => __( 'اتصال باسلام', 'salamhub' ),
					),
					$e->to_log()
				)
			);
			return array(
				'ok'         => false,
				'message'    => trim( $e->getMessage() . ' ' . $e->reason ),
				'suggestion' => $e->suggestion,
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Verifies nonce and capability for AJAX calls.
	 */
	private static function ajax_guard() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'slh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'salamhub' ) ), 403 );
		}
	}

	/**
	 * "Test connection" button.
	 */
	public static function ajax_test_connection() {
		self::ajax_guard();
		$result = self::test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * "Retry" on one log row.
	 */
	public static function ajax_retry_log() {
		self::ajax_guard();
		$log = SLH_Logger::get( isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0 );
		if ( ! $log || ! SLH_Queue::retry_from_log( $log ) ) {
			wp_send_json_error( array( 'message' => __( 'این مورد قابل تلاش مجدد نیست.', 'salamhub' ) ) );
		}
		wp_send_json_success( array( 'message' => __( 'دوباره در صف قرار گرفت.', 'salamhub' ) ) );
	}

	/**
	 * "Retry all errors": re-queues every object with an unresolved error (once each).
	 */
	public static function ajax_retry_all() {
		self::ajax_guard();
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT MAX(id) AS id FROM ' . SLH_Logger::table() . " WHERE level = 'error' AND resolved = 0 AND retry_hook IS NOT NULL GROUP BY object_type, object_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = 0;
		foreach ( (array) $rows as $row ) {
			if ( SLH_Queue::retry_from_log( SLH_Logger::get( (int) $row->id ) ) ) {
				++$count;
			}
		}
		wp_send_json_success(
			array(
				/* translators: %s: count */
				'message' => $count ? sprintf( __( '%s مورد دوباره در صف قرار گرفت.', 'salamhub' ), slh_fa_digits( $count ) ) : __( 'خطای بازی برای تلاش مجدد نبود.', 'salamhub' ),
				'count'   => $count,
			)
		);
	}

	/**
	 * Stores a notice to show after the redirect.
	 *
	 * @param string $type success|error.
	 * @param string $text Text.
	 */
	public static function set_notice( $type, $text ) {
		set_transient( 'slh_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 60 );
	}

	/**
	 * Prints (once) the notice stored by the last form handler.
	 */
	public static function print_notice() {
		$key    = 'slh_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="slh-alert slh-alert--%1$s" role="status">%2$s</div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['text'] )
		);
	}
}
