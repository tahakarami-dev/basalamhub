<?php
/**
 * Sales by channel (site vs Basalam) for the «فروش» page and the weekly report.
 *
 * Reads the order tables directly (HPOS or posts storage), so it works whether or not
 * WooCommerce Analytics is enabled, and caches each result for a few minutes. A Basalam
 * order is one created by BasalamHub (created_via basalamhub, or salamhub before the rename).
 *
 * Counted: processing, completed and on-hold orders (paid or being fulfilled). Refunds of
 * individual items are not subtracted; cancelled and refunded orders are left out.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Sales {

	const OPTION   = 'bsh_sales';
	const STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

	/**
	 * Hooks: a new or changed order invalidates the cached numbers.
	 */
	public static function init() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'bust' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bust' ) );
		add_action( 'admin_post_bsh_save_sales', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Cache version bump.
	 */
	public static function bust() {
		// A counter, not a timestamp: two orders in the same second must both refresh it.
		update_option( 'bsh_sales_ver', (int) get_option( 'bsh_sales_ver', 0 ) + 1, false );
	}

	/**
	 * @return array{commission: float}
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array( 'commission' => isset( $s['commission'] ) ? (float) $s['commission'] : 0.0 );
	}

	/**
	 * Saves the commission form on the sales page.
	 */
	public static function handle_save() {
		if ( ! current_user_can( BSH_Admin::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'basalamhub' ) );
		}
		check_admin_referer( 'bsh_save_sales' );
		$raw = isset( $_POST['commission'] ) ? trim(
			strtr(
				sanitize_text_field( wp_unslash( $_POST['commission'] ) ),
				array(
					'۰' => '0',
					'۱' => '1',
					'۲' => '2',
					'۳' => '3',
					'۴' => '4',
					'۵' => '5',
					'۶' => '6',
					'۷' => '7',
					'۸' => '8',
					'۹' => '9',
					'٫' => '.',
					'/' => '.',
				)
			)
		) : '';
		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw > 50 ) {
			BSH_Admin::set_notice( 'error', __( 'درصد کمیسیون باید عددی بین ۰ تا ۵۰ باشد؛ مثلاً 7.5', 'basalamhub' ) );
		} else {
			update_option( self::OPTION, array( 'commission' => round( (float) $raw, 2 ) ), false );
			self::bust();
			BSH_Admin::set_notice( 'success', __( 'کمیسیون ذخیره شد؛ سود باسلام با آن حساب می‌شود.', 'basalamhub' ) );
		}
		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		wp_safe_redirect( admin_url( 'admin.php?page=basalamhub-sales&days=' . $days ) );
		exit;
	}

	/**
	 * @param string $via created_via value.
	 * @return bool
	 */
	public static function is_basalam( $via ) {
		return in_array( (string) $via, array( 'basalamhub', 'salamhub' ), true );
	}

	/**
	 * Orders in [from, to) (UTC "Y-m-d H:i:s").
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return object[] id, total, d (UTC), via.
	 */
	public static function orders( $from, $to ) {
		global $wpdb;
		$in   = "'" . implode( "','", self::STATUSES ) . "'";
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a fixed list of statuses.
		if ( $hpos ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.id, o.total_amount AS total, o.date_created_gmt AS d, od.created_via AS via
					FROM {$wpdb->prefix}wc_orders o
					LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id
					WHERE o.type = 'shop_order' AND o.status IN ({$in}) AND o.date_created_gmt >= %s AND o.date_created_gmt < %s",
					$from,
					$to
				)
			);
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, pt.meta_value AS total, p.post_date_gmt AS d, pv.meta_value AS via
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pt ON pt.post_id = p.ID AND pt.meta_key = '_order_total'
				LEFT JOIN {$wpdb->postmeta} pv ON pv.post_id = p.ID AND pv.meta_key = '_created_via'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s AND p.post_date_gmt < %s",
				$from,
				$to
			)
		);
		// phpcs:enable
	}

	/**
	 * Line items of the given orders: product (parent for variations), quantity, line total.
	 *
	 * @param int[] $order_ids Orders.
	 * @return object[] order_id, pid, qty, total.
	 */
	public static function items( array $order_ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_map( 'intval', $order_ids ), 500 ) as $chunk ) {
			$ids = implode( ',', $chunk );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $ids are integers.
			$rows = $wpdb->get_results(
				"SELECT oi.order_id,
					MAX(CASE WHEN im.meta_key = '_product_id' THEN im.meta_value END) AS pid,
					MAX(CASE WHEN im.meta_key = '_qty' THEN im.meta_value END) AS qty,
					MAX(CASE WHEN im.meta_key = '_line_total' THEN im.meta_value END) AS total,
					MAX(oi.order_item_name) AS name
				FROM {$wpdb->prefix}woocommerce_order_items oi
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id
				WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$ids}) AND im.meta_key IN ('_product_id','_qty','_line_total')
				GROUP BY oi.order_item_id, oi.order_id"
			);
			// phpcs:enable
			$out = array_merge( $out, (array) $rows );
		}
		return $out;
	}

	/**
	 * Everything the sales page shows for the last $days days (cached).
	 *
	 * @param int $days 7, 30 or 90.
	 * @return array
	 */
	public static function summary( $days ) {
		$days = in_array( (int) $days, array( 7, 30, 90 ), true ) ? (int) $days : 30;
		$key  = 'bsh_sales_' . $days . '_' . get_option( 'bsh_sales_ver', 0 ) . '_' . md5( wp_json_encode( self::settings() ) );
		$hit  = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$start = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$end   = $today->modify( '+1 day' );
		$prev  = $start->modify( "-{$days} days" );
		$utc   = new DateTimeZone( 'UTC' );
		$fmt   = function ( DateTimeImmutable $d ) use ( $utc ) {
			return $d->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		};

		$current  = self::orders( $fmt( $start ), $fmt( $end ) );
		$previous = self::orders( $fmt( $prev ), $fmt( $start ) );
		$out      = array(
			'days'       => $days,
			'channels'   => array(
				'basalam' => self::totals( $current, 'basalam' ),
				'site'    => self::totals( $current, 'site' ),
			),
			'previous'   => array(
				'basalam' => self::totals( $previous, 'basalam' ),
				'site'    => self::totals( $previous, 'site' ),
			),
			'series'     => array(),
			'top'        => array(
				'basalam' => array(),
				'site'    => array(),
			),
			'commission' => self::settings()['commission'],
			'built_at'   => bsh_now(),
		);

		// Daily series in the site's timezone.
		for ( $d = $start; $d < $end; $d = $d->modify( '+1 day' ) ) {
			list( $jy, $jm, $jd )                   = bsh_gregorian_to_jalali( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), (int) $d->format( 'j' ) );
			$out['series'][ $d->format( 'Y-m-d' ) ] = array(
				'day'     => bsh_fa_digits( $jd ),
				'label'   => bsh_fa_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) ),
				'basalam' => 0.0,
				'site'    => 0.0,
			);
		}
		$channel_of = array();
		foreach ( $current as $o ) {
			$ch                         = self::is_basalam( $o->via ) ? 'basalam' : 'site';
			$channel_of[ (int) $o->id ] = $ch;
			$local                      = ( new DateTimeImmutable( $o->d, $utc ) )->setTimezone( $tz )->format( 'Y-m-d' );
			if ( isset( $out['series'][ $local ] ) ) {
				$out['series'][ $local ][ $ch ] += (float) $o->total;
			}
		}
		$out['series'] = array_values( $out['series'] );

		// Items: units sold, item revenue (commission base) and top products per channel.
		$top = array(
			'basalam' => array(),
			'site'    => array(),
		);
		foreach ( self::items( array_keys( $channel_of ) ) as $it ) {
			$ch                                    = isset( $channel_of[ (int) $it->order_id ] ) ? $channel_of[ (int) $it->order_id ] : 'site';
			$out['channels'][ $ch ]['units']      += (int) $it->qty;
			$out['channels'][ $ch ]['item_sales'] += (float) $it->total;
			$pid                                   = (int) $it->pid;
			$k                                     = $pid ? $pid : 'n:' . $it->name;
			if ( ! isset( $top[ $ch ][ $k ] ) ) {
				$top[ $ch ][ $k ] = array(
					'id'      => $pid,
					'name'    => $pid && get_post( $pid ) ? get_the_title( $pid ) : (string) $it->name,
					'qty'     => 0,
					'revenue' => 0.0,
				);
			}
			$top[ $ch ][ $k ]['qty']     += (int) $it->qty;
			$top[ $ch ][ $k ]['revenue'] += (float) $it->total;
		}
		foreach ( $top as $ch => $rows ) {
			usort(
				$rows,
				function ( $a, $b ) {
					return $b['revenue'] <=> $a['revenue'];
				}
			);
			$out['top'][ $ch ] = array_slice( array_values( $rows ), 0, 5 );
		}

		// Basalam's commission is charged on the items, not on shipping (estimate).
		$b                                        = $out['channels']['basalam'];
		$out['channels']['basalam']['commission'] = round( $b['item_sales'] * $out['commission'] / 100, wc_get_price_decimals() );
		$out['channels']['basalam']['net']        = $b['revenue'] - $out['channels']['basalam']['commission'];
		$out['channels']['site']['commission']    = 0;
		$out['channels']['site']['net']           = $out['channels']['site']['revenue'];

		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * @param object[] $orders  Orders.
	 * @param string   $channel basalam|site.
	 * @return array{orders:int, revenue:float, aov:float, units:int, item_sales:float}
	 */
	private static function totals( array $orders, $channel ) {
		$n   = 0;
		$sum = 0.0;
		foreach ( $orders as $o ) {
			if ( ( 'basalam' === $channel ) === self::is_basalam( $o->via ) ) {
				++$n;
				$sum += (float) $o->total;
			}
		}
		return array(
			'orders'     => $n,
			'revenue'    => $sum,
			'aov'        => $n ? $sum / $n : 0.0,
			'units'      => 0,
			'item_sales' => 0.0,
		);
	}

	/**
	 * Percentage change, null when there is nothing to compare with.
	 *
	 * @param float $now  Now.
	 * @param float $then Then.
	 * @return float|null
	 */
	public static function change( $now, $then ) {
		return $then > 0 ? ( $now - $then ) / $then * 100 : null;
	}

	/**
	 * Short money for chart axes and tiles: «۱٫۲ میلیون».
	 *
	 * @param float $amount Amount in store currency.
	 * @return string
	 */
	public static function compact( $amount ) {
		$a = abs( (float) $amount );
		if ( $a >= 1e9 ) {
			$v = $amount / 1e9;
			$u = __( 'میلیارد', 'basalamhub' );
		} elseif ( $a >= 1e6 ) {
			$v = $amount / 1e6;
			$u = __( 'میلیون', 'basalamhub' );
		} elseif ( $a >= 1e3 ) {
			$v = $amount / 1e3;
			$u = __( 'هزار', 'basalamhub' );
		} else {
			return bsh_fa_number( round( $amount ) );
		}
		$s = rtrim( rtrim( number_format( $v, abs( $v ) < 10 ? 1 : 0, '.', '' ), '0' ), '.' );
		return str_replace( '.', '٫', bsh_fa_digits( $s ) ) . ' ' . $u;
	}

	/**
	 * Full money with the store currency, as plain text.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		// «۵۸۰٬۰۰۰ تومان»: Persian digits, no trailing decimals, currency after the number.
		$decimals = abs( $amount - round( $amount ) ) < 0.005 ? 0 : wc_get_price_decimals();
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		return bsh_fa_number( round( (float) $amount, $decimals ) ) . ' ' . $symbol;
	}
}
