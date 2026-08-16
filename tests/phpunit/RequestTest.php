<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request;

class Test_Request extends Request {

	public static $responses = array();
	public static $performed = array();

	protected function request( $id ) {
		self::$performed[] = $id;
		if ( isset( self::$responses[ $id ] ) ) {
			self::$results[ $id ] = self::$responses[ $id ];
		}
	}

	public static function get_cacheable( $id ) {
		return isset( self::$is_cacheable[ $id ] ) ? self::$is_cacheable[ $id ] : null;
	}

	public function unserialize_public( $str ) {
		return $this->unserialize( $str );
	}

	public function build_url_public( $config ) {
		return $this->build_url( $config );
	}
}

class RequestTest extends WP_Piwik_TestCase {

	/**
	 * @var \WP_Piwik_Test_Mock_Plugin
	 */
	private $plugin;

	/**
	 * @var \WP_Piwik_Test_Mock_Settings
	 */
	private $settings;

	/**
	 * @var Test_Request
	 */
	private $request;

	public function set_up() {
		parent::set_up();
		$this->plugin                            = new \WP_Piwik_Test_Mock_Plugin();
		$this->settings                          = new \WP_Piwik_Test_Mock_Settings();
		$this->settings->global_options['cache'] = false;
		$this->settings->options['site_id']      = 7;
		Test_Request::$responses                 = [];
		Test_Request::$performed                 = [];
		$this->request                           = new Test_Request( $this->plugin, $this->settings );
		$this->request->reset();
	}

	public function test_register_builds_id_from_method_and_parameters() {
		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		$this->assertSame( 'method=VisitsSummary.get&period=day&date=2015-01-01', $id );
	}

	public function test_register_uses_global_id_for_piwik_version_requests() {
		$id = Test_Request::register( 'API.getPiwikVersion', [] );

		$this->assertSame( 'global.getPiwikVersion', $id );
		$this->assertFalse( Test_Request::get_cacheable( $id ) );
	}

	public function test_register_marks_past_date_requests_as_cacheable() {
		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		$this->assertNotFalse( Test_Request::get_cacheable( $id ) );
		$this->assertStringStartsWith( 'VisitsSummary.get-', Test_Request::get_cacheable( $id ) );
	}

	public function test_register_marks_current_period_requests_as_not_cacheable() {
		$today = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => 'today',
			]
		);
		$last  = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => 'last30',
			]
		);

		$this->assertFalse( Test_Request::get_cacheable( $today ) );
		$this->assertFalse( Test_Request::get_cacheable( $last ) );
	}

	public function test_perform_returns_error_for_unregistered_request() {
		$result = $this->request->perform( 'method=Not.registered' );

		$this->assertSame( 'error', $result['result'] );
		$this->assertStringContainsString( 'was not registered', $result['message'] );
	}

	public function test_perform_delegates_to_request_and_returns_result() {
		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		Test_Request::$responses[ $id ] = array( 'nb_visits' => 10 );

		$this->assertSame( [ 'nb_visits' => 10 ], $this->request->perform( $id ) );
		$this->assertSame( [ $id ], Test_Request::$performed );
	}

	public function test_perform_reuses_results_of_earlier_requests() {
		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		Test_Request::$responses[ $id ] = [ 'nb_visits' => 10 ];

		$this->request->perform( $id );
		$this->request->perform( $id );

		$this->assertCount( 1, Test_Request::$performed );
	}

	public function test_perform_returns_false_when_request_produces_no_result() {
		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		$this->assertFalse( $this->request->perform( $id ) );
	}

	public function test_perform_stores_cacheable_results_in_a_transient() {
		$this->settings->global_options['cache'] = true;

		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		Test_Request::$responses[ $id ] = [ 'nb_visits' => 10 ];

		$this->request->perform( $id );

		$key = 'wp-piwik_c_' . md5( Test_Request::get_cacheable( $id ) );
		$this->assertSame( [ 'nb_visits' => 10 ], get_transient( $key ) );

		$timeout = (int) get_option( '_transient_timeout_' . $key );
		$this->assertGreaterThan( time(), $timeout );
		$this->assertLessThanOrEqual( time() + WEEK_IN_SECONDS, $timeout );
	}

	public function test_perform_delivers_cached_data_without_a_new_request() {
		$this->settings->global_options['cache'] = true;

		$id  = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);
		$key = 'wp-piwik_c_' . md5( Test_Request::get_cacheable( $id ) );

		set_transient( $key, [ 'nb_visits' => 99 ], WEEK_IN_SECONDS );

		$this->assertSame( [ 'nb_visits' => 99 ], $this->request->perform( $id ) );
		$this->assertSame( [], Test_Request::$performed );
	}

	public function test_perform_ignores_cached_error_responses() {
		$this->settings->global_options['cache'] = true;

		$id  = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);
		$key = 'wp-piwik_c_' . md5( Test_Request::get_cacheable( $id ) );

		set_transient(
			$key,
			[
				'result'  => 'error',
				'message' => 'boom',
			],
			WEEK_IN_SECONDS
		);
		Test_Request::$responses[ $id ] = [ 'nb_visits' => 10 ];

		$this->assertSame( [ 'nb_visits' => 10 ], $this->request->perform( $id ) );
		$this->assertSame( [ $id ], Test_Request::$performed );
	}

	public function test_reset_clears_registered_requests() {
		$id = Test_Request::register(
			'VisitsSummary.get',
			[
				'period' => 'day',
				'date'   => '2015-01-01',
			]
		);

		$this->request->reset();

		$result = $this->request->perform( $id );
		$this->assertSame( 'error', $result['result'] );
	}

	public function test_unserialize_decodes_json_strings() {
		$this->assertSame( [ 'a' => 1 ], $this->request->unserialize_public( '{"a":1}' ) );
		$this->assertSame( [ 1, 2 ], $this->request->unserialize_public( '[1,2]' ) );
	}

	public function test_unserialize_returns_empty_array_for_invalid_json() {
		$this->assertEquals( [], $this->request->unserialize_public( 'this is not json' ) );
	}

	public function test_build_url_includes_method_site_id_and_parameters() {
		$url = $this->request->build_url_public(
			[
				'method'    => 'VisitsSummary.get',
				'parameter' => [
					'period' => 'day',
					'date'   => 'today',
				],
			]
		);

		$this->assertSame( 'period=day&date=today&idSite=7&method=VisitsSummary.get', $url );
	}

	public function test_build_url_should_ignore_a_method_supplied_as_a_parameter() {
		$url = $this->request->build_url_public(
			[
				'method'    => 'VisitsSummary.get',
				'parameter' => [
					'method' => 'SitesManager.deleteSite',
					'period' => 'day',
					'date'   => 'today',
				],
			]
		);

		// array_merge keeps the position a string key already had, so the injected
		// method keeps its slot but never its value
		$this->assertSame( 'method=VisitsSummary.get&period=day&date=today&idSite=7', $url );
	}

	public function test_build_url_should_ignore_a_site_id_supplied_as_a_parameter() {
		$url = $this->request->build_url_public(
			[
				'method'    => 'VisitsSummary.get',
				'parameter' => [ 'idSite' => 12 ],
			]
		);

		$this->assertSame( 'idSite=7&method=VisitsSummary.get', $url );
	}

	public function test_build_url_should_use_the_site_id_the_request_was_registered_with() {
		$url = $this->request->build_url_public(
			[
				'method'    => 'SitesManager.getJavascriptTag',
				'parameter' => [],
				'id_site'   => 12,
			]
		);

		$this->assertSame( 'idSite=12&method=SitesManager.getJavascriptTag', $url );
	}

	public function test_register_should_keep_requests_for_different_sites_apart() {
		$first  = Test_Request::register( 'VisitsSummary.get', [ 'period' => 'day' ], 7 );
		$second = Test_Request::register( 'VisitsSummary.get', [ 'period' => 'day' ], 12 );

		$this->assertNotSame( $first, $second, 'the site is part of the request identity, not an interchangeable parameter' );
	}

	public function test_get_debug_returns_false_when_no_debug_data_collected() {
		$this->assertFalse( $this->request->get_debug( 'method=Foo.bar' ) );
	}

	public function test_get_last_error_is_empty_by_default() {
		$this->assertSame( '', Test_Request::get_last_error() );
	}
}
