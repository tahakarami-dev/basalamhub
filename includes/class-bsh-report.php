<?php
/**
 * Weekly report («گزارش هفتگی»): every Saturday morning (start of the Iranian week) a
 * short summary of the last 7 days goes to Bale/Telegram and into the log — sales on both
 * channels, Basalam orders, missed orders, errors and low stock. The seller knows all is
 * well without opening the panel.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Report {

	const HOOK   = 'bsh_weekly_report';
	const OPTION = 'bsh_report_last';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 1 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Recurring job while connected.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( self::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
		if ( ! BSH_Settings::is_connected() ) {
			if ( $next ) {
				as_unschedule_all_actions( self::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
			}
			return;
		}
		if ( ! $next ) {
			as_schedule_recurring_action( self::next_saturday(), WEEK_IN_SECONDS, self::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
		}
	}

	/**
	 * Next Saturday 09:00 in the site's timezone.
	 *
	 * @return int Timestamp.
	 */
	public static function next_saturday() {
		$tz  = wp_timezone();
		$sat = new DateTimeImmutable( 'saturday this week 09:00', $tz );
		if ( $sat->getTimestamp() <= time() ) {
			$sat = $sat->modify( '+1 week' );
		}
		return $sat->getTimestamp() + wp_rand( 0, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Queue callback.
	 *
	 * @param int $manual Started by the button.
	 */
	public static function handle( $manual = 0 ) {
		self::send( (bool) $manual );
	}

	/**
	 * Builds, logs and (if enabled) sends the report.
	 *
	 * @param bool $manual From the «ارسال الان» button (sent even if the weekly switch is off).
	 * @return array{text:string, channels:int}
	 */
	public static function send( $manual = false ) {
		$text = self::build();
		update_option(
			self::OPTION,
			array(
				'at'   => bsh_now(),
				'text' => $text,
			),
			false
		);
		$events   = BSH_Notifier::settings()['events'];
		$channels = ( $manual || $events['weekly'] ) ? BSH_Notifier::broadcast( $text ) : 0;
		BSH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'weekly_report',
				'object_type' => 'system',
				'title'       => __( 'گزارش هفتگی', 'basalamhub' ),
				'message'     => $channels
					/* translators: %s: number of messengers */
					? sprintf( __( 'ساخته و به %s پیام‌رسان فرستاده شد.', 'basalamhub' ), bsh_fa_number( $channels ) )
					: __( 'ساخته شد (اعلان پیام‌رسان خاموش است).', 'basalamhub' ),
				'context'     => array( 'text' => $text ),
			)
		);
		return array(
			'text'     => $text,
			'channels' => $channels,
		);
	}

	/**
	 * The report text for the last 7 days.
	 *
	 * @return string
	 */
	public static function build() {
		global $wpdb;
		$s     = BSH_Sales::summary( 7 );
		$b     = $s['channels']['basalam'];
		$w     = $s['channels']['site'];
		$lines = array( '📊 ' . __( 'گزارش هفتگی باسلام‌هاب', 'basalamhub' ) . ' (' . __( '۷ روز اخیر', 'basalamhub' ) . ')' );

		$trend   = function ( $now, $then ) {
			$c = BSH_Sales::change( $now, $then );
			if ( null === $c ) {
				return '';
			}
			$sign = $c >= 0 ? '▲' : '▼';
			return ' ' . $sign . bsh_fa_number( abs( round( $c ) ) ) . '٪';
		};
		$lines[] = '';
		/* translators: 1: revenue, 2: orders, 3: trend */
		$lines[] = '🛍 ' . sprintf( __( 'باسلام: %1$s در %2$s سفارش%3$s', 'basalamhub' ), BSH_Sales::money( $b['revenue'] ), bsh_fa_number( $b['orders'] ), $trend( $b['revenue'], $s['previous']['basalam']['revenue'] ) );
		if ( $s['commission'] > 0 ) {
			/* translators: %s: net amount */
			$lines[] = '   ' . sprintf( __( 'پس از کمیسیون (تخمینی): %s', 'basalamhub' ), BSH_Sales::money( $b['net'] ) );
		}
		/* translators: 1: revenue, 2: orders, 3: trend */
		$lines[] = '🌐 ' . sprintf( __( 'سایت: %1$s در %2$s سفارش%3$s', 'basalamhub' ), BSH_Sales::money( $w['revenue'] ), bsh_fa_number( $w['orders'] ), $trend( $w['revenue'], $s['previous']['site']['revenue'] ) );
		$total   = $b['revenue'] + $w['revenue'];
		if ( $total > 0 ) {
			/* translators: %s: percent */
			$lines[] = '   ' . sprintf( __( 'سهم باسلام از فروش: %s٪', 'basalamhub' ), bsh_fa_number( round( 100 * $b['revenue'] / $total ) ) );
		}
		if ( ! empty( $s['top']['basalam'][0] ) ) {
			/* translators: 1: product, 2: units */
			$lines[] = '🏆 ' . sprintf( __( 'پرفروش باسلام: %1$s (%2$s عدد)', 'basalamhub' ), $s['top']['basalam'][0]['name'], bsh_fa_number( $s['top']['basalam'][0]['qty'] ) );
		}

		$lines[] = '';
		$since   = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$errors  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE level = 'error' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$open    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE level = 'error' AND resolved = 0 AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$found   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSH_Logger::table() . " WHERE event = 'reconcile_missing' AND created_at >= %s", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$missing = BSH_Order_Sync::missing_count();
		$low     = BSH_Stock_Alerts::low_items( 100 );

		$lines[] = $missing
			/* translators: %s: count */
			? '⚠️ ' . sprintf( __( '%s سفارش باسلام هنوز در سایت ثبت نشده — صفحه‌ی سفارش‌ها را ببین.', 'basalamhub' ), bsh_fa_number( $missing ) )
			: '✅ ' . __( 'هیچ سفارش باسلامی جا نیفتاده.', 'basalamhub' );
		if ( $found ) {
			/* translators: %s: nights */
			$lines[] = '🔁 ' . sprintf( __( 'تطبیق شبانه در %s شب سفارش جاافتاده پیدا و ثبت کرد.', 'basalamhub' ), bsh_fa_number( $found ) );
		}
		$lines[] = $errors
			/* translators: 1: errors, 2: still open */
			? '🧰 ' . sprintf( __( '%1$s خطا در هفته؛ %2$s مورد هنوز باز است.', 'basalamhub' ), bsh_fa_number( $errors ), bsh_fa_number( $open ) )
			: '🧰 ' . __( 'هیچ خطایی در هفته نبود.', 'basalamhub' );
		if ( $low ) {
			/* translators: 1: count, 2: names */
			$lines[] = '📉 ' . sprintf( __( '%1$s کالای رو به اتمام: %2$s', 'basalamhub' ), bsh_fa_number( count( $low ) ), implode( '، ', array_slice( wp_list_pluck( $low, 'name' ), 0, 5 ) ) . ( count( $low ) > 5 ? '…' : '' ) );
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=basalamhub-sales&days=7' );
		return implode( "\n", $lines );
	}
}
