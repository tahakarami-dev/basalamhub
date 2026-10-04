<?php
/**
 * One-time move of a SalamHub (≤ 0.6.1, prefix slh_, slug salamhub) install to
 * BasalamHub (prefix bsh_, slug basalamhub): tables, options, product/order meta,
 * queued jobs, log retry hooks and the encrypted tokens. Nothing is deleted: rows are
 * renamed in place, so the connection, links, orders and logs carry over.
 *
 * The old plugin is deactivated (never both at once: that would sync everything twice).
 * Deleting the old plugin afterwards is safe — its uninstaller only touches slh_ names,
 * which no longer exist.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Migrate {

	const DONE      = 'bsh_migrated_from_salamhub';
	const OLD_FILE  = 'salamhub/salamhub.php';
	const OLD_GROUP = 'salamhub';

	/**
	 * Runs the move when old data is present and it hasn't run yet. Cheap otherwise
	 * (one autoloaded option read).
	 *
	 * @return bool Whether a migration ran now.
	 */
	public static function maybe_run() {
		if ( get_option( self::DONE ) ) {
			return false;
		}
		if ( ! self::has_old_data() ) {
			update_option( self::DONE, 'fresh', true );
			return false;
		}
		// One process only.
		if ( ! add_option( 'bsh_migrating', time(), '', false ) ) {
			if ( (int) get_option( 'bsh_migrating' ) > time() - 300 ) {
				return false;
			}
			update_option( 'bsh_migrating', time(), false );
		}
		try {
			self::run();
		} finally {
			delete_option( 'bsh_migrating' );
		}
		return true;
	}

	/**
	 * @return bool
	 */
	private static function has_old_data() {
		global $wpdb;
		if ( false !== get_option( 'slh_db_version' ) || false !== get_option( 'slh_settings' ) ) {
			return true;
		}
		return self::table_exists( $wpdb->prefix . 'slh_links' );
	}

	/**
	 * The move itself. Each step is idempotent, so an interrupted run can simply run again.
	 */
	public static function run() {
		global $wpdb;
		self::deactivate_old();

		// 1. Tables: renamed (instant on MySQL/MariaDB); where the database layer can't
		// rename, the new tables are created and the rows copied over.
		BSH_Installer::install();
		foreach ( array( 'links', 'logs', 'remote_products' ) as $name ) {
			self::move_table( $wpdb->prefix . 'slh_' . $name, $wpdb->prefix . 'bsh_' . $name );
		}

		// 2. Options (locks and transients are short-lived: dropped, not moved).
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_slh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_slh\\_%' OR option_name LIKE 'slh\\_lock\\_%'" );
		foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'slh\\_%'" ) as $old ) {
			$new = 'bsh_' . substr( $old, 4 );
			if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ) ) {
				$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) );
			} else {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $old ) );
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_flush();

		// 3. Meta on products, attachments and orders (posts table and HPOS table).
		$meta_tables = array( $wpdb->postmeta );
		if ( self::table_exists( $wpdb->prefix . 'wc_orders_meta' ) ) {
			$meta_tables[] = $wpdb->prefix . 'wc_orders_meta';
		}
		foreach ( $meta_tables as $table ) {
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$table} WHERE meta_key LIKE '\\_slh\\_%'" ) as $old ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update( $table, array( 'meta_key' => '_bsh_' . substr( $old, 5 ) ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			}
		}

		// 4. Queued background jobs keep their place.
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( self::table_exists( $actions ) ) {
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT hook FROM {$actions} WHERE hook LIKE 'slh\\_%'" ) as $old ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update( $actions, array( 'hook' => 'bsh_' . substr( $old, 4 ) ), array( 'hook' => $old ) );
			}
			$old_group = $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$groups} WHERE slug = %s", self::OLD_GROUP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $old_group ) {
				$new_group = $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$groups} WHERE slug = %s", BSH_Queue::GROUP ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $new_group ) {
					$wpdb->update( $actions, array( 'group_id' => (int) $new_group ), array( 'group_id' => (int) $old_group ) );
					$wpdb->delete( $groups, array( 'group_id' => (int) $old_group ) );
				} else {
					$wpdb->update( $groups, array( 'slug' => BSH_Queue::GROUP ), array( 'group_id' => (int) $old_group ) );
				}
			}
			// Recurring jobs are re-created under the new names; drop pending duplicates.
			foreach ( array( 'bsh_maintenance', 'bsh_poll_orders', 'bsh_stock_pull', 'bsh_reconcile_orders' ) as $hook ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$actions} WHERE hook = %s AND status = 'pending' ORDER BY action_id ASC", $hook ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( array_slice( $ids, 1 ) as $id ) {
					$wpdb->delete( $actions, array( 'action_id' => (int) $id ) );
				}
			}
		}

		// 5. "Retry" buttons on old log rows point at the new hooks.
		$logs = $wpdb->prefix . 'bsh_logs';
		if ( self::table_exists( $logs ) ) {
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT retry_hook FROM {$logs} WHERE retry_hook LIKE 'slh\\_%'" ) as $old ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->update( $logs, array( 'retry_hook' => 'bsh_' . substr( $old, 4 ) ), array( 'retry_hook' => $old ) );
			}
		}

		// 6. Encrypted secrets in the new format.
		self::reencrypt();

		BSH_Installer::install();

		update_option( self::DONE, gmdate( 'Y-m-d H:i:s' ), true );
		set_transient( 'bsh_migrated_notice', 1, WEEK_IN_SECONDS );
		BSH_Logger::log(
			array(
				'level'       => 'success',
				'event'       => 'migrated',
				'object_type' => 'system',
				'title'       => __( 'باسلام‌هاب', 'basalamhub' ),
				'message'     => __( 'اطلاعات نسخه‌ی قبلی (سلام‌هاب) منتقل شد: اتصال، محصولات متصل، سفارش‌ها، لاگ و تنظیمات.', 'basalamhub' ),
				'suggestion'  => __( 'افزونه‌ی قدیمی غیرفعال شد و می‌توانی حذفش کنی؛ حذفش به داده‌های باسلام‌هاب دست نمی‌زند.', 'basalamhub' ),
			)
		);
	}

	/**
	 * Old-format tokens → new format.
	 */
	private static function reencrypt() {
		$token = get_option( 'bsh_token' );
		if ( $token && BSH_Crypto::is_legacy( $token ) ) {
			$plain = BSH_Crypto::decrypt( $token );
			if ( null !== $plain && '' !== $plain ) {
				update_option( 'bsh_token', BSH_Crypto::encrypt( $plain ), false );
			}
		}
		$notify = get_option( 'bsh_notify' );
		if ( is_array( $notify ) ) {
			foreach ( $notify as $ch => $conf ) {
				if ( is_array( $conf ) && ! empty( $conf['token'] ) && BSH_Crypto::is_legacy( $conf['token'] ) ) {
					$plain = BSH_Crypto::decrypt( $conf['token'] );
					if ( null !== $plain && '' !== $plain ) {
						$notify[ $ch ]['token'] = BSH_Crypto::encrypt( $plain );
					}
				}
			}
			update_option( 'bsh_notify', $notify, false );
		}
	}

	/**
	 * Deactivates the old SalamHub plugin if it is still active.
	 */
	public static function deactivate_old() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( self::OLD_FILE ) ) {
			deactivate_plugins( self::OLD_FILE, true );
			set_transient( 'bsh_migrated_notice', 1, WEEK_IN_SECONDS );
		}
	}

	/**
	 * Admin notice after the move.
	 */
	public static function notice() {
		if ( ! get_transient( 'bsh_migrated_notice' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		delete_transient( 'bsh_migrated_notice' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'سلام‌هاب حالا «باسلام‌هاب» است. همه‌ی اطلاعات (اتصال، محصولات متصل، سفارش‌ها، لاگ و تنظیمات) منتقل شد و نسخه‌ی قدیمی غیرفعال شد. می‌توانی افزونه‌ی قدیمی را حذف کنی.', 'basalamhub' ) . '</p></div>';
	}

	/**
	 * Moves an old table's rows into the new table (or renames it when the new one is empty).
	 *
	 * @param string $old Old table.
	 * @param string $new New table (already created by the installer).
	 */
	private static function move_table( $old, $new ) {
		global $wpdb;
		if ( ! self::table_exists( $old ) ) {
			return;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$empty = ! self::table_exists( $new ) || 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" );
		if ( ! $empty ) {
			return; // Never mix with data written by the new version.
		}
		$wpdb->suppress_errors( true );
		if ( self::table_exists( $new ) ) {
			$wpdb->query( "DROP TABLE {$new}" );
		}
		$wpdb->query( "RENAME TABLE {$old} TO {$new}" );
		$wpdb->suppress_errors( false );
		if ( self::table_exists( $new ) && ! self::table_exists( $old ) ) {
			return;
		}
		// Fallback: same columns in both versions → plain copy, then drop the old table.
		if ( ! self::table_exists( $new ) ) {
			BSH_Installer::install();
		}
		$cols = array_intersect( (array) $wpdb->get_col( "SHOW COLUMNS FROM {$old}" ), (array) $wpdb->get_col( "SHOW COLUMNS FROM {$new}" ) );
		if ( ! $cols ) {
			return;
		}
		$list   = '`' . implode( '`,`', $cols ) . '`';
		$copied = $wpdb->query( "INSERT INTO {$new} ({$list}) SELECT {$list} FROM {$old}" );
		if ( false !== $copied && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old}" ) ) {
			$wpdb->query( "DROP TABLE {$old}" );
		}
		// phpcs:enable
	}

	/**
	 * @param string $table Table.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		// Exact name, no LIKE escaping: escaped "\_" is not understood by every database
		// layer (e.g. the SQLite integration), and an unescaped "_" still matches itself.
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return in_array( $table, (array) $found, true );
	}
}
