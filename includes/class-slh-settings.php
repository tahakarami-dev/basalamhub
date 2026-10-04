<?php
/**
 * Settings storage: plugin options, the encrypted token and the connection state.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Settings {

	const OPTION            = 'slh_settings';
	const TOKEN_OPTION      = 'slh_token';
	const CONNECTION_OPTION = 'slh_connection';

	/** Basalam product status codes (from the official OpenAPI spec). */
	const BASALAM_STATUS_PUBLISHED   = 2976;
	const BASALAM_STATUS_UNPUBLISHED = 3790;

	/**
	 * Field groups the user can choose to keep in sync on updates.
	 * On first creation every field is always sent (Basalam requires them).
	 *
	 * @return array<string,string>
	 */
	public static function field_groups() {
		return array(
			'title'       => __( 'نام محصول', 'salamhub' ),
			'description' => __( 'توضیحات', 'salamhub' ),
			'images'      => __( 'تصاویر', 'salamhub' ),
			'price'       => __( 'قیمت', 'salamhub' ),
			'stock'       => __( 'موجودی', 'salamhub' ),
			'shipping'    => __( 'وزن، ابعاد و زمان آماده‌سازی', 'salamhub' ),
			'category'    => __( 'دسته‌بندی', 'salamhub' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'sync_fields'          => array_keys( self::field_groups() ),
			'auto_update'          => 1,
			'auto_send_new'        => 0,
			'default_category_id'  => '',
			'preparation_days'     => 3,
			'default_weight'       => 500,
			'packaging_weight'     => 0,
			'unmanaged_stock'      => 1,
			'price_unit'           => 'auto',
			'create_status'        => self::BASALAM_STATUS_PUBLISHED,
			'log_retention_days'   => 30,
			// Phase 4: orders and stock.
			'orders_enabled'       => 1,
			'orders_interval'      => 5,
			'orders_import_days'   => 3,
			'orders_auto_confirm'  => 0,
			'stock_reference'      => 'site',
			'safety_stock'         => 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Validates and saves user-submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array<string,string> Field errors in Persian (empty when all valid).
	 */
	public static function save( array $input ) {
		$errors = array();
		$clean  = self::all();

		$groups               = array_keys( self::field_groups() );
		$fields               = isset( $input['sync_fields'] ) ? (array) $input['sync_fields'] : array();
		$clean['sync_fields'] = array_values( array_intersect( $groups, array_map( 'sanitize_key', $fields ) ) );

		$clean['auto_update']   = empty( $input['auto_update'] ) ? 0 : 1;
		$clean['auto_send_new'] = empty( $input['auto_send_new'] ) ? 0 : 1;

		$clean['orders_enabled']      = empty( $input['orders_enabled'] ) ? 0 : 1;
		$clean['orders_auto_confirm'] = empty( $input['orders_auto_confirm'] ) ? 0 : 1;
		$reference                    = isset( $input['stock_reference'] ) ? sanitize_key( $input['stock_reference'] ) : 'site';
		$clean['stock_reference']     = in_array( $reference, array( 'site', 'basalam' ), true ) ? $reference : 'site';

		$cat = isset( $input['default_category_id'] ) ? trim( (string) $input['default_category_id'] ) : '';
		if ( '' !== $cat && ! ctype_digit( $cat ) ) {
			$errors['default_category_id'] = __( 'شناسه‌ی دسته فقط عدد است؛ مثلاً 1287.', 'salamhub' );
		} else {
			$clean['default_category_id'] = $cat;
		}

		foreach ( array(
			'preparation_days' => array( 0, 60, __( 'زمان آماده‌سازی باید بین ۰ تا ۶۰ روز باشد.', 'salamhub' ) ),
			'default_weight'   => array( 1, 1000000, __( 'وزن پیش‌فرض باید بیشتر از صفر گرم باشد.', 'salamhub' ) ),
			'packaging_weight' => array( 0, 100000, __( 'وزن بسته‌بندی باید صفر یا بیشتر باشد.', 'salamhub' ) ),
			'unmanaged_stock'  => array( 0, 100000, __( 'موجودی پیش‌فرض باید صفر یا بیشتر باشد.', 'salamhub' ) ),
			'log_retention_days' => array( 7, 365, __( 'نگهداری لاگ باید بین ۷ تا ۳۶۵ روز باشد.', 'salamhub' ) ),
			'orders_interval'    => array( 2, 60, __( 'فاصله‌ی دریافت سفارش باید بین ۲ تا ۶۰ دقیقه باشد.', 'salamhub' ) ),
			'orders_import_days' => array( 0, 30, __( 'سفارش‌های گذشته را بین ۰ تا ۳۰ روز می‌شود وارد کرد.', 'salamhub' ) ),
			'safety_stock'       => array( 0, 100000, __( 'موجودی اطمینان باید صفر یا بیشتر باشد.', 'salamhub' ) ),
		) as $key => $rule ) {
			$raw = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			$raw = strtr( $raw, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
			if ( '' === $raw || ! preg_match( '/^-?\d+$/', $raw ) || (int) $raw < $rule[0] || (int) $raw > $rule[1] ) {
				$errors[ $key ] = $rule[2];
				continue;
			}
			$clean[ $key ] = (int) $raw;
		}

		$unit                = isset( $input['price_unit'] ) ? sanitize_key( $input['price_unit'] ) : 'auto';
		$clean['price_unit'] = in_array( $unit, array( 'auto', 'irt', 'irr' ), true ) ? $unit : 'auto';

		$status                 = isset( $input['create_status'] ) ? (int) $input['create_status'] : self::BASALAM_STATUS_PUBLISHED;
		$clean['create_status'] = in_array( $status, array( self::BASALAM_STATUS_PUBLISHED, self::BASALAM_STATUS_UNPUBLISHED ), true ) ? $status : self::BASALAM_STATUS_PUBLISHED;

		update_option( self::OPTION, $clean, false );
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			SLH_Order_Sync::reschedule();
			SLH_Inventory::schedule();
		}
		return $errors;
	}

	/* ---------------------------------------------------------------------
	 * Token
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $token Plain token.
	 * @return bool False when the server cannot encrypt.
	 */
	public static function set_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			delete_option( self::TOKEN_OPTION );
			return true;
		}
		$encrypted = SLH_Crypto::encrypt( $token );
		if ( '' === $encrypted ) {
			return false;
		}
		update_option( self::TOKEN_OPTION, $encrypted, false );
		return true;
	}

	/**
	 * @return string|null Plain token, '' when not set, null when stored but unreadable.
	 */
	public static function get_token() {
		return SLH_Crypto::decrypt( (string) get_option( self::TOKEN_OPTION, '' ) );
	}

	/**
	 * @return bool
	 */
	public static function has_token() {
		return '' !== (string) get_option( self::TOKEN_OPTION, '' );
	}

	/* ---------------------------------------------------------------------
	 * Connection state (filled by "test connection")
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	public static function connection() {
		$c = get_option( self::CONNECTION_OPTION, array() );
		return wp_parse_args(
			is_array( $c ) ? $c : array(),
			array(
				'status'            => 'unknown', // ok | invalid | unknown
				'vendor_id'         => 0,
				'vendor_title'      => '',
				'vendor_identifier' => '',
				'user_name'         => '',
				'checked_at'        => '',
				'message'           => '',
			)
		);
	}

	/**
	 * @param array $data Partial connection data.
	 */
	public static function update_connection( array $data ) {
		update_option( self::CONNECTION_OPTION, array_merge( self::connection(), $data ), false );
	}

	/**
	 * @return int Vendor ID or 0.
	 */
	public static function vendor_id() {
		return (int) self::connection()['vendor_id'];
	}

	/**
	 * Whether sync jobs may talk to Basalam.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$c = self::connection();
		return self::has_token() && 'ok' === $c['status'] && $c['vendor_id'] > 0;
	}
}
