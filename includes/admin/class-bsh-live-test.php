<?php
/**
 * «تست با باسلام واقعی»: runs the plugin's own code paths against the real Basalam API,
 * step by step, and produces a plain-text report the shop owner can copy to the developer.
 *
 * Steps (only the last three change anything, and each asks first):
 *   read    — connection, booth, categories, attributes, booth products, orders (read-only)
 *   upload  — uploads one small test image (not attached to any product)
 *   product — sends one chosen product through the normal sync and reads it back
 *   hide    — sets that product's stock on Basalam to 0
 *   orders  — imports recent Basalam orders into WooCommerce right now
 *
 * The report never contains the token; customer names and phone numbers are masked.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Live_Test {

	/**
	 * @var array<int,array{0:string,1:string,2:string}> [state ok|warn|fail|info, label, detail]
	 */
	private $lines = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_bsh_live_test', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Page callback.
	 */
	public static function page() {
		BSH_App::render( 'live-test', 'basalamhub-livetest' );
	}

	/**
	 * Submenu entry.
	 */
	public static function add_page() {
		add_submenu_page( 'basalamhub', __( 'تست با باسلام واقعی', 'basalamhub' ), __( 'تست واقعی', 'basalamhub' ), BSH_Admin::CAP, 'basalamhub-livetest', array( __CLASS__, 'page' ) );
	}

	/**
	 * Runs one step.
	 */
	public static function ajax() {
		if ( ! current_user_can( BSH_Admin::CAP ) || ! check_ajax_referer( 'bsh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'basalamhub' ) ), 403 );
		}
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$pid  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! in_array( $step, array( 'read', 'upload', 'product', 'hide', 'orders' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'مرحله‌ی نامعتبر.', 'basalamhub' ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a few sequential API calls.
		}
		$run = new self();
		$run->{ 'step_' . $step }( $pid );
		wp_send_json_success( $run->result( $step ) );
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------ */

	/**
	 * Read-only checks.
	 */
	public function step_read() {
		if ( ! BSH_Settings::has_token() ) {
			$this->add( 'fail', __( 'توکن', 'basalamhub' ), __( 'توکنی وارد نشده؛ اول در تنظیمات توکن را بچسبان.', 'basalamhub' ) );
			return;
		}
		$api = BSH_Plugin::api();

		$me = $this->call(
			__( 'اتصال و اعتبار توکن (GET /v1/users/me)', 'basalamhub' ),
			function () use ( $api ) {
				return $api->me();
			}
		);
		if ( null === $me ) {
			return;
		}
		$vendor = isset( $me['vendor'] ) && is_array( $me['vendor'] ) ? $me['vendor'] : null;
		if ( ! $vendor || empty( $vendor['id'] ) ) {
			$this->add( 'fail', __( 'غرفه', 'basalamhub' ), __( 'توکن معتبر است ولی این حساب غرفه ندارد (vendor خالی است).', 'basalamhub' ) );
			return;
		}
		/* translators: 1: booth title, 2: booth id */
		$this->add( 'ok', __( 'غرفه', 'basalamhub' ), sprintf( __( '«%1$s» با شناسه‌ی %2$s', 'basalamhub' ), isset( $vendor['title'] ) ? $vendor['title'] : '', $vendor['id'] ) );
		$this->fields( __( 'فیلدهای users/me', 'basalamhub' ), $me, array( 'id', 'name', 'vendor.id', 'vendor.title', 'vendor.identifier' ) );
		BSH_Admin::test_connection();
		$vid = (int) $vendor['id'];

		$cats = $this->call(
			__( 'دسته‌بندی‌های باسلام (GET /v1/categories)', 'basalamhub' ),
			function () use ( $api ) {
				return $api->categories();
			},
			function ( $r ) {
				/* translators: %s: count */
				return sprintf( __( '%s دسته‌ی سطح اول', 'basalamhub' ), count( $r ) );
			}
		);
		if ( $cats ) {
			$this->fields( __( 'فیلدهای دسته', 'basalamhub' ), $cats[0], array( 'id', 'title', 'children' ) );
			$leaf = $this->first_leaf( $cats );
			if ( $leaf ) {
				$this->call(
					/* translators: %s: category title */
					sprintf( __( 'ویژگی‌های دسته‌ی «%s»', 'basalamhub' ), $leaf['title'] ),
					function () use ( $api, $leaf ) {
						return $api->category_attributes( (int) $leaf['id'] );
					},
					function ( $r ) {
						/* translators: %s: count */
						return sprintf( __( '%s گروه ویژگی', 'basalamhub' ), count( $r ) );
					}
				);
			}
		}

		$products = $this->call(
			__( 'محصولات غرفه (GET /v1/vendors/{id}/products)', 'basalamhub' ),
			function () use ( $api, $vid ) {
				return $api->vendor_products( $vid, 1, 10 );
			},
			function ( $r ) {
				/* translators: %s: count */
				return sprintf( __( '%s محصول در کل غرفه', 'basalamhub' ), null !== $r['total_count'] ? $r['total_count'] : count( $r['data'] ) );
			}
		);
		if ( $products && $products['data'] ) {
			$first = $products['data'][0];
			$this->fields( __( 'فیلدهای فهرست محصولات', 'basalamhub' ), $first, array( 'id', 'title|name', 'price|primary_price', 'photo' ) );
			if ( ! empty( $first['id'] ) ) {
				$one = $this->call(
					__( 'جزئیات یک محصول (GET /v1/products/{id})', 'basalamhub' ),
					function () use ( $api, $first ) {
						return $api->get_product( (int) $first['id'] );
					}
				);
				if ( $one ) {
					$this->fields( __( 'فیلدهای جزئیات محصول (برای ایمپورت و موجودی)', 'basalamhub' ), $one, array( 'id', 'title|name', 'price|primary_price', 'inventory|stock', 'photo', 'photos', 'category.id|category_id', 'net_weight|weight', 'packaged_weight|package_weight', 'preparation_day|preparation_days', 'description', 'summary|brief', 'sku' ) );
				}
			}
		} elseif ( $products ) {
			$this->add( 'info', __( 'محصولات غرفه', 'basalamhub' ), __( 'غرفه هنوز محصولی ندارد؛ بررسی فیلدهای محصول بعد از مرحله‌ی ۳ ممکن است.', 'basalamhub' ) );
		}

		$parcels = $this->call(
			__( 'سفارش‌های غرفه (GET /v1/vendor-parcels)', 'basalamhub' ),
			function () use ( $api ) {
				return $api->vendor_parcels( array( 'per_page' => 5 ) );
			},
			function ( $r ) {
				/* translators: %s: count */
				return sprintf( __( '%s سفارش در صفحه‌ی اول', 'basalamhub' ), count( $r['data'] ) );
			}
		);
		if ( $parcels && $parcels['data'] ) {
			$p = $parcels['data'][0];
			$this->fields( __( 'فیلدهای فهرست سفارش', 'basalamhub' ), $p, array( 'id', 'status.id', 'status.title', 'created_at' ) );
			$detail = $this->call(
				__( 'جزئیات یک سفارش (GET /v1/vendor-parcels/{id})', 'basalamhub' ),
				function () use ( $api, $p ) {
					return $api->get_parcel( (int) $p['id'] );
				}
			);
			if ( $detail ) {
				$this->fields(
					__( 'فیلدهای جزئیات سفارش (برای ثبت در ووکامرس)', 'basalamhub' ),
					$detail,
					array( 'id', 'status.id', 'order.id', 'order.created_at', 'order.paid_at', 'order.customer.recipient.name', 'order.customer.recipient.mobile', 'order.customer.recipient.postal_address', 'order.customer.recipient.postal_code', 'order.customer.city.title', 'order.customer.city.parent.title', 'items', 'shipping_cost', 'total_items_price', 'shipping_method.current' )
				);
				if ( ! empty( $detail['items'][0] ) ) {
					$this->fields( __( 'فیلدهای قلم سفارش', 'basalamhub' ), $detail['items'][0], array( 'title', 'price', 'quantity', 'product.id', 'variation' ) );
				}
			}
		} elseif ( $parcels ) {
			$this->add( 'info', __( 'سفارش‌ها', 'basalamhub' ), __( 'غرفه هنوز سفارشی ندارد؛ برای تست سفارش، یک خرید آزمایشی از غرفه انجام بده.', 'basalamhub' ) );
		}
	}

	/**
	 * Uploads one generated test image.
	 */
	public function step_upload() {
		$path = $this->test_image();
		if ( ! $path ) {
			$this->add( 'fail', __( 'ساخت تصویر آزمایشی', 'basalamhub' ), __( 'کتابخانه‌ی GD روی هاست نیست؛ این مرحله را با ارسال یک محصول عکس‌دار (مرحله‌ی ۳) تست کن.', 'basalamhub' ) );
			return;
		}
		$file = $this->call(
			__( 'آپلود تصویر (POST /v1/files، product.photo)', 'basalamhub' ),
			function () use ( $path ) {
				return BSH_Plugin::api()->upload_file( $path );
			},
			function ( $r ) {
				/* translators: %s: file id */
				return sprintf( __( 'شناسه‌ی فایل: %s', 'basalamhub' ), isset( $r['id'] ) ? $r['id'] : '?' );
			}
		);
		wp_delete_file( $path );
		if ( $file ) {
			$this->fields( __( 'فیلدهای پاسخ آپلود', 'basalamhub' ), $file, array( 'id', 'file_name', 'mime_type', 'size' ) );
		}
	}

	/**
	 * Sends one product through the normal sync and reads it back.
	 *
	 * @param int $pid Product.
	 */
	public function step_product( $pid ) {
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			$this->add( 'fail', __( 'محصول', 'basalamhub' ), __( 'یک محصول انتخاب کن.', 'basalamhub' ) );
			return;
		}
		$before = BSH_Links::get( 'product', $pid );
		set_transient( 'bsh_force_product_' . $pid, 1, HOUR_IN_SECONDS );
		$t       = microtime( true );
		$outcome = ( new BSH_Product_Sync( BSH_Plugin::api() ) )->sync( $pid );
		$ms      = (int) round( 1000 * ( microtime( true ) - $t ) );
		$link    = BSH_Links::get( 'product', $pid );
		$log     = BSH_Logger::query(
			array(
				'object_type' => 'product',
				'object_id'   => $pid,
				'per_page'    => 1,
			)
		)['items'];
		$log     = $log ? $log[0] : null;
		$ok      = in_array( $outcome, array( 'created', 'updated', 'unchanged' ), true );
		$detail  = $outcome . ( $log ? ' — ' . $log->message . ( $log->reason ? ' | ' . $log->reason : '' ) : '' );
		/* translators: %s: product name */
		$this->add( $ok ? 'ok' : 'fail', sprintf( __( 'ارسال «%s» با مسیر عادی افزونه', 'basalamhub' ), $product->get_name() ), $detail . ' (' . $ms . 'ms)' );
		if ( ! $ok ) {
			if ( $log && $log->context ) {
				$this->add( 'info', __( 'جزئیات خطا', 'basalamhub' ), mb_substr( (string) $log->context, 0, 1500 ) );
			}
			return;
		}
		$this->add( 'info', __( 'نوع ارسال', 'basalamhub' ), ( $before && $before->basalam_id ) ? __( 'به‌روزرسانی محصول موجود', 'basalamhub' ) : __( 'ساخت محصول جدید', 'basalamhub' ) );
		if ( ! $link || ! $link->basalam_id ) {
			$this->add( 'fail', __( 'شناسه‌ی باسلام', 'basalamhub' ), __( 'ارسال موفق گزارش شد ولی شناسه‌ی محصول باسلام ذخیره نشد.', 'basalamhub' ) );
			return;
		}
		$bid    = (int) $link->basalam_id;
		$remote = $this->call(
			/* translators: %s: Basalam product id */
			sprintf( __( 'خواندن دوباره از باسلام (محصول %s)', 'basalamhub' ), $bid ),
			function () use ( $bid ) {
				return BSH_Plugin::api()->get_product( $bid );
			}
		);
		if ( ! $remote ) {
			return;
		}
		$this->compare( __( 'نام', 'basalamhub' ), $product->get_name(), isset( $remote['title'] ) ? $remote['title'] : ( isset( $remote['name'] ) ? $remote['name'] : null ) );
		$price = isset( $remote['primary_price'] ) ? $remote['primary_price'] : ( isset( $remote['price'] ) ? $remote['price'] : null );
		if ( $product->is_type( 'variable' ) ) {
			/* translators: %s: price */
			$this->add( 'info', __( 'قیمت', 'basalamhub' ), sprintf( __( 'قیمت پایه در باسلام: %s (محصول متغیر؛ قیمت هر تنوع جداست)', 'basalamhub' ), (string) $price ) );
		} else {
			// Same calculation as the mapper: currency → rial, then price rules (bsh_basalam_price).
			$expect = (int) apply_filters( 'bsh_basalam_price', (int) round( (float) $product->get_price() * BSH_Product_Mapper::rial_multiplier() ), $product );
			$this->compare( __( 'قیمت (ریال، بعد از قوانین قیمت)', 'basalamhub' ), (string) $expect, null === $price ? null : (string) $price, __( 'اگر ۱۰ برابر است، واحد پول سایت (تومان/ریال) را بررسی کن.', 'basalamhub' ) );
		}
		if ( $product->managing_stock() && ! $product->is_type( 'variable' ) ) {
			$this->compare( __( 'موجودی (بعد از موجودی اطمینان)', 'basalamhub' ), (string) apply_filters( 'bsh_basalam_stock', (int) $product->get_stock_quantity(), $product ), isset( $remote['inventory'] ) ? (string) $remote['inventory'] : ( isset( $remote['stock'] ) ? (string) $remote['stock'] : null ) );
		}
		$this->add( ! empty( $remote['photo'] ) ? 'ok' : 'warn', __( 'تصویر اصلی', 'basalamhub' ), ! empty( $remote['photo'] ) ? __( 'روی باسلام هست', 'basalamhub' ) : __( 'در پاسخ باسلام نیست', 'basalamhub' ) );
		if ( $product->is_type( 'variable' ) ) {
			$local  = count( $product->get_children() );
			$remote_v = isset( $remote['variants'] ) && is_array( $remote['variants'] ) ? count( $remote['variants'] ) : 0;
			/* translators: 1: site variations, 2: Basalam variants */
			$this->add( $local === $remote_v ? 'ok' : 'warn', __( 'تنوع‌ها', 'basalamhub' ), sprintf( __( 'سایت %1$s، باسلام %2$s', 'basalamhub' ), $local, $remote_v ) );
		}
		if ( isset( $remote['status'] ) ) {
			$this->add( 'info', __( 'وضعیت در باسلام', 'basalamhub' ), is_array( $remote['status'] ) ? (string) wp_json_encode( $remote['status'], JSON_UNESCAPED_UNICODE ) : (string) $remote['status'] );
		}
		$this->add( 'info', __( 'قدم بعد', 'basalamhub' ), __( 'قیمت یا موجودی را در ووکامرس عوض کن و همین مرحله را دوباره بزن تا به‌روزرسانی هم تست شود.', 'basalamhub' ) );
	}

	/**
	 * Sets the product's stock on Basalam to 0 so nobody orders the test product.
	 *
	 * @param int $pid Product.
	 */
	public function step_hide( $pid ) {
		$link = $pid ? BSH_Links::get( 'product', $pid ) : null;
		if ( ! $link || ! $link->basalam_id ) {
			$this->add( 'fail', __( 'محصول', 'basalamhub' ), __( 'این محصول در باسلام نیست.', 'basalamhub' ) );
			return;
		}
		$bid    = (int) $link->basalam_id;
		$api    = BSH_Plugin::api();
		$remote = $this->call(
			__( 'خواندن محصول از باسلام', 'basalamhub' ),
			function () use ( $api, $bid ) {
				return $api->get_product( $bid );
			}
		);
		if ( ! $remote ) {
			return;
		}
		if ( ! empty( $remote['variants'] ) && is_array( $remote['variants'] ) ) {
			foreach ( $remote['variants'] as $v ) {
				if ( empty( $v['id'] ) ) {
					continue;
				}
				$this->call(
					/* translators: %s: variant id */
					sprintf( __( 'موجودی تنوع %s → ۰', 'basalamhub' ), $v['id'] ),
					function () use ( $api, $bid, $v ) {
						return $api->update_variant( $bid, (int) $v['id'], array( 'stock' => 0 ) );
					}
				);
			}
		} else {
			$this->call(
				__( 'موجودی → ۰', 'basalamhub' ),
				function () use ( $api, $bid ) {
					return $api->update_product( $bid, array( 'stock' => 0 ) );
				}
			);
		}
		$after = $this->call(
			__( 'بررسی دوباره', 'basalamhub' ),
			function () use ( $api, $bid ) {
				return $api->get_product( $bid );
			}
		);
		if ( $after ) {
			$stock = isset( $after['inventory'] ) ? (int) $after['inventory'] : ( isset( $after['stock'] ) ? (int) $after['stock'] : -1 );
			if ( ! empty( $after['variants'] ) ) {
				$stock = array_sum( array_map( 'intval', wp_list_pluck( $after['variants'], 'stock' ) ) );
			}
			$this->add( 0 === $stock ? 'ok' : 'warn', __( 'موجودی در باسلام', 'basalamhub' ), (string) $stock );
		}
		$this->add( 'info', __( 'توجه', 'basalamhub' ), __( 'ویرایش بعدی این محصول در ووکامرس موجودی را دوباره می‌فرستد. برای حذف کامل، محصول را از پنل غرفه‌ی باسلام پاک کن.', 'basalamhub' ) );
	}

	/**
	 * Imports recent Basalam orders right now.
	 */
	public function step_orders() {
		$api     = BSH_Plugin::api();
		$parcels = $this->call(
			__( 'سفارش‌های اخیر غرفه', 'basalamhub' ),
			function () use ( $api ) {
				return $api->vendor_parcels( array( 'per_page' => 10 ) );
			},
			function ( $r ) {
				/* translators: %s: count */
				return sprintf( __( '%s سفارش', 'basalamhub' ), count( $r['data'] ) );
			}
		);
		if ( ! $parcels ) {
			return;
		}
		if ( ! $parcels['data'] ) {
			$this->add( 'info', __( 'سفارش‌ها', 'basalamhub' ), __( 'سفارشی نیست؛ اول یک خرید آزمایشی از غرفه انجام بده.', 'basalamhub' ) );
			return;
		}
		foreach ( $parcels['data'] as $p ) {
			if ( empty( $p['id'] ) ) {
				continue;
			}
			$pid    = (int) $p['id'];
			$status = isset( $p['status']['title'] ) ? $p['status']['title'] : ( isset( $p['status']['id'] ) ? $p['status']['id'] : '?' );
			$exists = BSH_Order_Sync::find_order( $pid );
			if ( ! $exists ) {
				$t       = microtime( true );
				$outcome = BSH_Order_Sync::import( $pid );
				$ms      = (int) round( 1000 * ( microtime( true ) - $t ) );
				$exists  = BSH_Order_Sync::find_order( $pid );
				if ( ! $exists ) {
					$log = BSH_Logger::query(
						array(
							'object_type' => 'parcel',
							'object_id'   => $pid,
							'per_page'    => 1,
						)
					)['items'];
					/* translators: 1: parcel id, 2: status */
					$this->add( 'skipped' === $outcome ? 'warn' : 'fail', sprintf( __( 'سفارش باسلام #%1$s (%2$s)', 'basalamhub' ), $pid, $status ), (string) $outcome . ( $log ? ' — ' . $log[0]->message . ( $log[0]->reason ? ' | ' . $log[0]->reason : '' ) : '' ) . ' (' . $ms . 'ms)' );
					continue;
				}
			}
			$order = wc_get_order( $exists );
			$check = $order ? $this->order_summary( $order ) : '';
			/* translators: 1: parcel id, 2: status */
			$this->add( 'ok', sprintf( __( 'سفارش باسلام #%1$s (%2$s)', 'basalamhub' ), $pid, $status ), $check );
		}
		$this->add( 'info', __( 'قدم بعد', 'basalamhub' ), __( 'یکی از سفارش‌ها را در ووکامرس باز کن و از کادر باسلام‌هاب «تأیید سفارش» و بعد «ثبت ارسال» را بزن؛ سپس همین مرحله را دوباره اجرا کن تا وضعیت جدید را ببینی.', 'basalamhub' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Runs one API call and records the result.
	 *
	 * @param string        $label   Label.
	 * @param callable      $fn      Call.
	 * @param callable|null $summary Turns the result into a short text.
	 * @return mixed|null Result, or null on failure.
	 */
	private function call( $label, callable $fn, $summary = null ) {
		$t = microtime( true );
		try {
			$r  = $fn();
			$ms = (int) round( 1000 * ( microtime( true ) - $t ) );
			$this->add( 'ok', $label, ( $summary ? $summary( $r ) . ' ' : '' ) . '(' . $ms . 'ms)' );
			return $r;
		} catch ( BSH_Api_Error $e ) {
			$ms    = (int) round( 1000 * ( microtime( true ) - $t ) );
			$extra = array_filter( array( $e->http_status ? 'HTTP ' . $e->http_status : '', $e->kind, $e->reason ) );
			$this->add( 'fail', $label, $e->getMessage() . ' [' . implode( ' · ', $extra ) . '] (' . $ms . 'ms)' );
			if ( $e->details ) {
				$this->add( 'info', __( 'پاسخ باسلام', 'basalamhub' ), mb_substr( (string) wp_json_encode( $e->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 1500 ) );
			}
		} catch ( Throwable $e ) {
			$this->add( 'fail', $label, get_class( $e ) . ': ' . $e->getMessage() );
		}
		return null;
	}

	/**
	 * Reports which of the fields the plugin relies on are missing from a response.
	 *
	 * @param string $label  Label.
	 * @param array  $data   Response.
	 * @param array  $paths  Dotted paths.
	 */
	private function fields( $label, $data, array $paths ) {
		$missing = array();
		foreach ( $paths as $path ) {
			// «a|b»: the plugin accepts either field.
			$found = false;
			foreach ( explode( '|', $path ) as $alt ) {
				$node = $data;
				foreach ( explode( '.', $alt ) as $key ) {
					if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
						continue 2;
					}
					$node = $node[ $key ];
				}
				$found = true;
				break;
			}
			if ( ! $found ) {
				$missing[] = $path;
			}
		}
		$keys = is_array( $data ) ? implode( ', ', array_slice( array_keys( $data ), 0, 40 ) ) : '';
		if ( $missing ) {
			/* translators: 1: missing fields, 2: fields present */
			$this->add( 'warn', $label, sprintf( __( 'نبود: %1$s — موجود: %2$s', 'basalamhub' ), implode( ', ', $missing ), $keys ) );
		} else {
			/* translators: %s: count */
			$this->add( 'ok', $label, sprintf( __( 'همه‌ی %s فیلد لازم هست', 'basalamhub' ), count( $paths ) ) );
		}
	}

	/**
	 * @param string      $label  Label.
	 * @param string      $local  Site value.
	 * @param string|null $remote Basalam value.
	 * @param string      $hint   Shown when they differ.
	 */
	private function compare( $label, $local, $remote, $hint = '' ) {
		if ( null === $remote ) {
			$this->add( 'warn', $label, __( 'در پاسخ باسلام نبود', 'basalamhub' ) );
			return;
		}
		$same = trim( (string) $local ) === trim( (string) $remote ) || ( is_numeric( $local ) && is_numeric( $remote ) && abs( (float) $local - (float) $remote ) < 0.01 );
		/* translators: 1: site value, 2: Basalam value */
		$this->add( $same ? 'ok' : 'warn', $label, sprintf( __( 'سایت: %1$s | باسلام: %2$s', 'basalamhub' ), $local, $remote ) . ( $same || ! $hint ? '' : ' — ' . $hint ) );
	}

	/**
	 * A WooCommerce order in one line, with the customer masked.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function order_summary( WC_Order $order ) {
		$name = $order->get_formatted_shipping_full_name();
		$name = $name ? mb_substr( $name, 0, 1 ) . '***' : '—';
		/* translators: 1: order number, 2: status, 3: total, 4: item count, 5: city, 6: customer (masked), 7: phone present */
		return sprintf( __( 'سفارش ووکامرس %1$s · %2$s · %3$s · %4$s قلم · %5$s · %6$s · تلفن: %7$s', 'basalamhub' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ), BSH_Sales::money( (float) $order->get_total() ), count( $order->get_items() ), $order->get_shipping_city() ? $order->get_shipping_city() : '—', $name, $order->get_billing_phone() ? __( 'دارد', 'basalamhub' ) : __( 'ندارد', 'basalamhub' ) );
	}

	/**
	 * @param array $cats Category tree.
	 * @return array|null
	 */
	private function first_leaf( array $cats ) {
		foreach ( $cats as $c ) {
			if ( empty( $c['id'] ) ) {
				continue;
			}
			if ( empty( $c['children'] ) ) {
				return $c;
			}
			$leaf = $this->first_leaf( (array) $c['children'] );
			if ( $leaf ) {
				return $leaf;
			}
		}
		return null;
	}

	/**
	 * A 600×600 JPEG made on the fly.
	 *
	 * @return string|null Path.
	 */
	private function test_image() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}
		$img = imagecreatetruecolor( 600, 600 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 92, 53 ) );
		imagefilledellipse( $img, 300, 300, 320, 320, imagecolorallocate( $img, 255, 255, 255 ) );
		$path = trailingslashit( get_temp_dir() ) . 'bsh-live-test-' . wp_generate_password( 6, false ) . '.jpg';
		imagejpeg( $img, $path, 85 );
		imagedestroy( $img );
		return is_readable( $path ) ? $path : null;
	}

	/**
	 * @param string $state ok|warn|fail|info.
	 * @param string $label Label.
	 * @param string $detail Detail.
	 */
	private function add( $state, $label, $detail ) {
		$this->lines[] = array( $state, (string) $label, (string) $detail );
	}

	/**
	 * @param string $step Step.
	 * @return array{lines: array, text: string, failed: int}
	 */
	private function result( $step ) {
		$marks = array(
			'ok'   => '✓',
			'warn' => '⚠',
			'fail' => '✗',
			'info' => '·',
		);
		$text  = '== ' . $step . ' · ' . wp_date( 'Y-m-d H:i' ) . " ==\n";
		foreach ( $this->lines as $l ) {
			$text .= $marks[ $l[0] ] . ' ' . $l[1] . ( '' !== $l[2] ? ' — ' . $l[2] : '' ) . "\n";
		}
		return array(
			'lines'  => array_map(
				function ( $l ) {
					return array(
						'state'  => $l[0],
						'label'  => $l[1],
						'detail' => $l[2],
					);
				},
				$this->lines
			),
			'text'   => $text,
			'failed' => count(
				array_filter(
					$this->lines,
					function ( $l ) {
						return 'fail' === $l[0];
					}
				)
			),
		);
	}

	/**
	 * Header of the report: versions and settings that change results.
	 *
	 * @return string
	 */
	public static function environment() {
		global $wp_version;
		$conn = BSH_Settings::connection();
		return implode(
			"\n",
			array(
				'BasalamHub ' . BSH_VERSION . ' · WordPress ' . $wp_version . ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' · PHP ' . PHP_VERSION,
				'API: ' . untrailingslashit( apply_filters( 'bsh_api_base', BSH_API_BASE ) ) . ' · currency: ' . get_woocommerce_currency() . ' · HPOS: ' . ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no' ),
				'Booth: ' . ( 'ok' === $conn['status'] ? $conn['vendor_title'] . ' (' . $conn['vendor_id'] . ')' : '—' ) . ' · stock reference: ' . BSH_Settings::get( 'stock_reference', 'site' ) . ' · safety: ' . BSH_Settings::get( 'safety_stock', 0 ),
			)
		) . "\n";
	}
}
