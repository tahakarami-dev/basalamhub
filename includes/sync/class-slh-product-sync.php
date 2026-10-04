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
					'message'    => __( 'ارسال نشد؛ سلام‌هاب به باسلام وصل نیست.', 'salamhub' ),
					'reason'     => __( 'توکن وارد نشده یا آخرین تست اتصال ناموفق بوده است.', 'salamhub' ),
					'suggestion' => __( 'در سلام‌هاب › تنظیمات توکن را وارد کن و «تست اتصال» را بزن، بعد «تلاش مجدد».', 'salamhub' ),
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
		$groups    = (array) SLH_Settings::get( 'sync_fields', array() );

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
		} else {
			$update = SLH_Product_Mapper::filter_by_groups( $payload, $groups );
			if ( $update ) {
				try {
					$this->api->update_product( $basalam_id, $update );
				} catch ( SLH_Api_Error $e ) {
					if ( 'not_found' === $e->kind ) {
						// Deleted on Basalam: unlink so "retry" re-creates it cleanly.
						SLH_Links::upsert( 'product', $id, array( 'basalam_id' => null, 'payload_hash' => null ) );
						$e->reason     = __( 'این محصول در باسلام حذف شده است.', 'salamhub' );
						$e->suggestion = __( 'اگر می‌خواهی دوباره در باسلام باشد، «تلاش مجدد» را بزن تا از نو ساخته شود.', 'salamhub' );
					}
					throw $e;
				}
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
