<?php
/**
 * A tiny cross-process mutex built on the options table.
 *
 * add_option() fails when the row already exists (UNIQUE option_name), which makes
 * acquiring atomic on every MySQL/MariaDB host without needing GET_LOCK or Redis.
 * Locks expire on their own so a crashed PHP process can never block the queue forever.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Lock {

	/**
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before the lock is considered stale.
	 * @return string|false Owner token on success.
	 */
	public static function acquire( $name, $ttl = 120 ) {
		$option = 'slh_lock_' . $name;
		$owner  = wp_generate_password( 12, false );
		$value  = ( time() + $ttl ) . '|' . $owner;

		if ( add_option( $option, $value, '', 'no' ) ) {
			return $owner;
		}

		// Existing lock: take it over only if it has expired.
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$expires = (int) strtok( (string) $current, '|' );
		if ( $current && $expires < time() ) {
			// Compare-and-swap so two processes cannot both steal a stale lock.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$value,
					$option,
					$current
				)
			);
			wp_cache_delete( $option, 'options' );
			if ( 1 === (int) $updated ) {
				return $owner;
			}
		}
		return false;
	}

	/**
	 * @param string $name  Lock name.
	 * @param string $owner Token returned by acquire().
	 */
	public static function release( $name, $owner ) {
		global $wpdb;
		$option = 'slh_lock_' . $name;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				$option,
				'%|' . $wpdb->esc_like( $owner )
			)
		);
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
