<?php
/**
 * Creates and upgrades the plugin's database tables.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Installer {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Deactivation hook: stop our scheduled jobs but keep data (uninstall.php removes it).
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), SLH_Queue::GROUP );
		}
		delete_option( 'slh_lock_worker' );
		delete_option( 'slh_pause_until' );
	}

	/**
	 * Runs dbDelta when the stored schema version is older than the code's.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'slh_db_version' ) !== SLH_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates/updates tables. Safe to run many times.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = $wpdb->prefix . 'slh_links';
		$logs    = $wpdb->prefix . 'slh_logs';

		// Links: the anti-duplicate backbone. One row per WooCommerce object, unique.
		dbDelta(
			"CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(20) NOT NULL,
				wc_id bigint(20) unsigned NOT NULL,
				basalam_id bigint(20) unsigned DEFAULT NULL,
				sync_status varchar(20) NOT NULL DEFAULT 'stale',
				payload_hash char(32) DEFAULT NULL,
				last_synced_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				last_log_id bigint(20) unsigned DEFAULT NULL,
				batch_id varchar(20) DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY object_wc (object_type,wc_id),
				KEY basalam (object_type,basalam_id),
				KEY sync_status (sync_status),
				KEY batch (batch_id,sync_status)
			) {$charset};"
		);

		// Logs: every event in plain Persian — what, what happened, why, what to do.
		dbDelta(
			"CREATE TABLE {$logs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(10) NOT NULL,
				event varchar(50) NOT NULL,
				object_type varchar(20) DEFAULT NULL,
				object_id bigint(20) unsigned DEFAULT NULL,
				title varchar(255) NOT NULL,
				message text NOT NULL,
				reason text DEFAULT NULL,
				suggestion text DEFAULT NULL,
				context longtext DEFAULT NULL,
				retry_hook varchar(100) DEFAULT NULL,
				retry_args text DEFAULT NULL,
				resolved tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY level_created (level,created_at),
				KEY object (object_type,object_id)
			) {$charset};"
		);

		// Snapshot of the booth's products on Basalam, for linking (and later, importing).
		$remote = $wpdb->prefix . 'slh_remote_products';
		dbDelta(
			"CREATE TABLE {$remote} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				basalam_id bigint(20) unsigned NOT NULL,
				title varchar(255) NOT NULL,
				sku varchar(100) DEFAULT NULL,
				price bigint(20) DEFAULT NULL,
				stock int(11) DEFAULT NULL,
				photo varchar(255) DEFAULT NULL,
				variants longtext DEFAULT NULL,
				match_status varchar(12) NOT NULL DEFAULT 'none',
				match_wc_id bigint(20) unsigned DEFAULT NULL,
				match_score smallint(5) unsigned NOT NULL DEFAULT 0,
				match_reason varchar(255) DEFAULT NULL,
				candidates text DEFAULT NULL,
				run_id varchar(20) NOT NULL,
				fetched_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY basalam (basalam_id),
				KEY match_status (match_status)
			) {$charset};"
		);

		update_option( 'slh_db_version', SLH_DB_VERSION, false );
	}
}
