<?php
/**
 * Admin pages (dashboard/health, settings, log) and their AJAX endpoints.
 *
 * Principle: nothing heavy runs on page load. Pages read a few cheap counters;
 * the only synchronous API call is the explicit "test connection" button.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_bsh_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_bsh_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'wp_ajax_bsh_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_bsh_retry_log', array( __CLASS__, 'ajax_retry_log' ) );
		add_action( 'wp_ajax_bsh_retry_all', array( __CLASS__, 'ajax_retry_all' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BSH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( BSH_Icons::logo( 20, false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page( __( 'باسلام‌هاب', 'basalamhub' ), __( 'باسلام‌هاب', 'basalamhub' ), self::CAP, 'basalamhub', array( __CLASS__, 'page_dashboard' ), $icon, 56 );
		add_submenu_page( 'basalamhub', __( 'داشبورد و سلامت', 'basalamhub' ), __( 'داشبورد', 'basalamhub' ), self::CAP, 'basalamhub', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'basalamhub', __( 'فروش دوکاناله', 'basalamhub' ), __( 'فروش', 'basalamhub' ), self::CAP, 'basalamhub-sales', array( __CLASS__, 'page_sales' ) );
		add_submenu_page( 'basalamhub', __( 'محصولات', 'basalamhub' ), __( 'محصولات', 'basalamhub' ), self::CAP, 'basalamhub-products', array( __CLASS__, 'page_products' ) );
		$missing = BSH_Order_Sync::missing_count();
		$orders  = $missing ? ' <span class="awaiting-mod">' . esc_html( bsh_fa_digits( $missing ) ) . '</span>' : '';
		add_submenu_page( 'basalamhub', __( 'سفارش‌های باسلام', 'basalamhub' ), __( 'سفارش‌ها', 'basalamhub' ) . $orders, self::CAP, 'basalamhub-orders', array( __CLASS__, 'page_orders' ) );
		BSH_Admin_Tools::add_pages();
		BSH_Import_UI::add_import_page();

		$errors = BSH_Logger::count_open_errors( 24 * 7 );
		$badge  = $errors ? ' <span class="awaiting-mod">' . esc_html( bsh_fa_digits( $errors ) ) . '</span>' : '';
		add_submenu_page( 'basalamhub', __( 'لاگ همگام‌سازی', 'basalamhub' ), __( 'لاگ', 'basalamhub' ) . $badge, self::CAP, 'basalamhub-logs', array( __CLASS__, 'page_logs' ) );
		BSH_Import_UI::add_notify_page();
		BSH_Live_Test::add_page();
		add_submenu_page( 'basalamhub', __( 'تنظیمات باسلام‌هاب', 'basalamhub' ), __( 'تنظیمات', 'basalamhub' ), self::CAP, 'basalamhub-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=basalamhub-settings' ) ) . '">' . esc_html__( 'تنظیمات', 'basalamhub' ) . '</a>' );
		return $links;
	}

	/**
	 * Loads CSS/JS only on BasalamHub pages and the product screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$ours    = false !== strpos( (string) $hook, 'basalamhub' );
		$product = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true );
		if ( ! $ours && ! $product && ! BSH_Order_UI::is_order_screen() ) {
			return;
		}
		wp_enqueue_style( 'basalamhub-admin', BSH_URL . 'assets/css/admin.css', array(), BSH_VERSION );
		if ( $ours ) {
			wp_enqueue_style( 'basalamhub-app', BSH_URL . 'assets/css/app.css', array( 'basalamhub-admin' ), BSH_VERSION );
		}
		wp_enqueue_script( 'basalamhub-admin', BSH_URL . 'assets/js/admin.js', array(), BSH_VERSION, true );
		wp_localize_script(
			'basalamhub-admin',
			'BasalamHub',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'bagIcon' => BSH_Icons::svg( 'bag', 20 ),
				'nonce'   => wp_create_nonce( 'bsh_admin' ),
				'i18n'    => array(
					'testing'           => __( 'در حال تست اتصال…', 'basalamhub' ),
					'sending'           => __( 'در حال افزودن به صف…', 'basalamhub' ),
					'retrying'          => __( 'در حال افزودن به صف…', 'basalamhub' ),
					'queued'            => __( 'در صف قرار گرفت. نتیجه همین‌جا نمایش داده می‌شود.', 'basalamhub' ),
					'networkError'      => __( 'درخواست به سایت خودت نرسید. اینترنت یا ورودت به پیشخوان را بررسی کن و دوباره امتحان کن.', 'basalamhub' ),
					'starting'          => __( 'در حال شروع…', 'basalamhub' ),
					'refreshing'        => __( 'در حال دریافت از باسلام…', 'basalamhub' ),
					'loading'           => __( 'در حال دریافت…', 'basalamhub' ),
					'checkAttrs'        => __( 'بررسی ویژگی‌های اجباری', 'basalamhub' ),
					'themeAuto'         => __( 'پوسته: خودکار (مطابق سیستم)', 'basalamhub' ),
					'themeLight'        => __( 'پوسته: روشن', 'basalamhub' ),
					'themeDark'         => __( 'پوسته: تیره', 'basalamhub' ),
					'chartOk'           => __( 'ارسال موفق', 'basalamhub' ),
					'chartErr'          => __( 'خطا', 'basalamhub' ),
					'queuedShort'       => __( 'در صف', 'basalamhub' ),
					'linking'           => __( 'در حال اتصال…', 'basalamhub' ),
					'nothingSelected'   => __( 'هیچ ردیفی انتخاب نشده.', 'basalamhub' ),
					'confirmLinkAll'    => __( 'همه‌ی جفت‌های قطعی متصل شوند؟ تا وقتی گزینه‌ی ارسال تیک نخورده، چیزی در باسلام تغییر نمی‌کند.', 'basalamhub' ),
					/* translators: %s: number of selected products */
					'selected'          => __( '%s محصول انتخاب شده', 'basalamhub' ),
					/* translators: %s: product count */
					'confirmBulk'       => __( '%s محصول در صف ارسال به باسلام قرار می‌گیرد. ادامه می‌دهی؟', 'basalamhub' ),
					'confirmCancel'     => __( 'ارسال گروهی متوقف شود؟ محصولاتی که تا الان ارسال شده‌اند در باسلام می‌مانند و بقیه از صف خارج می‌شوند.', 'basalamhub' ),
					'saving'            => __( 'در حال ثبت در باسلام…', 'basalamhub' ),
					'ltRunning'         => __( 'در حال اجرا…', 'basalamhub' ),
					'ltPickProduct'     => __( 'اول یک محصول تست انتخاب کن.', 'basalamhub' ),
					'ltCopied'          => __( 'کپی شد', 'basalamhub' ),
					'ltDone'            => __( 'بدون خطا', 'basalamhub' ),
					/* translators: %s: count */
					'ltFailed'          => __( '%s خطا', 'basalamhub' ),
					'demoFilling'       => __( 'در حال ساخت داده‌ی نمایشی…', 'basalamhub' ),
					'demoClearing'      => __( 'در حال پاک کردن…', 'basalamhub' ),
					'confirmDemoClear'  => __( 'همه‌ی محصولات و سفارش‌های نمایشی پاک شوند؟ داده‌ی واقعی دست نمی‌خورد.', 'basalamhub' ),
					/* translators: %s: order number */
					'liveTitle'         => __( 'سفارش جدید باسلام #%s', 'basalamhub' ),
					/* translators: %s: item count */
					'liveItems'         => __( '%s قلم', 'basalamhub' ),
					'liveView'          => __( 'مشاهده', 'basalamhub' ),
					'close'             => __( 'بستن', 'basalamhub' ),
					'live'              => (bool) BSH_Settings::is_connected() || BSH_Demo::active(),
					'confirmPosted'     => __( 'ارسال این سفارش در باسلام ثبت شود؟ بعد از ثبت، مشتری کد رهگیری را می‌بیند و این کار برگشت‌پذیر نیست.', 'basalamhub' ),
					'copied'            => __( 'کپی شد.', 'basalamhub' ),
					'sendingTest'       => __( 'در حال فرستادن…', 'basalamhub' ),
					'searching'         => __( 'در حال جستجو…', 'basalamhub' ),
					/* translators: %s: number of products */
					'confirmImport'     => __( '%s محصول از باسلام وارد سایت می‌شود. ادامه می‌دهی؟', 'basalamhub' ),
					'confirmStopImport' => __( 'ایمپورت متوقف شود؟ محصولاتی که تا الان وارد شده‌اند در سایت می‌مانند.', 'basalamhub' ),
					'confirmDisconn'    => __( 'اتصال به باسلام قطع شود؟ توکن پاک می‌شود و همگام‌سازی تا اتصال دوباره متوقف می‌ماند. محصولات در باسلام دست نمی‌خورند.', 'basalamhub' ),
				),
			)
		);
	}

	/*
	---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard + health.
	 */
	public static function page_dashboard() {
		self::render( 'dashboard', 'basalamhub' );
	}

	/**
	 * Settings.
	 */
	public static function page_settings() {
		self::render( 'settings', 'basalamhub-settings' );
	}

	/**
	 * Products with their Basalam status.
	 */
	public static function page_products() {
		self::render( 'products', 'basalamhub-products' );
	}

	/**
	 * Sales by channel.
	 */
	public static function page_sales() {
		self::render( 'sales', 'basalamhub-sales' );
	}

	/**
	 * Basalam orders.
	 */
	public static function page_orders() {
		self::render( 'orders', 'basalamhub-orders' );
	}

	/**
	 * Log center.
	 */
	public static function page_logs() {
		self::render( 'logs', 'basalamhub-logs' );
	}

	/**
	 * @param string $view View name.
	 * @param string $slug Page slug.
	 */
	private static function render( $view, $slug ) {
		BSH_App::render( $view, $slug );
	}

	/*
	---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Saves settings (and the token, when a new one was typed).
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'basalamhub' ) );
		}
		check_admin_referer( 'bsh_save_settings' );

		$input   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in BSH_Settings::save().
		$section = isset( $input['bsh_section'] ) ? sanitize_key( $input['bsh_section'] ) : 'products';
		$notice  = array(
			'type' => 'success',
			'text' => __( 'تنظیمات ذخیره شد.', 'basalamhub' ),
		);

		if ( 'connection' === $section ) {
			$token = isset( $input['bsh_token'] ) ? trim( (string) $input['bsh_token'] ) : '';
			if ( '' === $token ) {
				$notice = array(
					'type' => 'error',
					'text' => __( 'توکن خالی است. توکن را از پنل توسعه‌دهندگان باسلام کپی کن و اینجا بچسبان.', 'basalamhub' ),
				);
			} elseif ( ! BSH_Settings::set_token( $token ) ) {
				$notice = array(
					'type' => 'error',
					'text' => __( 'این سرور امکان رمزنگاری ندارد، پس توکن ذخیره نشد. از هاستینگ بخواه افزونه‌ی sodium یا openssl در PHP را فعال کند.', 'basalamhub' ),
				);
			} else {
				$result = self::test_connection();
				$notice = $result['ok']
					? array(
						'type' => 'success',
						'text' => $result['message'],
					)
					: array(
						'type' => 'error',
						'text' => $result['message'] . ' ' . $result['suggestion'],
					);
			}
		} else {
			$errors = BSH_Settings::save( $input );
			if ( $errors ) {
				set_transient( 'bsh_settings_errors_' . get_current_user_id(), $errors, 300 );
				set_transient( 'bsh_settings_input_' . get_current_user_id(), $input, 300 );
				$notice = array(
					'type' => 'error',
					'text' => __( 'بعضی فیلدها درست نبودند و ذخیره نشدند. پیام کنار هر فیلد را ببین.', 'basalamhub' ),
				);
			}
		}

		set_transient( 'bsh_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=basalamhub-settings' ) );
		exit;
	}

	/**
	 * Removes the token and connection info.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'basalamhub' ) );
		}
		check_admin_referer( 'bsh_disconnect' );
		BSH_Settings::set_token( '' );
		delete_option( BSH_Settings::CONNECTION_OPTION );
		BSH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'disconnected',
				'object_type' => 'connection',
				'title'       => __( 'اتصال باسلام', 'basalamhub' ),
				'message'     => __( 'اتصال قطع و توکن پاک شد.', 'basalamhub' ),
			)
		);
		set_transient(
			'bsh_notice_' . get_current_user_id(),
			array(
				'type' => 'success',
				'text' => __( 'اتصال قطع شد. محصولات در باسلام دست نخوردند.', 'basalamhub' ),
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=basalamhub-settings' ) );
		exit;
	}

	/**
	 * Calls /users/me and stores the booth info.
	 *
	 * @return array{ok: bool, message: string, suggestion: string, vendor_title?: string, vendor_id?: int}
	 */
	public static function test_connection() {
		try {
			$me     = BSH_Plugin::api()->me();
			$vendor = isset( $me['vendor'] ) && is_array( $me['vendor'] ) ? $me['vendor'] : null;
			if ( ! $vendor || empty( $vendor['id'] ) ) {
				BSH_Settings::update_connection(
					array(
						'status'     => 'invalid',
						'user_name'  => isset( $me['name'] ) ? (string) $me['name'] : '',
						'checked_at' => bsh_now(),
						'message'    => __( 'این حساب باسلام غرفه ندارد.', 'basalamhub' ),
					)
				);
				return array(
					'ok'         => false,
					'message'    => __( 'توکن درست است، ولی این حساب باسلام غرفه ندارد.', 'basalamhub' ),
					'suggestion' => __( 'با حسابی توکن بساز که صاحب غرفه است.', 'basalamhub' ),
				);
			}
			BSH_Settings::update_connection(
				array(
					'status'            => 'ok',
					'vendor_id'         => (int) $vendor['id'],
					'vendor_title'      => isset( $vendor['title'] ) ? (string) $vendor['title'] : '',
					'vendor_identifier' => isset( $vendor['identifier'] ) ? (string) $vendor['identifier'] : '',
					'user_name'         => isset( $me['name'] ) ? (string) $me['name'] : '',
					'checked_at'        => bsh_now(),
					'message'           => '',
				)
			);
			BSH_Logger::log(
				array(
					'level'       => 'success',
					'event'       => 'connected',
					'object_type' => 'connection',
					'title'       => __( 'اتصال باسلام', 'basalamhub' ),
					/* translators: 1: booth title, 2: booth id */
					'message'     => sprintf( __( 'به غرفه‌ی «%1$s» (شناسه %2$s) وصل شد.', 'basalamhub' ), $vendor['title'], $vendor['id'] ),
				)
			);
			return array(
				'ok'           => true,
				/* translators: 1: booth title, 2: booth id */
				'message'      => sprintf( __( 'وصل شدی! غرفه‌ی «%1$s» با شناسه‌ی %2$s.', 'basalamhub' ), $vendor['title'], $vendor['id'] ),
				'suggestion'   => '',
				'vendor_title' => (string) $vendor['title'],
				'vendor_id'    => (int) $vendor['id'],
			);
		} catch ( BSH_Api_Error $e ) {
			BSH_Settings::update_connection(
				array(
					'status'     => 'auth' === $e->kind || 'forbidden' === $e->kind ? 'invalid' : BSH_Settings::connection()['status'],
					'checked_at' => bsh_now(),
					'message'    => $e->getMessage(),
				)
			);
			BSH_Logger::log(
				array_merge(
					array(
						'level'       => 'error',
						'event'       => 'connection_failed',
						'object_type' => 'connection',
						'title'       => __( 'اتصال باسلام', 'basalamhub' ),
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

	/*
	---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Verifies nonce and capability for AJAX calls.
	 */
	private static function ajax_guard() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'bsh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'basalamhub' ) ), 403 );
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
		$log = BSH_Logger::get( isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in ajax_guard().
		if ( ! $log || ! BSH_Queue::retry_from_log( $log ) ) {
			wp_send_json_error( array( 'message' => __( 'این مورد قابل تلاش مجدد نیست.', 'basalamhub' ) ) );
		}
		wp_send_json_success( array( 'message' => __( 'دوباره در صف قرار گرفت.', 'basalamhub' ) ) );
	}

	/**
	 * "Retry all errors": re-queues every object with an unresolved error (once each).
	 */
	public static function ajax_retry_all() {
		self::ajax_guard();
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT MAX(id) AS id FROM ' . BSH_Logger::table() . " WHERE level = 'error' AND resolved = 0 AND retry_hook IS NOT NULL GROUP BY object_type, object_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = 0;
		foreach ( (array) $rows as $row ) {
			if ( BSH_Queue::retry_from_log( BSH_Logger::get( (int) $row->id ) ) ) {
				++$count;
			}
		}
		wp_send_json_success(
			array(
				/* translators: %s: count */
				'message' => $count ? sprintf( __( '%s مورد دوباره در صف قرار گرفت.', 'basalamhub' ), bsh_fa_digits( $count ) ) : __( 'خطای بازی برای تلاش مجدد نبود.', 'basalamhub' ),
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
		set_transient(
			'bsh_notice_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
			),
			60
		);
	}

	/**
	 * Prints (once) the notice stored by the last form handler.
	 */
	public static function print_notice() {
		$key    = 'bsh_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="bsh-alert bsh-alert--%1$s" role="status">%2$s</div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['text'] )
		);
	}
}
