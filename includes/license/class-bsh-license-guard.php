<?php
/**
 * Loads the (encoded) license gate safely and explains to the shop owner why the plugin is
 * not running yet. This file is NOT encoded.
 *
 * An ionCube-encoded file on a server without the ionCube Loader prints an error and calls
 * exit(), which would take the whole site down, not just this plugin. So the gate is only
 * loaded when the loader is present; otherwise BasalamHub stays off and says why.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_License_Guard {

	/**
	 * Called from basalamhub.php instead of booting directly.
	 */
	public static function load() {
		if ( ! extension_loaded( 'ionCube Loader' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_ioncube' ) );
			return;
		}
		require_once __DIR__ . '/bsh-license.php';
		add_action( 'admin_notices', array( __CLASS__, 'notice_inactive' ) );
	}

	/**
	 * @return bool
	 */
	private static function can_see() {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * Server cannot run encoded files.
	 */
	public static function notice_ioncube() {
		if ( ! self::can_see() ) {
			return;
		}
		echo '<div class="notice notice-error"><p dir="rtl" style="text-align:right"><strong>' . esc_html__( 'باسلام‌هاب فعال نشد.', 'basalamhub' ) . '</strong> ';
		/* translators: %s: PHP version */
		echo esc_html( sprintf( __( 'این نسخه برای بررسی لایسنس به «ionCube Loader» روی هاست نیاز دارد که الان نصب نیست. از پشتیبانی هاست بخواه آن را برای PHP نسخه‌ی %s فعال کند؛ معمولاً چند دقیقه طول می‌کشد. تا آن موقع افزونه کاری انجام نمی‌دهد و به سایتت آسیبی نمی‌زند.', 'basalamhub' ), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ) );
		echo '</p></div>';
	}

	/**
	 * Loader present, license not active (yet).
	 */
	public static function notice_inactive() {
		if ( defined( 'BSH_LICENSED' ) || ! self::can_see() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p dir="rtl" style="text-align:right"><strong>' . esc_html__( 'باسلام‌هاب هنوز فعال نشده.', 'basalamhub' ) . '</strong> ';
		echo esc_html__( 'لایسنس خریدت از راست‌چین را فعال کن تا همگام‌سازی با باسلام شروع شود. تا آن موقع هیچ چیزی به باسلام فرستاده نمی‌شود.', 'basalamhub' );
		echo '</p></div>';
	}
}
