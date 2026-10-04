<?php
/**
 * Basalam categories: cached tree, WooCommerce → Basalam mapping, and category attributes.
 *
 * Mapping is set once per WooCommerce category and applies to every product in it.
 * A mapping on a parent category also covers its child categories, unless a child has its
 * own mapping. Resolution order for a product:
 *   1. the per-product override in the SalamHub box,
 *   2. the deepest mapped WooCommerce category of the product (or of its ancestors),
 *   3. the default category from settings.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Categories {

	const CACHE_OPTION = 'slh_basalam_categories';
	const MAP_OPTION   = 'slh_category_map';
	const ATTR_OPTION  = 'slh_category_attributes';
	const ATTR_TTL     = WEEK_IN_SECONDS;

	/**
	 * Hooks into the mapper.
	 */
	public static function init() {
		add_filter( 'slh_basalam_category', array( __CLASS__, 'filter_category' ), 10, 2 );
		add_filter( 'slh_product_payload', array( __CLASS__, 'filter_payload' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Basalam category tree
	 * ------------------------------------------------------------------ */

	/**
	 * Downloads the Basalam category tree and caches a flat list.
	 *
	 * @return int Number of categories.
	 * @throws SLH_Api_Error On API failure.
	 */
	public static function refresh() {
		$tree  = SLH_Plugin::api()->categories();
		$items = array();
		self::flatten( $tree, 0, array(), $items );
		if ( ! $items ) {
			throw new SLH_Api_Error(
				__( 'فهرست دسته‌های باسلام خالی برگشت.', 'salamhub' ),
				'server',
				array(
					'retryable'  => true,
					'reason'     => __( 'باسلام دسته‌ای برنگرداند.', 'salamhub' ),
					'suggestion' => __( 'چند دقیقه‌ی بعد دوباره «به‌روزرسانی فهرست» را بزن.', 'salamhub' ),
				)
			);
		}
		update_option( self::CACHE_OPTION, array( 'fetched_at' => slh_now(), 'items' => $items ), false );
		return count( $items );
	}

	/**
	 * @param array $nodes  Nodes.
	 * @param int   $parent Parent ID.
	 * @param array $path   Titles of ancestors.
	 * @param array $out    Output (by reference): id => [title, path, parent, leaf].
	 */
	private static function flatten( array $nodes, $parent, array $path, array &$out ) {
		foreach ( $nodes as $node ) {
			if ( empty( $node['id'] ) || ! isset( $node['title'] ) ) {
				continue;
			}
			$id       = (int) $node['id'];
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$here     = array_merge( $path, array( (string) $node['title'] ) );
			$out[ $id ] = array(
				'title'  => (string) $node['title'],
				'path'   => implode( ' › ', $here ),
				'parent' => (int) $parent,
				'leaf'   => empty( $children ),
			);
			if ( $children ) {
				self::flatten( $children, $id, $here, $out );
			}
		}
	}

	/**
	 * @return array{fetched_at: string, items: array<int,array>}
	 */
	public static function cache() {
		$c = get_option( self::CACHE_OPTION, array() );
		return array(
			'fetched_at' => isset( $c['fetched_at'] ) ? $c['fetched_at'] : '',
			'items'      => isset( $c['items'] ) && is_array( $c['items'] ) ? $c['items'] : array(),
		);
	}

	/**
	 * @param int $id Basalam category ID.
	 * @return array|null
	 */
	public static function find( $id ) {
		$items = self::cache()['items'];
		return isset( $items[ (int) $id ] ) ? $items[ (int) $id ] : null;
	}

	/**
	 * Human label "Path › Title (id)" or just the ID if the list was never downloaded.
	 *
	 * @param int $id Basalam category ID.
	 * @return string
	 */
	public static function label( $id ) {
		$cat = self::find( $id );
		return $cat ? $cat['path'] : '#' . (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Mapping
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int,array{category_id:int, attrs:array<int,string>}> term_id => mapping.
	 */
	public static function map() {
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Validates and stores the mapping submitted from the mapping page.
	 *
	 * @param array $input term_id => ['category_id' => …, 'attrs' => [attr_id => value]].
	 * @return array<int,string> Errors keyed by term_id.
	 */
	public static function save_map( array $input ) {
		$errors = array();
		$items  = self::cache()['items'];
		$clean  = array();
		foreach ( $input as $term_id => $row ) {
			$term_id = (int) $term_id;
			$raw     = isset( $row['category_id'] ) ? trim( (string) $row['category_id'] ) : '';
			// Accept "Path › Title (1234)" from the search box as well as a bare ID.
			if ( preg_match( '/\((\d+)\)\s*$/u', $raw, $m ) ) {
				$raw = $m[1];
			}
			$raw = strtr( $raw, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
			if ( '' === $raw ) {
				continue;
			}
			if ( ! ctype_digit( $raw ) ) {
				$errors[ $term_id ] = __( 'دسته را از فهرست پیشنهادی انتخاب کن یا شناسه‌ی عددی‌اش را بنویس.', 'salamhub' );
				continue;
			}
			$cat_id = (int) $raw;
			if ( $items && ! isset( $items[ $cat_id ] ) ) {
				$errors[ $term_id ] = __( 'این شناسه در فهرست دسته‌های باسلام نیست.', 'salamhub' );
				continue;
			}
			if ( $items && ! $items[ $cat_id ]['leaf'] ) {
				$errors[ $term_id ] = __( 'این دسته زیرمجموعه دارد؛ باسلام محصول را فقط در دسته‌ی آخر (بدون زیرمجموعه) می‌پذیرد.', 'salamhub' );
				continue;
			}
			$attrs = array();
			if ( isset( $row['attrs'] ) && is_array( $row['attrs'] ) ) {
				foreach ( $row['attrs'] as $attr_id => $value ) {
					$value = sanitize_text_field( (string) $value );
					if ( '' !== $value ) {
						$attrs[ (int) $attr_id ] = $value;
					}
				}
			}
			$clean[ $term_id ] = array( 'category_id' => $cat_id, 'attrs' => $attrs );
		}
		update_option( self::MAP_OPTION, $clean, false );
		return $errors;
	}

	/**
	 * Finds the Basalam category for a product from the mapping.
	 *
	 * @param WC_Product $product Product.
	 * @return array{category_id:int, term_id:int} category_id 0 when unmapped.
	 */
	public static function resolve( WC_Product $product ) {
		$map  = self::map();
		$best = array( 'category_id' => 0, 'term_id' => 0, 'depth' => -1 );
		if ( ! $map ) {
			return array( 'category_id' => 0, 'term_id' => 0 );
		}
		foreach ( $product->get_category_ids() as $term_id ) {
			$chain = array_merge( array( (int) $term_id ), array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
			$depth = count( $chain );
			foreach ( $chain as $candidate ) {
				if ( isset( $map[ $candidate ] ) && $map[ $candidate ]['category_id'] > 0 ) {
					if ( $depth > $best['depth'] ) {
						$best = array( 'category_id' => (int) $map[ $candidate ]['category_id'], 'term_id' => $candidate, 'depth' => $depth );
					}
					break;
				}
			}
		}
		return array( 'category_id' => $best['category_id'], 'term_id' => $best['term_id'] );
	}

	/**
	 * slh_basalam_category filter: fill in from the mapping when the product has no override.
	 *
	 * @param int        $cat     Current value.
	 * @param WC_Product $product Product.
	 * @return int
	 */
	public static function filter_category( $cat, $product ) {
		if ( (int) $cat > 0 ) {
			return (int) $cat;
		}
		return self::resolve( $product )['category_id'];
	}

	/**
	 * WooCommerce categories that have published products but no mapping (directly or via a parent).
	 *
	 * @return WP_Term[]
	 */
	public static function unmapped_terms() {
		$map   = self::map();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$chain  = array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) );
			$mapped = false;
			foreach ( $chain as $id ) {
				if ( isset( $map[ $id ] ) ) {
					$mapped = true;
					break;
				}
			}
			if ( ! $mapped ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Category attributes
	 * ------------------------------------------------------------------ */

	/**
	 * Attributes of a Basalam category, flattened: [{id, title, required, unit, options: [id => title]}].
	 * Cached for a week; fetched from the API when missing (only from admin AJAX or the queue).
	 *
	 * @param int  $category_id Basalam category.
	 * @param bool $fetch       Whether to call the API when not cached.
	 * @return array[]|null Null when not cached and $fetch is false.
	 * @throws SLH_Api_Error When fetching fails.
	 */
	public static function attributes( $category_id, $fetch = true ) {
		$category_id = (int) $category_id;
		$all         = get_option( self::ATTR_OPTION, array() );
		$all         = is_array( $all ) ? $all : array();
		if ( isset( $all[ $category_id ] ) && strtotime( $all[ $category_id ]['at'] . ' UTC' ) > time() - self::ATTR_TTL ) {
			return $all[ $category_id ]['attrs'];
		}
		if ( ! $fetch ) {
			return null;
		}
		$attrs = array();
		foreach ( SLH_Plugin::api()->category_attributes( $category_id ) as $group ) {
			foreach ( isset( $group['attributes'] ) && is_array( $group['attributes'] ) ? $group['attributes'] : array() as $a ) {
				if ( empty( $a['id'] ) ) {
					continue;
				}
				$options = array();
				foreach ( isset( $a['selected_values'] ) && is_array( $a['selected_values'] ) ? $a['selected_values'] : array() as $v ) {
					if ( isset( $v['id'] ) ) {
						$options[ (int) $v['id'] ] = isset( $v['title'] ) ? (string) $v['title'] : (string) ( isset( $v['value'] ) ? $v['value'] : $v['id'] );
					}
				}
				$attrs[] = array(
					'id'       => (int) $a['id'],
					'title'    => isset( $a['title'] ) ? (string) $a['title'] : '#' . $a['id'],
					'required' => ! empty( $a['required'] ),
					'unit'     => isset( $a['unit'] ) ? (string) $a['unit'] : '',
					'options'  => $options,
				);
			}
		}
		// Keep the cache small: at most 200 categories.
		if ( count( $all ) >= 200 ) {
			$all = array_slice( $all, -150, null, true );
		}
		$all[ $category_id ] = array( 'at' => slh_now(), 'attrs' => $attrs );
		update_option( self::ATTR_OPTION, $all, false );
		return $attrs;
	}

	/**
	 * Builds `product_attribute` for a product: a WooCommerce attribute with the same name
	 * wins, otherwise the default value set on the category mapping page.
	 *
	 * @param WC_Product $product     Product.
	 * @param int        $category_id Basalam category.
	 * @param int        $term_id     Mapped WooCommerce term (for defaults).
	 * @return array{payload: array[], missing: string[]}
	 */
	public static function product_attributes( WC_Product $product, $category_id, $term_id ) {
		$out = array( 'payload' => array(), 'missing' => array() );
		try {
			$attrs = self::attributes( $category_id, true );
		} catch ( SLH_Api_Error $e ) {
			return $out; // Attributes are best-effort; Basalam's own validation still applies.
		}
		if ( ! $attrs ) {
			return $out;
		}

		$map      = self::map();
		$defaults = $term_id && isset( $map[ $term_id ]['attrs'] ) ? $map[ $term_id ]['attrs'] : array();
		$own      = self::wc_attribute_values( $product );

		foreach ( $attrs as $a ) {
			$key   = self::normalize( $a['title'] );
			$value = isset( $own[ $key ] ) ? $own[ $key ] : ( isset( $defaults[ $a['id'] ] ) ? $defaults[ $a['id'] ] : '' );
			if ( '' === $value ) {
				if ( $a['required'] ) {
					$out['missing'][] = $a['title'];
				}
				continue;
			}
			if ( $a['options'] ) {
				$option_id = isset( $a['options'][ (int) $value ] ) ? (int) $value : (int) array_search( self::normalize( $value ), array_map( array( __CLASS__, 'normalize' ), $a['options'] ), true );
				if ( $option_id ) {
					$out['payload'][] = array( 'attribute_id' => $a['id'], 'selected_values' => array( $option_id ) );
				} elseif ( $a['required'] ) {
					$out['missing'][] = $a['title'];
				}
				continue;
			}
			$out['payload'][] = array( 'attribute_id' => $a['id'], 'value' => $value );
		}
		return $out;
	}

	/**
	 * slh_product_payload filter: adds category attributes.
	 *
	 * @param array      $payload Payload.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function filter_payload( $payload, $product ) {
		if ( empty( $payload['category_id'] ) || ! SLH_Settings::is_connected() ) {
			return $payload;
		}
		$resolved = self::resolve( $product );
		$term_id  = (int) $resolved['category_id'] === (int) $payload['category_id'] ? $resolved['term_id'] : 0;
		$attrs    = self::product_attributes( $product, (int) $payload['category_id'], $term_id );
		if ( $attrs['payload'] ) {
			$payload['product_attribute'] = $attrs['payload'];
		}
		if ( $attrs['missing'] ) {
			$payload['_slh_missing_attributes'] = $attrs['missing'];
		}
		return $payload;
	}

	/**
	 * WooCommerce attribute values of a product keyed by normalized label.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,string>
	 */
	private static function wc_attribute_values( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$label = wc_attribute_label( $attribute->get_name(), $product );
			if ( $attribute->is_taxonomy() ) {
				$names = array_map(
					function ( $term ) {
						return $term->name;
					},
					$attribute->get_terms() ? $attribute->get_terms() : array()
				);
			} else {
				$names = $attribute->get_options();
			}
			if ( $names ) {
				$out[ self::normalize( $label ) ] = implode( '، ', $names );
			}
		}
		return $out;
	}

	/**
	 * Normalizes Persian/Arabic letters and spacing for loose name matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = strtr( (string) $text, array( 'ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' ', 'ة' => 'ه' ) );
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) ) );
	}
}
