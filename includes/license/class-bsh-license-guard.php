<?php
/**
 * Loads the (encoded) license gate safely and explains to the shop owner why the plugin is
 * not running yet. This file is NOT encoded.
 *
 * An ionCube-encoded file on a server without the ionCube Loader, or on a PHP version it
 * was not encoded for, prints an error and calls exit(), which would take the whole site
 * down, not just this plugin. So the gate is only loaded when both are right; otherwise
 * BasalamHub stays off and says why.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_License_Guard {

	/**
	 * PHP versions RTL Theme's license file runs on (major.minor).
	 */
	const PHP_VERSIONS = array( '7.4', '8.1', '8.2' );

	/**
	 * Called from basalamhub.php instead of booting directly.
	 */
	public static function load() {
		if ( ! in_array( self::php_version(), self::PHP_VERSIONS, true ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_php' ) );
			return;
		}
		if ( ! extension_loaded( 'ionCube Loader' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_ioncube' ) );
			return;
		}
		// The license file and its gate sit in the plugin's root folder, as RTL Theme requires.
		require_once dirname( __DIR__, 2 ) . '/bsh-license.php';
	}

	/**
	 * @return string e.g. "8.2"
	 */
	private static function php_version() {
		return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	}

	/**
	 * @return bool
	 */
	private static function can_see() {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * PHP version the license file cannot run on.
	 */
	public static function notice_php() {
		if ( ! self::can_see() ) {
			return;
		}
		echo '<div class="notice notice-error"><p dir="rtl" style="text-align:right"><strong>' . esc_html__( 'باسلام‌هاب فعال نشد.', 'basalamhub' ) . '</strong> ';
		/* translators: 1: current PHP version, 2: supported versions */
		echo esc_html( sprintf( __( 'لایسنس راست‌چین روی PHP نسخه‌ی %1$s کار نمی‌کند. نسخه‌ی PHP هاست را از پنل هاست (یا با پشتیبانی هاست) روی یکی از این نسخه‌ها بگذار: %2$s. تا آن موقع افزونه کاری انجام نمی‌دهد و به سایتت آسیبی نمی‌زند.', 'basalamhub' ), self::php_version(), implode( '، ', self::PHP_VERSIONS ) ) );
		echo '</p></div>';
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
		echo esc_html( sprintf( __( 'این نسخه برای بررسی لایسنس به «ionCube Loader» روی هاست نیاز دارد که الان نصب نیست. از پشتیبانی هاست بخواه آن را برای PHP نسخه‌ی %s فعال کند؛ معمولاً چند دقیقه طول می‌کشد. تا آن موقع افزونه کاری انجام نمی‌دهد و به سایتت آسیبی نمی‌زند.', 'basalamhub' ), self::php_version() ) );
		echo '</p></div>';
	}
}
