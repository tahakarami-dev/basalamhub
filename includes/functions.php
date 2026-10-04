<?php
/**
 * Small helpers shared across the plugin.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts Latin digits to Persian digits. Use only for human-facing numbers,
 * never for IDs or technical codes (those stay Latin per the brand book).
 *
 * @param int|float|string $value Value to convert.
 * @return string
 */
function bsh_fa_digits( $value ) {
	return strtr( (string) $value, array(
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
	) );
}

/**
 * Formats an integer with Persian digits and the Persian thousands separator.
 *
 * @param int|float $number Number.
 * @return string
 */
function bsh_fa_number( $number ) {
	return bsh_fa_digits( number_format( (float) $number, 0, '.', '٬' ) );
}

/**
 * Converts a Gregorian date to Jalali.
 *
 * @param int $gy Year.
 * @param int $gm Month.
 * @param int $gd Day.
 * @return int[] [year, month, day]
 */
function bsh_gregorian_to_jalali( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = 355666 + ( 365 * $gy ) + (int) ( ( $gy2 + 3 ) / 4 ) - (int) ( ( $gy2 + 99 ) / 100 ) + (int) ( ( $gy2 + 399 ) / 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy    = -1595 + ( 33 * (int) ( $days / 12053 ) );
	$days %= 12053;
	$jy   += 4 * (int) ( $days / 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy  += (int) ( ( $days - 1 ) / 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + (int) ( $days / 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + (int) ( ( $days - 186 ) / 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return array( $jy, $jm, $jd );
}

/**
 * Human-friendly Persian time for a UTC MySQL datetime: "۱۳:۰۴" for today,
 * "۱۴۰۵/۰۷/۱۲ ۱۳:۰۴" for earlier days. Uses the site timezone.
 *
 * @param string|null $mysql_utc Datetime in UTC (Y-m-d H:i:s).
 * @return string
 */
function bsh_format_time( $mysql_utc ) {
	if ( empty( $mysql_utc ) ) {
		return '—';
	}
	$ts = strtotime( $mysql_utc . ' UTC' );
	if ( ! $ts ) {
		return '—';
	}
	$tz    = wp_timezone();
	$local = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
	$today = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
	$time  = bsh_fa_digits( $local->format( 'H:i' ) );
	if ( $local->format( 'Y-m-d' ) === $today ) {
		return $time;
	}
	list( $jy, $jm, $jd ) = bsh_gregorian_to_jalali( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), (int) $local->format( 'j' ) );
	return bsh_fa_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) ) . ' ' . $time;
}

/**
 * Relative Persian time: "همین حالا"، "۲ دقیقه پیش"، "۳ ساعت پیش"، "۵ روز پیش".
 *
 * @param string|null $mysql_utc Datetime in UTC.
 * @return string
 */
function bsh_time_ago( $mysql_utc ) {
	if ( empty( $mysql_utc ) ) {
		return __( 'هنوز انجام نشده', 'basalamhub' );
	}
	$diff = time() - (int) strtotime( $mysql_utc . ' UTC' );
	if ( $diff < 60 ) {
		return __( 'همین حالا', 'basalamhub' );
	}
	if ( $diff < HOUR_IN_SECONDS ) {
		/* translators: %s: minutes */
		return sprintf( __( '%s دقیقه پیش', 'basalamhub' ), bsh_fa_digits( (int) floor( $diff / 60 ) ) );
	}
	if ( $diff < DAY_IN_SECONDS ) {
		/* translators: %s: hours */
		return sprintf( __( '%s ساعت پیش', 'basalamhub' ), bsh_fa_digits( (int) floor( $diff / HOUR_IN_SECONDS ) ) );
	}
	/* translators: %s: days */
	return sprintf( __( '%s روز پیش', 'basalamhub' ), bsh_fa_digits( (int) floor( $diff / DAY_IN_SECONDS ) ) );
}

/**
 * The four sync states and their fixed labels (brand book: never invent other words).
 *
 * @return array<string,string>
 */
function bsh_status_labels() {
	return array(
		'synced' => __( 'همگام', 'basalamhub' ),
		'queued' => __( 'در صف', 'basalamhub' ),
		'stale'  => __( 'همگام نیست', 'basalamhub' ),
		'error'  => __( 'خطا', 'basalamhub' ),
	);
}

/**
 * Renders a status badge.
 *
 * @param string $status One of synced|queued|stale|error.
 * @param string $url    Optional link (e.g. error → log row).
 * @return string HTML.
 */
function bsh_badge( $status, $url = '' ) {
	$labels = bsh_status_labels();
	if ( ! isset( $labels[ $status ] ) ) {
		$status = 'stale';
	}
	$html = sprintf( '<span class="bsh-badge bsh-badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ) );
	if ( $url ) {
		$html = sprintf( '<a href="%1$s" class="bsh-badge-link">%2$s</a>', esc_url( $url ), $html );
	}
	return $html;
}

/**
 * Current UTC time in MySQL format.
 *
 * @return string
 */
function bsh_now() {
	return gmdate( 'Y-m-d H:i:s' );
}
