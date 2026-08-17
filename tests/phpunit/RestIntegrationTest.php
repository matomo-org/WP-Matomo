<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request;
use WP_Piwik\Request\Rest;

class RestIntegrationTest extends WP_Piwik_TestCase {

	use Mock_Matomo_Endpoint;

	public function set_up() {
		parent::set_up();

		if ( ! self::is_integration_environment() ) {
			self::markTestSkipped( 'WP_MATOMO_INTEGRATION_TESTS is not set, cannot run this test' );
		}

		$this->set_up_mock_endpoint();
		$this->set_mock_response( [ 'echo_bulk' => true ] );
	}

	/**
	 * The test needs a web server that serves this plugin over HTTP, so it only
	 * runs when the environment opts in. DDEV sets this in .ddev/config.yaml.
	 */
	private static function is_integration_environment() {
		return (bool) getenv( 'WP_MATOMO_INTEGRATION_TESTS' );
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
		$host         = getenv( 'WP_MATOMO_TEST_HTTP_HOST' ) ? getenv( 'WP_MATOMO_TEST_HTTP_HOST' ) : 'localhost';
		$plugins_path = wp_parse_url( plugins_url(), PHP_URL_PATH );
		$plugin_dir   = basename( dirname( __DIR__, 2 ) );

		return 'http://' . $host . rtrim( $plugins_path, '/' ) . '/' . $plugin_dir
			. '/tests/phpunit/rest/mock' . ( $trailing_slash ? '/' : '' );
	}

	private function perform_bulk_request( $url, $connection, $method ) {
		$settings = $this->create_settings(
			[
				'piwik_mode'         => 'http',
				'piwik_url'          => $url,
				'piwik_token'        => 'secret-token',
				'http_connection'    => $connection,
				'http_method'        => $method,
				'cache'              => false,
				'connection_timeout' => 15,
			],
			[ 'site_id' => 1 ]
		);

		$request = new Rest( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		$request->reset();
		$id = Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		return [ $request->perform( $id ), Request::get_last_error() ];
	}

	public function get_transport_providers_to_test() {
		return [
			'curl, POST'  => [ 'curl', 'post', 'POST' ],
			'curl, GET'   => [ 'curl', 'get', 'GET' ],
			'fopen, POST' => [ 'fopen', 'post', 'POST' ],
			'fopen, GET'  => [ 'fopen', 'get', 'GET' ],
		];
	}

	/**
	 * @dataProvider get_transport_providers_to_test
	 */
	public function test_bulk_request_reaches_matomo_and_returns_results( $connection, $method, $expected_http_method ) {
		list( $result, $error ) = $this->perform_bulk_request( $this->mock_url(), $connection, $method );

		$this->assertSame( [ 'nb_visits' => 1 ], $result );
		$this->assertSame( '', $error );

		$requests = $this->get_captured_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( $expected_http_method, $requests[0]['method'] );

		$sent = 'POST' === $expected_http_method ? $requests[0]['post'] : $requests[0]['get'];
		$this->assertSame( 'API.getBulkRequest', $sent['method'] );
		$this->assertSame( 'secret-token', $sent['token_auth'] );
		$this->assertSame(
			[ 'method=VisitsSummary.get&idSite=1&period=day&date=2015-01-01' ],
			$sent['urls']
		);
	}

	/**
	 * POST keeps the sub request URLs and the auth token out of the query string.
	 *
	 * @dataProvider get_transport_providers_to_test
	 */
	public function test_post_sends_nothing_in_the_query_string( $connection, $method, $expected_http_method ) {
		$this->perform_bulk_request( $this->mock_url(), $connection, $method );

		$requests = $this->get_captured_requests();
		if ( 'POST' === $expected_http_method ) {
			$this->assertSame( '', $requests[0]['query'] );
			$this->assertStringNotContainsString( 'secret-token', $requests[0]['uri'] );
		} else {
			$this->assertStringContainsString( 'token_auth=secret-token', urldecode( $requests[0]['query'] ) );
		}
	}

	/**
	 * Neither transport replays a bulk request against the location a redirect
	 * points at, so the 3xx body arrives instead and decodes to nothing. That used
	 * to leave the stats screens empty without any explanation.
	 *
	 * @dataProvider get_tests_for_redirecting_transport_provider
	 */
	public function test_redirecting_matomo_url_is_reported_instead_of_failing_silently( $connection ) {
		list( $result, $error ) = $this->perform_bulk_request( $this->mock_url( false ), $connection, 'post' );

		$this->assertFalse( $result );
		$this->assertStringContainsString( 'valid JSON', $error );
		$this->assertStringContainsString( '301', $error );
	}

	public function get_tests_for_redirecting_transport_provider() {
		return [
			'curl'  => [ 'curl' ],
			'fopen' => [ 'fopen' ],
		];
	}

	/**
	 * @dataProvider get_tests_for_redirecting_transport_provider
	 */
	public function test_a_non_json_response_is_reported( $connection ) {
		$this->set_mock_response(
			[
				'headers' => [ 'Content-Type' => 'text/html' ],
				'body'    => '<!DOCTYPE html><html><body>Matomo</body></html>',
			]
		);

		list( $result, $error ) = $this->perform_bulk_request( $this->mock_url(), $connection, 'post' );

		$this->assertFalse( $result );
		$this->assertStringContainsString( 'valid JSON', $error );
	}

	/**
	 * @dataProvider get_tests_for_redirecting_transport_provider
	 */
	public function test_a_server_error_is_reported( $connection ) {
		$this->set_mock_response(
			[
				'status'  => 500,
				'headers' => [ 'Content-Type' => 'text/html' ],
				'body'    => 'Internal Server Error',
			]
		);

		list( $result, $error ) = $this->perform_bulk_request( $this->mock_url(), $connection, 'post' );

		$this->assertFalse( $result );
		// curl hands the error page to check_response(), while the fopen stream wrapper refuses
		// to open the stream at all, so the error message wording differs between the transports
		$this->assertNotSame( '', $error );
		$this->assertStringContainsString( '500', $error );
	}
}
