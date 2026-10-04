<?php
/**
 * Links products that already exist in the Basalam booth to WooCommerce products.
 *
 * Nothing is ever linked blindly:
 *   1. a background job downloads the booth's products page by page,
 *   2. a matcher sorts them into «قطعی» (same SKU), «مشکوک» (similar name, or a
 *      conflict) and «بدون جفت»,
 *   3. the user reviews the preview and approves; only then are links written.
 * Linking never sends anything to Basalam unless the user asks for it.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Linker {

	const STATE      = 'bsh_link_state';
	const HOOK_FETCH = 'bsh_link_fetch';
	const HOOK_MATCH = 'bsh_link_match';
	const PER_PAGE   = 50;
	const CHUNK      = 300;

	/** Name similarity (0-100) from which a pair is shown as «مشکوک». */
	const MIN_SCORE = 70;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_FETCH, array( __CLASS__, 'fetch_page' ), 10, 2 );
		add_action( self::HOOK_MATCH, array( __CLASS__, 'match_chunk' ), 10, 2 );
	}

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bsh_remote_products';
	}

	/**
	 * @return array{status: string, run_id: string, page: int, total_pages: int, fetched: int, matched: int, total: int, started_at: string, finished_at: string, error: string}
	 */
	public static function state() {
		$s = get_option( self::STATE, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'status'      => 'idle', // idle | fetching | matching | ready | failed
				'run_id'      => '',
				'page'        => 0,
				'total_pages' => 0,
				'fetched'     => 0,
				'matched'     => 0,
				'total'       => 0,
				'started_at'  => '',
				'finished_at' => '',
				'error'       => '',
			)
		);
	}

	/**
	 * @param array $data Partial state.
	 */
	private static function set_state( array $data ) {
		update_option( self::STATE, array_merge( self::state(), $data ), false );
	}

	/**
	 * @return bool
	 */
	public static function is_running() {
		return in_array( self::state()['status'], array( 'fetching', 'matching' ), true );
	}

	/**
	 * Starts fetching the booth's products (background).
	 *
	 * @return true|WP_Error
	 */
	public static function start() {
		if ( ! BSH_Settings::is_connected() ) {
			return new WP_Error( 'not_connected', __( 'اول در باسلام‌هاب › تنظیمات به باسلام وصل شو.', 'basalamhub' ) );
		}
		if ( self::is_running() || BSH_Bulk::is_running() || BSH_Importer::is_running() ) {
			return new WP_Error( 'busy', __( 'یک عملیات سنگین دیگر در حال اجراست. صبر کن تمام شود؛ دو عملیات سنگین هم‌زمان اجرا نمی‌شوند.', 'basalamhub' ) );
		}
		$run = 'r' . time() . wp_rand( 100, 999 );
		update_option(
			self::STATE,
			array(
				'status'     => 'fetching',
				'run_id'     => $run,
				'page'       => 0,
				'fetched'    => 0,
				'matched'    => 0,
				'started_at' => bsh_now(),
			),
			false
		);
		as_enqueue_async_action( self::HOOK_FETCH, array( 'run_id' => $run, 'page' => 1 ), BSH_Queue::GROUP );
		return true;
	}

	/**
	 * Background: downloads one page, stores it, queues the next one.
	 *
	 * @param string $run_id Run.
	 * @param int    $page   Page.
	 */
	public static function fetch_page( $run_id, $page ) {
		if ( self::state()['run_id'] !== $run_id || 'fetching' !== self::state()['status'] ) {
			return;
		}
		BSH_Queue::run_exclusive(
			self::HOOK_FETCH,
			array( 'run_id' => $run_id, 'page' => $page ),
			function () use ( $run_id, $page ) {
				try {
					$res = BSH_Plugin::api()->vendor_products( BSH_Settings::vendor_id(), $page, self::PER_PAGE );
				} catch ( BSH_Api_Error $e ) {
					if ( $e->retryable && false !== BSH_Queue::retry_later( self::HOOK_FETCH, array( 'run_id' => $run_id, 'page' => $page ), 'link_fetch', $e->retry_after ) ) {
						return;
					}
					self::set_state( array( 'status' => 'failed', 'error' => trim( $e->getMessage() . ' ' . $e->reason ) ) );
					BSH_Logger::log(
						array_merge(
							array(
								'level'       => 'error',
								'event'       => 'link_fetch_failed',
								'object_type' => 'system',
								'title'       => __( 'اتصال محصولات غرفه', 'basalamhub' ),
							),
							$e->to_log()
						)
					);
					return;
				}
				BSH_Queue::reset_attempts( 'link_fetch' );
				foreach ( $res['data'] as $item ) {
					self::store_remote( $item, $run_id );
				}
				$state = self::state();
				$more  = $res['total_page'] ? $page < $res['total_page'] : count( $res['data'] ) === self::PER_PAGE;
				self::set_state(
					array(
						'page'        => $page,
						'total_pages' => (int) $res['total_page'],
						'fetched'     => $state['fetched'] + count( $res['data'] ),
					)
				);
				if ( $more ) {
					as_enqueue_async_action( self::HOOK_FETCH, array( 'run_id' => $run_id, 'page' => $page + 1 ), BSH_Queue::GROUP );
					return;
				}
				// Products deleted from the booth since the last run disappear from the preview.
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE run_id <> %s', $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::set_state( array( 'status' => 'matching', 'total' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				as_enqueue_async_action( self::HOOK_MATCH, array( 'run_id' => $run_id, 'offset' => 0 ), BSH_Queue::GROUP );
			}
		);
	}

	/**
	 * Saves one Basalam product (VendorProductResponse) into the snapshot table.
	 *
	 * @param array  $item   Product.
	 * @param string $run_id Run.
	 */
	private static function store_remote( array $item, $run_id ) {
		global $wpdb;
		if ( empty( $item['id'] ) ) {
			return;
		}
		$variants = array();
		$list     = isset( $item['variants'] ) ? $item['variants'] : ( isset( $item['variant'] ) ? $item['variant'] : array() );
		foreach ( is_array( $list ) ? $list : array() as $v ) {
			if ( empty( $v['id'] ) ) {
				continue;
			}
			$label = array();
			foreach ( isset( $v['properties'] ) && is_array( $v['properties'] ) ? $v['properties'] : array() as $p ) {
				$label[] = isset( $p['value']['title'] ) ? $p['value']['title'] : ( isset( $p['value'] ) && is_string( $p['value'] ) ? $p['value'] : '' );
			}
			$variants[] = array(
				'id'    => (int) $v['id'],
				'sku'   => isset( $v['sku'] ) ? (string) $v['sku'] : '',
				'label' => implode( '، ', array_filter( $label ) ),
				'raw'   => $v,
			);
		}
		$photo = '';
		if ( isset( $item['photo'] ) && is_array( $item['photo'] ) ) {
			foreach ( array( 'sm', 'xs', 'md', 'original' ) as $size ) {
				if ( ! empty( $item['photo'][ $size ] ) ) {
					$photo = (string) $item['photo'][ $size ];
					break;
				}
			}
		}
		$price = isset( $item['primary_price'] ) ? $item['primary_price'] : ( isset( $item['price'] ) ? $item['price'] : null );
		$row   = array(
			'basalam_id' => (int) $item['id'],
			'title'      => mb_substr( isset( $item['title'] ) ? (string) $item['title'] : ( isset( $item['name'] ) ? (string) $item['name'] : '' ), 0, 250 ),
			'sku'        => isset( $item['sku'] ) && '' !== (string) $item['sku'] ? mb_substr( (string) $item['sku'], 0, 100 ) : null,
			'price'      => null === $price ? null : (int) $price,
			'stock'      => isset( $item['inventory'] ) ? (int) $item['inventory'] : ( isset( $item['stock'] ) ? (int) $item['stock'] : null ),
			'photo'      => $photo ? esc_url_raw( $photo ) : null,
			'variants'   => $variants ? wp_json_encode( $variants ) : null,
			'run_id'     => $run_id,
			'fetched_at' => bsh_now(),
		);
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE basalam_id = %d', $row['basalam_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			$wpdb->update( self::table(), $row, array( 'id' => $exists ) );
		} else {
			$wpdb->insert( self::table(), $row );
		}
	}

	/* ---------------------------------------------------------------------
	 * Matching
	 * ------------------------------------------------------------------ */

	/**
	 * Background: matches a chunk of the snapshot against WooCommerce products.
	 *
	 * @param string $run_id Run.
	 * @param int    $offset Offset.
	 */
	public static function match_chunk( $run_id, $offset ) {
		global $wpdb;
		if ( self::state()['run_id'] !== $run_id || 'matching' !== self::state()['status'] ) {
			return;
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id ASC LIMIT %d OFFSET %d', self::CHUNK, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$index = self::wc_index();
		foreach ( $rows as $row ) {
			$result = self::match_one( $row, $index );
			$wpdb->update(
				self::table(),
				array(
					'match_status' => $result['status'],
					'match_wc_id'  => $result['wc_id'] ? $result['wc_id'] : null,
					'match_score'  => $result['score'],
					'match_reason' => mb_substr( $result['reason'], 0, 250 ),
					'candidates'   => $result['candidates'] ? wp_json_encode( $result['candidates'] ) : null,
				),
				array( 'id' => $row->id )
			);
		}
		self::set_state( array( 'matched' => $offset + count( $rows ) ) );
		if ( count( $rows ) === self::CHUNK ) {
			as_enqueue_async_action( self::HOOK_MATCH, array( 'run_id' => $run_id, 'offset' => $offset + self::CHUNK ), BSH_Queue::GROUP );
			return;
		}
		self::resolve_conflicts();
		self::set_state( array( 'status' => 'ready', 'finished_at' => bsh_now() ) );
		$c = self::counts();
		BSH_Logger::log(
			array(
				'level'       => 'info',
				'event'       => 'link_ready',
				'object_type' => 'system',
				'title'       => __( 'اتصال محصولات غرفه', 'basalamhub' ),
				/* translators: 1: total, 2: certain, 3: suspect, 4: none */
				'message'     => sprintf( __( '%1$s محصول غرفه بررسی شد: %2$s قطعی، %3$s مشکوک، %4$s بدون جفت. تا تأیید تو چیزی متصل نمی‌شود.', 'basalamhub' ), bsh_fa_number( array_sum( $c ) ), bsh_fa_number( $c['certain'] ), bsh_fa_number( $c['suspect'] ), bsh_fa_number( $c['none'] ) ),
			)
		);
	}

	/**
	 * Lookup tables of WooCommerce products: SKU → product, normalized title → products,
	 * token → products, and which are already linked.
	 *
	 * @return array
	 */
	private static function wc_index() {
		global $wpdb;
		$posts = $wpdb->get_results( "SELECT ID, post_title, post_parent, post_type FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')" );
		$skus  = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value <> ''" );
		$links = $wpdb->get_results( 'SELECT wc_id, basalam_id FROM ' . BSH_Links::table() . " WHERE object_type = 'product' AND basalam_id IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$parent = array();
		$titles = array();
		$tokens = array();
		$names  = array();
		foreach ( $posts as $p ) {
			if ( 'product_variation' === $p->post_type ) {
				$parent[ (int) $p->ID ] = (int) $p->post_parent;
				continue;
			}
			$parent[ (int) $p->ID ] = (int) $p->ID;
			$norm                   = self::normalize( $p->post_title );
			$names[ (int) $p->ID ]  = $norm;
			$titles[ $norm ][]      = (int) $p->ID;
			foreach ( self::tokens( $norm ) as $t ) {
				$tokens[ $t ][] = (int) $p->ID;
			}
		}
		$by_sku = array();
		foreach ( $skus as $s ) {
			if ( isset( $parent[ (int) $s->post_id ] ) ) {
				$by_sku[ mb_strtolower( trim( $s->meta_value ) ) ] = $parent[ (int) $s->post_id ];
			}
		}
		$linked_wc      = array();
		$linked_basalam = array();
		foreach ( $links as $l ) {
			$linked_wc[ (int) $l->wc_id ]           = (int) $l->basalam_id;
			$linked_basalam[ (int) $l->basalam_id ] = (int) $l->wc_id;
		}
		return compact( 'by_sku', 'titles', 'tokens', 'names', 'parent', 'linked_wc', 'linked_basalam' );
	}

	/**
	 * Decides one remote product.
	 *
	 * @param object $row   Snapshot row.
	 * @param array  $index wc_index().
	 * @return array{status: string, wc_id: int, score: int, reason: string, candidates: int[]}
	 */
	public static function match_one( $row, array $index ) {
		$bid = (int) $row->basalam_id;
		if ( isset( $index['linked_basalam'][ $bid ] ) ) {
			return array( 'status' => 'linked', 'wc_id' => $index['linked_basalam'][ $bid ], 'score' => 100, 'reason' => __( 'قبلاً متصل شده.', 'basalamhub' ), 'candidates' => array() );
		}

		// 1) SKU: the product's own, or any of its variants'.
		$skus = array();
		if ( $row->sku ) {
			$skus[] = $row->sku;
		}
		foreach ( $row->variants ? (array) json_decode( $row->variants, true ) : array() as $v ) {
			if ( ! empty( $v['sku'] ) ) {
				$skus[] = $v['sku'];
			}
		}
		$sku_hits = array();
		foreach ( $skus as $sku ) {
			$key = mb_strtolower( trim( $sku ) );
			if ( isset( $index['by_sku'][ $key ] ) ) {
				$sku_hits[ $index['by_sku'][ $key ] ] = $sku;
			} elseif ( preg_match( '/^(?:BSH|SLH)-(\d+)$/i', $sku, $m ) && isset( $index['parent'][ (int) $m[1] ] ) ) {
				// Our own synthetic SKU: the WooCommerce ID is inside it.
				$sku_hits[ $index['parent'][ (int) $m[1] ] ] = $sku;
			}
		}
		if ( 1 === count( $sku_hits ) ) {
			$wc_id = (int) key( $sku_hits );
			if ( isset( $index['linked_wc'][ $wc_id ] ) ) {
				return array(
					'status'     => 'suspect',
					'wc_id'      => $wc_id,
					'score'      => 90,
					/* translators: %s: Basalam id */
					'reason'     => sprintf( __( 'SKU یکی است، ولی این محصول سایت قبلاً به محصول دیگری در باسلام (#%s) متصل است.', 'basalamhub' ), $index['linked_wc'][ $wc_id ] ),
					'candidates' => array( $wc_id ),
				);
			}
			/* translators: %s: SKU */
			return array( 'status' => 'certain', 'wc_id' => $wc_id, 'score' => 100, 'reason' => sprintf( __( 'SKU یکسان: %s', 'basalamhub' ), current( $sku_hits ) ), 'candidates' => array( $wc_id ) );
		}
		if ( count( $sku_hits ) > 1 ) {
			return array( 'status' => 'suspect', 'wc_id' => (int) key( $sku_hits ), 'score' => 80, 'reason' => __( 'SKUهای این محصول به چند محصول مختلف سایت می‌خورند.', 'basalamhub' ), 'candidates' => array_map( 'intval', array_keys( $sku_hits ) ) );
		}

		// 2) Name: exact (normalized), then fuzzy among products that share words.
		$norm = self::normalize( $row->title );
		if ( '' === $norm ) {
			return array( 'status' => 'none', 'wc_id' => 0, 'score' => 0, 'reason' => '', 'candidates' => array() );
		}
		$scores = array();
		if ( isset( $index['titles'][ $norm ] ) ) {
			foreach ( $index['titles'][ $norm ] as $id ) {
				$scores[ $id ] = 100;
			}
		}
		$shared = array();
		foreach ( self::tokens( $norm ) as $t ) {
			foreach ( isset( $index['tokens'][ $t ] ) ? $index['tokens'][ $t ] : array() as $id ) {
				$shared[ $id ] = isset( $shared[ $id ] ) ? $shared[ $id ] + 1 : 1;
			}
		}
		arsort( $shared );
		foreach ( array_slice( $shared, 0, 25, true ) as $id => $n ) {
			if ( isset( $scores[ $id ] ) ) {
				continue;
			}
			similar_text( $norm, $index['names'][ $id ], $percent );
			$scores[ $id ] = (int) round( $percent );
		}
		// Different numbers ("۱ کیلو" vs "۲ کیلو") usually mean a different product.
		$numbers = self::numbers( $norm );
		$notes   = array();
		foreach ( $scores as $id => $score ) {
			if ( self::numbers( $index['names'][ $id ] ) !== $numbers ) {
				$scores[ $id ] = max( 0, $score - 35 );
				$notes[ $id ]  = true;
			}
		}
		arsort( $scores );
		$top = array_filter(
			$scores,
			function ( $s ) {
				return $s >= self::MIN_SCORE;
			}
		);
		if ( ! $top ) {
			return array( 'status' => 'none', 'wc_id' => 0, 'score' => 0, 'reason' => '', 'candidates' => array() );
		}
		$best  = (int) key( $top );
		$score = (int) current( $top );
		/* translators: %s: similarity percent */
		$reason = 100 === $score ? __( 'نام دقیقاً یکی است (SKU ندارد یا فرق دارد).', 'basalamhub' ) : sprintf( __( 'نام شبیه است (%s٪).', 'basalamhub' ), bsh_fa_digits( $score ) );
		if ( count( $top ) > 1 ) {
			$reason .= ' ' . __( 'چند گزینه‌ی مشابه هست؛ درستش را انتخاب کن.', 'basalamhub' );
		}
		if ( isset( $index['linked_wc'][ $best ] ) ) {
			$reason .= ' ' . __( 'این محصول سایت قبلاً به محصول دیگری متصل است.', 'basalamhub' );
		}
		return array( 'status' => 'suspect', 'wc_id' => $best, 'score' => $score, 'reason' => $reason, 'candidates' => array_map( 'intval', array_slice( array_keys( $top ), 0, 5 ) ) );
	}

	/**
	 * Two Basalam products pointing at the same WooCommerce product can't both be «قطعی».
	 */
	private static function resolve_conflicts() {
		global $wpdb;
		$dupes = $wpdb->get_col( 'SELECT match_wc_id FROM ' . self::table() . " WHERE match_status = 'certain' GROUP BY match_wc_id HAVING COUNT(*) > 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $dupes as $wc_id ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table() . " SET match_status = 'suspect', match_score = 80, match_reason = %s WHERE match_status = 'certain' AND match_wc_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					__( 'چند محصول باسلام با یک SKU به همین محصول سایت می‌خورند؛ فقط یکی را متصل کن.', 'basalamhub' ),
					$wc_id
				)
			);
		}
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = strtr( $text, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و' ) );
		$text = BSH_Categories::normalize( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * @param string $norm Normalized text.
	 * @return string[] Words of 2+ characters.
	 */
	private static function tokens( $norm ) {
		return array_values( array_unique( array_filter( explode( ' ', $norm ), function ( $t ) {
			return mb_strlen( $t ) >= 2;
		} ) ) );
	}

	/**
	 * @param string $norm Normalized text.
	 * @return string Digits sequences, e.g. "1|500".
	 */
	private static function numbers( $norm ) {
		preg_match_all( '/\d+/', $norm, $m );
		return implode( '|', $m[0] );
	}

	/* ---------------------------------------------------------------------
	 * Preview data and approval
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,int> certain, suspect, none, linked.
	 */
	public static function counts() {
		global $wpdb;
		$out = array( 'certain' => 0, 'suspect' => 0, 'none' => 0, 'linked' => 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT match_status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY match_status' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( isset( $out[ $r->match_status ] ) ) {
				$out[ $r->match_status ] = (int) $r->n;
			}
		}
		return $out;
	}

	/**
	 * @param string $status   Tab.
	 * @param int    $page     Page.
	 * @param int    $per_page Page size.
	 * @return array{items: object[], total: int}
	 */
	public static function rows( $status, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$order = 'suspect' === $status ? 'match_score DESC, id ASC' : 'id ASC';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE match_status = %s', $status ) );
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE match_status = %s ORDER BY {$order} LIMIT %d OFFSET %d", $status, $per_page, ( max( 1, $page ) - 1 ) * $per_page ) );
		// phpcs:enable
		return array( 'items' => $items ? $items : array(), 'total' => $total );
	}

	/**
	 * Links approved pairs. Validates every pair again: products may have changed since
	 * the preview was built.
	 *
	 * @param array<int,int> $pairs basalam_id => wc_id.
	 * @param bool           $push  Queue a sync (site → Basalam) after linking.
	 * @return array{linked: int, errors: string[]}
	 */
	public static function approve( array $pairs, $push = false ) {
		global $wpdb;
		$linked = 0;
		$errors = array();
		$mapper = new BSH_Product_Mapper();
		foreach ( $pairs as $basalam_id => $wc_id ) {
			$basalam_id = (int) $basalam_id;
			$wc_id      = (int) $wc_id;
			$row        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE basalam_id = %d', $basalam_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$product    = $wc_id ? wc_get_product( $wc_id ) : null;
			if ( ! $row || ! $product || ! ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) ) {
				/* translators: %s: Basalam id */
				$errors[] = sprintf( __( '#%s: محصول سایت پیدا نشد یا نوعش پشتیبانی نمی‌شود.', 'basalamhub' ), $basalam_id );
				continue;
			}
			$other = BSH_Links::get_by_basalam( 'product', $basalam_id );
			if ( $other && (int) $other->wc_id !== $wc_id ) {
				/* translators: %s: Basalam id */
				$errors[] = sprintf( __( '#%s: این محصول باسلام قبلاً به محصول دیگری متصل شده.', 'basalamhub' ), $basalam_id );
				continue;
			}
			$mine = BSH_Links::get( 'product', $wc_id );
			if ( $mine && $mine->basalam_id && (int) $mine->basalam_id !== $basalam_id ) {
				/* translators: 1: product, 2: Basalam id */
				$errors[] = sprintf( __( '«%1$s» قبلاً به محصول باسلام #%2$s متصل است.', 'basalamhub' ), $product->get_name(), $mine->basalam_id );
				continue;
			}

			// Variants: match Basalam variants to WooCommerce variations by SKU, then by name.
			$unmatched = 0;
			if ( $product->is_type( 'variable' ) && $row->variants ) {
				$problems   = array();
				$variations = $mapper->variations( $product, $problems );
				$remote     = (array) json_decode( $row->variants, true );
				$map        = array();
				foreach ( $variations as $vid => $v ) {
					foreach ( $remote as $r ) {
						$same_sku   = '' !== $r['sku'] && mb_strtolower( $r['sku'] ) === mb_strtolower( $v['sku'] );
						$same_label = self::normalize( $r['label'] ) === self::normalize( $v['label'] );
						if ( $same_sku || $same_label ) {
							// Price/stock unknown (null) so the first sync sends them.
							$map[ $vid ] = array( 'id' => (int) $r['id'], 'sig' => $v['sig'], 'price' => null, 'stock' => null, 'sku' => $v['sku'] );
							break;
						}
					}
				}
				$unmatched = count( $variations ) - count( $map );
				update_post_meta( $wc_id, '_bsh_variants', $unmatched ? array() : $map );
			}

			BSH_Links::upsert(
				'product',
				$wc_id,
				array(
					'basalam_id'   => $basalam_id,
					'sync_status'  => 'stale',
					'payload_hash' => null,
					'last_error'   => null,
				)
			);
			$wpdb->update( self::table(), array( 'match_status' => 'linked', 'match_wc_id' => $wc_id ), array( 'basalam_id' => $basalam_id ) );
			BSH_Logger::log(
				array(
					'level'       => 'success',
					'event'       => 'product_linked',
					'object_type' => 'product',
					'object_id'   => $wc_id,
					'title'       => $product->get_name(),
					/* translators: %s: Basalam id */
					'message'     => sprintf( __( 'به محصول موجود باسلام #%s متصل شد.', 'basalamhub' ), $basalam_id ),
					'reason'      => $unmatched
						/* translators: %s: count */
						? sprintf( __( '%s تنوع جفت نشد؛ در اولین ارسال، فهرست کامل تنوع‌ها از سایت فرستاده می‌شود.', 'basalamhub' ), bsh_fa_number( $unmatched ) )
						: null,
				)
			);
			if ( $push ) {
				BSH_Queue::enqueue_product( $wc_id, true );
			}
			++$linked;
		}
		return array( 'linked' => $linked, 'errors' => $errors );
	}
}
