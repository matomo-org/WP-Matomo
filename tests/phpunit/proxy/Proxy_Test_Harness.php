<?php

namespace WP_Piwik\Tests;

/**
 * Manages the bundled tracker proxy within a running wp-env environment.
 * - configures the proxy to use a mock Matomo endpoint at tests/phpunit/proxy/mock/
 * - captures requests sent to it for later inspection
 */
class Proxy_Test_Harness {

	/**
	 * token the proxy is configured to inject / scrub.
	 *
	 * @var string
	 */
	public $token = 'secret_token_abcdef0123456789';

	private $proxy_host;

	private $matomo_host = 'localhost';

	private $plugin_http_path;

	private $mock_matomo_dir;

	private $config_local_path;

	public function start() {
		$this->proxy_host = getenv( 'WP_MATOMO_TEST_HTTP_HOST' ) ? getenv( 'WP_MATOMO_TEST_HTTP_HOST' ) : 'tests-wordpress';

		$plugins_path            = wp_parse_url( plugins_url(), PHP_URL_PATH );
		$plugin_dir              = basename( dirname( __DIR__, 3 ) );
		$this->plugin_http_path  = rtrim( $plugins_path, '/' ) . '/' . $plugin_dir . '/';
		$this->mock_matomo_dir   = __DIR__ . '/mock/runtime';
		$this->config_local_path = dirname( __DIR__, 3 ) . '/proxy/config.local.php';

		if ( ! is_dir( $this->mock_matomo_dir ) && ! mkdir( $this->mock_matomo_dir, 0777, true ) && ! is_dir( $this->mock_matomo_dir ) ) {
			throw new \Exception( 'Could not create the mock matomo directory' );
		}
		chmod( $this->mock_matomo_dir, 0777 );

		$this->configure_plugin();
		$this->reset();
		$this->check_server_is_working();
	}

	private function configure_plugin() {
		$options = [
			'piwik_mode'         => 'http',
			'piwik_url'          => $this->matomo_base_url(),
			'piwik_token'        => $this->token,
			'connection_timeout' => '5',
		];
		foreach ( $options as $key => $value ) {
			$this->wp_cli( 'option update ' . escapeshellarg( 'wp-piwik_global-' . $key ) . ' ' . escapeshellarg( $value ) );
		}
	}

	private function wp_cli( $args ) {
		$cmd  = 'cd ' . escapeshellarg( ABSPATH ) . ' && wp --skip-plugins --skip-themes ' . $args . ' 2>&1';
		$code = 0;
		exec( $cmd, $output, $code );
		if ( 0 !== $code ) {
			throw new \Exception( 'wp-cli command failed (' . $args . '): ' . implode( "\n", $output ) );
		}
		return $output;
	}

	private function check_server_is_working() {
		$response = $this->get( 'matomo.php', [ 'idsite' => 1 ] );
		if ( 200 !== $response->status ) {
			throw new \Exception( 'The proxy did not respond as expected (status ' . $response->status . ')' );
		}
	}

	public function stop() {
		if ( $this->mock_matomo_dir && is_dir( $this->mock_matomo_dir ) ) {
			unlink( $this->mock_matomo_dir . '/requests.jsonl' );
			unlink( $this->mock_matomo_dir . '/response.json' );
		}
		$this->remove_config_local();
	}

	public function matomo_base_url() {
		return 'http://' . $this->matomo_host . $this->plugin_http_path . 'tests/phpunit/proxy/mock/';
	}

	public function proxy_base_url() {
		return 'http://' . $this->proxy_host . $this->plugin_http_path . 'proxy/';
	}

	public function reset() {
		$requests_file = $this->mock_matomo_dir . '/requests.jsonl';
		file_put_contents( $requests_file, '' );
		chmod( $requests_file, 0666 );
		$this->set_matomo_tracker_response( 200, [ 'Content-Type' => 'image/gif' ], 'MOCKGIF' );

		$this->set_cookie_allowlist( '' );
		$this->remove_config_local();
	}

	public function set_cookie_allowlist( $value ) {
		$this->wp_cli( 'option update ' . escapeshellarg( 'wp-piwik_global-cookie_allowlist' ) . ' ' . escapeshellarg( $value ) );
	}

	public function set_config_local( $php_code ) {
		file_put_contents( $this->config_local_path, $php_code );
		chmod( $this->config_local_path, 0666 );
	}

	public function remove_config_local() {
		if ( $this->config_local_path && file_exists( $this->config_local_path ) ) {
			unlink( $this->config_local_path );
		}
	}

	public function set_matomo_tracker_response( $status, array $headers, $body, $body_is_base64 = false ) {
		$response = [
			'status'  => $status,
			'headers' => $headers,
		];
		if ( $body_is_base64 ) {
			$response['body_base64'] = $body;
		} else {
			$response['body'] = $body;
		}
		$response_file = $this->mock_matomo_dir . '/response.json';
		file_put_contents( $response_file, json_encode( $response ) );
		chmod( $response_file, 0666 );
	}

	public function get_captured_requests() {
		$raw = file_get_contents( $this->mock_matomo_dir . '/requests.jsonl' );
		if ( '' === trim( (string) $raw ) ) {
			return [];
		}
		$requests = [];
		foreach ( explode( "\n", trim( $raw ) ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$requests[] = json_decode( $line, true );
		}
		return $requests;
	}

	public function get_single_captured_request() {
		$requests = $this->get_captured_requests();
		if ( 1 !== count( $requests ) ) {
			throw new \Exception( 'Expected exactly one proxied request, saw ' . count( $requests ) );
		}
		return $requests[0];
	}

	public function get( $path, array $query = [], array $headers = [] ) {
		return $this->http( 'GET', $path, $query, $headers, null );
	}

	public function post( $path, $body, array $query = [], array $headers = [] ) {
		$headers += [ 'Content-Type' => 'application/x-www-form-urlencoded' ];
		return $this->http( 'POST', $path, $query, $headers, $body );
	}

	private function http( $method, $path, array $query, array $headers, $body ) {
		$url = $this->proxy_base_url() . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $query );
		}

		$header_lines = [ 'Expect:' ]; // disable curl's 100-continue handshake
		foreach ( $headers as $name => $value ) {
			$header_lines[] = $name . ': ' . $value;
		}

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_HTTPHEADER     => $header_lines,
				CURLOPT_TIMEOUT        => 15,
			]
		);
		if ( 'POST' === $method ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, (string) $body );
		}

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$error = curl_error( $ch );
			if ( version_compare( PHP_VERSION, '8', '<' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
				curl_close( $ch );
			}
			throw new \Exception( 'Request to the proxy failed: ' . $error );
		}

		$status      = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		if ( version_compare( PHP_VERSION, '8', '<' ) ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
			curl_close( $ch );
		}

		$raw_headers = substr( $raw, 0, $header_size );
		$body_out    = substr( $raw, $header_size );

		return (object) [
			'status'      => $status,
			'headers'     => $this->parse_headers( $raw_headers ),
			'raw_headers' => $raw_headers,
			'body'        => $body_out,
		];
	}

	private function parse_headers( $raw_headers ) {
		$headers = [];
		foreach ( preg_split( '/\r\n|\n/', trim( $raw_headers ) ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue; // status line
			}
			list( $name, $value )                   = explode( ':', $line, 2 );
			$headers[ strtolower( trim( $name ) ) ] = trim( $value );
		}
		return $headers;
	}
}
