<?php
/**
 * Uploads product images to Basalam once and remembers the Basalam file ID.
 *
 * Before upload an image that is too large (pixels or bytes) or in a format Basalam may
 * not accept (WebP/AVIF/GIF/BMP) is converted on a temporary COPY: resized, re-compressed
 * and saved as JPEG/PNG. The site's original file is never modified, and the copy is
 * deleted right after the upload.
 *
 * An image is uploaded again only when the file itself or the processing limits change.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are never printed raw: they are stored in the log and escaped where shown.

class BSH_Image_Sync {

	const META_ID  = '_bsh_basalam_file_id';
	const META_SIG = '_bsh_basalam_file_sig';

	/** @var BSH_Api_Client */
	private $api;

	/**
	 * @param BSH_Api_Client $api Client.
	 */
	public function __construct( BSH_Api_Client $api ) {
		$this->api = $api;
	}

	/**
	 * Processing limits. Basalam doesn't publish exact numbers in its API spec; these are
	 * conservative values that keep photos sharp while uploads stay fast on weak hosts.
	 *
	 * @return array{max_side:int, max_bytes:int, quality:int, max_images:int, formats:string[]}
	 */
	public static function limits() {
		return wp_parse_args(
			(array) apply_filters( 'bsh_image_limits', array() ),
			array(
				'max_side'   => 2048,
				'max_bytes'  => 2 * MB_IN_BYTES,
				'quality'    => 82,
				'max_images' => 10,
				'formats'    => array( 'image/jpeg', 'image/png' ),
			)
		);
	}

	/**
	 * @param int[] $attachment_ids Attachments, first is the main photo.
	 * @return int[] Basalam file IDs in the same order (failed gallery images are skipped).
	 * @throws BSH_Api_Error When the main image cannot be uploaded.
	 */
	public function ensure_uploaded( array $attachment_ids ) {
		$out    = array();
		$limits = self::limits();
		foreach ( array_slice( array_values( $attachment_ids ), 0, $limits['max_images'] ) as $i => $attachment_id ) {
			try {
				$file_id = $this->ensure_one( (int) $attachment_id );
				if ( $file_id ) {
					$out[] = $file_id;
				}
			} catch ( BSH_Api_Error $e ) {
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
	 * @throws BSH_Api_Error On upload failure.
	 */
	private function ensure_one( $attachment_id ) {
		$path = self::original_path( $attachment_id );
		if ( ! $path ) {
			return 0;
		}
		$sig      = self::signature( $path );
		$existing = (int) get_post_meta( $attachment_id, self::META_ID, true );
		if ( $existing && get_post_meta( $attachment_id, self::META_SIG, true ) === $sig ) {
			return $existing;
		}

		$prepared = $this->prepare( $path, $attachment_id );
		try {
			/**
			 * Last chance to replace the file that will be uploaded.
			 *
			 * @param string $path          File to upload.
			 * @param int    $attachment_id Attachment ID.
			 */
			$upload_path = apply_filters( 'bsh_image_upload_path', $prepared['path'], $attachment_id );
			$file        = $this->api->upload_file( $upload_path, 'product.photo' );
		} finally {
			if ( $prepared['temp'] && file_exists( $prepared['path'] ) ) {
				wp_delete_file( $prepared['path'] );
			}
		}

		if ( empty( $file['id'] ) ) {
			throw new BSH_Api_Error(
				__( 'آپلود تصویر در باسلام کامل نشد.', 'basalamhub' ),
				'server',
				array(
					'retryable'  => true,
					'reason'     => __( 'باسلام شناسه‌ی فایل را برنگرداند.', 'basalamhub' ),
					'suggestion' => __( 'لازم نیست کاری کنی؛ خودکار دوباره تلاش می‌شود.', 'basalamhub' ),
					'details'    => array( 'response' => $file ),
				)
			);
		}
		update_post_meta( $attachment_id, self::META_ID, (int) $file['id'] );
		update_post_meta( $attachment_id, self::META_SIG, $sig );
		return (int) $file['id'];
	}

	/**
	 * The full-size original, even when WordPress made a "-scaled" copy for big uploads.
	 *
	 * @param int $attachment_id Attachment.
	 * @return string|false
	 */
	private static function original_path( $attachment_id ) {
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment_id ) : false;
		if ( ! $path || ! file_exists( $path ) ) {
			$path = get_attached_file( $attachment_id );
		}
		return $path && file_exists( $path ) ? $path : false;
	}

	/**
	 * Returns a file that fits the limits: the original when it already fits, otherwise a
	 * processed temporary copy.
	 *
	 * @param string $path          Original file.
	 * @param int    $attachment_id Attachment (for naming).
	 * @return array{path:string, temp:bool}
	 */
	public function prepare( $path, $attachment_id = 0 ) {
		$limits = self::limits();
		$info   = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are handled below.
		$mime   = $info && ! empty( $info['mime'] ) ? $info['mime'] : '';
		$fits   = $info
			&& in_array( $mime, $limits['formats'], true )
			&& max( (int) $info[0], (int) $info[1] ) <= $limits['max_side']
			&& filesize( $path ) <= $limits['max_bytes'];
		if ( $fits || ! $info ) {
			return array(
				'path' => $path,
				'temp' => false,
			);
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			// No GD/Imagick: upload the original and let Basalam decide.
			return array(
				'path' => $path,
				'temp' => false,
			);
		}

		$size = $editor->get_size();
		if ( max( $size['width'], $size['height'] ) > $limits['max_side'] ) {
			$editor->resize( $limits['max_side'], $limits['max_side'], false );
		}

		// Keep PNG (it may have transparency); everything else becomes JPEG.
		$out_mime = 'image/png' === $mime ? 'image/png' : 'image/jpeg';
		$ext      = 'image/png' === $out_mime ? 'png' : 'jpg';
		$dir      = self::temp_dir();
		$target   = trailingslashit( $dir ) . 'bsh-' . (int) $attachment_id . '-' . wp_generate_password( 8, false ) . '.' . $ext;

		foreach ( array( $limits['quality'], 72, 62, 50 ) as $quality ) {
			$editor->set_quality( $quality );
			$saved = $editor->save( $target, $out_mime );
			if ( is_wp_error( $saved ) ) {
				return array(
					'path' => $path,
					'temp' => false,
				);
			}
			$target = $saved['path'];
			clearstatcache( true, $target );
			if ( filesize( $target ) <= $limits['max_bytes'] || 'image/png' === $out_mime ) {
				break;
			}
		}

		// A PNG that is still too big after resizing becomes a JPEG.
		if ( 'image/png' === $out_mime && filesize( $target ) > $limits['max_bytes'] ) {
			wp_delete_file( $target );
			$editor->set_quality( 72 );
			$saved = $editor->save( preg_replace( '/\.png$/', '.jpg', $target ), 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				return array(
					'path' => $path,
					'temp' => false,
				);
			}
			$target = $saved['path'];
		}
		return array(
			'path' => $target,
			'temp' => true,
		);
	}

	/**
	 * Private temp folder inside uploads (shared hosts often block the system temp dir).
	 *
	 * @return string
	 */
	private static function temp_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'basalamhub-tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Not browsable; files live only for the seconds of an upload.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Identity of the file plus the limits it was processed with.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public static function signature( $path ) {
		$size  = file_exists( $path ) ? filesize( $path ) : 0;
		$mtime = file_exists( $path ) ? filemtime( $path ) : 0;
		return md5( $path . '|' . $size . '|' . $mtime . '|' . wp_json_encode( self::limits() ) );
	}
}
