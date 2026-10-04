<?php
/**
 * Bulk send: puts many products in the queue without the browser having to stay open.
 *
 * The admin request only records a batch and queues a small "planner" job. The planner
 * then queues products 100 at a time in the background, so even "send all 5,000 products"
 * never risks a timeout. Progress is read from the links table (batch_id column).
 * Only one batch runs at a time — two heavy operations never overlap.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Bulk {

	const OPTION     = 'slh_batch';
	const HOOK_PLAN  = 'slh_bulk_plan';
	const CHUNK      = 100;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_PLAN, array( __CLASS__, 'plan' ), 10, 1 );
	}

	/**
	 * The current (or last) batch.
	 *
	 * @return array|null
	 */
	public static function current() {
		$b = get_option( self::OPTION, null );
		return is_array( $b ) ? $b : null;
	}

	/**
	 * @return bool
	 */
	public static function is_running() {
		$b = self::current();
		return $b && 'running' === $b['status'];
	}

	/**
	 * Product types bulk send handles in this version.
	 *
	 * @return string[]
	 */
	public static function product_types() {
		return (array) apply_filters( 'slh_bulk_product_types', array( 'simple', 'variable' ) );
	}

	/**
	 * Counts candidates for the bulk page ("what will happen" before confirming).
	 *
	 * @param int $term_id Optional WooCommerce category.
	 * @return array{all:int, unsent:int}
	 */
	public static function count_candidates( $term_id = 0 ) {
		global $wpdb;
		$all    = self::query_ids( 'all', $term_id, 1, 0, true );
		$linked = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SLH_Links::table() . " WHERE object_type = 'product' AND basalam_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$unsent = self::query_ids( 'unsent', $term_id, 1, 0, true );
		return array( 'all' => $all, 'unsent' => $unsent, 'linked' => $linked );
	}

	/**
	 * Product IDs for a batch scope (or their count).
	 *
	 * @param string $scope   all|unsent.
	 * @param int    $term_id Category filter (0 = all).
	 * @param int    $limit   Page size.
	 * @param int    $after   Return IDs greater than this (keyset pagination).
	 * @param bool   $count   Return the total count instead.
	 * @return int[]|int
	 */
	private static function query_ids( $scope, $term_id, $limit, $after, $count = false ) {
		global $wpdb;
		$types = self::product_types();
		$joins = '';
		$where = $wpdb->prepare( "p.post_type = 'product' AND p.post_status = 'publish' AND p.ID > %d", $after );

		// Product type is a taxonomy term (product_type).
		$type_ids = array();
		foreach ( $types as $type ) {
			$term = get_term_by( 'slug', $type, 'product_type' );
			if ( $term ) {
				$type_ids[] = (int) $term->term_taxonomy_id;
			}
		}
		if ( ! $type_ids ) {
			return $count ? 0 : array();
		}
		$joins .= " INNER JOIN {$wpdb->term_relationships} tr_type ON tr_type.object_id = p.ID AND tr_type.term_taxonomy_id IN (" . implode( ',', $type_ids ) . ')';

		if ( $term_id ) {
			$tt_ids = array( (int) $term_id );
			foreach ( get_term_children( $term_id, 'product_cat' ) as $child ) {
				$tt_ids[] = (int) $child;
			}
			$tt_ids = array_map(
				function ( $id ) {
					$t = get_term( $id, 'product_cat' );
					return $t && ! is_wp_error( $t ) ? (int) $t->term_taxonomy_id : 0;
				},
				$tt_ids
			);
			$joins .= " INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID AND tr_cat.term_taxonomy_id IN (" . implode( ',', array_filter( $tt_ids ) ) . ')';
		}

		if ( 'unsent' === $scope ) {
			$joins .= ' LEFT JOIN ' . SLH_Links::table() . " l ON l.object_type = 'product' AND l.wc_id = p.ID";
			$where .= ' AND l.basalam_id IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		if ( $count ) {
			return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins} WHERE {$where}" );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$joins} WHERE {$where} ORDER BY p.ID ASC LIMIT %d", $limit ) ) );
		// phpcs:enable
	}

	/**
	 * Starts a batch.
	 *
	 * @param array $args {scope: all|unsent|ids, term_id: int, ids: int[]}.
	 * @return array|WP_Error The batch.
	 */
	public static function start( array $args ) {
		if ( ! SLH_Settings::is_connected() ) {
			return new WP_Error( 'not_connected', __( 'اول در سلام‌هاب › تنظیمات به باسلام وصل شو.', 'salamhub' ) );
		}
		if ( self::is_running() || ( class_exists( 'SLH_Linker' ) && SLH_Linker::is_running() ) ) {
			return new WP_Error( 'busy', __( 'یک ارسال گروهی دیگر در حال اجراست. صبر کن تمام شود یا متوقفش کن؛ دو عملیات سنگین هم‌زمان اجرا نمی‌شوند.', 'salamhub' ) );
		}
		$scope   = isset( $args['scope'] ) && in_array( $args['scope'], array( 'all', 'unsent', 'ids' ), true ) ? $args['scope'] : 'unsent';
		$term_id = isset( $args['term_id'] ) ? (int) $args['term_id'] : 0;
		$ids     = 'ids' === $scope ? array_values( array_unique( array_filter( array_map( 'intval', (array) $args['ids'] ) ) ) ) : array();
		$total   = 'ids' === $scope ? count( $ids ) : self::query_ids( $scope, $term_id, 1, 0, true );

		if ( $total < 1 ) {
			return new WP_Error( 'empty', __( 'محصولی برای ارسال پیدا نشد. فقط محصولات ساده‌ی منتشرشده ارسال می‌شوند.', 'salamhub' ) );
		}

		$batch = array(
			'id'         => 'b' . time() . wp_rand( 100, 999 ),
			'status'     => 'running', // running | done | cancelled
			'scope'      => $scope,
			'term_id'    => $term_id,
			'ids'        => $ids,
			'total'      => $total,
			'planned'    => 0,
			'cursor'     => 0,
			'started_at' => slh_now(),
			'ended_at'   => '',
			'user_id'    => get_current_user_id(),
		);
		update_option( self::OPTION, $batch, false );
		as_enqueue_async_action( self::HOOK_PLAN, array( 'batch_id' => $batch['id'] ), SLH_Queue::GROUP );

		SLH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'bulk_started',
				'object_type' => 'system',
				'title'       => __( 'ارسال گروهی', 'salamhub' ),
				/* translators: %s: count */
				'message'     => sprintf( __( 'ارسال گروهی %s محصول شروع شد.', 'salamhub' ), slh_fa_number( $total ) ),
				'context'     => array( 'batch' => $batch['id'], 'scope' => $scope, 'term_id' => $term_id ),
			)
		);
		return $batch;
	}

	/**
	 * Planner job: queues the next chunk of products, then schedules itself again.
	 *
	 * @param string $batch_id Batch.
	 */
	public static function plan( $batch_id ) {
		$batch = self::current();
		if ( ! $batch || $batch['id'] !== $batch_id || 'running' !== $batch['status'] ) {
			return;
		}

		$size = max( 1, (int) apply_filters( 'slh_bulk_chunk', self::CHUNK ) );
		if ( 'ids' === $batch['scope'] ) {
			$chunk = array_slice( $batch['ids'], $batch['planned'], $size );
		} else {
			$chunk = self::query_ids( $batch['scope'], $batch['term_id'], $size, $batch['cursor'] );
		}

		foreach ( $chunk as $product_id ) {
			SLH_Queue::enqueue_product( $product_id, 'all' === $batch['scope'] || 'ids' === $batch['scope'], $batch['id'] );
		}

		$batch['planned'] += count( $chunk );
		$batch['cursor']   = $chunk ? max( $chunk ) : $batch['cursor'];
		// "all"/"unsent" are counted at start; products added meanwhile are included, so keep total honest.
		$batch['total'] = max( $batch['total'], $batch['planned'] );

		$more = count( $chunk ) === $size && ( 'ids' !== $batch['scope'] || $batch['planned'] < count( $batch['ids'] ) );
		if ( ! $more ) {
			$batch['total'] = $batch['planned'];
		}
		update_option( self::OPTION, $batch, false );

		if ( $more ) {
			as_enqueue_async_action( self::HOOK_PLAN, array( 'batch_id' => $batch['id'] ), SLH_Queue::GROUP );
		} else {
			self::maybe_finish( $batch['id'] );
		}
	}

	/**
	 * Progress numbers from the links table.
	 *
	 * @param array|null $batch Batch (default: current).
	 * @return array{total:int, done:int, failed:int, skipped:int, waiting:int, percent:int, planning:bool}
	 */
	public static function progress( $batch = null ) {
		global $wpdb;
		$batch = $batch ? $batch : self::current();
		$out   = array( 'total' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'waiting' => 0, 'percent' => 0, 'planning' => false );
		if ( ! $batch ) {
			return $out;
		}
		$queued = 0;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT sync_status, COUNT(*) AS n FROM ' . SLH_Links::table() . " WHERE object_type = 'product' AND batch_id = %s GROUP BY sync_status", $batch['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $r ) {
			if ( 'synced' === $r->sync_status ) {
				$out['done'] += (int) $r->n;
			} elseif ( 'error' === $r->sync_status ) {
				$out['failed'] += (int) $r->n;
			} elseif ( 'queued' === $r->sync_status ) {
				$queued += (int) $r->n;
			} else {
				$out['skipped'] += (int) $r->n; // Trashed meanwhile, or taken out by "stop".
			}
		}
		$out['total']    = (int) $batch['total'];
		$out['planning'] = 'running' === $batch['status'] && $batch['planned'] < $batch['total'];
		$out['waiting']  = 'running' === $batch['status'] ? $queued + max( 0, $batch['total'] - $batch['planned'] ) : $queued;
		$out['percent']  = $out['total'] ? (int) floor( 100 * max( 0, $out['total'] - $out['waiting'] ) / $out['total'] ) : 0;
		return $out;
	}

	/**
	 * Marks the batch done when nothing is left, and logs a summary.
	 *
	 * @param string $batch_id Batch.
	 */
	public static function maybe_finish( $batch_id ) {
		$batch = self::current();
		if ( ! $batch || $batch['id'] !== $batch_id || 'running' !== $batch['status'] || $batch['planned'] < $batch['total'] ) {
			return;
		}
		$p = self::progress( $batch );
		if ( $p['waiting'] > 0 ) {
			return;
		}
		$batch['status']   = 'done';
		$batch['ended_at'] = slh_now();
		update_option( self::OPTION, $batch, false );
		SLH_Logger::log(
			array(
				'level'       => $p['failed'] ? 'warning' : 'success',
				'event'       => 'bulk_done',
				'object_type' => 'system',
				'title'       => __( 'ارسال گروهی', 'salamhub' ),
				/* translators: 1: done, 2: failed */
				'message'     => sprintf( __( 'ارسال گروهی تمام شد: %1$s موفق، %2$s خطا.', 'salamhub' ), slh_fa_number( $p['done'] ), slh_fa_number( $p['failed'] ) ),
				'suggestion'  => $p['failed'] ? __( 'خطاها را در لاگ ببین و بعد از رفع، «تلاش مجدد همه‌ی خطاها» را بزن.', 'salamhub' ) : '',
				'context'     => array( 'batch' => $batch['id'] ),
			)
		);
	}

	/**
	 * Stops a batch: products still waiting are taken out of the queue (marked «همگام نیست»).
	 *
	 * @return int How many products were taken out.
	 */
	public static function cancel() {
		global $wpdb;
		$batch = self::current();
		if ( ! $batch || 'running' !== $batch['status'] ) {
			return 0;
		}
		$batch['status']   = 'cancelled';
		$batch['ended_at'] = slh_now();
		update_option( self::OPTION, $batch, false );
		as_unschedule_all_actions( self::HOOK_PLAN, array( 'batch_id' => $batch['id'] ), SLH_Queue::GROUP );

		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT wc_id FROM ' . SLH_Links::table() . " WHERE object_type = 'product' AND batch_id = %s AND sync_status = 'queued'", $batch['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ids as $id ) {
			as_unschedule_all_actions( SLH_Queue::HOOK_PRODUCT, array( 'product_id' => (int) $id ), SLH_Queue::GROUP );
			SLH_Links::set_status( 'product', (int) $id, 'stale' );
		}
		SLH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'bulk_cancelled',
				'object_type' => 'system',
				'title'       => __( 'ارسال گروهی', 'salamhub' ),
				/* translators: %s: count */
				'message'     => sprintf( __( 'ارسال گروهی متوقف شد؛ %s محصول از صف خارج شد.', 'salamhub' ), slh_fa_number( count( $ids ) ) ),
				'context'     => array( 'batch' => $batch['id'] ),
			)
		);
		return count( $ids );
	}
}
