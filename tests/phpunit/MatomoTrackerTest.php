<?php

namespace WP_Piwik\Tests;

if ( ! class_exists( '\WP_Piwik\MatomoTracker' ) ) {
	require_once dirname( __DIR__, 2 ) . '/libs/matomo-php-tracker/MatomoTracker.php';
}

/**
 * @phpcs:disable PHPCompatibility.FunctionDeclarations.NewReturnTypeDeclarations.boolFound
 */
class Testable_Matomo_Tracker extends \WP_Piwik\MatomoTracker {

	private $curl_support;

	public function __construct( $id_site, $curl_support ) {
		parent::__construct( $id_site );
		$this->curl_support = $curl_support;
	}

	public function send_request_public( $url ) {
		return $this->sendRequest( $url );
	}

	protected function hasCurlSupport(): bool {
		return $this->curl_support;
	}
}

/**
 * @phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 */
class MatomoTrackerTest extends WP_Piwik_TestCase {

	private $mock_response_file;

	public function tear_down() {
		if ( $this->mock_response_file && file_exists( $this->mock_response_file ) ) {
			unlink( $this->mock_response_file );
			$this->mock_response_file = null;
		}
		parent::tear_down();
	}

	public function test_sendRequest_should_return_an_empty_string_when_matomo_cannot_be_reached_without_curl() {
		$tracker = new Testable_Matomo_Tracker( 1, false );

		$this->assertSame( '', $tracker->send_request_public( $this->get_unreachable_url() ) );
	}

	public function test_sendRequest_should_leave_the_incoming_cookies_empty_when_matomo_cannot_be_reached_without_curl() {
		$tracker = new Testable_Matomo_Tracker( 1, false );

		$tracker->send_request_public( $this->get_unreachable_url() );

		$this->assertSame( [], $tracker->incomingTrackerCookies );
	}

	public function test_sendRequest_should_report_the_transport_error_when_matomo_cannot_be_reached_with_curl() {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			self::markTestSkipped( 'the curl extension is not available' );
		}

		$tracker = new Testable_Matomo_Tracker( 1, true );

		$this->expectException( \RuntimeException::class );

		$tracker->send_request_public( $this->get_unreachable_url() );
	}

	public function test_sendRequest_should_not_parse_an_earlier_responses_cookies_when_the_url_cannot_be_requested_over_http() {
		$mock_url = $this->serve_mock_matomo( [ 'Set-Cookie' => 'stale_cookie=from_the_first_response; Path=/' ] );

		$tracker = new Testable_Matomo_Tracker( 1, false );
		$tracker->send_request_public( $mock_url . '?idsite=1&rec=1' );

		$this->assertSame( [ 'stale_cookie' => 'from_the_first_response' ], $tracker->incomingTrackerCookies );

		// a Matomo URL saved without a scheme is handled by the file stream wrapper, which leaves
		// the response headers PHP 8.4+ keeps process wide untouched
		$tracker->send_request_public( 'stats.example.org/matomo.php?idsite=1&rec=1' );

		$this->assertSame( [], $tracker->incomingTrackerCookies );
	}

	private function get_unreachable_url() {
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$this->assertNotFalse( $server, 'could not reserve a port: ' . $errstr );

		$address = stream_socket_get_name( $server, false );
		fclose( $server );

		return 'http://' . $address . '/matomo.php?idsite=1&rec=1';
	}

	/**
	 * Points the mock Matomo endpoint at tests/phpunit/proxy/mock/ at a response with the given
	 * headers and returns its URL. Needs a web server serving this plugin, so it skips without one.
	 *
	 * @param array $headers response headers the mock should send
	 * @return string URL of the mock tracking endpoint
	 * @throws \Exception If the directory the mock reads its response from cannot be created.
	 */
	private function serve_mock_matomo( array $headers ) {
		if ( ! getenv( 'WP_MATOMO_INTEGRATION_TESTS' ) ) {
			self::markTestSkipped( 'WP_MATOMO_INTEGRATION_TESTS is not set, cannot run this test' );
		}

		$runtime = __DIR__ . '/proxy/mock/runtime';
		if ( ! is_dir( $runtime ) && ! mkdir( $runtime, 0777, true ) && ! is_dir( $runtime ) ) {
			throw new \Exception( 'Could not create the mock matomo directory' );
		}
		chmod( $runtime, 0777 );

		$response_file = $runtime . '/response.json';
		file_put_contents(
			$response_file,
			wp_json_encode(
				[
					'status'  => 200,
					'headers' => $headers,
					'body'    => 'MOCKGIF',
				]
			)
		);
		chmod( $response_file, 0666 );

		$this->mock_response_file = $response_file;

		$plugins_path = wp_parse_url( plugins_url(), PHP_URL_PATH );
		$plugin_dir   = basename( dirname( __DIR__, 2 ) );

		return 'http://localhost' . rtrim( $plugins_path, '/' ) . '/' . $plugin_dir . '/tests/phpunit/proxy/mock/matomo.php';
	}
}
