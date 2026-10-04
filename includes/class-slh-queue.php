<?php
/**
 * Background queue on top of Action Scheduler (bundled with WooCommerce).
 *
 * Rules:
 * - Nothing heavy runs on an admin page load; pages only enqueue.
 * - Only one SalamHub job runs at a time (SLH_Lock "worker"); a job that finds the
 *   lock taken reschedules itself a few seconds later instead of running in parallel.
 * - Jobs live in the database, so after a host outage the queue simply continues.
 * - Retryable failures (network, 5xx, rate limit) back off: 1m, 5m, 15m, 1h, 3h.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Queue {

	const GROUP        = 'salamhub';
	const HOOK_PRODUCT = 'slh_sync_product';
	const HOOK_MAINTENANCE   = 'slh_maintenance';
	const LOCK_TTL     = 180;
	const BACKOFF      = array( 60, 300, 900, 3600, 10800 );

	/**
	 * Registers queue hooks.
	 */
	public static function init() {
		add_action( self::HOOK_PRODUCT, array( __CLASS__, 'handle_product' ), 10, 1 );
		add_action( self::HOOK_MAINTENANCE, array( __CLASS__, 'maintenance' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_recurring' ) );
	}

	/**
	 * Hourly maintenance: self-heal the queue and prune old logs.
	 */
	public static function schedule_recurring() {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_MAINTENANCE, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK_MAINTENANCE, array(), self::GROUP );
		}
	}

	/**
	 * Recurring maintenance job.
	 */
	public static function maintenance() {
		self::heal();
		SLH_Logger::prune();
	}

	/**
	 * Re-queues products that show «در صف» but have no job waiting — e.g. when a PHP fatal
	 * from another plugin killed the job. Without this they would stay queued forever.
	 *
	 * @return int How many products were re-queued.
	 */
	public static function heal() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT wc_id FROM ' . SLH_Links::table() . " WHERE object_type = 'product' AND sync_status = 'queued' AND updated_at < %s LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
			)
		);
		$healed = 0;
		foreach ( (array) $ids as $id ) {
			$args = array( 'product_id' => (int) $id );
			if ( self::has_pending( self::HOOK_PRODUCT, $args ) ) {
				continue;
			}
			self::enqueue_product( (int) $id );
			++$healed;
		}
		if ( $healed ) {
			SLH_Logger::log(
				array(
					'level'       => 'info',
					'event'       => 'queue_healed',
					'object_type' => 'system',
					'title'       => __( 'صف پس‌زمینه', 'salamhub' ),
					/* translators: %s: count */
					'message'     => sprintf( __( '%s محصول که کارشان نیمه‌کاره متوقف شده بود دوباره در صف قرار گرفتند.', 'salamhub' ), slh_fa_digits( $healed ) ),
					'reason'      => __( 'احتمالاً یک خطای سیستمی (مثلاً از افزونه‌ی دیگر یا قطعی هاست) کار قبلی را نیمه‌کاره گذاشته بود.', 'salamhub' ),
				)
			);
		}
		return $healed;
	}

	/**
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Puts a product in the queue once (duplicates are ignored).
	 *
	 * @param int         $product_id Product ID.
	 * @param bool        $force      Send even if nothing changed since the last sync.
	 * @param string|null $batch_id   Bulk batch this product belongs to.
	 * @return bool Whether the product is now in the queue.
	 */
	public static function enqueue_product( $product_id, $force = false, $batch_id = null ) {
		$product_id = (int) $product_id;
		if ( ! self::available() || $product_id <= 0 ) {
			return false;
		}
		if ( $force ) {
			set_transient( 'slh_force_product_' . $product_id, 1, DAY_IN_SECONDS );
		}
		$args = array( 'product_id' => $product_id );
		if ( ! self::has_pending( self::HOOK_PRODUCT, $args ) ) {
			as_enqueue_async_action( self::HOOK_PRODUCT, $args, self::GROUP );
		}
		$data = array( 'sync_status' => 'queued' );
		if ( null !== $batch_id ) {
			$data['batch_id'] = $batch_id;
		}
		SLH_Links::upsert( 'product', $product_id, $data );
		return true;
	}

	/**
	 * Whether an identical job is waiting to run. Unlike as_has_scheduled_action() this
	 * ignores a job that is running right now, so an edit made during a sync is not lost.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return bool
	 */
	private static function has_pending( $hook, array $args ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return as_has_scheduled_action( $hook, $args, self::GROUP );
		}
		return (bool) ActionScheduler::store()->query_action(
			array(
				'hook'   => $hook,
				'args'   => $args,
				'group'  => self::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function handle_product( $product_id ) {
		self::run_exclusive(
			self::HOOK_PRODUCT,
			array( 'product_id' => (int) $product_id ),
			function () use ( $product_id ) {
				( new SLH_Product_Sync( SLH_Plugin::api() ) )->sync( (int) $product_id );
				$link = SLH_Links::get( 'product', (int) $product_id );
				if ( $link && $link->batch_id ) {
					SLH_Bulk::maybe_finish( $link->batch_id );
				}
			}
		);
	}

	/**
	 * Runs a job under the worker lock, or postpones it.
	 *
	 * @param string   $hook Hook (for rescheduling).
	 * @param array    $args Args (for rescheduling).
	 * @param callable $job  Work.
	 */
	public static function run_exclusive( $hook, array $args, callable $job ) {
		// Basalam asked us to slow down (429): hold every job until the pause ends.
		$paused_until = (int) get_option( 'slh_pause_until', 0 );
		if ( $paused_until > time() ) {
			as_schedule_single_action( $paused_until + wp_rand( 1, 20 ), $hook, $args, self::GROUP );
			return;
		}
		$owner = SLH_Lock::acquire( 'worker', self::LOCK_TTL );
		if ( ! $owner ) {
			as_schedule_single_action( time() + 15, $hook, $args, self::GROUP );
			return;
		}
		try {
			$job();
		} finally {
			SLH_Lock::release( 'worker', $owner );
		}
	}

	/**
	 * Schedules a retry with exponential backoff.
	 *
	 * @param string $hook        Hook.
	 * @param array  $args        Args.
	 * @param string $attempt_key Unique key for counting attempts.
	 * @param int    $min_delay   Minimum delay (e.g. Retry-After).
	 * @return int|false Seconds until the retry, or false when attempts are exhausted.
	 */
	public static function retry_later( $hook, array $args, $attempt_key, $min_delay = 0 ) {
		$attempt = (int) get_transient( 'slh_attempt_' . $attempt_key );
		if ( $attempt >= count( self::BACKOFF ) ) {
			delete_transient( 'slh_attempt_' . $attempt_key );
			return false;
		}
		$delay = max( (int) $min_delay, self::BACKOFF[ $attempt ] );
		set_transient( 'slh_attempt_' . $attempt_key, $attempt + 1, DAY_IN_SECONDS );
		as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
		return $delay;
	}

	/**
	 * Pauses all SalamHub jobs (rate limit). Jobs keep their place and resume afterwards.
	 *
	 * @param int $seconds Seconds.
	 */
	public static function pause( $seconds ) {
		$until = time() + max( 10, (int) $seconds );
		if ( $until > (int) get_option( 'slh_pause_until', 0 ) ) {
			update_option( 'slh_pause_until', $until, false );
		}
	}

	/**
	 * Clears the attempt counter after success.
	 *
	 * @param string $attempt_key Key.
	 */
	public static function reset_attempts( $attempt_key ) {
		delete_transient( 'slh_attempt_' . $attempt_key );
	}

	/**
	 * Re-runs the job recorded on a log entry (the "retry" button).
	 *
	 * @param object $log Log row.
	 * @return bool
	 */
	public static function retry_from_log( $log ) {
		if ( ! $log || empty( $log->retry_hook ) ) {
			return false;
		}
		$args = json_decode( (string) $log->retry_args, true );
		if ( self::HOOK_PRODUCT === $log->retry_hook && ! empty( $args['product_id'] ) ) {
			self::reset_attempts( 'product_' . (int) $args['product_id'] );
			return self::enqueue_product( (int) $args['product_id'], true );
		}
		return false;
	}

	/**
	 * Queue counters for the health page — a single cheap COUNT per status.
	 *
	 * @return array{pending:int, running:int, failed:int, past_due:int}
	 */
	public static function stats() {
		$out = array( 'pending' => 0, 'running' => 0, 'failed' => 0, 'past_due' => 0 );
		if ( ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return $out;
		}
		$store = ActionScheduler::store();
		$base  = array( 'group' => self::GROUP, 'per_page' => -1 );
		$out['pending'] = (int) $store->query_actions( $base + array( 'status' => ActionScheduler_Store::STATUS_PENDING ), 'count' );
		$out['running'] = (int) $store->query_actions( $base + array( 'status' => ActionScheduler_Store::STATUS_RUNNING ), 'count' );
		$out['failed']  = (int) $store->query_actions(
			$base + array(
				'status'       => ActionScheduler_Store::STATUS_FAILED,
				'date'         => as_get_datetime_object( time() - DAY_IN_SECONDS ),
				'date_compare' => '>=',
			),
			'count'
		);
		// Pending jobs that should have run 10+ minutes ago point to a broken WP-Cron.
		$out['past_due'] = (int) $store->query_actions(
			$base + array(
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => as_get_datetime_object( time() - 10 * MINUTE_IN_SECONDS ),
				'date_compare' => '<=',
			),
			'count'
		);
		// Recurring maintenance is always pending; don't count it as work.
		$out['pending'] = max( 0, $out['pending'] - ( as_has_scheduled_action( self::HOOK_MAINTENANCE, array(), self::GROUP ) ? 1 : 0 ) );
		return $out;
	}
}
