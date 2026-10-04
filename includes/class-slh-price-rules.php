<?php
/**
 * Price rules for Basalam: raise or lower by percent or a fixed amount, globally or per
 * WooCommerce category (a category rule wins over the global rule; a child category
 * inherits its parent's rule), then round. Amounts and rounding are entered in Toman.
 *
 * The guard that refuses zero or negative prices lives in the mapper and runs after this.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Price_Rules {

	const OPTION = 'slh_price_rules';

	/**
	 * Hooks into the mapper.
	 */
	public static function init() {
		add_filter( 'slh_basalam_price', array( __CLASS__, 'filter_price' ), 10, 2 );
	}

	/**
	 * @return array{global: array, categories: array<int,array>, rounding: array}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'global'     => isset( $saved['global'] ) ? $saved['global'] : self::empty_rule(),
			'categories' => isset( $saved['categories'] ) && is_array( $saved['categories'] ) ? $saved['categories'] : array(),
			'rounding'   => isset( $saved['rounding'] ) ? $saved['rounding'] : array( 'unit' => 0, 'mode' => 'up' ),
		);
	}

	/**
	 * @return bool Whether any rule or rounding changes prices.
	 */
	public static function is_active() {
		$rules = self::get();
		return 'none' !== $rules['global']['type'] || ! empty( $rules['categories'] ) || (int) $rules['rounding']['unit'] > 0;
	}

	/**
	 * @return array
	 */
	public static function empty_rule() {
		return array( 'type' => 'none', 'direction' => 'up', 'value' => 0 );
	}

	/**
	 * Validates and stores rules.
	 *
	 * @param array $input Raw input: global, categories[term_id], rounding.
	 * @return array<string,string> Errors keyed by field ("global" or "cat_{term_id}").
	 */
	public static function save( array $input ) {
		$errors = array();
		$clean  = array( 'global' => self::empty_rule(), 'categories' => array(), 'rounding' => array( 'unit' => 0, 'mode' => 'up' ) );

		$global = self::clean_rule( isset( $input['global'] ) ? (array) $input['global'] : array(), $error );
		if ( $error ) {
			$errors['global'] = $error;
		} else {
			$clean['global'] = $global;
		}

		foreach ( isset( $input['categories'] ) ? (array) $input['categories'] : array() as $term_id => $rule ) {
			$rule = self::clean_rule( (array) $rule, $error );
			if ( $error ) {
				$errors[ 'cat_' . (int) $term_id ] = $error;
				continue;
			}
			if ( 'none' !== $rule['type'] ) {
				$clean['categories'][ (int) $term_id ] = $rule;
			}
		}

		$unit = isset( $input['rounding']['unit'] ) ? (int) $input['rounding']['unit'] : 0;
		$mode = isset( $input['rounding']['mode'] ) ? sanitize_key( $input['rounding']['mode'] ) : 'up';
		$clean['rounding'] = array(
			'unit' => in_array( $unit, array( 0, 100, 1000, 10000 ), true ) ? $unit : 0,
			'mode' => in_array( $mode, array( 'up', 'nearest', 'down' ), true ) ? $mode : 'up',
		);

		update_option( self::OPTION, $clean, false );
		return $errors;
	}

	/**
	 * @param array       $rule  Raw rule.
	 * @param string|null $error Error message (by reference).
	 * @return array
	 */
	private static function clean_rule( array $rule, &$error ) {
		$error = null;
		$type  = isset( $rule['type'] ) ? sanitize_key( $rule['type'] ) : 'none';
		$type  = in_array( $type, array( 'none', 'percent', 'fixed' ), true ) ? $type : 'none';
		$dir   = isset( $rule['direction'] ) && 'down' === $rule['direction'] ? 'down' : 'up';
		$raw   = isset( $rule['value'] ) ? strtr( trim( (string) $rule['value'] ), array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', '٬' => '', ',' => '' ) ) : '';

		if ( 'none' === $type ) {
			return self::empty_rule();
		}
		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw <= 0 ) {
			$error = __( 'مقدار باید عددی بزرگ‌تر از صفر باشد.', 'salamhub' );
			return self::empty_rule();
		}
		$value = (float) $raw;
		if ( 'percent' === $type && 'down' === $dir && $value >= 100 ) {
			$error = __( 'کاهش ۱۰۰ درصد یا بیشتر قیمت را صفر می‌کند.', 'salamhub' );
			return self::empty_rule();
		}
		if ( 'percent' === $type && $value > 1000 ) {
			$error = __( 'درصد بیش از ۱۰۰۰ احتمالاً اشتباه تایپی است.', 'salamhub' );
			return self::empty_rule();
		}
		return array( 'type' => $type, 'direction' => $dir, 'value' => 'fixed' === $type ? (int) round( $value ) : $value );
	}

	/**
	 * The rule that applies to a product, and where it came from.
	 *
	 * @param WC_Product $product Product.
	 * @return array{rule: array, source: string, term_id: int}
	 */
	public static function rule_for( WC_Product $product ) {
		$rules = self::get();
		$best  = null;
		$depth = -1;
		$ids   = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() )->get_category_ids() : $product->get_category_ids();
		foreach ( $ids as $term_id ) {
			$chain = array_merge( array( (int) $term_id ), array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
			foreach ( $chain as $candidate ) {
				if ( isset( $rules['categories'][ $candidate ] ) ) {
					if ( count( $chain ) > $depth ) {
						$depth = count( $chain );
						$best  = array( 'rule' => $rules['categories'][ $candidate ], 'source' => 'category', 'term_id' => $candidate );
					}
					break;
				}
			}
		}
		return $best ? $best : array( 'rule' => $rules['global'], 'source' => 'global', 'term_id' => 0 );
	}

	/**
	 * Applies a rule and rounding to a Rial price.
	 *
	 * @param int   $rial     Price in Rial.
	 * @param array $rule     Rule.
	 * @param array $rounding Rounding (unit in Toman).
	 * @return int
	 */
	public static function apply( $rial, array $rule, array $rounding ) {
		$price = (float) $rial;
		$sign  = 'down' === $rule['direction'] ? -1 : 1;
		if ( 'percent' === $rule['type'] ) {
			$price = $price * ( 1 + $sign * $rule['value'] / 100 );
		} elseif ( 'fixed' === $rule['type'] ) {
			$price = $price + $sign * $rule['value'] * 10; // Toman → Rial.
		}

		$unit = (int) $rounding['unit'] * 10; // Toman → Rial.
		if ( $unit > 0 && $price > 0 ) {
			$steps = $price / $unit;
			if ( 'down' === $rounding['mode'] ) {
				$steps = floor( $steps );
			} elseif ( 'nearest' === $rounding['mode'] ) {
				$steps = round( $steps );
			} else {
				$steps = ceil( $steps - 1e-9 );
			}
			$price = $steps * $unit;
		}
		return (int) round( $price );
	}

	/**
	 * slh_basalam_price filter.
	 *
	 * @param int        $rial    Price in Rial.
	 * @param WC_Product $product Product.
	 * @return int
	 */
	public static function filter_price( $rial, $product ) {
		if ( (int) $rial <= 0 ) {
			return (int) $rial;
		}
		$for = self::rule_for( $product );
		return self::apply( (int) $rial, $for['rule'], self::get()['rounding'] );
	}

	/**
	 * Persian description of a rule, e.g. "۱۰٪ افزایش".
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	public static function describe( array $rule ) {
		if ( 'none' === $rule['type'] ) {
			return __( 'بدون تغییر', 'salamhub' );
		}
		$dir = 'down' === $rule['direction'] ? __( 'کاهش', 'salamhub' ) : __( 'افزایش', 'salamhub' );
		if ( 'percent' === $rule['type'] ) {
			/* translators: 1: percent, 2: increase/decrease */
			return sprintf( __( '%1$s٪ %2$s', 'salamhub' ), slh_fa_digits( rtrim( rtrim( number_format( $rule['value'], 2, '.', '' ), '0' ), '.' ) ), $dir );
		}
		/* translators: 1: amount in Toman, 2: increase/decrease */
		return sprintf( __( '%1$s تومان %2$s', 'salamhub' ), slh_fa_number( $rule['value'] ), $dir );
	}
}
