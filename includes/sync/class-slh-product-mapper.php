<?php
/**
 * Turns a WooCommerce product into a Basalam product payload.
 *
 * The mapper never talks to the network and never writes anything; it only builds the
 * payload and reports blocking problems in Persian. That keeps it easy to test and lets
 * later phases (price rules, category mapping, safety stock) plug in through filters.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Product_Mapper {

	/** Which Basalam fields belong to which user-selectable group. */
	const GROUP_FIELDS = array(
		'title'       => array( 'name' ),
		'description' => array( 'description', 'brief' ),
		'images'      => array( 'photo', 'photos' ),
		'price'       => array( 'primary_price' ),
		'stock'       => array( 'stock' ),
		'shipping'    => array( 'weight', 'package_weight', 'packaging_dimensions', 'preparation_days' ),
		'category'    => array( 'category_id', 'product_attribute' ),
	);

	/**
	 * @param WC_Product $product Product.
	 * @return array{payload: array, image_ids: int[], problems: array[]}
	 *         problems: list of ['field' => …, 'message' => …, 'suggestion' => …].
	 */
	public function map( WC_Product $product ) {
		$problems = array();

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			$problems[] = array(
				'field'      => 'type',
				'message'    => __( 'فقط محصولات ساده و متغیر قابل ارسال هستند.', 'salamhub' ),
				'suggestion' => __( 'محصولات گروهی و خارجی در باسلام معادل ندارند.', 'salamhub' ),
			);
		}

		$payload = array(
			'name'                 => $this->name( $product ),
			'description'          => $this->plain_text( $product->get_description() ),
			'brief'                => $this->plain_text( $product->get_short_description() ),
			'primary_price'        => $this->price( $product, $problems ),
			'stock'                => $this->stock( $product ),
			'category_id'          => $this->category( $product, $problems ),
			'preparation_days'     => $this->preparation_days( $product ),
			'sku'                  => self::sku_for( $product ),
			'status'               => (int) SLH_Settings::get( 'create_status', SLH_Settings::BASALAM_STATUS_PUBLISHED ),
			'is_wholesale'         => false,
		);

		$weight                    = $this->weight_grams( $product );
		$payload['weight']         = $weight;
		$payload['package_weight'] = (int) round( $weight + (int) SLH_Settings::get( 'packaging_weight', 0 ) );

		$dimensions = $this->dimensions( $product );
		if ( $dimensions ) {
			$payload['packaging_dimensions'] = $dimensions;
		}

		if ( '' === $payload['name'] ) {
			$problems[] = array(
				'field'      => 'name',
				'message'    => __( 'محصول نام ندارد.', 'salamhub' ),
				'suggestion' => __( 'برای محصول نام بنویس.', 'salamhub' ),
			);
		}

		$image_ids  = array_values( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) ) );
		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			$variations = $this->variations( $product, $problems );
			if ( $variations ) {
				$payload['variants']      = array_values( array_map( array( __CLASS__, 'variant_payload' ), $variations ) );
				$payload['primary_price'] = min( wp_list_pluck( $variations, 'primary_price' ) );
				$payload['stock']         = array_sum( wp_list_pluck( $variations, 'stock' ) );
				// The product-level price check doesn't apply: each variant has its own price.
				$problems = array_values( array_filter( $problems, function ( $p ) {
					return 'primary_price' !== $p['field'];
				} ) );
				// Variation photos join the gallery so buyers see every option.
				foreach ( $variations as $v ) {
					if ( $v['image_id'] ) {
						$image_ids[] = $v['image_id'];
					}
				}
			}
		}
		if ( ! $image_ids ) {
			$problems[] = array(
				'field'      => 'photo',
				'message'    => __( 'محصول تصویر ندارد.', 'salamhub' ),
				'suggestion' => __( 'باسلام محصول بدون عکس را نمایش نمی‌دهد؛ حداقل یک تصویر شاخص اضافه کن.', 'salamhub' ),
			);
		}

		/**
		 * Last chance to change the payload before it is hashed and sent.
		 *
		 * @param array      $payload Basalam payload.
		 * @param WC_Product $product Product.
		 */
		$payload = apply_filters( 'slh_product_payload', $payload, $product );

		return array(
			'payload'    => $payload,
			'image_ids'  => array_values( array_unique( $image_ids ) ),
			'problems'   => $problems,
			'variations' => $variations,
		);
	}

	/**
	 * Enabled variations of a variable product as Basalam variants.
	 *
	 * @param WC_Product_Variable $product  Product.
	 * @param array               $problems Problems (by reference).
	 * @return array<int,array> wc_variation_id => [sku, properties, primary_price, stock, image_id, sig, label].
	 */
	public function variations( WC_Product $product, array &$problems ) {
		$out  = array();
		$seen = array();
		foreach ( $product->get_children() as $child_id ) {
			$v = wc_get_product( $child_id );
			// Disabled variations are stored as "private"; Basalam never sees them.
			if ( ! $v || 'publish' !== $v->get_status() ) {
				continue;
			}
			$props = array();
			$label = array();
			foreach ( $v->get_variation_attributes( false ) as $taxonomy => $value ) {
				$name = wc_attribute_label( $taxonomy, $product );
				if ( '' === (string) $value ) {
					$problems[] = array(
						'field'      => 'variants',
						/* translators: 1: variation id, 2: attribute */
						'message'    => sprintf( __( 'تنوع #%1$s برای «%2$s» مقدار مشخص ندارد («هرکدام»).', 'salamhub' ), $child_id, $name ),
						'suggestion' => __( 'در تب «متغیرها»ی محصول برای هر تنوع مقدار دقیق انتخاب کن؛ باسلام تنوع «هرکدام» ندارد.', 'salamhub' ),
					);
					continue 2;
				}
				if ( taxonomy_exists( $taxonomy ) ) {
					$term  = get_term_by( 'slug', $value, $taxonomy );
					$value = $term ? $term->name : $value;
				}
				$props[] = array( 'property' => (string) $name, 'value' => (string) $value );
				$label[] = $value;
			}
			$sig = md5( wp_json_encode( $props ) );
			if ( isset( $seen[ $sig ] ) ) {
				$problems[] = array(
					'field'      => 'variants',
					/* translators: %s: variation label */
					'message'    => sprintf( __( 'دو تنوع با ویژگی‌های یکسان («%s») وجود دارد.', 'salamhub' ), implode( '، ', $label ) ),
					'suggestion' => __( 'تنوع تکراری را حذف یا غیرفعال کن.', 'salamhub' ),
				);
				continue;
			}
			$seen[ $sig ] = true;

			$price_problems = array();
			$price          = $this->price( $v, $price_problems );
			if ( $price <= 0 ) {
				$problems[] = array(
					'field'      => 'variants',
					/* translators: %s: variation label */
					'message'    => sprintf( __( 'تنوع «%s» قیمت ندارد یا قیمتش صفر است.', 'salamhub' ), implode( '، ', $label ) ),
					'suggestion' => __( 'قیمت همه‌ی تنوع‌های فعال را وارد کن، یا تنوع را غیرفعال کن.', 'salamhub' ),
				);
				continue;
			}
			$out[ (int) $child_id ] = array(
				'sku'           => self::sku_for( $v ),
				'properties'    => $props,
				'primary_price' => $price,
				'stock'         => $this->stock( $v ),
				'image_id'      => $v->get_image_id() && (int) $v->get_image_id() !== (int) $product->get_image_id() ? (int) $v->get_image_id() : 0,
				'sig'           => $sig,
				'label'         => implode( '، ', $label ),
			);
		}
		if ( ! $out && ! array_filter( $problems, function ( $p ) {
			return 'variants' === $p['field'];
		} ) ) {
			$problems[] = array(
				'field'      => 'variants',
				'message'    => __( 'محصول متغیر هیچ تنوع فعالی ندارد.', 'salamhub' ),
				'suggestion' => __( 'حداقل یک تنوع با قیمت بساز و فعالش کن.', 'salamhub' ),
			);
		}
		return $out;
	}

	/**
	 * A mapped variation as Basalam's ProductVariants item.
	 *
	 * @param array $v Mapped variation.
	 * @return array
	 */
	public static function variant_payload( array $v ) {
		return array(
			'primary_price' => $v['primary_price'],
			'stock'         => $v['stock'],
			'sku'           => $v['sku'],
			'properties'    => $v['properties'],
		);
	}

	/**
	 * Keeps only the fields of the selected groups (used for updates).
	 *
	 * @param array    $payload Full payload.
	 * @param string[] $groups  Selected group keys.
	 * @return array
	 */
	public static function filter_by_groups( array $payload, array $groups ) {
		$allowed = array();
		foreach ( $groups as $g ) {
			if ( isset( self::GROUP_FIELDS[ $g ] ) ) {
				$allowed = array_merge( $allowed, self::GROUP_FIELDS[ $g ] );
			}
		}
		return array_intersect_key( $payload, array_flip( $allowed ) );
	}

	/**
	 * The SKU sent to Basalam. Products without a SKU get a stable synthetic one so a
	 * lost "create" response can always be recovered without making a duplicate.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function sku_for( WC_Product $product ) {
		$sku = trim( (string) $product->get_sku() );
		return '' !== $sku ? $sku : 'SLH-' . $product->get_id();
	}

	/**
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function name( WC_Product $product ) {
		return trim( wp_strip_all_tags( html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * HTML → plain text with paragraph breaks kept.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function plain_text( $html ) {
		$html = strip_shortcodes( (string) $html );
		$html = preg_replace( '#<\s*br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</\s*(p|div|li|h[1-6]|tr)\s*>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\n\s*\n\s*\n+/u", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Multiplier from the store currency to Rial (Basalam prices are in Rial).
	 *
	 * @return int|null Null when the currency cannot be converted.
	 */
	public static function rial_multiplier() {
		$unit = SLH_Settings::get( 'price_unit', 'auto' );
		if ( 'irr' === $unit ) {
			return 1;
		}
		if ( 'irt' === $unit ) {
			return 10;
		}
		$map      = array(
			'IRR'  => 1,     // ریال
			'IRT'  => 10,    // تومان
			'IRHR' => 1000,  // هزار ریال
			'IRHT' => 10000, // هزار تومان
		);
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		return isset( $map[ $currency ] ) ? $map[ $currency ] : null;
	}

	/**
	 * Price in Rial. Never zero or negative (price guard).
	 *
	 * @param WC_Product $product  Product.
	 * @param array      $problems Problems (by reference).
	 * @return int
	 */
	private function price( WC_Product $product, array &$problems ) {
		$multiplier = self::rial_multiplier();
		if ( null === $multiplier ) {
			$problems[] = array(
				'field'      => 'primary_price',
				/* translators: %s: currency code */
				'message'    => sprintf( __( 'واحد پول فروشگاه (%s) قابل تبدیل به ریال نیست.', 'salamhub' ), get_woocommerce_currency() ),
				'suggestion' => __( 'در باسلام‌هاب › تنظیمات، واحد قیمت‌های سایت را دستی روی «تومان» یا «ریال» بگذار.', 'salamhub' ),
			);
			return 0;
		}

		// The active price: the sale price while a sale runs, the regular price otherwise.
		$price = (float) wc_get_price_excluding_tax( $product, array( 'price' => $product->get_price() ) );
		$rial  = (int) round( $price * $multiplier );

		/**
		 * Price rules (percentage/fixed change, rounding) plug in here.
		 *
		 * @param int        $rial    Price in Rial.
		 * @param WC_Product $product Product.
		 */
		$rial = (int) apply_filters( 'slh_basalam_price', $rial, $product );

		if ( $rial <= 0 ) {
			$problems[] = array(
				'field'      => 'primary_price',
				'message'    => __( 'قیمت محصول صفر یا خالی است.', 'salamhub' ),
				'suggestion' => __( 'باسلام‌هاب هیچ‌وقت قیمت صفر به باسلام نمی‌فرستد. قیمت محصول را وارد کن.', 'salamhub' ),
			);
			return 0;
		}
		return $rial;
	}

	/**
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private function stock( WC_Product $product ) {
		if ( $product->managing_stock() ) {
			$stock = max( 0, (int) $product->get_stock_quantity() );
		} elseif ( 'outofstock' === $product->get_stock_status() ) {
			$stock = 0;
		} else {
			$stock = (int) SLH_Settings::get( 'unmanaged_stock', 1 );
		}
		/**
		 * Safety stock (hide N units from Basalam) plugs in here.
		 *
		 * @param int        $stock   Stock to publish.
		 * @param WC_Product $product Product.
		 */
		return max( 0, (int) apply_filters( 'slh_basalam_stock', $stock, $product ) );
	}

	/**
	 * @param WC_Product $product  Product.
	 * @param array      $problems Problems (by reference).
	 * @return int
	 */
	private function category( WC_Product $product, array &$problems ) {
		$cat = (int) $product->get_meta( '_slh_category_id', true );
		/**
		 * Category mapping (WooCommerce category → Basalam category) plugs in here.
		 *
		 * @param int        $cat     Basalam category ID (0 = none yet).
		 * @param WC_Product $product Product.
		 */
		$cat = (int) apply_filters( 'slh_basalam_category', $cat, $product );
		if ( $cat <= 0 ) {
			$cat = (int) SLH_Settings::get( 'default_category_id', 0 );
		}
		if ( $cat <= 0 ) {
			$names = array();
			foreach ( $product->get_category_ids() as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = '«' . $term->name . '»';
				}
			}
			$problems[] = array(
				'field'      => 'category_id',
				'message'    => $names
					/* translators: %s: WooCommerce category names */
					? sprintf( __( 'دسته‌ی %s به هیچ دسته‌ی باسلام نگاشت نشده.', 'salamhub' ), implode( '، ', $names ) )
					: __( 'دسته‌ی باسلام برای این محصول انتخاب نشده.', 'salamhub' ),
				'suggestion' => __( 'در باسلام‌هاب › نگاشت دسته‌ها برای این دسته، دسته‌ی باسلام را انتخاب کن. (یا شناسه را در کادر باسلام‌هاب همین محصول بنویس.)', 'salamhub' ),
			);
		}
		return $cat;
	}

	/**
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private function preparation_days( WC_Product $product ) {
		$own = $product->get_meta( '_slh_preparation_days', true );
		return '' !== $own && null !== $own ? max( 0, (int) $own ) : (int) SLH_Settings::get( 'preparation_days', 3 );
	}

	/**
	 * Net weight in grams; falls back to the default weight setting.
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private function weight_grams( WC_Product $product ) {
		$w = (float) $product->get_weight();
		if ( $w > 0 ) {
			return max( 1, (int) round( wc_get_weight( $w, 'g' ) ) );
		}
		return (int) SLH_Settings::get( 'default_weight', 500 );
	}

	/**
	 * Package dimensions in centimeters, only when all three are set.
	 *
	 * @param WC_Product $product Product.
	 * @return array|null
	 */
	private function dimensions( WC_Product $product ) {
		$l = (float) $product->get_length();
		$w = (float) $product->get_width();
		$h = (float) $product->get_height();
		if ( $l <= 0 || $w <= 0 || $h <= 0 ) {
			return null;
		}
		return array(
			'length' => max( 1, (int) ceil( wc_get_dimension( $l, 'cm' ) ) ),
			'width'  => max( 1, (int) ceil( wc_get_dimension( $w, 'cm' ) ) ),
			'height' => max( 1, (int) ceil( wc_get_dimension( $h, 'cm' ) ) ),
		);
	}
}
