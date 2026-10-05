<?php
/**
 * Dashboard insights: «امروز چی شد» (a one-sentence summary of today), the store health
 * score (0–100, with the reasons behind it), and the live feed that pops up a toast when a
 * Basalam order arrives while the panel is open.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Insights {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_bsh_live', array( __CLASS__, 'ajax_live' ) );
	}

	/* ---------------------------------------------------------------------
	 * Today
	 * ------------------------------------------------------------------ */

	/**
	 * «صبح بخیر» … «شب بخیر» by the site's local time.
	 *
	 * @return string
	 */
	public static function greeting() {
		$h = (int) ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'G' );
		if ( $h >= 5 && $h < 11 ) {
			return __( 'صبح بخیر', 'basalamhub' );
		}
		if ( $h >= 11 && $h < 15 ) {
			return __( 'ظهر بخیر', 'basalamhub' );
		}
		if ( $h >= 15 && $h < 19 ) {
			return __( 'عصر بخیر', 'basalamhub' );
		}
		return __( 'شب بخیر', 'basalamhub' );
	}

	/**
	 * Today's numbers and the sentence that sums them up.
	 *
	 * @return array{orders:int, basalam:int, revenue:float, basalam_revenue:float, low:int, errors:int, missing:int, sentence:string, mood:string}
	 */
	public static function today() {
		$tz    = wp_timezone();
		$utc   = new DateTimeZone( 'UTC' );
		$start = ( new DateTimeImmutable( 'today', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end   = gmdate( 'Y-m-d H:i:s', time() + 60 );
		$out   = array(
			'orders'          => 0,
			'basalam'         => 0,
			'revenue'         => 0.0,
			'basalam_revenue' => 0.0,
		);
		foreach ( BSH_Sales::orders( $start, $end ) as $o ) {
			++$out['orders'];
			$out['revenue'] += (float) $o->total;
			if ( BSH_Sales::is_basalam( $o->via ) ) {
				++$out['basalam'];
				$out['basalam_revenue'] += (float) $o->total;
			}
		}
		$out['low']     = count( BSH_Stock_Alerts::low_items( 100 ) );
		$out['errors']  = BSH_Logger::count_open_errors( 24 );
		$out['missing'] = BSH_Order_Sync::missing_count();

		$parts = array();
		if ( $out['orders'] ) {
			$parts[] = $out['basalam']
				/* translators: 1: orders, 2: from Basalam, 3: amount */
				? sprintf( __( 'امروز تا این لحظه %1$s سفارش (%2$s از باسلام) به ارزش %3$s ثبت شده', 'basalamhub' ), bsh_fa_number( $out['orders'] ), bsh_fa_number( $out['basalam'] ), BSH_Sales::money( $out['revenue'] ) )
				/* translators: 1: orders, 2: amount */
				: sprintf( __( 'امروز تا این لحظه %1$s سفارش از سایت به ارزش %2$s ثبت شده', 'basalamhub' ), bsh_fa_number( $out['orders'] ), BSH_Sales::money( $out['revenue'] ) );
		} else {
			$parts[] = __( 'امروز هنوز سفارشی ثبت نشده', 'basalamhub' );
		}
		$mood = 'ok';
		if ( $out['missing'] ) {
			/* translators: %s: count */
			$parts[] = sprintf( __( '%s سفارش باسلام در سایت ثبت نشده', 'basalamhub' ), bsh_fa_number( $out['missing'] ) );
			$mood    = 'bad';
		}
		if ( $out['errors'] ) {
			/* translators: %s: count */
			$parts[] = sprintf( __( '%s خطای باز داری', 'basalamhub' ), bsh_fa_number( $out['errors'] ) );
			$mood    = 'bad' === $mood ? 'bad' : 'warn';
		}
		if ( $out['low'] ) {
			/* translators: %s: count */
			$parts[] = sprintf( __( '%s کالای باسلام رو به اتمام است', 'basalamhub' ), bsh_fa_number( $out['low'] ) );
			$mood    = 'bad' === $mood ? 'bad' : 'warn';
		}
		if ( 'ok' === $mood ) {
			$parts[] = __( 'همه‌چیز سالم است', 'basalamhub' );
		}
		$out['sentence'] = implode( '؛ ', $parts ) . '.';
		$out['mood']     = $mood;
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Health score
	 * ------------------------------------------------------------------ */

	/**
	 * Store health from 0 to 100 and what took points away.
	 *
	 * @return array{score:int, level:string, label:string, reasons: array<int,array{points:int, text:string, url:string}>}
	 */
	public static function score() {
		$reasons = array();
		foreach ( BSH_App::health_checks() as $c ) {
			if ( 'bad' === $c[0] ) {
				$reasons[] = array(
					'points' => 20,
					'text'   => $c[1] . ': ' . $c[2],
					'url'    => (string) $c[3],
				);
			} elseif ( 'warn' === $c[0] ) {
				$reasons[] = array(
					'points' => 8,
					'text'   => $c[1] . ': ' . $c[2],
					'url'    => (string) $c[3],
				);
			}
		}
		$errors = BSH_Logger::count_open_errors( 24 );
		if ( $errors ) {
			$reasons[] = array(
				'points' => min( 10, 2 * $errors ),
				/* translators: %s: count */
				'text'   => sprintf( __( '%s خطای باز در ۲۴ ساعت اخیر', 'basalamhub' ), bsh_fa_number( $errors ) ),
				'url'    => admin_url( 'admin.php?page=basalamhub-logs&level=error&unresolved=1' ),
			);
		}
		$missing = BSH_Order_Sync::missing_count();
		if ( $missing ) {
			$reasons[] = array(
				'points' => min( 20, 10 * $missing ),
				/* translators: %s: count */
				'text'   => sprintf( __( '%s سفارش باسلام در سایت ثبت نشده', 'basalamhub' ), bsh_fa_number( $missing ) ),
				'url'    => admin_url( 'admin.php?page=basalamhub-orders' ),
			);
		}
		$failed = BSH_Links::counts( 'product' )['error'];
		if ( $failed ) {
			$reasons[] = array(
				'points' => min( 10, $failed ),
				/* translators: %s: count */
				'text'   => sprintf( __( '%s محصول در ارسال به باسلام خطا دارد', 'basalamhub' ), bsh_fa_number( $failed ) ),
				'url'    => admin_url( 'admin.php?page=basalamhub-products&status=error' ),
			);
		}
		usort(
			$reasons,
			function ( $a, $b ) {
				return $b['points'] <=> $a['points'];
			}
		);
		$score = max( 0, 100 - array_sum( wp_list_pluck( $reasons, 'points' ) ) );
		if ( $score >= 90 ) {
			$level = 'ok';
			$label = __( 'عالی', 'basalamhub' );
		} elseif ( $score >= 70 ) {
			$level = 'warn';
			$label = __( 'خوب؛ چند مورد قابل بهبود', 'basalamhub' );
		} else {
			$level = 'bad';
			$label = __( 'نیاز به توجه', 'basalamhub' );
		}
		return compact( 'score', 'level', 'label', 'reasons' );
	}

	/* ---------------------------------------------------------------------
	 * Live feed
	 * ------------------------------------------------------------------ */

	/**
	 * New Basalam orders since the last log id the browser saw. The first call (not primed)
	 * only sets the starting point, so opening the panel never replays old orders.
	 */
	public static function ajax_live() {
		if ( ! current_user_can( BSH_Admin::CAP ) || ! check_ajax_referer( 'bsh_admin', 'nonce', false ) ) {
			wp_send_json_error( array(), 403 );
		}
		global $wpdb;
		$since  = isset( $_POST['since'] ) ? absint( $_POST['since'] ) : 0;
		$primed = ! empty( $_POST['primed'] ); // The browser already has its starting point (which may be 0).
		$table  = BSH_Logger::table();
		$latest = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table} WHERE event = 'order_imported'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items  = array();
		if ( $primed && $latest > $since ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id FROM {$table} WHERE event = 'order_imported' AND id > %d ORDER BY id DESC LIMIT 3", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( array_reverse( (array) $rows ) as $r ) {
				$order = wc_get_order( (int) $r->object_id );
				if ( ! $order ) {
					continue;
				}
				$items[] = array(
					'number' => $order->get_order_number(),
					'total'  => BSH_Sales::money( (float) $order->get_total() ),
					'city'   => $order->get_shipping_city(),
					'items'  => bsh_fa_number( count( $order->get_items() ) ),
					'url'    => $order->get_edit_order_url(),
				);
			}
		}
		wp_send_json_success(
			array(
				'latest' => $latest,
				'items'  => $items,
			)
		);
	}
}
