<?php
/**
 * The links table: WooCommerce ID ⇄ Basalam ID. This is what makes every operation
 * idempotent — a product that already has a Basalam ID is always updated, never re-created.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Links {

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bsh_links';
	}

	/**
	 * @param string $type  Object type (product).
	 * @param int    $wc_id WooCommerce ID.
	 * @return object|null
	 */
	public static function get( $type, $wc_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND wc_id = %d', $type, $wc_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param string $type       Object type.
	 * @param int    $basalam_id Basalam ID.
	 * @return object|null
	 */
	public static function get_by_basalam( $type, $basalam_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND basalam_id = %d', $type, $basalam_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Inserts or updates the row for an object.
	 *
	 * @param string $type  Object type.
	 * @param int    $wc_id WooCommerce ID.
	 * @param array  $data  Columns to set.
	 */
	public static function upsert( $type, $wc_id, array $data ) {
		global $wpdb;
		$data['updated_at'] = bsh_now();
		$existing           = self::get( $type, $wc_id );
		if ( $existing ) {
			$wpdb->update( self::table(), $data, array( 'id' => $existing->id ) );
			return;
		}
		$row = array_merge(
			array(
				'object_type' => $type,
				'wc_id'       => $wc_id,
				'sync_status' => 'stale',
			),
			$data
		);
		// INSERT IGNORE protects the UNIQUE key if two processes race; then update.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (object_type, wc_id, sync_status, updated_at) VALUES (%s, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$type,
				$wc_id,
				$row['sync_status'],
				$row['updated_at']
			)
		);
		$wpdb->update( self::table(), $data, array( 'object_type' => $type, 'wc_id' => $wc_id ) );
	}

	/**
	 * @param string $type   Object type.
	 * @param int    $wc_id  ID.
	 * @param string $status synced|queued|stale|error.
	 */
	public static function set_status( $type, $wc_id, $status ) {
		self::upsert( $type, $wc_id, array( 'sync_status' => $status ) );
	}

	/**
	 * Counts rows by status for an object type.
	 *
	 * @param string $type Object type.
	 * @return array<string,int>
	 */
	public static function counts( $type ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT sync_status, COUNT(*) AS n FROM ' . self::table() . ' WHERE object_type = %s GROUP BY sync_status', $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array( 'synced' => 0, 'queued' => 0, 'stale' => 0, 'error' => 0, 'linked' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r->sync_status ] = (int) $r->n;
		}
		$out['linked'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE object_type = %s AND basalam_id IS NOT NULL', $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $out;
	}

	/**
	 * Fetches link rows for many IDs in one query (for list-table columns).
	 *
	 * @param string $type Object type.
	 * @param int[]  $ids  IDs.
	 * @return array<int,object>
	 */
	public static function get_many( $type, array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE object_type = %s AND wc_id IN ({$placeholders})", array_merge( array( $type ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out          = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->wc_id ] = $r;
		}
		return $out;
	}

	/**
	 * Most recent successful sync time across all objects.
	 *
	 * @return string|null
	 */
	public static function last_synced_at() {
		global $wpdb;
		return $wpdb->get_var( 'SELECT MAX(last_synced_at) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
