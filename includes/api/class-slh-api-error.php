<?php
/**
 * An API failure translated into plain Persian: what happened, why, what to do.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Api_Error extends Exception {

	/** @var int HTTP status, 0 for network failures. */
	public $http_status = 0;

	/** @var bool Whether trying again later can succeed on its own. */
	public $retryable = false;

	/** @var int Seconds the server asked us to wait (429). */
	public $retry_after = 0;

	/** @var string Persian "why". */
	public $reason = '';

	/** @var string Persian "what to do". */
	public $suggestion = '';

	/** @var array Raw technical details for the expandable log section. */
	public $details = array();

	/** @var string Short machine code: network|auth|forbidden|not_found|validation|rate_limit|server|unknown. */
	public $kind = 'unknown';

	/** Persian names for fields Basalam may reject. */
	const FIELD_LABELS = array(
		'name'                 => 'نام محصول',
		'category_id'          => 'دسته‌بندی',
		'status'               => 'وضعیت انتشار',
		'preparation_days'     => 'زمان آماده‌سازی',
		'package_weight'       => 'وزن با بسته‌بندی',
		'weight'               => 'وزن خالص',
		'primary_price'        => 'قیمت',
		'stock'                => 'موجودی',
		'photo'                => 'تصویر اصلی',
		'photos'               => 'تصاویر',
		'description'          => 'توضیحات',
		'brief'                => 'توضیح کوتاه',
		'sku'                  => 'کد محصول (SKU)',
		'packaging_dimensions' => 'ابعاد بسته',
		'file'                 => 'فایل تصویر',
		'variants'             => 'تنوع‌ها',
		'product_attribute'    => 'ویژگی‌های دسته',
		'attributes'           => 'ویژگی‌های دسته',
	);

	/**
	 * @param string $message    Persian "what happened".
	 * @param string $kind       Machine code.
	 * @param array  $props      Other properties.
	 */
	public function __construct( $message, $kind = 'unknown', array $props = array() ) {
		parent::__construct( $message );
		$this->kind = $kind;
		foreach ( $props as $k => $v ) {
			if ( property_exists( $this, $k ) ) {
				$this->$k = $v;
			}
		}
	}

	/**
	 * Builds an error from a wp_remote_request() result.
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $method   HTTP method.
	 * @param string         $path     Path.
	 * @return self
	 */
	public static function from_response( $response, $method, $path ) {
		if ( is_wp_error( $response ) ) {
			$raw = $response->get_error_message();
			$is_timeout = false !== stripos( $raw, 'timed out' ) || false !== stripos( $raw, 'timeout' );
			return new self(
				__( 'ارتباط با باسلام برقرار نشد.', 'salamhub' ),
				'network',
				array(
					'retryable'  => true,
					'reason'     => $is_timeout
						? __( 'باسلام در زمان مقرر جواب نداد (Timeout).', 'salamhub' )
						: __( 'سرور سایتت نتوانست به سرور باسلام وصل شود.', 'salamhub' ),
					'suggestion' => __( 'لازم نیست کاری کنی؛ چند دقیقه‌ی بعد خودکار دوباره تلاش می‌شود. اگر بارها تکرار شد، از پشتیبانی هاستت بپرس دسترسی خروجی به openapi.basalam.com باز است یا نه.', 'salamhub' ),
					'details'    => array( 'request' => $method . ' ' . $path, 'error' => $raw ),
				)
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$details = array(
			'request' => $method . ' ' . $path,
			'status'  => $code,
			'body'    => is_array( $decoded ) ? $decoded : mb_substr( (string) $body, 0, 2000 ),
		);
		$server_msg = self::extract_message( $decoded );

		if ( 401 === $code ) {
			return new self(
				__( 'باسلام توکن را نپذیرفت.', 'salamhub' ),
				'auth',
				array(
					'http_status' => $code,
					'reason'      => __( 'توکن دسترسی منقضی شده، باطل شده یا اشتباه کپی شده است.', 'salamhub' ),
					'suggestion'  => __( 'از پنل توسعه‌دهندگان باسلام یک توکن تازه بساز و در باسلام‌هاب › تنظیمات وارد کن.', 'salamhub' ),
					'details'     => $details,
				)
			);
		}
		if ( 403 === $code ) {
			return new self(
				__( 'باسلام اجازه‌ی این کار را نداد.', 'salamhub' ),
				'forbidden',
				array(
					'http_status' => $code,
					'reason'      => __( 'توکن فعلی دسترسی لازم (مثلاً مدیریت محصولات غرفه) را ندارد، یا غرفه غیرفعال است.', 'salamhub' ),
					'suggestion'  => __( 'هنگام ساخت توکن، دسترسی‌های «محصولات غرفه» را هم تیک بزن و توکن جدید را وارد کن. اگر غرفه بسته است، اول آن را در باسلام فعال کن.', 'salamhub' ),
					'details'     => $details,
				)
			);
		}
		if ( 404 === $code ) {
			return new self(
				__( 'مورد خواسته‌شده در باسلام پیدا نشد.', 'salamhub' ),
				'not_found',
				array(
					'http_status' => $code,
					'reason'      => __( 'احتمالاً این مورد در باسلام حذف شده است.', 'salamhub' ),
					'suggestion'  => __( 'اگر محصول را در باسلام حذف کرده‌ای، «تلاش مجدد» آن را از نو می‌سازد.', 'salamhub' ),
					'details'     => $details,
				)
			);
		}
		if ( 422 === $code || 400 === $code ) {
			$fields = self::validation_fields( $decoded );
			$reason = $fields
				/* translators: %s: comma separated list of field names */
				? sprintf( __( 'این فیلدها را نپذیرفت: %s.', 'salamhub' ), implode( '، ', $fields ) )
				: ( $server_msg ? $server_msg : __( 'اطلاعات ارسالی با قوانین باسلام جور نبود.', 'salamhub' ) );
			return new self(
				__( 'باسلام اطلاعات محصول را نپذیرفت.', 'salamhub' ),
				'validation',
				array(
					'http_status' => $code,
					'reason'      => $reason,
					'suggestion'  => __( 'همین فیلدها را در صفحه‌ی ویرایش محصول درست کن و ذخیره کن؛ دوباره خودکار ارسال می‌شود. جزئیات فنی پایین همین ردیف است.', 'salamhub' ),
					'details'     => $details,
				)
			);
		}
		if ( 429 === $code ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return new self(
				__( 'باسلام موقتاً درخواست‌ها را محدود کرد.', 'salamhub' ),
				'rate_limit',
				array(
					'http_status' => $code,
					'retryable'   => true,
					'retry_after' => $retry_after > 0 ? $retry_after : 60,
					'reason'      => __( 'تعداد درخواست‌ها در زمان کوتاه از سقف مجاز باسلام بیشتر شد.', 'salamhub' ),
					'suggestion'  => __( 'لازم نیست کاری کنی؛ صف کمی صبر می‌کند و ادامه می‌دهد.', 'salamhub' ),
					'details'     => $details,
				)
			);
		}
		if ( $code >= 500 ) {
			return new self(
				__( 'سرور باسلام خطا داد.', 'salamhub' ),
				'server',
				array(
					'http_status' => $code,
					'retryable'   => true,
					'reason'      => __( 'مشکل از سمت باسلام است، نه سایت تو.', 'salamhub' ),
					'suggestion'  => __( 'لازم نیست کاری کنی؛ چند دقیقه‌ی بعد خودکار دوباره تلاش می‌شود.', 'salamhub' ),
					'details'     => $details,
				)
			);
		}
		return new self(
			/* translators: %d: HTTP status code */
			sprintf( __( 'باسلام پاسخ غیرمنتظره داد (کد %d).', 'salamhub' ), $code ),
			'unknown',
			array(
				'http_status' => $code,
				'reason'      => $server_msg ? $server_msg : __( 'پاسخ باسلام قابل تشخیص نبود.', 'salamhub' ),
				'suggestion'  => __( 'یک بار «تلاش مجدد» بزن. اگر تکرار شد، جزئیات فنی همین ردیف را برای پشتیبانی باسلام‌هاب بفرست.', 'salamhub' ),
				'details'     => $details,
			)
		);
	}

	/**
	 * Pulls a human message from typical error bodies ({"message":…}, {"detail":"…"}).
	 *
	 * @param mixed $decoded Decoded JSON.
	 * @return string
	 */
	private static function extract_message( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		foreach ( array( 'message', 'detail', 'error', 'error_description' ) as $key ) {
			if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
				return mb_substr( $decoded[ $key ], 0, 300 );
			}
		}
		if ( isset( $decoded['messages'][0]['message'] ) && is_string( $decoded['messages'][0]['message'] ) ) {
			return mb_substr( $decoded['messages'][0]['message'], 0, 300 );
		}
		return '';
	}

	/**
	 * Maps FastAPI-style validation errors ({"detail":[{"loc":["body","primary_price"],"msg":"…"}]})
	 * to Persian field names.
	 *
	 * @param mixed $decoded Decoded JSON.
	 * @return string[]
	 */
	private static function validation_fields( $decoded ) {
		$out = array();
		if ( ! is_array( $decoded ) ) {
			return $out;
		}
		$items = array();
		if ( isset( $decoded['detail'] ) && is_array( $decoded['detail'] ) ) {
			$items = $decoded['detail'];
		} elseif ( isset( $decoded['messages'] ) && is_array( $decoded['messages'] ) ) {
			$items = $decoded['messages'];
		}
		foreach ( $items as $item ) {
			$field = '';
			if ( isset( $item['loc'] ) && is_array( $item['loc'] ) ) {
				$loc   = array_values( array_filter( $item['loc'], function ( $p ) {
					return 'body' !== $p && ! is_int( $p );
				} ) );
				$field = $loc ? (string) $loc[0] : '';
			} elseif ( isset( $item['fields'] ) && is_array( $item['fields'] ) ) {
				$field = (string) reset( $item['fields'] );
			} elseif ( isset( $item['field'] ) ) {
				$field = (string) $item['field'];
			}
			if ( '' === $field ) {
				continue;
			}
			$label = isset( self::FIELD_LABELS[ $field ] ) ? self::FIELD_LABELS[ $field ] : $field;
			$out[ $label ] = $label;
		}
		return array_values( $out );
	}

	/**
	 * @return array Log-ready fields.
	 */
	public function to_log() {
		return array(
			'message'    => $this->getMessage(),
			'reason'     => $this->reason,
			'suggestion' => $this->suggestion,
			'context'    => $this->details,
		);
	}
}
