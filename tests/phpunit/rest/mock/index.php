<?php
/**
 * Mock Matomo API endpoint for the Rest request integration tests.
 * Records every request it receives and answers according to runtime/response.json.
 *
 * @package wp-piwik
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * phpcs:disable WordPress.Security.ValidatedSanitizedInput
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 */

$runtime = __DIR__ . '/runtime';

$record = [
	'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '',
	'uri'    => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '',
	'query'  => isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '',
	'get'    => $_GET,
	'post'   => $_POST,
	'body'   => file_get_contents( 'php://input' ),
];

// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
@file_put_contents( $runtime . '/requests.jsonl', json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );

$response = [
	'status'  => 200,
	'headers' => [ 'Content-Type' => 'application/json' ],
	'body'    => '[]',
];

$response_file = $runtime . '/response.json';
if ( is_file( $response_file ) ) {
	$decoded = json_decode( file_get_contents( $response_file ), true );
	if ( is_array( $decoded ) ) {
		$response = array_merge( $response, $decoded );
	}
}

// answer a bulk request with one result per submitted url
if ( ! empty( $response['echo_bulk'] ) ) {
	$bulk_urls = isset( $_POST['urls'] ) ? $_POST['urls'] : ( isset( $_GET['urls'] ) ? $_GET['urls'] : [] );
	if ( empty( $bulk_urls ) ) {
		// a request that carries no API parameters is answered by Matomo with its
		// UI rather than with JSON, which is what the plugin ends up
		// with when a redirect drops the request body
		$response['headers'] = [ 'Content-Type' => 'text/html; charset=utf-8' ];
		$response['body']    = '<!DOCTYPE html><html><head><title>Matomo</title></head><body>Sign in</body></html>';
	} else {
		$results = [];
		foreach ( (array) $bulk_urls as $index => $url ) {
			$results[] = [ 'nb_visits' => $index + 1 ];
		}
		$response['body'] = json_encode( $results );
	}
}

http_response_code( (int) $response['status'] );

foreach ( (array) $response['headers'] as $name => $value ) {
	header( $name . ': ' . $value, true );
}

echo $response['body'];
