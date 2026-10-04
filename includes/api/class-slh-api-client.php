<?php
/**
 * Minimal Basalam Open API client built on the WordPress HTTP API.
 *
 * Endpoints and payload shapes follow Basalam's official OpenAPI spec (the same one the
 * official SDKs are generated from). We deliberately don't bundle the official PHP SDK:
 * it requires Guzzle, and two plugins shipping different Guzzle versions is a classic
 * cause of fatal errors on WordPress sites.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Api_Client {

	/** @var string|null */
	private $token;

	/** @var string */
	private $base;

	/** @var int */
	private $timeout;

	/**
	 * @param string|null $token   Access token (null = read from settings).
	 * @param int         $timeout Seconds.
	 */
	public function __construct( $token = null, $timeout = 30 ) {
		$this->token   = $token;
		$this->base    = untrailingslashit( apply_filters( 'slh_api_base', SLH_API_BASE ) );
		$this->timeout = $timeout;
	}

	/**
	 * @return string
	 * @throws SLH_Api_Error When no usable token exists.
	 */
	private function token() {
		$token = null !== $this->token ? $this->token : SLH_Settings::get_token();
		if ( null === $token ) {
			throw new SLH_Api_Error(
				__( 'توکن ذخیره‌شده قابل خواندن نیست.', 'salamhub' ),
				'auth',
				array(
					'reason'     => __( 'کلیدهای امنیتی وردپرس (wp-config.php) عوض شده‌اند و توکن رمزنگاری‌شده دیگر باز نمی‌شود.', 'salamhub' ),
					'suggestion' => __( 'توکن باسلام را دوباره در سلام‌هاب › تنظیمات وارد کن.', 'salamhub' ),
				)
			);
		}
		if ( '' === $token ) {
			throw new SLH_Api_Error(
				__( 'هنوز به باسلام وصل نشده‌ای.', 'salamhub' ),
				'auth',
				array(
					'reason'     => __( 'توکن دسترسی باسلام وارد نشده است.', 'salamhub' ),
					'suggestion' => __( 'در سلام‌هاب › تنظیمات توکن را وارد کن و «تست اتصال» را بزن.', 'salamhub' ),
				)
			);
		}
		return $token;
	}

	/**
	 * Sends a request and returns decoded JSON.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path starting with /v1/….
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query args (arrays become repeated keys: a=1&a=2).
	 * @return array
	 * @throws SLH_Api_Error On any failure.
	 */
	public function request( $method, $path, $body = null, array $query = array() ) {
		$url  = $this->base . $path . self::build_query( $query );
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token(),
				'Accept'        => 'application/json',
				'User-Agent'    => 'SalamHub/' . SLH_VERSION . '; WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		return $this->send( $url, $args, $method, $path );
	}

	/**
	 * @param string $url    URL.
	 * @param array  $args   wp_remote_request args.
	 * @param string $method Method (for errors).
	 * @param string $path   Path (for errors).
	 * @return array
	 * @throws SLH_Api_Error On failure.
	 */
	private function send( $url, array $args, $method, $path ) {
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw SLH_Api_Error::from_response( $response, $method, $path );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw SLH_Api_Error::from_response( $response, $method, $path );
		}
		$raw = wp_remote_retrieve_body( $response );
		if ( '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new SLH_Api_Error(
				__( 'پاسخ باسلام قابل خواندن نبود.', 'salamhub' ),
				'server',
				array(
					'retryable'  => true,
					'reason'     => __( 'باسلام به‌جای داده‌ی معتبر، پاسخ ناقص یا صفحه‌ی HTML برگرداند.', 'salamhub' ),
					'suggestion' => __( 'لازم نیست کاری کنی؛ خودکار دوباره تلاش می‌شود.', 'salamhub' ),
					'details'    => array( 'request' => $method . ' ' . $path, 'status' => $code, 'body' => mb_substr( $raw, 0, 1000 ) ),
				)
			);
		}
		return $data;
	}

	/**
	 * @param array $query Query args.
	 * @return string
	 */
	private static function build_query( array $query ) {
		$parts = array();
		foreach ( $query as $key => $value ) {
			foreach ( (array) $value as $v ) {
				if ( null === $v || '' === $v ) {
					continue;
				}
				$parts[] = rawurlencode( $key ) . '=' . rawurlencode( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v );
			}
		}
		return $parts ? '?' . implode( '&', $parts ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * GET /v1/users/me — the token owner, including their booth (vendor).
	 *
	 * @return array
	 */
	public function me() {
		return $this->request( 'GET', '/v1/users/me' );
	}

	/**
	 * POST /v1/vendors/{vendor_id}/products
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $payload   CreateProductSchema.
	 * @return array ReadProductResponse.
	 */
	public function create_product( $vendor_id, array $payload ) {
		return $this->request( 'POST', '/v1/vendors/' . (int) $vendor_id . '/products', $payload );
	}

	/**
	 * PATCH /v1/products/{product_id}
	 *
	 * @param int   $product_id Basalam product.
	 * @param array $payload    PatchUpdateProductSchema (only changed fields).
	 * @return array
	 */
	public function update_product( $product_id, array $payload ) {
		return $this->request( 'PATCH', '/v1/products/' . (int) $product_id, $payload );
	}

	/**
	 * GET /v1/products/{product_id}
	 *
	 * @param int $product_id Basalam product.
	 * @return array
	 */
	public function get_product( $product_id ) {
		return $this->request( 'GET', '/v1/products/' . (int) $product_id );
	}

	/**
	 * GET /v1/vendors/{vendor_id}/products filtered by SKU — used to recover from a
	 * create request whose response was lost (timeout) without creating a duplicate.
	 *
	 * @param int      $vendor_id Vendor.
	 * @param string[] $skus      SKUs.
	 * @return array[] Products.
	 */
	public function find_products_by_sku( $vendor_id, array $skus ) {
		$res = $this->request( 'GET', '/v1/vendors/' . (int) $vendor_id . '/products', null, array( 'skus' => $skus, 'per_page' => 10 ) );
		return isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
	}

	/**
	 * GET /v1/categories — the whole Basalam category tree.
	 *
	 * @return array[] Root categories with nested `children`.
	 */
	public function categories() {
		$res = $this->request( 'GET', '/v1/categories' );
		return isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
	}

	/**
	 * GET /v1/categories/{id}/attributes — attribute groups of a category (with `required`).
	 *
	 * @param int $category_id Basalam category.
	 * @return array[] Attribute groups.
	 */
	public function category_attributes( $category_id ) {
		$res = $this->request( 'GET', '/v1/categories/' . (int) $category_id . '/attributes', null, array( 'exclude_multi_selects' => true ) );
		return isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
	}

	/**
	 * POST /v1/files (multipart) — uploads a product photo, returns the file record with `id`.
	 *
	 * @param string $path      Local file path.
	 * @param string $file_type Basalam file type.
	 * @return array
	 * @throws SLH_Api_Error On failure.
	 */
	public function upload_file( $path, $file_type = 'product.photo' ) {
		if ( ! is_readable( $path ) ) {
			throw new SLH_Api_Error(
				__( 'فایل تصویر روی سرور پیدا نشد.', 'salamhub' ),
				'validation',
				array(
					'reason'     => __( 'تصویر در کتابخانه‌ی رسانه ثبت شده ولی فایلش روی هاست نیست.', 'salamhub' ),
					'suggestion' => __( 'تصویر را دوباره در محصول بارگذاری کن.', 'salamhub' ),
					'details'    => array( 'path' => basename( $path ) ),
				)
			);
		}
		$boundary = 'slh' . wp_generate_password( 24, false );
		$mime     = wp_check_filetype( $path );
		$mime     = $mime['type'] ? $mime['type'] : 'application/octet-stream';
		$eol      = "\r\n";
		$body     = '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="file_type"' . $eol . $eol
			. $file_type . $eol
			. '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="file"; filename="' . sanitize_file_name( basename( $path ) ) . '"' . $eol
			. 'Content-Type: ' . $mime . $eol . $eol
			. file_get_contents( $path ) . $eol // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			. '--' . $boundary . '--' . $eol;

		$args = array(
			'method'  => 'POST',
			'timeout' => max( 60, $this->timeout ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token(),
				'Accept'        => 'application/json',
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				'User-Agent'    => 'SalamHub/' . SLH_VERSION,
			),
			'body'    => $body,
		);
		return $this->send( $this->base . '/v1/files', $args, 'POST', '/v1/files' );
	}
}
