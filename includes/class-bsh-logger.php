<?php
/**
 * The Persian log center. Every entry answers: what, what happened, why, what to do.
 * Raw API details go into `context` and are only shown in an expandable section.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Logger {

	const LEVELS = array( 'info', 'success', 'warning', 'error' );

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bsh_logs';
	}

	/**
	 * Writes a log entry.
	 *
	 * @param array $entry {
	 *     @type string $level       info|success|warning|error.
	 *     @type string $event       Machine name, e.g. product_sync.
	 *     @type string $object_type product|order|connection|system.
	 *     @type int    $object_id   WooCommerce ID.
	 *     @type string $title       The "what": product name, order number.
	 *     @type string $message     What happened.
	 *     @type string $reason      Why.
	 *     @type string $suggestion  What to do.
	 *     @type array  $context     Raw technical details.
	 *     @type string $retry_hook  Queue hook to re-run on "retry".
	 *     @type array  $retry_args  Args for the retry hook.
	 * }
	 * @return int Log ID.
	 */
	public static function log( array $entry ) {
		global $wpdb;
		$level = isset( $entry['level'] ) && in_array( $entry['level'], self::LEVELS, true ) ? $entry['level'] : 'info';

		$context = isset( $entry['context'] ) ? $entry['context'] : null;
		if ( null !== $context ) {
			$context = wp_json_encode( self::redact( $context ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			if ( strlen( $context ) > 20000 ) {
				$context = substr( $context, 0, 20000 ) . "\n…";
			}
		}

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => bsh_now(),
				'level'       => $level,
				'event'       => isset( $entry['event'] ) ? substr( (string) $entry['event'], 0, 50 ) : 'general',
				'object_type' => isset( $entry['object_type'] ) ? $entry['object_type'] : null,
				'object_id'   => isset( $entry['object_id'] ) ? (int) $entry['object_id'] : null,
				'title'       => isset( $entry['title'] ) ? mb_substr( (string) $entry['title'], 0, 250 ) : '',
				'message'     => isset( $entry['message'] ) ? (string) $entry['message'] : '',
				'reason'      => isset( $entry['reason'] ) ? (string) $entry['reason'] : null,
				'suggestion'  => isset( $entry['suggestion'] ) ? (string) $entry['suggestion'] : null,
				'context'     => $context,
				'retry_hook'  => isset( $entry['retry_hook'] ) ? $entry['retry_hook'] : null,
				'retry_args'  => isset( $entry['retry_args'] ) ? wp_json_encode( $entry['retry_args'] ) : null,
				'resolved'    => 0,
			)
		);
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a log entry is written. Notifications (Telegram/Bale) hook here in a later phase.
		 */
		do_action( 'bsh_logged', $id, $level, $entry );

		return $id;
	}

	/**
	 * Removes secrets from context before it reaches the database.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function redact( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				if ( is_string( $k ) && preg_match( '/token|authorization|secret|password/i', $k ) ) {
					$data[ $k ] = '[hidden]';
				} else {
					$data[ $k ] = self::redact( $v );
				}
			}
		}
		return $data;
	}

	/**
	 * Marks all open errors for an object as resolved (after a later success).
	 *
	 * @param string $object_type Type.
	 * @param int    $object_id   ID.
	 */
	public static function resolve_for( $object_type, $object_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET resolved = 1 WHERE object_type = %s AND object_id = %d AND level IN ('error','warning') AND resolved = 0", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$object_type,
				$object_id
			)
		);
	}

	/**
	 * @param int $id Log ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Paginated query for the log page.
	 *
	 * @param array $args level, object_type, object_id, unresolved, search, page, per_page.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['level'] ) && in_array( $args['level'], self::LEVELS, true ) ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = $args['object_type'];
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}
		if ( ! empty( $args['unresolved'] ) ) {
			$where[] = 'resolved = 0';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR message LIKE %s OR reason LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page  = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 30;
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$sql_where = implode( ' AND ', $where );

		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql_where}";
		$items_sql = 'SELECT * FROM ' . self::table() . " WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * @param int $limit How many.
	 * @return object[] Latest errors (resolved or not).
	 */
	public static function recent_errors( $limit = 10 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE level = 'error' ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $hours Window.
	 * @return int Unresolved errors in the window.
	 */
	public static function count_open_errors( $hours = 24 ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE level = 'error' AND resolved = 0 AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS )
			)
		);
	}

	/**
	 * Deletes logs older than the retention period. Runs daily from the queue.
	 */
	public static function prune() {
		global $wpdb;
		$days = (int) BSH_Settings::get( 'log_retention_days', 30 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
