<?php

namespace WP_Piwik\Tests;

require_once __DIR__ . '/proxy/Proxy_Test_Harness.php';

/**
 * End-to-end integration tests for the bundled tracker proxy in ./proxy.
 * The tests skip automatically when not run inside the wp-env container.
 */
class ProxyIntegrationTest extends \WP_UnitTestCase {

	/**
	 * @var Proxy_Test_Harness|null
	 */
	private static $harness;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! self::is_in_wp_env_environment() ) {
			self::markTestSkipped( 'Not in wp-env environment, cannot run this test' );
		}

		$harness = new Proxy_Test_Harness();
		$harness->start();
		self::$harness = $harness;
	}

	public static function tear_down_after_class() {
		if ( self::$harness ) {
			self::$harness->stop();
			self::$harness = null;
		}
		parent::tear_down_after_class();
	}

	private static function is_in_wp_env_environment() {
		return defined( 'IN_WP_ENV' ) && IN_WP_ENV;
	}

	public function set_up() {
		parent::set_up();
		self::$harness->reset();
	}

	public function test_proxy_serves_matomo_js_when_there_are_no_tracking_params() {
		$harness = self::$harness;

		$response = $harness->get( 'matomo.php' );

		$this->assertSame( 200, $response->status );
		$this->assertStringContainsString( 'application/javascript', $response->headers['content-type'] );
		$this->assertStringContainsString( 'mock matomo.js', $response->body );
		// the .js is fetched from Matomo as a static file, so the tracking endpoint is not hit
		$this->assertSame( [], $harness->get_captured_requests() );
	}

	public function test_proxy_forwards_tracking_request_and_injects_token_and_ip() {
		$harness = self::$harness;

		$harness->get(
			'matomo.php',
			[
				'idsite'      => 1,
				'rec'         => 1,
				'action_name' => 'Home',
			]
		);

		$request = $harness->get_single_captured_request();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'matomo.php', $request['script'] );
		$this->assertSame( $harness->token, $request['get']['token_auth'] );
		$this->assertNotFalse( filter_var( $request['get']['cip'], FILTER_VALIDATE_IP ) );
		$this->assertSame( '1', $request['get']['idsite'] );
		$this->assertSame( '1', $request['get']['rec'] );
		$this->assertSame( 'Home', $request['get']['action_name'] );
	}

	public function test_proxy_strips_geolocation_override_params_when_no_token_is_supplied() {
		$harness = self::$harness;

		$harness->get(
			'matomo.php',
			[
				'idsite'  => 1,
				'url'     => 'https://example.org/page',
				'cdt'     => '2020-01-01 00:00:00',
				'country' => 'gb',
				'region'  => 'eng',
				'city'    => 'London',
				'lat'     => '51.5',
				'long'    => '-0.12',
			]
		);

		$forwarded = $harness->get_single_captured_request()['get'];

		foreach ( [ 'cdt', 'country', 'region', 'city', 'lat', 'long' ] as $stripped ) {
			$this->assertArrayNotHasKey( $stripped, $forwarded, $stripped . ' should have been stripped' );
		}
		// non-override params are preserved
		$this->assertSame( 'https://example.org/page', $forwarded['url'] );
		$this->assertSame( '1', $forwarded['idsite'] );
	}

	public function test_proxy_keeps_override_params_when_the_client_supplies_a_token() {
		$harness = self::$harness;

		$harness->get(
			'matomo.php',
			[
				'idsite'     => 1,
				'cdt'        => '2020-01-01 00:00:00',
				'country'    => 'gb',
				'token_auth' => 'client-provided-token',
			]
		);

		$forwarded = $harness->get_single_captured_request()['get'];
		$this->assertSame( '2020-01-01 00:00:00', $forwarded['cdt'] );
		$this->assertSame( 'gb', $forwarded['country'] );
		// a client supplied token takes precedence over the proxy's configured one
		$this->assertSame( 'client-provided-token', $forwarded['token_auth'] );
	}

	public function test_proxy_uses_forwarded_ip_header_as_cip() {
		$harness = self::$harness;

		$harness->get( 'matomo.php', [ 'idsite' => 1 ], [ 'X-Forwarded-For' => '8.8.8.8' ] );

		$this->assertSame( '8.8.8.8', $harness->get_single_captured_request()['get']['cip'] );
	}

	public function test_proxy_forwards_post_body_as_post_and_strips_overrides() {
		$harness = self::$harness;

		$harness->post( 'matomo.php', 'foo=bar&cdt=2020-01-01+00%3A00%3A00', [ 'idsite' => 1 ] );

		$request = $harness->get_single_captured_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'bar', $request['post']['foo'] );
		$this->assertArrayNotHasKey( 'cdt', $request['post'] );
		$this->assertSame( 'foo=bar', $request['body'] );
		// token and cip are still injected via the query string
		$this->assertSame( $harness->token, $request['get']['token_auth'] );
		$this->assertNotFalse( filter_var( $request['get']['cip'], FILTER_VALIDATE_IP ) );
	}

	public function test_proxy_sanitizes_token_and_matomo_url_out_of_the_response() {
		$harness = self::$harness;
		$harness->set_matomo_tracker_response(
			200,
			[ 'Content-Type' => 'text/plain' ],
			'token=' . $harness->token . ' endpoint=' . $harness->matomo_base_url()
		);

		$response = $harness->get( 'matomo.php', [ 'idsite' => 1 ] );

		$this->assertStringContainsString( '<token>', $response->body );
		$this->assertStringNotContainsString( $harness->token, $response->body );
		$this->assertStringNotContainsString( $harness->matomo_base_url(), $response->body );
	}

	public function test_proxy_forwards_the_upstream_status_code() {
		$harness = self::$harness;
		$harness->set_matomo_tracker_response( 204, [ 'Content-Type' => 'image/gif' ], '' );

		$response = $harness->get( 'matomo.php', [ 'idsite' => 1 ] );

		$this->assertSame( 204, $response->status );
	}

	public function test_proxy_forwards_whitelisted_headers_and_strips_secure_from_cookies() {
		$harness = self::$harness;
		$harness->set_matomo_tracker_response(
			200,
			[
				'Content-Type'                => 'image/gif',
				'Set-Cookie'                  => 'visitor=abc; path=/; secure;',
				'Access-Control-Allow-Origin' => '*',
			],
			'x'
		);

		$response = $harness->get( 'matomo.php', [ 'idsite' => 1 ] );

		$this->assertSame( '*', $response->headers['access-control-allow-origin'] );
		$this->assertArrayHasKey( 'set-cookie', $response->headers );
		$this->assertStringContainsString( 'visitor=abc', $response->headers['set-cookie'] );
		// the proxy is reached over http, so the secure flag must be dropped
		$this->assertStringNotContainsString( 'secure', $response->headers['set-cookie'] );
	}

	public function test_proxy_returns_304_without_contacting_matomo() {
		$harness = self::$harness;

		$response = $harness->get(
			'matomo.php',
			[],
			[ 'If-Modified-Since' => gmdate( 'D, d M Y H:i:s' ) . ' GMT' ]
		);

		$this->assertSame( 304, $response->status );
		$this->assertSame( [], $harness->get_captured_requests() );
	}

	public function test_proxy_piwik_php_entry_point_forwards_tracking_requests() {
		$harness = self::$harness;

		$harness->get(
			'piwik.php',
			[
				'idsite' => 1,
				'rec'    => 1,
			]
		);

		$request = $harness->get_single_captured_request();
		$this->assertSame( 'matomo.php', $request['script'] );
		$this->assertSame( '1', $request['get']['idsite'] );
		$this->assertSame( $harness->token, $request['get']['token_auth'] );
	}
}
