<?php
/**
 * Uploads product images to Basalam once and remembers the Basalam file ID.
 *
 * An image is re-uploaded only when the file itself changes (path, size or mtime).
 * The site's original image is never modified. Resizing to Basalam's limits is added
 * in the images phase through the `slh_image_upload_path` filter.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Image_Sync {

	const META_ID  = '_slh_basalam_file_id';
	const META_SIG = '_slh_basalam_file_sig';

	/** @var SLH_Api_Client */
	private $api;

	/**
	 * @param SLH_Api_Client $api Client.
	 */
	public function __construct( SLH_Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * @param int[] $attachment_ids Attachments, first is the main photo.
	 * @return int[] Basalam file IDs in the same order (failed images are skipped).
	 * @throws SLH_Api_Error When the main image cannot be uploaded.
	 */
	public function ensure_uploaded( array $attachment_ids ) {
		$out = array();
		foreach ( array_values( $attachment_ids ) as $i => $attachment_id ) {
			try {
				$file_id = $this->ensure_one( (int) $attachment_id );
				if ( $file_id ) {
					$out[] = $file_id;
				}
			} catch ( SLH_Api_Error $e ) {
				// The main image is required; gallery images are best-effort.
				if ( 0 === $i || $e->retryable || 'auth' === $e->kind ) {
					throw $e;
				}
			}
		}
		return $out;
	}

	/**
	 * @param int $attachment_id Attachment.
	 * @return int Basalam file ID, 0 if the attachment has no file.
	 * @throws SLH_Api_Error On upload failure.
	 */
	private function ensure_one( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path ) {
			return 0;
		}
		$sig      = $this->signature( $path );
		$existing = (int) get_post_meta( $attachment_id, self::META_ID, true );
		if ( $existing && get_post_meta( $attachment_id, self::META_SIG, true ) === $sig ) {
			return $existing;
		}

		/**
		 * Lets the images phase hand over a resized copy instead of the original.
		 *
		 * @param string $path          Original file path.
		 * @param int    $attachment_id Attachment ID.
		 */
		$upload_path = apply_filters( 'slh_image_upload_path', $path, $attachment_id );
		$file        = $this->api->upload_file( $upload_path, 'product.photo' );

		if ( empty( $file['id'] ) ) {
			throw new SLH_Api_Error(
				__( 'آپلود تصویر در باسلام کامل نشد.', 'salamhub' ),
				'server',
				array(
					'retryable'  => true,
					'reason'     => __( 'باسلام شناسه‌ی فایل را برنگرداند.', 'salamhub' ),
					'suggestion' => __( 'لازم نیست کاری کنی؛ خودکار دوباره تلاش می‌شود.', 'salamhub' ),
					'details'    => array( 'response' => $file ),
				)
			);
		}
		update_post_meta( $attachment_id, self::META_ID, (int) $file['id'] );
		update_post_meta( $attachment_id, self::META_SIG, $sig );
		return (int) $file['id'];
	}

	/**
	 * @param string $path File path.
	 * @return string
	 */
	private function signature( $path ) {
		$size  = file_exists( $path ) ? filesize( $path ) : 0;
		$mtime = file_exists( $path ) ? filemtime( $path ) : 0;
		return md5( $path . '|' . $size . '|' . $mtime );
	}
}
