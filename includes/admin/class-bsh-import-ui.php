<?php
/**
 * Admin side of phase 5: the «ایمپورت غرفه» and «اعلان‌ها» pages, their AJAX endpoints, and
 * the «تطبیق الان» button of the orders page.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Import_UI {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_bsh_import_start', array( __CLASS__, 'ajax_import_start' ) );
		add_action( 'wp_ajax_bsh_import_status', array( __CLASS__, 'ajax_import_status' ) );
		add_action( 'wp_ajax_bsh_import_cancel', array( __CLASS__, 'ajax_import_cancel' ) );
		add_action( 'wp_ajax_bsh_reconcile_now', array( __CLASS__, 'ajax_reconcile_now' ) );
		add_action( 'wp_ajax_bsh_notify_test', array( __CLASS__, 'ajax_notify_test' ) );
		add_action( 'wp_ajax_bsh_notify_find_chat', array( __CLASS__, 'ajax_notify_find_chat' ) );
		add_action( 'admin_post_bsh_save_notify', array( __CLASS__, 'handle_save_notify' ) );
	}

	/**
	 * Submenu entries (called from BSH_Admin::menu so the order matches the app sidebar).
	 */
	public static function add_import_page() {
		add_submenu_page( 'basalamhub', __( 'ایمپورت غرفه', 'basalamhub' ), __( 'ایمپورت غرفه', 'basalamhub' ), BSH_Admin::CAP, 'basalamhub-import', array( __CLASS__, 'page_import' ) );
	}

	/**
	 * Submenu entry for notifications.
	 */
	public static function add_notify_page() {
		add_submenu_page( 'basalamhub', __( 'اعلان‌ها', 'basalamhub' ), __( 'اعلان‌ها', 'basalamhub' ), BSH_Admin::CAP, 'basalamhub-notify', array( __CLASS__, 'page_notify' ) );
	}

	/**
	 * Page callback.
	 */
	public static function page_import() {
		BSH_App::render( 'import', 'basalamhub-import' );
	}

	/**
	 * Page callback.
	 */
	public static function page_notify() {
		BSH_App::render( 'notify', 'basalamhub-notify' );
	}

	/**
	 * Capability + nonce for AJAX.
	 */
	private static function guard() {
		if ( ! current_user_can( BSH_Admin::CAP ) || ! check_ajax_referer( 'bsh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'basalamhub' ) ), 403 );
		}
	}

	/*
	---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Starts the import with the chosen options.
	 */
	public static function ajax_import_start() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$r = BSH_Importer::start(
			array(
				'update_linked' => ! empty( $_POST['update_linked'] ),
				'publish'       => isset( $_POST['publish'] ) && 'draft' === $_POST['publish'] ? 'draft' : 'publish',
			)
		);
		// phpcs:enable
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( self::import_payload() );
	}

	/**
	 * Light poll.
	 */
	public static function ajax_import_status() {
		self::guard();
		wp_send_json_success( self::import_payload() );
	}

	/**
	 * Stop.
	 */
	public static function ajax_import_cancel() {
		self::guard();
		BSH_Importer::cancel();
		wp_send_json_success( self::import_payload() );
	}

	/**
	 * @return array
	 */
	private static function import_payload() {
		ob_start();
		self::import_progress_html();
		return array(
			'running' => BSH_Importer::is_running(),
			'html'    => ob_get_clean(),
		);
	}

	/**
	 * Progress bar and counters of the current (or last) import.
	 */
	public static function import_progress_html() {
		$s = BSH_Importer::state();
		$p = BSH_Importer::progress();
		?>
		<div class="bsh-card__head">
			<h2 class="bsh-card__title">
				<?php
				if ( 'running' === $s['status'] ) {
					esc_html_e( 'در حال واردکردن محصولات غرفه…', 'basalamhub' );
				} elseif ( 'cancelled' === $s['status'] ) {
					esc_html_e( 'ایمپورت متوقف شد', 'basalamhub' );
				} else {
					esc_html_e( 'آخرین ایمپورت', 'basalamhub' );
				}
				?>
			</h2>
			<?php if ( 'running' === $s['status'] ) : ?>
				<button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-import-cancel><?php esc_html_e( 'توقف', 'basalamhub' ); ?></button>
			<?php endif; ?>
		</div>
		<div class="bsh-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $p['percent'] ); ?>">
			<div class="bsh-progress__bar" style="width:<?php echo esc_attr( $p['percent'] ); ?>%"></div>
		</div>
		<div class="bsh-stats">
			<?php
			foreach ( array(
				'created' => __( 'ساخته شد', 'basalamhub' ),
				'updated' => __( 'به‌روز شد', 'basalamhub' ),
				'skipped' => __( 'رد شد', 'basalamhub' ),
				'failed'  => __( 'خطا', 'basalamhub' ),
			) as $key => $label ) :
				?>
				<div>
					<div class="bsh-stat__value"><?php echo esc_html( bsh_fa_number( $s[ $key ] ) ); ?></div>
					<div class="bsh-stat__label"><?php echo esc_html( $label ); ?></div>
				</div>
			<?php endforeach; ?>
			<div>
				<?php /* translators: 1: done, 2: total */ ?>
				<div class="bsh-stat__value"><?php echo esc_html( sprintf( __( '%1$s از %2$s', 'basalamhub' ), bsh_fa_number( $p['done'] ), bsh_fa_number( $p['total'] ) ) ); ?></div>
				<div class="bsh-stat__label"><?php esc_html_e( 'پیشرفت', 'basalamhub' ); ?></div>
			</div>
		</div>
		<?php if ( 'running' === $s['status'] ) : ?>
			<p class="bsh-field__hint"><?php esc_html_e( 'می‌توانی این صفحه را ببندی؛ کار در پس‌زمینه ادامه پیدا می‌کند.', 'basalamhub' ); ?></p>
		<?php elseif ( $s['failed'] || $s['skipped'] ) : ?>
			<p class="bsh-field__hint">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-logs&object_type=import' ) ); ?>" data-bsh-nav><?php esc_html_e( 'دلیل موارد ردشده و خطاها در لاگ', 'basalamhub' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/*
	---------------------------------------------------------------------
	 * Reconciliation
	 * ------------------------------------------------------------------ */

	/**
	 * «تطبیق الان».
	 */
	public static function ajax_reconcile_now() {
		self::guard();
		if ( ! BSH_Order_Sync::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'دریافت سفارش‌ها در تنظیمات خاموش است یا به باسلام وصل نیستی.', 'basalamhub' ) ) );
		}
		BSH_Reconcile::run_now();
		wp_send_json_success( array( 'message' => __( 'در صف قرار گرفت؛ نتیجه تا یکی دو دقیقه‌ی دیگر همین‌جا و در لاگ می‌آید.', 'basalamhub' ) ) );
	}

	/*
	---------------------------------------------------------------------
	 * Notifications
	 * ------------------------------------------------------------------ */

	/**
	 * Saves the notifications form.
	 */
	public static function handle_save_notify() {
		if ( ! current_user_can( BSH_Admin::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'basalamhub' ) );
		}
		check_admin_referer( 'bsh_save_notify' );
		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in BSH_Notifier::save().
		$errors = BSH_Notifier::save( (array) $input );
		if ( $errors ) {
			set_transient( 'bsh_notify_errors_' . get_current_user_id(), $errors, 300 );
			BSH_Admin::set_notice( 'error', __( 'بعضی فیلدها درست نبودند و ذخیره نشدند. پیام کنار هر فیلد را ببین.', 'basalamhub' ) );
		} else {
			BSH_Admin::set_notice( 'success', __( 'تنظیمات اعلان ذخیره شد. با «پیام آزمایشی» مطمئن شو پیام می‌رسد.', 'basalamhub' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=basalamhub-notify' ) );
		exit;
	}

	/**
	 * @return string Channel from the request.
	 */
	private static function channel() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$ch = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! isset( BSH_Notifier::channels()[ $ch ] ) ) {
			wp_send_json_error( array( 'message' => __( 'پیام‌رسان نامعتبر است.', 'basalamhub' ) ) );
		}
		return $ch;
	}

	/**
	 * «پیام آزمایشی» — sent right away so the seller sees the result.
	 */
	public static function ajax_notify_test() {
		self::guard();
		$ch  = self::channel();
		$res = BSH_Notifier::send( $ch, '✅ ' . __( 'پیام آزمایشی باسلام‌هاب: اعلان‌ها درست کار می‌کنند.', 'basalamhub' ) . "\n" . home_url() );
		if ( true === $res ) {
			wp_send_json_success( array( 'message' => __( 'پیام فرستاده شد. در پیام‌رسان نگاه کن.', 'basalamhub' ) ) );
		}
		wp_send_json_error( array( 'message' => trim( $res['message'] . ' ' . $res['suggestion'] ) ) );
	}

	/**
	 * «پیدا کردن شناسه».
	 */
	public static function ajax_notify_find_chat() {
		self::guard();
		$ch  = self::channel();
		$res = BSH_Notifier::find_chat( $ch );
		if ( isset( $res['id'] ) ) {
			/* translators: 1: chat id, 2: chat title */
			wp_send_json_success(
				array(
					'id'      => $res['id'],
					'message' => sprintf( __( 'پیدا شد: %2$s (%1$s) — ذخیره شد.', 'basalamhub' ), $res['id'], $res['title'] ),
				)
			);
		}
		wp_send_json_error( array( 'message' => trim( $res['message'] . ' ' . $res['suggestion'] ) ) );
	}
}
