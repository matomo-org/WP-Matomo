<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request;
use WP_Piwik\Request\Rest;

class Test_Rest extends Rest {

	public function build_bulk_params_public() {
		return $this->build_bulk_params();
	}

	public function build_param_string_public( $params, $mask_token = false ) {
		return $this->build_param_string( $params, $mask_token );
	}

	public function use_post_public() {
		return $this->should_use_post();
	}

	public function check_response_public( $response, $status = '' ) {
		return $this->check_response( $response, $status );
	}

	public static function set_result( $id, $result ) {
		self::$results[ $id ] = $result;
	}
}

class RestTest extends WP_Piwik_TestCase {

	/**
	 * @var \WP_Piwik_Test_Mock_Plugin
	 */
	private $plugin;

	/**
	 * @var \WP_Piwik_Test_Mock_Settings
	 */
	private $settings;

	/**
	 * @var Test_Rest
	 */
	private $request;

	public function set_up() {
		parent::set_up();
		$this->plugin                                  = new \WP_Piwik_Test_Mock_Plugin();
		$this->settings                                = new \WP_Piwik_Test_Mock_Settings();
		$this->settings->global_options['cache']       = false;
		$this->settings->global_options['piwik_token'] = 'secret-token';
		$this->settings->options['site_id']            = 7;
		$this->request                                 = new Test_Rest( $this->plugin, $this->settings );
		$this->request->reset();
	}

	private function register_visits_summary( $date = '2015-01-01' ) {
		return Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => $date,
			]
		);
	}

	public function test_bulk_params_address_the_bulk_endpoint() {
		list( $params ) = $this->request->build_bulk_params_public();

		$this->assertSame( 'API', $params['module'] );
		$this->assertSame( 'API.getBulkRequest', $params['method'] );
		$this->assertSame( 'json', $params['format'] );
	}

	public function test_bulk_params_collect_pending_requests_as_an_urls_array() {
		$this->register_visits_summary( '2015-01-01' );
		$this->register_visits_summary( '2015-01-02' );

		list( $params, $map ) = $this->request->build_bulk_params_public();

		$this->assertSame(
			[
				'period=day&date=2015-01-01&idSite=7&method=VisitsSummary.get',
				'period=day&date=2015-01-02&idSite=7&method=VisitsSummary.get',
			],
			$params['urls']
		);
		$this->assertSame(
			[
				'method=VisitsSummary.get&period=day&date=2015-01-01',
				'method=VisitsSummary.get&period=day&date=2015-01-02',
			],
			array_values( $map )
		);
	}

	public function test_bulk_params_add_a_numeric_filter_limit() {
		$this->settings->global_options['filter_limit'] = 500;

		list( $params ) = $this->request->build_bulk_params_public();

		$this->assertSame( 500, $params['filter_limit'] );
	}

	public function test_bulk_params_ignore_an_empty_filter_limit() {
		$this->settings->global_options['filter_limit'] = '';

		list( $params ) = $this->request->build_bulk_params_public();

		$this->assertArrayNotHasKey( 'filter_limit', $params );
	}

	public function test_request_body_carries_every_parameter_so_nothing_is_left_for_a_query_string() {
		$this->register_visits_summary();

		list( $params ) = $this->request->build_bulk_params_public();
		$body           = $this->request->build_param_string_public( $params );

		$decoded = [];
		parse_str( $body, $decoded );

		$this->assertSame(
			[
				'module'     => 'API',
				'method'     => 'API.getBulkRequest',
				'format'     => 'json',
				'urls'       => [ 'period=day&date=2015-01-01&idSite=7&method=VisitsSummary.get' ],
				'token_auth' => 'secret-token',
			],
			$decoded
		);
	}

	public function test_request_body_keeps_sub_request_query_strings_parseable() {
		$this->register_visits_summary();

		list( $params ) = $this->request->build_bulk_params_public();
		$body           = $this->request->build_param_string_public( $params );

		$decoded = [];
		parse_str( $body, $decoded );

		// this is how Matomo's API.getBulkRequest reads each sub request
		$sub_request = [];
		parse_str( $decoded['urls'][0], $sub_request );

		$this->assertSame(
			[
				'period' => 'day',
				'date'   => '2015-01-01',
				'idSite' => '7',
				'method' => 'VisitsSummary.get',
			],
			$sub_request
		);
	}

	public function test_request_body_encodes_the_url_separators() {
		$this->register_visits_summary();

		list( $params ) = $this->request->build_bulk_params_public();
		$body           = $this->request->build_param_string_public( $params );

		$this->assertSame( 'module=API&method=API.getBulkRequest&format=json&urls%5B0%5D=period%3Dday%26date%3D2015-01-01%26idSite%3D7%26method%3DVisitsSummary.get&token_auth=secret-token', $body );
	}

	public function test_request_body_can_mask_the_auth_token_for_debug_output() {
		list( $params ) = $this->request->build_bulk_params_public();

		$body = $this->request->build_param_string_public( $params, true );

		$this->assertStringNotContainsString( 'secret-token', $body );
		$this->assertSame( 'module=API&method=API.getBulkRequest&format=json&token_auth=...', $body );
	}

	public function test_post_is_used_when_no_http_method_is_configured() {
		$this->assertTrue( $this->request->use_post_public() );
	}

	public function test_post_is_used_when_configured() {
		$this->settings->global_options['http_method'] = 'post';

		$this->assertTrue( $this->request->use_post_public() );
	}

	public function test_get_is_used_when_explicitly_configured() {
		$this->settings->global_options['http_method'] = 'get';

		$this->assertFalse( $this->request->use_post_public() );
	}

	public function test_post_is_used_for_an_unrecognised_http_method() {
		$this->settings->global_options['http_method'] = 'whatever';

		$this->assertTrue( $this->request->use_post_public() );
	}

	public function test_check_response_accepts_a_json_document() {
		$this->assertSame( '', $this->request->check_response_public( '[{"nb_visits":10}]' ) );
	}

	public function test_check_response_reports_a_failed_transfer() {
		$this->assertNotSame( '', $this->request->check_response_public( false ) );
	}

	public function test_check_response_reports_a_non_json_body() {
		// what Matomo answers when a redirect turned the POST into a bodyless GET
		$error = $this->request->check_response_public(
			'<!DOCTYPE html><html><body>Matomo</body></html>',
			'HTTP/1.1 200 OK'
		);

		$this->assertStringContainsString( 'valid JSON', $error );
		$this->assertStringContainsString( 'HTTP/1.1 200 OK', $error );
	}

	/**
	 * @dataProvider get_redirect_bodies_for_test
	 */
	public function test_check_response_reports_a_redirect_body( $body, $status ) {
		$error = $this->request->check_response_public( $body, $status );

		$this->assertStringContainsString( 'valid JSON', $error );
		$this->assertStringContainsString( $status, $error );
	}

	public function get_redirect_bodies_for_test() {
		return [
			'empty 301 body, fopen status' => [ '', 'HTTP/1.1 301 Moved Permanently' ],
			'empty 302 body, curl status'  => [ '', 'HTTP 302' ],
			'apache 301 landing page'      => [
				'<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head>'
				. '<title>301 Moved Permanently</title></head><body>'
				. '<h1>Moved Permanently</h1><p>The document has moved '
				. '<a href="https://matomo.example.org/">here</a>.</p></body></html>',
				'HTTP/1.1 301 Moved Permanently',
			],
			'nginx 302 landing page'       => [
				'<html><head><title>302 Found</title></head><body>'
				. '<center><h1>302 Found</h1></center><hr><center>nginx</center></body></html>',
				'HTTP 302',
			],
			'307 with no body at all'      => [ '', 'HTTP/1.1 307 Temporary Redirect' ],
		];
	}

	public function test_check_response_reports_an_empty_body() {
		$this->assertStringContainsString( 'valid JSON', $this->request->check_response_public( '', 'HTTP 200' ) );
	}

	public function test_check_response_omits_the_status_when_it_is_unknown() {
		$error = $this->request->check_response_public( 'not json' );

		$this->assertStringContainsString( 'valid JSON', $error );
		$this->assertStringNotContainsString( '(', $error );
	}

	public function test_bulk_params_skip_requests_that_already_have_a_result() {
		$first = $this->register_visits_summary( '2015-01-01' );
		$this->register_visits_summary( '2015-01-02' );

		Test_Rest::set_result( $first, [ 'nb_visits' => 10 ] );

		list( $params, $map ) = $this->request->build_bulk_params_public();

		$this->assertSame(
			[ 'period=day&date=2015-01-02&idSite=7&method=VisitsSummary.get' ],
			$params['urls']
		);
		$this->assertSame( [ 'method=VisitsSummary.get&period=day&date=2015-01-02' ], array_values( $map ) );
	}
}
