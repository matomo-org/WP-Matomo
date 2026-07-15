<?php
/**
 * Mock Matomo tracking endpoint for the tracker-proxy integration tests.
 *
 * @package wp-piwik
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 */

$runtime = __DIR__ . '/runtime';

if ( function_exists( 'getallheaders' ) ) {
	$request_headers = getallheaders();
} else {
	$request_headers = [];
	foreach ( $_SERVER as $key => $value ) {
		if ( strpos( $key, 'HTTP_' ) === 0 ) {
			$name                     = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $key, 5 ) ) ) ) );
			$request_headers[ $name ] = $value;
		}
	}
}

$record = [
	'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '',
	'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '',
	'script'  => basename( isset( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : '' ),
	'get'     => $_GET,
	'post'    => $_POST,
	'body'    => file_get_contents( 'php://input' ),
	'headers' => $request_headers,
];

file_put_contents( $runtime . '/requests.jsonl', json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );

$response = [
	'status'  => 200,
	'headers' => [ 'Content-Type' => 'image/gif' ],
	'body'    => 'MOCKGIF',
];

$response_file = $runtime . '/response.json';
if ( is_file( $response_file ) ) {
	$decoded = json_decode( file_get_contents( $response_file ), true );
	if ( is_array( $decoded ) ) {
		$response = array_merge( $response, $decoded );
	}
}

http_response_code( (int) $response['status'] );

foreach ( (array) $response['headers'] as $name => $value ) {
	header( $name . ': ' . $value, true );
}

if ( isset( $response['body_base64'] ) ) {
	echo base64_decode( $response['body_base64'] );
} else {
	echo $response['body'];
}
