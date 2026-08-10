<?php

namespace WP_Piwik\Tests;

require_once __DIR__ . '/proxy/Proxy_Test_Harness.php';

/**
 * End-to-end integration tests for the bundled tracker proxy in ./proxy.
 * The tests skip automatically unless WP_MATOMO_INTEGRATION_TESTS is set, since
 * they need a web server that serves this plugin over HTTP.
 */
class ProxyIntegrationTest extends \WP_UnitTestCase {

	/**
	 * @var Proxy_Test_Harness|null
	 */
	private static $harness;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! self::is_integration_environment() ) {
			self::markTestSkipped( 'WP_MATOMO_INTEGRATION_TESTS is not set, cannot run this test' );
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

	private static function is_integration_environment() {
		return (bool) getenv( 'WP_MATOMO_INTEGRATION_TESTS' );
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

	public function test_proxy_does_not_authorize_geolocation_override_params_when_no_token_is_supplied() {
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

		// the client sent override params but no token. The proxy must not lend
		// its own token_auth, so Matomo will reject the unauthorized overrides.
		$this->assertArrayNotHasKey( 'token_auth', $forwarded );
		// check that the request is still forwarded, including the (now unauthorized) params.
		$this->assertSame( 'https://example.org/page', $forwarded['url'] );
		$this->assertSame( '1', $forwarded['idsite'] );
		$this->assertSame( '2020-01-01 00:00:00', $forwarded['cdt'] );
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

	public function test_proxy_forwards_custom_cdt_and_token_auth() {
		$harness = self::$harness;

		$harness->get(
			'matomo.php',
			[
				'idsite'     => 1,
				'cdt'        => '2021-06-15 12:34:56',
				'token_auth' => 'client-provided-token',
			]
		);

		$forwarded = $harness->get_single_captured_request()['get'];
		// custom token + custom cdt should not result in a redaction. if the custom token
		// is valid, it means we trust the sender is allowed to set a custom cdt.
		$this->assertSame( '2021-06-15 12:34:56', $forwarded['cdt'] );
		$this->assertSame( 'client-provided-token', $forwarded['token_auth'] );
		$this->assertNotSame( $harness->token, $forwarded['token_auth'] );
	}

	public function test_proxy_uses_forwarded_ip_header_as_cip() {
		$harness = self::$harness;

		$harness->get( 'matomo.php', [ 'idsite' => 1 ], [ 'X-Forwarded-For' => '8.8.8.8' ] );

		$this->assertSame( '8.8.8.8', $harness->get_single_captured_request()['get']['cip'] );
	}

	public function test_proxy_forwards_post_body_as_post_and_injects_token_and_ip() {
		$harness = self::$harness;

		$harness->post( 'matomo.php', 'e_c=category&e_a=action', [ 'idsite' => 1 ] );

		$request = $harness->get_single_captured_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'category', $request['post']['e_c'] );
		$this->assertSame( 'action', $request['post']['e_a'] );
		// a clean request gets the proxy's token and the visitor IP injected via the query string
		$this->assertSame( $harness->token, $request['get']['token_auth'] );
		$this->assertNotFalse( filter_var( $request['get']['cip'], FILTER_VALIDATE_IP ) );
	}

	public function test_proxy_does_not_authorize_override_params_in_the_post_body() {
		$harness = self::$harness;

		$harness->post( 'matomo.php', 'foo=bar&cdt=2020-01-01+00%3A00%3A00', [ 'idsite' => 1 ] );

		$request = $harness->get_single_captured_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'bar', $request['post']['foo'] );
		// an override param (eg, cdt) in the body means the proxy should withhold its configured token,
		// so matomo ignores the override param
		$this->assertArrayNotHasKey( 'token_auth', $request['get'] );
		$this->assertArrayNotHasKey( 'token_auth', $request['post'] );
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

	public function test_proxy_strips_wordpress_cookies_but_forwards_others_when_no_allowlist() {
		$harness = self::$harness;

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => 'wordpress_logged_in_abc123=alice%7Csecret; wp-settings-1=editor; _pk_id.1.1fff=daslkfjs; PHPSESSID=sess123; my_pref=keep' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// with no allow list configured, unrecognised cookies (incl. the Matomo cookie) still pass through
		$this->assertStringContainsString( '_pk_id.1.1fff=daslkfjs', $forwarded );
		$this->assertStringContainsString( 'my_pref=keep', $forwarded );
		// known WordPress cookies are always removed, so the login/session value never reaches Matomo
		$this->assertStringNotContainsString( 'wordpress_logged_in', $forwarded );
		$this->assertStringNotContainsString( 'secret', $forwarded );
		$this->assertStringNotContainsString( 'wp-settings-1', $forwarded );
		// the PHP session cookie is removed by default too
		$this->assertStringNotContainsString( 'PHPSESSID', $forwarded );
	}

	public function test_proxy_only_forwards_allowlisted_cookies_when_allowlist_is_set() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist( '_pk_*, mtm_*' );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; mtm_consent=1; PHPSESSID=sess123; foo=bar; wordpress_logged_in_abc123=alice%7Csecret' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// only cookies matching the allow list are forwarded
		$this->assertStringContainsString( '_pk_id.1.1fff=daslkfjs', $forwarded );
		$this->assertStringContainsString( 'mtm_consent=1', $forwarded );
		// everything else is dropped, including a non-WordPress cookie not on the list
		$this->assertStringNotContainsString( 'PHPSESSID', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
		$this->assertStringNotContainsString( 'wordpress_logged_in', $forwarded );
	}

	public function test_proxy_lets_config_local_override_the_cookie_allowlist_setting() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist( '_pk_*' );
		$harness->set_config_local( "<?php\n\$COOKIE_ALLOWLIST = [ 'custom_*' ];\n" );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => 'custom_pref=keep; _pk_id.1.1fff=daslkfjs; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// config.local.php runs before config.php, so the allow list it defines wins and the setting is ignored
		$this->assertStringContainsString( 'custom_pref=keep', $forwarded );
		$this->assertStringNotContainsString( '_pk_id', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
	}

	public function test_proxy_falls_back_to_the_cookie_allowlist_setting_when_config_local_does_not_set_one() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist( '_pk_*' );
		$harness->set_config_local( "<?php\n// no \$COOKIE_ALLOWLIST here\n" );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		$this->assertStringContainsString( '_pk_id.1.1fff=daslkfjs', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
	}

	public function test_proxy_forwards_no_cookies_when_the_allowlist_setting_has_no_usable_entries() {
		$harness = self::$harness;
		// a value like this parses to nothing; it must not fall back to forwarding everything, or the
		// settings screen would show a configured allow list while no filtering happens at all
		$harness->set_cookie_allowlist( ' , , ' );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		$this->assertSame( '', $forwarded );
	}

	public function test_proxy_forwards_no_cookies_when_the_allowlist_setting_is_only_a_wildcard() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist( '*' );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// a bare '*' is not a way to allow everything, that is what an empty setting is for
		$this->assertSame( '', $forwarded );
	}

	public function test_proxy_drops_malformed_allowlist_entries_but_keeps_the_valid_ones() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist( '_pk_*, bad;entry, "quoted", *, mtm_consent' );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; mtm_consent=1; bad;entry=x; "quoted"=x; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		$this->assertStringContainsString( '_pk_id.1.1fff=daslkfjs', $forwarded );
		$this->assertStringContainsString( 'mtm_consent=1', $forwarded );
		// a malformed entry is dropped from the list rather than kept as an exact match, so a cookie
		// literally named "quoted" (quotes included) is not forwarded either
		$this->assertStringNotContainsString( 'quoted', $forwarded );
		$this->assertStringNotContainsString( 'entry=x', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
	}

	public function test_proxy_forwards_no_cookies_when_the_allowlist_setting_is_not_a_string() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist_json( [ '_pk_*' ] );

		$response = $harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// an option that is not a string is unusable rather than "off", so the allow list fails closed
		$this->assertSame( '', $forwarded );
		// and reading it must not print a PHP warning into the tracker response
		$this->assertSame( 200, $response->status );
		$this->assertSame( 'MOCKGIF', $response->body );
	}

	public function test_proxy_always_forwards_the_matomo_optout_cookies() {
		$harness = self::$harness;
		// an allow list that does not mention the opt-out cookie would otherwise hide it from Matomo,
		// which evaluates it server side, and opted-out visitors would be tracked again
		$harness->set_cookie_allowlist( '_pk_*' );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; matomo_ignore=1; piwik_ignore=1; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		$this->assertStringContainsString( 'matomo_ignore=1', $forwarded );
		$this->assertStringContainsString( 'piwik_ignore=1', $forwarded );
		$this->assertStringContainsString( '_pk_id.1.1fff=daslkfjs', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
	}

	public function test_proxy_always_forwards_the_matomo_optout_cookies_with_a_config_local_allowlist() {
		$harness = self::$harness;
		$harness->set_config_local( "<?php\n\$COOKIE_ALLOWLIST = [ 'custom_*' ];\n" );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => 'custom_pref=keep; matomo_ignore=1; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		$this->assertStringContainsString( 'custom_pref=keep', $forwarded );
		$this->assertStringContainsString( 'matomo_ignore=1', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
	}

	public function test_proxy_forwards_no_cookies_when_the_allowlist_setting_fails_closed_and_no_optout_is_set() {
		$harness = self::$harness;
		$harness->set_cookie_allowlist( ',' );

		$harness->get( 'matomo.php', [ 'idsite' => 1 ], [ 'Cookie' => 'foo=bar' ] );

		$request = $harness->get_single_captured_request();
		$this->assertSame( '', $this->get_forwarded_cookie_header( $request ) );
	}

	public function test_proxy_still_forwards_the_optout_cookies_when_config_local_sets_a_malformed_allowlist() {
		$harness = self::$harness;
		// not an array, so the allow list cannot be applied as written
		$harness->set_config_local( "<?php\n\$COOKIE_ALLOWLIST = '_pk_*';\n" );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => '_pk_id.1.1fff=daslkfjs; matomo_ignore=1; foo=bar' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// blocking the opt-out cookie would make Matomo track a visitor who opted out, so that
		// one still has to get through
		$this->assertStringContainsString( 'matomo_ignore=1', $forwarded );
		$this->assertStringNotContainsString( '_pk_id', $forwarded );
		$this->assertStringNotContainsString( 'foo=bar', $forwarded );
	}

	public function test_proxy_strips_wordpress_auth_cookies_renamed_through_wp_config_constants() {
		$harness = self::$harness;
		// config.local.php is included before wp-load.php runs, so defining the constant there has the
		// same effect as defining it in wp-config.php
		$harness->set_config_local( "<?php\ndefine( 'LOGGED_IN_COOKIE', 'my_custom_login' );\n" );

		$harness->get(
			'matomo.php',
			[ 'idsite' => 1 ],
			[ 'Cookie' => 'my_custom_login=alice%7Csecret; my_pref=keep' ]
		);

		$forwarded = $this->get_forwarded_cookie_header( $harness->get_single_captured_request() );

		// the renamed login cookie matches none of the default WordPress prefixes, the constant is
		// what makes it blocked
		$this->assertStringNotContainsString( 'my_custom_login', $forwarded );
		$this->assertStringNotContainsString( 'secret', $forwarded );
		$this->assertStringContainsString( 'my_pref=keep', $forwarded );
	}

	private function get_forwarded_cookie_header( $request ) {
		foreach ( (array) $request['headers'] as $name => $value ) {
			if ( 'cookie' === strtolower( $name ) ) {
				return $value;
			}
		}
		return '';
	}
}
