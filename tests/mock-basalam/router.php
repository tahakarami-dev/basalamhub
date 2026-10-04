<?php
/**
 * A small fake of Basalam's Open API for local end-to-end tests.
 *
 *   php -S 127.0.0.1:8099 tests/mock-basalam/router.php
 *   define( 'SLH_API_BASE', 'http://127.0.0.1:8099' ); // in wp-config.php
 *
 * Valid token: "good-token". "novendor-token" = account without a booth.
 * Fault injection: write {"fail": {"POST /v1/vendors/*\/products": {"status": 504, "times": 1, "create": true}}}
 * into the state file; "create": true makes the product anyway (lost response).
 *
 * @package SalamHub
 */

$state_file = getenv( 'SLH_MOCK_STATE' ) ?: sys_get_temp_dir() . '/slh-mock-state.json';
$state      = file_exists( $state_file ) ? json_decode( file_get_contents( $state_file ), true ) : array();
$state     += array( 'products' => array(), 'files' => array(), 'next_id' => 9000, 'requests' => array(), 'fail' => array() );

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$auth   = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$token  = preg_replace( '/^Bearer\s+/i', '', $auth );
$raw    = file_get_contents( 'php://input' );
$body   = json_decode( $raw, true );

$state['requests'][] = array( 'method' => $method, 'path' => $path, 'query' => $_SERVER['QUERY_STRING'] ?? '', 'body' => is_array( $body ) ? $body : ( $_POST ?: null ) );

/** ProductVariants input → VariantResponse-like records with new IDs. */
function mock_variants( array $in, array &$state ) {
	$out = array();
	foreach ( $in as $v ) {
		$props = array();
		foreach ( $v['properties'] ?? array() as $p ) {
			$props[] = array( 'property' => array( 'id' => crc32( $p['property'] ) % 1000, 'title' => $p['property'] ), 'value' => array( 'id' => crc32( $p['value'] ) % 10000, 'title' => $p['value'] ) );
		}
		$out[] = array( 'id' => $state['next_id']++, 'primary_price' => $v['primary_price'], 'stock' => $v['stock'], 'sku' => $v['sku'] ?? null, 'properties' => $props );
	}
	return $out;
}

function out( $code, $data, &$state, $state_file ) {
	file_put_contents( $state_file, json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE );
	exit;
}

if ( ! in_array( $token, array( 'good-token', 'novendor-token' ), true ) ) {
	out( 401, array( 'detail' => 'Invalid token' ), $state, $state_file );
}

// Fault injection.
$route_key = $method . ' ' . preg_replace( '#/\d+#', '/*', $path );
$injected  = null;
if ( isset( $state['fail'][ $route_key ] ) && $state['fail'][ $route_key ]['times'] > 0 ) {
	$state['fail'][ $route_key ]['times']--;
	$injected = $state['fail'][ $route_key ];
	if ( empty( $injected['create'] ) ) {
		if ( 429 === $injected['status'] ) {
			header( 'Retry-After: 30' );
		}
		out( $injected['status'], array( 'detail' => 'injected failure' ), $state, $state_file );
	}
}

if ( 'GET' === $method && '/v1/users/me' === $path ) {
	$vendor = 'good-token' === $token
		? array( 'id' => 555, 'identifier' => 'asal-kuhpaye', 'title' => 'عسل طبیعی کوهپایه', 'is_active' => true )
		: null;
	out( 200, array( 'id' => 77, 'name' => 'طاها', 'vendor' => $vendor ), $state, $state_file );
}

if ( 'POST' === $method && '/v1/files' === $path ) {
	if ( empty( $_FILES['file'] ) || ( $_POST['file_type'] ?? '' ) !== 'product.photo' ) {
		out( 422, array( 'detail' => array( array( 'loc' => array( 'body', 'file' ), 'msg' => 'field required' ) ) ), $state, $state_file );
	}
	$id   = $state['next_id']++;
	$info = @getimagesize( $_FILES['file']['tmp_name'] );
	$state['files'][ $id ] = array( 'name' => $_FILES['file']['name'], 'size' => $_FILES['file']['size'], 'width' => $info ? $info[0] : 0, 'height' => $info ? $info[1] : 0, 'mime' => $info ? $info['mime'] : '' );
	out( 201, array( 'id' => $id, 'file_name' => $_FILES['file']['name'], 'url' => 'https://statics.basalam.com/' . $id . '.jpg' ), $state, $state_file );
}

if ( 'GET' === $method && '/v1/categories' === $path ) {
	out( 200, array( 'data' => array(
		array( 'id' => 1, 'title' => 'خوراکی', 'children' => array(
			array( 'id' => 1287, 'title' => 'عسل', 'children' => array() ),
			array( 'id' => 1300, 'title' => 'ادویه', 'children' => array(
				array( 'id' => 1301, 'title' => 'زعفران', 'children' => null ),
			) ),
		) ),
		array( 'id' => 2, 'title' => 'پوشاک', 'children' => array(
			array( 'id' => 2000, 'title' => 'تیشرت', 'children' => array() ),
		) ),
	) ), $state, $state_file );
}

if ( 'GET' === $method && preg_match( '#^/v1/categories/(\d+)/attributes$#', $path, $m ) ) {
	$groups = array();
	if ( '2000' === $m[1] ) {
		$groups[] = array( 'title' => 'مشخصات', 'attributes' => array(
			array( 'id' => 501, 'title' => 'جنس', 'type' => null, 'required' => true, 'selected_values' => array() ),
			array( 'id' => 502, 'title' => 'یقه', 'type' => null, 'required' => true, 'selected_values' => array(
				array( 'id' => 7001, 'title' => 'گرد', 'value' => 'round', 'attribute_id' => 502 ),
				array( 'id' => 7002, 'title' => 'هفت', 'value' => 'v', 'attribute_id' => 502 ),
			) ),
			array( 'id' => 503, 'title' => 'کشور سازنده', 'type' => null, 'required' => false, 'selected_values' => array() ),
		) );
	}
	out( 200, array( 'data' => $groups ), $state, $state_file );
}

if ( 'POST' === $method && preg_match( '#^/v1/vendors/(\d+)/products$#', $path, $m ) ) {
	$missing = array();
	if ( isset( $body['category_id'] ) && 2000 === $body['category_id'] ) {
		$given = array_column( isset( $body['product_attribute'] ) ? $body['product_attribute'] : array(), 'attribute_id' );
		if ( array_diff( array( 501, 502 ), $given ) ) {
			$missing[] = array( 'loc' => array( 'body', 'product_attribute' ), 'msg' => 'required attributes missing', 'type' => 'value_error' );
		}
	}
	foreach ( array( 'name', 'category_id', 'status', 'preparation_days', 'package_weight' ) as $f ) {
		if ( ! isset( $body[ $f ] ) ) {
			$missing[] = array( 'loc' => array( 'body', $f ), 'msg' => 'field required', 'type' => 'value_error.missing' );
		}
	}
	if ( isset( $body['primary_price'] ) && $body['primary_price'] < 1000 ) {
		$missing[] = array( 'loc' => array( 'body', 'primary_price' ), 'msg' => 'price too low', 'type' => 'value_error' );
	}
	if ( $missing ) {
		out( 422, array( 'detail' => $missing ), $state, $state_file );
	}
	$id = $state['next_id']++;
	if ( ! empty( $body['variants'] ) ) {
		$body['variants'] = mock_variants( $body['variants'], $state );
	}
	$state['products'][ $id ] = array_merge( $body, array( 'id' => $id, 'vendor_id' => (int) $m[1] ) );
	if ( $injected ) {
		out( $injected['status'], array( 'detail' => 'gateway timeout' ), $state, $state_file );
	}
	out( 201, array( 'id' => $id, 'title' => $body['name'], 'sku' => $body['sku'] ?? null, 'variants' => $body['variants'] ?? array() ), $state, $state_file );
}

if ( 'GET' === $method && preg_match( '#^/v1/vendors/(\d+)/products$#', $path ) ) {
	parse_str( preg_replace( '/(^|&)skus=/', '$1skus[]=', $_SERVER['QUERY_STRING'] ?? '' ), $q );
	$skus = isset( $q['skus'] ) ? (array) $q['skus'] : array();
	$data = array_values( array_filter( $state['products'], function ( $p ) use ( $skus ) {
		return ! $skus || in_array( $p['sku'] ?? '', $skus, true );
	} ) );
	$per   = max( 1, (int) ( $q['per_page'] ?? 10 ) );
	$page  = max( 1, (int) ( $q['page'] ?? 1 ) );
	$total = count( $data );
	// VendorProductResponse shape.
	$data = array_map( function ( $p ) {
		return array(
			'id'            => $p['id'],
			'title'         => $p['title'] ?? $p['name'] ?? '',
			'sku'           => $p['sku'] ?? null,
			'price'         => $p['primary_price'] ?? null,
			'primary_price' => $p['primary_price'] ?? null,
			'inventory'     => $p['stock'] ?? 0,
			'photo'         => array( 'id' => 1, 'sm' => 'https://statics.basalam.com/mock/' . $p['id'] . '.jpg' ),
			'variant'       => $p['variants'] ?? array(),
		);
	}, array_slice( $data, ( $page - 1 ) * $per, $per ) );
	out( 200, array( 'data' => $data, 'total_count' => $total, 'result_count' => count( $data ), 'total_page' => (int) ceil( $total / $per ), 'page' => $page, 'per_page' => $per ), $state, $state_file );
}

if ( 'PATCH' === $method && preg_match( '#^/v1/products/(\d+)/variations/(\d+)$#', $path, $m ) ) {
	$pid = (int) $m[1];
	$vid = (int) $m[2];
	if ( ! isset( $state['products'][ $pid ] ) ) {
		out( 404, array( 'detail' => 'Not found' ), $state, $state_file );
	}
	foreach ( $state['products'][ $pid ]['variants'] ?? array() as $i => $v ) {
		if ( (int) $v['id'] === $vid ) {
			$state['products'][ $pid ]['variants'][ $i ] = array_merge( $v, (array) $body );
			out( 200, $state['products'][ $pid ], $state, $state_file );
		}
	}
	out( 404, array( 'detail' => 'Variation not found' ), $state, $state_file );
}

if ( preg_match( '#^/v1/products/(\d+)$#', $path, $m ) ) {
	$id = (int) $m[1];
	if ( ! isset( $state['products'][ $id ] ) ) {
		out( 404, array( 'detail' => 'Not found' ), $state, $state_file );
	}
	if ( 'PATCH' === $method ) {
		if ( isset( $body['variants'] ) ) {
			$new = mock_variants( $body['variants'], $state );
			// Basalam's real behaviour is unverified: "replace" (default) or "append".
			$body['variants'] = 'append' === ( $state['variants_mode'] ?? 'replace' ) ? array_merge( $state['products'][ $id ]['variants'] ?? array(), $new ) : $new;
		}
		$state['products'][ $id ] = array_merge( $state['products'][ $id ], (array) $body );
	}
	out( 200, $state['products'][ $id ], $state, $state_file );
}

// Orders (vendor parcels). Tests put full ParcelResponse records into $state['parcels'].
if ( 'GET' === $method && '/v1/vendor-parcels' === $path ) {
	parse_str( $_SERVER['QUERY_STRING'] ?? '', $q );
	$list = array_values( $state['parcels'] ?? array() );
	usort( $list, function ( $a, $b ) {
		return strcmp( $b['created_at'], $a['created_at'] ) ?: $b['id'] - $a['id'];
	} );
	if ( ! empty( $q['ids'] ) ) {
		$ids  = array_map( 'intval', explode( ',', $q['ids'] ) );
		$list = array_values( array_filter( $list, function ( $p ) use ( $ids ) { return in_array( (int) $p['id'], $ids, true ); } ) );
	}
	if ( ! empty( $q['statuses'] ) ) {
		$st   = array_map( 'intval', explode( ',', $q['statuses'] ) );
		$list = array_values( array_filter( $list, function ( $p ) use ( $st ) { return in_array( (int) $p['status']['id'], $st, true ); } ) );
	}
	$per    = max( 1, (int) ( $q['per_page'] ?? 30 ) );
	$offset = isset( $q['cursor'] ) ? (int) base64_decode( $q['cursor'] ) : 0;
	$page   = array_slice( $list, $offset, $per );
	$next   = $offset + $per < count( $list ) ? base64_encode( (string) ( $offset + $per ) ) : null;
	out( 200, array( 'data' => $page, 'next_cursor' => $next, 'previous_cursor' => null ), $state, $state_file );
}

if ( preg_match( '#^/v1/vendor-parcels/(\d+)(/set-preparation|/set-posted)?$#', $path, $m ) ) {
	$id = (int) $m[1];
	if ( ! isset( $state['parcels'][ $id ] ) ) {
		out( 404, array( 'detail' => 'Parcel not found' ), $state, $state_file );
	}
	$status = (int) $state['parcels'][ $id ]['status']['id'];
	$action = $m[2] ?? '';
	if ( '/set-preparation' === $action ) {
		if ( 3739 !== $status ) {
			out( 422, array( 'message' => array( array( 'message' => 'وضعیت سفارش اجازه‌ی این تغییر را نمی‌دهد' ) ) ), $state, $state_file );
		}
		$state['parcels'][ $id ]['status'] = array( 'id' => 3237, 'title' => 'در حال آماده سازی' );
		out( 200, array( 'id' => $id ), $state, $state_file );
	}
	if ( '/set-posted' === $action ) {
		if ( empty( $body['shipping_method'] ) ) {
			out( 422, array( 'message' => array( array( 'fields' => array( 'shipping_method' ), 'message' => 'field required' ) ) ), $state, $state_file );
		}
		if ( ! in_array( $status, array( 3739, 3237, 5017 ), true ) ) {
			out( 422, array( 'message' => array( array( 'message' => 'وضعیت سفارش اجازه‌ی این تغییر را نمی‌دهد' ) ) ), $state, $state_file );
		}
		$state['parcels'][ $id ]['status']       = array( 'id' => 3238, 'title' => 'ارسال شده' );
		$state['parcels'][ $id ]['post_receipt'] = array( 'tracking_code' => $body['tracking_code'] ?? null, 'shipping_method' => $body['shipping_method'] );
		out( 200, array( 'id' => $id ), $state, $state_file );
	}
	if ( 'GET' === $method ) {
		out( 200, $state['parcels'][ $id ], $state, $state_file );
	}
}

out( 404, array( 'detail' => 'mock: unknown route ' . $route_key ), $state, $state_file );
