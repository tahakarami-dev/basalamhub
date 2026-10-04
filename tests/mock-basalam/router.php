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
	$id                       = $state['next_id']++;
	$state['products'][ $id ] = array_merge( $body, array( 'id' => $id, 'vendor_id' => (int) $m[1] ) );
	if ( $injected ) {
		out( $injected['status'], array( 'detail' => 'gateway timeout' ), $state, $state_file );
	}
	out( 201, array( 'id' => $id, 'title' => $body['name'], 'sku' => $body['sku'] ?? null ), $state, $state_file );
}

if ( 'GET' === $method && preg_match( '#^/v1/vendors/(\d+)/products$#', $path ) ) {
	parse_str( str_replace( 'skus=', 'skus[]=', $_SERVER['QUERY_STRING'] ?? '' ), $q );
	$skus = isset( $q['skus'] ) ? (array) $q['skus'] : array();
	$data = array_values( array_filter( $state['products'], function ( $p ) use ( $skus ) {
		return ! $skus || in_array( $p['sku'] ?? '', $skus, true );
	} ) );
	out( 200, array( 'data' => $data, 'result_count' => count( $data ), 'page' => 1, 'per_page' => 10 ), $state, $state_file );
}

if ( preg_match( '#^/v1/products/(\d+)$#', $path, $m ) ) {
	$id = (int) $m[1];
	if ( ! isset( $state['products'][ $id ] ) ) {
		out( 404, array( 'detail' => 'Not found' ), $state, $state_file );
	}
	if ( 'PATCH' === $method ) {
		$state['products'][ $id ] = array_merge( $state['products'][ $id ], (array) $body );
	}
	out( 200, $state['products'][ $id ], $state, $state_file );
}

out( 404, array( 'detail' => 'mock: unknown route ' . $route_key ), $state, $state_file );
