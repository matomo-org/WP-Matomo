<?php

namespace WP_Piwik\Tests;

/**
 * Drives the mock Matomo endpoint in tests/phpunit/rest/mock.
 */
trait Mock_Matomo_Endpoint {

	/**
	 * @var string
	 */
	private $runtime;

	private function set_up_mock_endpoint() {
		$this->runtime = __DIR__ . '/mock/runtime';
		if ( ! is_dir( $this->runtime ) ) {
			mkdir( $this->runtime, 0777, true );
		}

		// the mock runs as the web server user, the tests as the cli user
		chmod( $this->runtime, 0777 );
		$this->write_runtime_file( 'requests.jsonl', '' );
	}

	private static function is_in_wp_env_environment() {
		return defined( 'IN_WP_ENV' ) && IN_WP_ENV;
	}

	private function write_runtime_file( $name, $contents ) {
		file_put_contents( $this->runtime . '/' . $name, $contents );
		chmod( $this->runtime . '/' . $name, 0666 );
	}

	private function set_mock_response( array $response ) {
		$this->write_runtime_file( 'response.json', wp_json_encode( $response ) );
	}

	private function get_captured_requests() {
		$raw = file_get_contents( $this->runtime . '/requests.jsonl' );
		if ( '' === trim( (string) $raw ) ) {
			return [];
		}
		$requests = [];
		foreach ( explode( "\n", trim( $raw ) ) as $line ) {
			if ( '' !== $line ) {
				$requests[] = json_decode( $line, true );
			}
		}
		return $requests;
	}

	/**
	 * @param bool $trailing_slash true to add a trailing slash, false if otherwise.
	 *
	 *                             A URL without the trailing slash makes the web
	 *                             server answer with a 301 to URL with a slash.
	 */
	private function mock_url( $trailing_slash = true ) {
		$host         = getenv( 'WP_MATOMO_TEST_HTTP_HOST' ) ? getenv( 'WP_MATOMO_TEST_HTTP_HOST' ) : 'tests-wordpress';
		$plugins_path = wp_parse_url( plugins_url(), PHP_URL_PATH );
		// this file lives in <plugin>/tests/phpunit/rest
		$plugin_dir = basename( dirname( __DIR__, 3 ) );

		return 'http://' . $host . rtrim( $plugins_path, '/' ) . '/' . $plugin_dir
			. '/tests/phpunit/rest/mock' . ( $trailing_slash ? '/' : '' );
	}
}
