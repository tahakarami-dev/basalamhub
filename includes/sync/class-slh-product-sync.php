<?php
/**
 * Sends one WooCommerce product to Basalam (create or update). Runs only inside the queue.
 *
 * Guarantees:
 * - A linked product is always updated, never created twice (links table).
 * - A create whose response got lost is recovered by SKU before trying again.
 * - Unchanged products are skipped (payload hash), saving API quota.
 * - Every outcome is logged in plain Persian; every failure has a retry path.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Product_Sync {

	/** @var SLH_Api_Client */
	private $api;

	/** @var SLH_Product_Mapper */
	private $mapper;

	/**
	 * @param SLH_Api_Client          $api    Client.
	 * @param SLH_Product_Mapper|null $mapper Mapper.
	 */
	public function __construct( SLH_Api_Client $api, $mapper = null ) {
		$this->api    = $api;
		$this->mapper = $mapper ? $mapper : new SLH_Product_Mapper();
	}

	/**
	 * @param int $product_id Product ID.
	 * @return string Outcome: created|updated|unchanged|skipped|failed|retrying.
	 */
	public function sync( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'trash' === $product->get_status() ) {
			SLH_Links::set_status( 'product', $product_id, 'stale' );
			return 'skipped';
		}

		$force = (bool) get_transient( 'slh_force_product_' . $product_id );
		delete_transient( 'slh_force_product_' . $product_id );

		try {
			$outcome = $this->do_sync( $product, $force );
			SLH_Queue::reset_attempts( 'product_' . $product_id );
			return $outcome;
		} catch ( SLH_Api_Error $e ) {
			return $this->handle_api_error( $product, $e );
		} catch ( Throwable $e ) {
			$this->fail(
				$product,
				array(
					'message'    => __( 'ارسال به باسلام با خطای داخلی متوقف شد.', 'salamhub' ),
					'reason'     => __( 'یک خطای پیش‌بینی‌نشده در سایت رخ داد (احتمالاً تداخل با افزونه‌ی دیگر).', 'salamhub' ),
					'suggestion' => __( 'یک بار «تلاش مجدد» بزن. اگر تکرار شد، جزئیات فنی همین ردیف را برای پشتیبانی بفرست.', 'salamhub' ),
					'context'    => array( 'exception' => get_class( $e ), 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ),
				)
			);
			return 'failed';
		}
	}

	/**
	 * @param WC_Product $product Product.
	 * @param bool       $force   Ignore the "unchanged" shortcut.
	 * @return string
	 * @throws SLH_Api_Error On API failure.
	 */
	private function do_sync( WC_Product $product, $force ) {
		$id = $product->get_id();

		if ( ! SLH_Settings::is_connected() ) {
			$this->fail(
				$product,
				array(
					'message'    => __( 'ارسال نشد؛ باسلام‌هاب به باسلام وصل نیست.', 'salamhub' ),
					'reason'     => __( 'توکن وارد نشده یا آخرین تست اتصال ناموفق بوده است.', 'salamhub' ),
					'suggestion' => __( 'در باسلام‌هاب › تنظیمات توکن را وارد کن و «تست اتصال» را بزن، بعد «تلاش مجدد».', 'salamhub' ),
				)
			);
			return 'failed';
		}

		$mapped = $this->mapper->map( $product );
		if ( $mapped['problems'] ) {
			$messages    = wp_list_pluck( $mapped['problems'], 'message' );
			$suggestions = array_unique( wp_list_pluck( $mapped['problems'], 'suggestion' ) );
			$this->fail(
				$product,
				array(
					'message'    => __( 'به باسلام ارسال نشد.', 'salamhub' ),
					'reason'     => implode( ' ', $messages ),
					'suggestion' => implode( ' ', $suggestions ),
					'context'    => array( 'problems' => wp_list_pluck( $mapped['problems'], 'field' ) ),
				)
			);
			return 'failed';
		}

		$payload = $mapped['payload'];
		if ( ! empty( $payload['_slh_missing_attributes'] ) ) {
			$this->fail(
				$product,
				array(
					'message'    => __( 'به باسلام ارسال نشد.', 'salamhub' ),
					/* translators: %s: attribute names */
					'reason'     => sprintf( __( 'دسته‌ی باسلام این ویژگی‌های اجباری را می‌خواهد که مقدار ندارند: %s.', 'salamhub' ), implode( '، ', $payload['_slh_missing_attributes'] ) ),
					'suggestion' => __( 'در باسلام‌هاب › نگاشت دسته‌ها مقدار پیش‌فرض این ویژگی‌ها را وارد کن، یا در محصول ویژگی ووکامرسی با همین نام بساز.', 'salamhub' ),
					'context'    => array( 'category_id' => $payload['category_id'] ),
				)
			);
			return 'failed';
		}
		$payload = array_filter(
			$payload,
			function ( $key ) {
				return 0 !== strpos( (string) $key, '_slh_' );
			},
			ARRAY_FILTER_USE_KEY
		);
		$link    = SLH_Links::get( 'product', $id );
		$hash    = md5( wp_json_encode( array( $payload, $this->image_signatures( $mapped['image_ids'] ) ) ) );

		if ( $link && $link->basalam_id && ! $force && $hash === $link->payload_hash && 'error' !== $link->sync_status ) {
			SLH_Links::upsert( 'product', $id, array( 'sync_status' => 'synced' ) );
			return 'unchanged';
		}

		$basalam_id = $link && $link->basalam_id ? (int) $link->basalam_id : 0;
		$vendor_id  = SLH_Settings::vendor_id();

		// A previous create may have succeeded on Basalam's side even though we never saw the
		// response (timeout). Look it up by SKU before creating anything.
		if ( ! $basalam_id && get_transient( 'slh_pending_create_' . $id ) ) {
			$basalam_id = $this->recover_by_sku( $vendor_id, $payload['sku'] );
			if ( $basalam_id ) {
				SLH_Links::upsert( 'product', $id, array( 'basalam_id' => $basalam_id ) );
			}
		}

		$is_create = ! $basalam_id;
		$groups    = SLH_Inventory::push_groups();

		if ( $is_create || in_array( 'images', $groups, true ) ) {
			$file_ids = ( new SLH_Image_Sync( $this->api ) )->ensure_uploaded( $mapped['image_ids'] );
			if ( $file_ids ) {
				$payload['photo']  = $file_ids[0];
				$payload['photos'] = array_slice( $file_ids, 1 );
			}
		}

		if ( $is_create ) {
			set_transient( 'slh_pending_create_' . $id, 1, DAY_IN_SECONDS );
			$response   = $this->api->create_product( $vendor_id, $payload );
			$basalam_id = self::extract_id( $response );
			if ( ! $basalam_id ) {
				// Created but no ID in the response: recover by SKU so we never duplicate.
				$basalam_id = $this->recover_by_sku( $vendor_id, $payload['sku'] );
			}
			if ( ! $basalam_id ) {
				throw new SLH_Api_Error(
					__( 'نتیجه‌ی ساخت محصول در باسلام معلوم نشد.', 'salamhub' ),
					'server',
					array(
						'retryable'  => true,
						'reason'     => __( 'باسلام شناسه‌ی محصول را برنگرداند.', 'salamhub' ),
						'suggestion' => __( 'لازم نیست کاری کنی؛ دوباره بررسی می‌شود و محصول تکراری ساخته نمی‌شود.', 'salamhub' ),
						'details'    => array( 'response' => $response ),
					)
				);
			}
			delete_transient( 'slh_pending_create_' . $id );
			if ( $mapped['variations'] ) {
				$this->store_variant_map( $product, $basalam_id, $mapped['variations'], isset( $response['variants'] ) ? $response['variants'] : null );
			}
		} else {
			$update = SLH_Product_Mapper::filter_by_groups( $payload, $groups );
			if ( $mapped['variations'] ) {
				// Variant prices/stock are handled per variant; the product-level ones are derived.
				unset( $update['primary_price'], $update['stock'] );
				$structural = $this->variants_changed_structurally( $product, $mapped['variations'] );
				if ( $structural ) {
					$update['variants'] = $payload['variants'];
				}
			}
			if ( $update ) {
				try {
					$this->api->update_product( $basalam_id, $update );
					if ( $mapped['variations'] ) {
						if ( ! empty( $structural ) ) {
							$this->store_variant_map( $product, $basalam_id, $mapped['variations'], null );
						}
					}
				} catch ( SLH_Api_Error $e ) {
					if ( 'not_found' === $e->kind ) {
						// Deleted on Basalam: unlink so "retry" re-creates it cleanly.
						SLH_Links::upsert( 'product', $id, array( 'basalam_id' => null, 'payload_hash' => null ) );
						delete_post_meta( $id, '_slh_variants' );
						$e->reason     = __( 'این محصول در باسلام حذف شده است.', 'salamhub' );
						$e->suggestion = __( 'اگر می‌خواهی دوباره در باسلام باشد، «تلاش مجدد» را بزن تا از نو ساخته شود.', 'salamhub' );
					}
					throw $e;
				}
			}
			if ( $mapped['variations'] && empty( $structural ) ) {
				$this->update_changed_variants( $product, $basalam_id, $mapped['variations'], $groups, $force );
			}
		}

		SLH_Links::upsert(
			'product',
			$id,
			array(
				'basalam_id'     => $basalam_id,
				'sync_status'    => 'synced',
				'payload_hash'   => $hash,
				'last_synced_at' => slh_now(),
				'last_error'     => null,
			)
		);
		SLH_Logger::resolve_for( 'product', $id );
		SLH_Logger::log(
			array(
				'level'       => 'success',
				'event'       => $is_create ? 'product_created' : 'product_updated',
				'object_type' => 'product',
				'object_id'   => $id,
				'title'       => $product->get_name(),
				'message'     => $is_create ? __( 'در باسلام ساخته شد.', 'salamhub' ) : __( 'تغییرات در باسلام اعمال شد.', 'salamhub' ),
				'context'     => array( 'basalam_id' => $basalam_id ),
			)
		);
		update_option( 'slh_last_sync_at', slh_now(), false );
		return $is_create ? 'created' : 'updated';
	}

	/* ---------------------------------------------------------------------
	 * Variants
	 *
	 * The map of WooCommerce variation → Basalam variant lives in the parent product's
	 * meta `_slh_variants`: [wc_variation_id => [id, sig, price, stock, sku]]. It is what
	 * keeps re-sends from creating duplicate variants.
	 * ------------------------------------------------------------------ */

	/**
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function variant_map( WC_Product $product ) {
		$map = get_post_meta( $product->get_id(), '_slh_variants', true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * A structural change (variation added/removed, attributes or SKU changed) needs the
	 * full variant list to be sent; anything else is a per-variant price/stock update.
	 *
	 * @param WC_Product $product    Product.
	 * @param array      $variations Mapped variations.
	 * @return bool
	 */
	private function variants_changed_structurally( WC_Product $product, array $variations ) {
		$known = self::variant_map( $product );
		if ( array_diff_key( $known, $variations ) || array_diff_key( $variations, $known ) ) {
			return true;
		}
		foreach ( $variations as $vid => $v ) {
			if ( empty( $known[ $vid ]['id'] ) || $known[ $vid ]['sig'] !== $v['sig'] || $known[ $vid ]['sku'] !== $v['sku'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Matches Basalam variants to WooCommerce variations (by SKU, then by properties),
	 * stores the map and warns about duplicates or unmatched variants.
	 *
	 * @param WC_Product $product    Product.
	 * @param int        $basalam_id Basalam product.
	 * @param array      $variations Mapped variations.
	 * @param array|null $remote     Variants from a response, or null to read the product.
	 * @throws SLH_Api_Error When reading the product fails.
	 */
	private function store_variant_map( WC_Product $product, $basalam_id, array $variations, $remote ) {
		if ( ! is_array( $remote ) || ! $remote ) {
			$read   = $this->api->get_product( $basalam_id );
			$remote = isset( $read['variants'] ) && is_array( $read['variants'] ) ? $read['variants'] : array();
		}
		$by_sku   = array();
		$by_props = array();
		foreach ( $remote as $r ) {
			if ( empty( $r['id'] ) ) {
				continue;
			}
			if ( ! empty( $r['sku'] ) ) {
				$by_sku[ (string) $r['sku'] ][] = (int) $r['id'];
			}
			$by_props[ self::remote_props_sig( $r ) ][] = (int) $r['id'];
		}

		$map       = array();
		$unmatched = array();
		$dupes     = array();
		foreach ( $variations as $vid => $v ) {
			$ids = isset( $by_sku[ $v['sku'] ] ) ? $by_sku[ $v['sku'] ] : ( isset( $by_props[ $v['sig'] ] ) ? $by_props[ $v['sig'] ] : array() );
			if ( ! $ids ) {
				$unmatched[] = $v['label'];
				continue;
			}
			if ( count( $ids ) > 1 ) {
				$dupes[] = $v['label'];
			}
			$map[ $vid ] = array(
				'id'    => max( $ids ), // The newest one if Basalam kept duplicates.
				'sig'   => $v['sig'],
				'price' => $v['primary_price'],
				'stock' => $v['stock'],
				'sku'   => $v['sku'],
			);
		}
		update_post_meta( $product->get_id(), '_slh_variants', $map );

		if ( $dupes || $unmatched || count( $remote ) > count( $variations ) ) {
			SLH_Logger::log(
				array(
					'level'       => 'warning',
					'event'       => 'variants_mismatch',
					'object_type' => 'product',
					'object_id'   => $product->get_id(),
					'title'       => $product->get_name(),
					/* translators: 1: remote count, 2: local count */
					'message'     => sprintf( __( 'تنوع‌های باسلام با سایت جور نیست: %1$s تنوع در باسلام، %2$s تنوع فعال در سایت.', 'salamhub' ), slh_fa_number( count( $remote ) ), slh_fa_number( count( $variations ) ) ),
					'reason'      => trim(
						( $dupes ? sprintf( /* translators: %s: labels */ __( 'تنوع تکراری: %s.', 'salamhub' ), implode( '، ', $dupes ) ) . ' ' : '' )
						. ( $unmatched ? sprintf( /* translators: %s: labels */ __( 'در باسلام پیدا نشد: %s.', 'salamhub' ), implode( '، ', $unmatched ) ) : '' )
					),
					'suggestion'  => __( 'تنوع‌های اضافه را در پنل باسلام حذف کن. باسلام‌هاب از این به بعد فقط جدیدترین تنوع هر ردیف را به‌روز می‌کند و تنوع تازه نمی‌سازد.', 'salamhub' ),
					'context'     => array( 'basalam_id' => $basalam_id, 'remote_variants' => wp_list_pluck( $remote, 'id' ) ),
				)
			);
		}
	}

	/**
	 * Same signature as the mapper's, from a Basalam VariantResponse.
	 *
	 * @param array $r Variant.
	 * @return string
	 */
	private static function remote_props_sig( array $r ) {
		$props = array();
		foreach ( isset( $r['properties'] ) && is_array( $r['properties'] ) ? $r['properties'] : array() as $p ) {
			$name    = isset( $p['property']['title'] ) ? $p['property']['title'] : ( isset( $p['property'] ) && is_string( $p['property'] ) ? $p['property'] : '' );
			$value   = isset( $p['value']['title'] ) ? $p['value']['title'] : ( isset( $p['value'] ) && is_string( $p['value'] ) ? $p['value'] : '' );
			$props[] = array( 'property' => (string) $name, 'value' => (string) $value );
		}
		return md5( wp_json_encode( $props ) );
	}

	/**
	 * Sends price/stock only for variants that changed (and only for the selected groups).
	 *
	 * @param WC_Product $product    Product.
	 * @param int        $basalam_id Basalam product.
	 * @param array      $variations Mapped variations.
	 * @param string[]   $groups     Selected field groups.
	 * @param bool       $force      Send every variant.
	 * @throws SLH_Api_Error On API failure (the map keeps what was already sent).
	 */
	private function update_changed_variants( WC_Product $product, $basalam_id, array $variations, array $groups, $force ) {
		$map  = self::variant_map( $product );
		$send = array_intersect( array( 'price', 'stock' ), $groups );
		if ( ! $send ) {
			return;
		}
		foreach ( $variations as $vid => $v ) {
			$body = array();
			if ( in_array( 'price', $send, true ) && ( $force || $map[ $vid ]['price'] !== $v['primary_price'] ) ) {
				$body['primary_price'] = $v['primary_price'];
			}
			if ( in_array( 'stock', $send, true ) && ( $force || $map[ $vid ]['stock'] !== $v['stock'] ) ) {
				$body['stock'] = $v['stock'];
			}
			if ( ! $body ) {
				continue;
			}
			try {
				$this->api->update_variant( $basalam_id, $map[ $vid ]['id'], $body );
			} catch ( SLH_Api_Error $e ) {
				if ( 'not_found' === $e->kind ) {
					// Variant deleted on Basalam: forget it so the retry re-sends the full list.
					unset( $map[ $vid ] );
					update_post_meta( $product->get_id(), '_slh_variants', $map );
					SLH_Links::upsert( 'product', $product->get_id(), array( 'payload_hash' => null ) );
					/* translators: %s: variation label */
					$e->reason     = sprintf( __( 'تنوع «%s» در باسلام حذف شده است.', 'salamhub' ), $v['label'] );
					$e->suggestion = __( '«تلاش مجدد» فهرست کامل تنوع‌ها را دوباره می‌فرستد.', 'salamhub' );
				}
				throw $e;
			}
			if ( isset( $body['primary_price'] ) ) {
				$map[ $vid ]['price'] = $v['primary_price'];
			}
			if ( isset( $body['stock'] ) ) {
				$map[ $vid ]['stock'] = $v['stock'];
			}
			update_post_meta( $product->get_id(), '_slh_variants', $map );
		}
	}

	/**
	 * @param int    $vendor_id Vendor.
	 * @param string $sku       SKU.
	 * @return int Basalam product ID or 0.
	 */
	private function recover_by_sku( $vendor_id, $sku ) {
		try {
			foreach ( $this->api->find_products_by_sku( $vendor_id, array( $sku ) ) as $item ) {
				if ( isset( $item['sku'] ) && (string) $item['sku'] === (string) $sku && ! empty( $item['id'] ) ) {
					return (int) $item['id'];
				}
			}
		} catch ( SLH_Api_Error $e ) {
			if ( $e->retryable ) {
				throw $e; // Can't be sure yet; try again later rather than risk a duplicate.
			}
		}
		return 0;
	}

	/**
	 * @param array $response API response.
	 * @return int
	 */
	private static function extract_id( $response ) {
		if ( isset( $response['id'] ) ) {
			return (int) $response['id'];
		}
		if ( isset( $response['data']['id'] ) ) {
			return (int) $response['data']['id'];
		}
		return 0;
	}

	/**
	 * The change hash a sync would compute for this product right now (null when the product
	 * can't be sent as is). Stored after an import, so a just-imported product is not sent
	 * straight back to Basalam until something actually changes.
	 *
	 * @param WC_Product $product Product.
	 * @return string|null
	 */
	public function fingerprint( WC_Product $product ) {
		$mapped = $this->mapper->map( $product );
		if ( $mapped['problems'] || ! empty( $mapped['payload']['_slh_missing_attributes'] ) ) {
			return null;
		}
		$payload = array_filter(
			$mapped['payload'],
			function ( $key ) {
				return 0 !== strpos( (string) $key, '_slh_' );
			},
			ARRAY_FILTER_USE_KEY
		);
		return md5( wp_json_encode( array( $payload, $this->image_signatures( $mapped['image_ids'] ) ) ) );
	}

	/**
	 * Image identity for the change hash (so a replaced image triggers a sync).
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array
	 */
	private function image_signatures( array $ids ) {
		$out = array();
		foreach ( $ids as $aid ) {
			$path  = get_attached_file( $aid );
			$out[] = $aid . ':' . ( $path && file_exists( $path ) ? filesize( $path ) . '-' . filemtime( $path ) : '0' );
		}
		return $out;
	}

	/**
	 * @param WC_Product    $product Product.
	 * @param SLH_Api_Error $e       Error.
	 * @return string
	 */
	private function handle_api_error( WC_Product $product, SLH_Api_Error $e ) {
		$id = $product->get_id();

		if ( 'auth' === $e->kind ) {
			SLH_Settings::update_connection( array( 'status' => 'invalid', 'message' => $e->getMessage() ) );
		}

		if ( 'rate_limit' === $e->kind ) {
			// Not this product's fault: pause the whole queue and keep its place, without
			// using up its retry attempts or writing one warning per product.
			$already_paused = (int) get_option( 'slh_pause_until', 0 ) > time();
			SLH_Queue::pause( $e->retry_after );
			as_schedule_single_action( (int) get_option( 'slh_pause_until' ) + wp_rand( 1, 20 ), SLH_Queue::HOOK_PRODUCT, array( 'product_id' => $id ), SLH_Queue::GROUP );
			SLH_Links::upsert( 'product', $id, array( 'sync_status' => 'queued' ) );
			if ( ! $already_paused ) {
				SLH_Logger::log(
					array(
						'level'       => 'warning',
						'event'       => 'rate_limited',
						'object_type' => 'system',
						'title'       => __( 'صف پس‌زمینه', 'salamhub' ),
						/* translators: %s: seconds */
						'message'     => $e->getMessage() . ' ' . sprintf( __( 'صف %s ثانیه مکث می‌کند و بعد از همان‌جا ادامه می‌دهد.', 'salamhub' ), slh_fa_digits( max( 10, (int) $e->retry_after ) ) ),
						'reason'      => $e->reason,
						'suggestion'  => $e->suggestion,
						'context'     => $e->details,
					)
				);
			}
			return 'retrying';
		}

		if ( $e->retryable ) {
			$delay = SLH_Queue::retry_later( SLH_Queue::HOOK_PRODUCT, array( 'product_id' => $id ), 'product_' . $id, $e->retry_after );
			if ( false !== $delay ) {
				SLH_Links::upsert( 'product', $id, array( 'sync_status' => 'queued', 'last_error' => $e->getMessage() ) );
				SLH_Logger::log(
					array(
						'level'       => 'warning',
						'event'       => 'product_retry',
						'object_type' => 'product',
						'object_id'   => $id,
						'title'       => $product->get_name(),
						/* translators: %s: minutes */
						'message'     => $e->getMessage() . ' ' . sprintf( __( 'حدود %s دقیقه‌ی دیگر خودکار دوباره تلاش می‌شود.', 'salamhub' ), slh_fa_digits( max( 1, (int) round( $delay / 60 ) ) ) ),
						'reason'      => $e->reason,
						'suggestion'  => $e->suggestion,
						'context'     => $e->details,
					)
				);
				return 'retrying';
			}
			$e->suggestion = __( 'چند بار خودکار تلاش شد و نشد. وقتی مشکل برطرف شد «تلاش مجدد» را بزن.', 'salamhub' );
		}

		$this->fail( $product, $e->to_log() );
		return 'failed';
	}

	/**
	 * Records a final failure.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $log     message, reason, suggestion, context.
	 */
	private function fail( WC_Product $product, array $log ) {
		$id     = $product->get_id();
		$log_id = SLH_Logger::log(
			array_merge(
				array(
					'level'       => 'error',
					'event'       => 'product_failed',
					'object_type' => 'product',
					'object_id'   => $id,
					'title'       => $product->get_name(),
					'retry_hook'  => SLH_Queue::HOOK_PRODUCT,
					'retry_args'  => array( 'product_id' => $id ),
				),
				$log
			)
		);
		SLH_Links::upsert(
			'product',
			$id,
			array(
				'sync_status' => 'error',
				'last_error'  => trim( $log['message'] . ' ' . ( isset( $log['reason'] ) ? $log['reason'] : '' ) ),
				'last_log_id' => $log_id,
			)
		);
	}
}
