<?php

namespace WP_Piwik\Tests;

use WP_Piwik\AjaxTracker;
use WP_Piwik\Logger\Dummy;

class Testable_Ajax_Tracker extends AjaxTracker {

	public function send_request_public( $url, $method = 'GET', $data = null ) {
		return $this->sendRequest( $url, $method, $data );
	}
}

class AjaxTrackerTest extends WP_Piwik_TestCase {

	private function create_tracker( $global_options = [], $options = [] ) {
		$global_options += [
			'piwik_mode' => 'http',
			'piwik_url'  => 'https://stats.example.org/',
		];
		$settings        = $this->create_settings( $global_options, $options );
		return new Testable_Ajax_Tracker( $settings, new Dummy( 'test' ) );
	}

	public function test_send_request_does_nothing_without_a_site_id() {
		$tracker = $this->create_tracker();

		$this->assertSame( '', $tracker->send_request_public( 'https://stats.example.org/matomo.php?idsite=1' ) );
		$this->assertSame( [], $this->captured_http_requests );
	}

	public function test_send_request_sends_a_non_blocking_bot_request() {
		$_SERVER['HTTP_USER_AGENT']       = 'WP-Piwik test agent';
		$this->mock_http_response['body'] = 'tracked';

		$tracker = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$result = $tracker->send_request_public( 'https://stats.example.org/matomo.php?idsite=5&rec=1' );

		$this->assertSame( 'tracked', $result );
		$this->assertCount( 1, $this->captured_http_requests );

		$request = $this->captured_http_requests[0];
		$this->assertSame( 'https://stats.example.org/matomo.php?idsite=5&rec=1&bots=1', $request['url'] );
		$this->assertFalse( $request['args']['blocking'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( 'WP-Piwik test agent', $this->get_request_user_agent( $request ) );
	}

	public function test_send_request_passes_post_data_along() {
		$tracker = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$tracker->send_request_public( 'https://stats.example.org/matomo.php', 'POST', '{"requests":[]}' );

		$request = $this->captured_http_requests[0];
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( '{"requests":[]}', $request['args']['body'] );
	}

	public function test_send_request_skips_prerender_requests() {
		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';

		$tracker = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$this->assertSame( '', $tracker->send_request_public( 'https://stats.example.org/matomo.php' ) );
		$this->assertSame( [], $this->captured_http_requests );
	}

	public function test_send_request_returns_empty_string_on_wp_error() {
		$this->mock_http_response = new \WP_Error( 'http_request_failed', 'could not connect' );

		$tracker = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$this->assertSame( '', $tracker->send_request_public( 'https://stats.example.org/matomo.php' ) );
	}

	public function test_set_visitor_id_safe_accepts_a_valid_visitor_id() {
		$tracker = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$tracker->set_visitor_id_safe( '0123456789abcdef' );

		$this->assertSame( '0123456789abcdef', $tracker->getVisitorId() );
	}

	public function test_set_visitor_id_safe_ignores_an_invalid_visitor_id() {
		$tracker = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$original_visitor_id = $tracker->getVisitorId();

		$tracker->set_visitor_id_safe( 'not-a-visitor-id' );

		$this->assertSame( $original_visitor_id, $tracker->getVisitorId() );
	}

	public function test_get_tracking_cookie_domain_is_empty_by_default() {
		$settings = $this->create_settings();
		$tracker  = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$this->assertSame( '', $tracker->get_tracking_cookie_domain( $settings ) );
	}

	public function test_get_tracking_cookie_domain_covers_subdomains_when_tracking_across() {
		$settings = $this->create_settings( [ 'track_across' => true ] );
		$tracker  = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$expected_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertSame( '*.' . $expected_host, $tracker->get_tracking_cookie_domain( $settings ) );
	}

	public function test_get_tracking_cookie_domain_covers_subdomains_when_crossdomain_linking() {
		$settings = $this->create_settings( [ 'track_crossdomain_linking' => true ] );
		$tracker  = $this->create_tracker( [], [ 'site_id' => 5 ] );

		$expected_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertSame( '*.' . $expected_host, $tracker->get_tracking_cookie_domain( $settings ) );
	}

	public function test_visitor_id_from_cookie_is_reused() {
		// cookie domain '*.<host>' is normalized to '.<host>', the default cookie
		// path is '/', and PHP replaces dots in cookie names with underscores
		$host        = wp_parse_url( home_url(), PHP_URL_HOST );
		$cookie_hash = substr( sha1( '.' . $host . '/' ), 0, 4 );

		$_COOKIE[ '_pk_id_5_' . $cookie_hash ] = 'abcdef0123456789.1600000000';

		$tracker = $this->create_tracker( [ 'track_across' => true ], [ 'site_id' => 5 ] );

		$this->assertSame( 'abcdef0123456789', $tracker->getVisitorId() );
	}
}
