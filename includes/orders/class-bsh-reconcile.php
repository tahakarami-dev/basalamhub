<?php
/**
 * Nightly reconciliation («تطبیق شبانه»): every night the last 7 days of Basalam orders are
 * compared with WooCommerce. Any parcel that has no order on the site — whatever the reason:
 * host down for hours, a failed import, an order deleted by mistake — is reported and queued
 * for import. The regular poll looks at recent pages only; this is the safety net.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Reconcile {

	const HOOK   = 'bsh_reconcile_orders';
	const OPTION = 'bsh_reconcile_last';
	const DAYS   = 7;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 1 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Recurring job at ~03:00 site time while order sync is on.
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$next = as_next_scheduled_action( self::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
		if ( ! BSH_Order_Sync::enabled() ) {
			if ( $next ) {
				as_unschedule_all_actions( self::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
			}
			return;
		}
		if ( ! $next ) {
			as_schedule_recurring_action( self::next_night(), DAY_IN_SECONDS, self::HOOK, array( 'manual' => 0 ), BSH_Queue::GROUP );
		}
	}

	/**
	 * Next 03:00 in the site's timezone (quiet hours for both the shop and Basalam).
	 *
	 * @return int Timestamp.
	 */
	public static function next_night() {
		$tz    = wp_timezone();
		$night = new DateTimeImmutable( 'today 03:00', $tz );
		if ( $night->getTimestamp() <= time() ) {
			$night = $night->modify( '+1 day' );
		}
		return $night->getTimestamp() + wp_rand( 0, 20 * MINUTE_IN_SECONDS ); // Not every site at the same second.
	}

	/**
	 * «تطبیق الان» button.
	 */
	public static function run_now() {
		if ( ! as_has_scheduled_action( self::HOOK, array( 'manual' => 1 ), BSH_Queue::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, array( 'manual' => 1 ), BSH_Queue::GROUP );
		}
	}

	/**
	 * Queue callback.
	 *
	 * @param int $manual Started by the button.
	 */
	public static function handle( $manual = 0 ) {
		if ( ! BSH_Order_Sync::enabled() ) {
			return;
		}
		BSH_Queue::run_exclusive(
			self::HOOK,
			array( 'manual' => (int) $manual ),
			function () use ( $manual ) {
				try {
					self::run();
					BSH_Queue::reset_attempts( 'reconcile' );
				} catch ( BSH_Api_Error $e ) {
					if ( 'rate_limit' === $e->kind ) {
						BSH_Queue::pause( $e->retry_after );
					}
					// The recurring job comes back tomorrow anyway; retry a few times tonight.
					if ( $e->retryable && false !== BSH_Queue::retry_later( self::HOOK, array( 'manual' => 1 ), 'reconcile', $e->retry_after ) ) {
						return;
					}
					update_option( self::OPTION, array( 'at' => bsh_now(), 'checked' => 0, 'missing' => 0, 'ids' => array(), 'error' => $e->getMessage() ), false );
					BSH_Logger::log(
						array_merge(
							array(
								'level'       => 'error',
								'event'       => 'reconcile_failed',
								'object_type' => 'system',
								'title'       => __( 'تطبیق شبانه‌ی سفارش‌ها', 'basalamhub' ),
								'retry_hook'  => self::HOOK,
								'retry_args'  => array( 'manual' => 1 ),
							),
							$e->to_log()
						)
					);
				}
			}
		);
	}

	/**
	 * One pass over the last 7 days.
	 *
	 * @return array{checked:int, missing:int, ids:int[]}
	 * @throws BSH_Api_Error On API failure.
	 */
	public static function run() {
		$since   = time() - self::DAYS * DAY_IN_SECONDS;
		$checked = 0;
		$missing = array();
		$cursor  = null;
		for ( $page = 0; $page < 60; $page++ ) {
			$res   = BSH_Plugin::api()->vendor_parcels( array( 'cursor' => $cursor, 'per_page' => 30 ) );
			$older = false;
			foreach ( $res['data'] as $parcel ) {
				if ( empty( $parcel['id'] ) ) {
					continue;
				}
				$created = isset( $parcel['created_at'] ) ? strtotime( (string) $parcel['created_at'] ) : time();
				if ( $created && $created < $since ) {
					$older = true;
					continue;
				}
				++$checked;
				$id = (int) $parcel['id'];
				if ( BSH_Order_Sync::find_order( $id ) || BSH_Order_Sync::is_skipped( $id ) ) {
					continue;
				}
				$status = isset( $parcel['status']['id'] ) ? (int) $parcel['status']['id'] : 0;
				if ( in_array( $status, array( BSH_Order_Sync::ST_CANCEL, BSH_Order_Sync::ST_VENDOR_CANCEL ), true ) ) {
					continue; // Cancelled before it ever reached us: nothing to fulfil.
				}
				$missing[] = $id;
				BSH_Queue::reset_attempts( 'parcel_' . $id );
				BSH_Order_Sync::queue_import( $id );
			}
			if ( $older || ! $res['next_cursor'] || ! $res['data'] ) {
				break;
			}
			$cursor = $res['next_cursor'];
		}

		$result = array( 'at' => bsh_now(), 'checked' => $checked, 'missing' => count( $missing ), 'ids' => array_slice( $missing, 0, 50 ), 'error' => '' );
		update_option( self::OPTION, $result, false );

		if ( $missing ) {
			BSH_Logger::log(
				array(
					'level'       => 'warning',
					'event'       => 'reconcile_missing',
					'object_type' => 'system',
					'title'       => __( 'تطبیق شبانه‌ی سفارش‌ها', 'basalamhub' ),
					/* translators: 1: missing, 2: checked */
					'message'     => sprintf( __( '%1$s سفارش باسلام در سایت نبود (از %2$s سفارش ۷ روز اخیر) و در صف ثبت قرار گرفت.', 'basalamhub' ), bsh_fa_number( count( $missing ) ), bsh_fa_number( $checked ) ),
					/* translators: %s: parcel ids */
					'reason'      => sprintf( __( 'شماره‌های باسلام: %s. معمولاً یعنی سایت مدتی در دسترس نبوده یا ثبتی ناموفق بوده.', 'basalamhub' ), implode( '، ', array_slice( $missing, 0, 20 ) ) ),
					'suggestion'  => __( 'لازم نیست کاری کنی؛ چند دقیقه‌ی دیگر در سفارش‌های ووکامرس می‌آیند. اگر نیامدند، دلیلش در صفحه‌ی «سفارش‌ها» است.', 'basalamhub' ),
					'context'     => array( 'parcel_ids' => $missing ),
				)
			);
		} else {
			BSH_Logger::log(
				array(
					'level'       => 'info',
					'event'       => 'reconcile_ok',
					'object_type' => 'system',
					'title'       => __( 'تطبیق شبانه‌ی سفارش‌ها', 'basalamhub' ),
					/* translators: %s: checked */
					'message'     => sprintf( __( 'همه‌ی %s سفارش ۷ روز اخیر باسلام در سایت هست.', 'basalamhub' ), bsh_fa_number( $checked ) ),
				)
			);
		}
		return $result;
	}

	/**
	 * @return array{at:string, checked:int, missing:int, ids:int[], error:string}|null
	 */
	public static function last() {
		$last = get_option( self::OPTION );
		return is_array( $last ) ? $last : null;
	}
}
