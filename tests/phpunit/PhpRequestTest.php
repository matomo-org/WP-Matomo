<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request\Php;

class Test_Php_Request extends Php {

	public function build_debug_params_public( $params ) {
		return $this->build_debug_params( $params );
	}
}

class PhpRequestTest extends WP_Piwik_TestCase {

	/**
	 * @var Test_Php_Request
	 */
	private $request;

	public function set_up() {
		parent::set_up();
		$settings                                = new \WP_Piwik_Test_Mock_Settings();
		$settings->global_options['cache']       = false;
		$settings->global_options['piwik_token'] = 'secret-token';
		$settings->options['site_id']            = 7;
		$this->request                           = new Test_Php_Request( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		$this->request->reset();
	}

	public function test_debug_params_converts_the_parameter_array_to_a_query_string() {
		$debug = $this->request->build_debug_params_public(
			array(
				'method'     => 'VisitsSummary.get',
				'idSite'     => 7,
				'module'     => 'API',
				'format'     => 'json',
				'token_auth' => 'secret-token',
			)
		);

		$this->assertSame( 'method=VisitsSummary.get&idSite=7&module=API&format=json&token_auth=...', $debug );
	}

	public function test_debug_params_mask_the_auth_token() {
		$debug = $this->request->build_debug_params_public(
			[
				'method'     => 'VisitsSummary.get',
				'token_auth' => 'secret-token',
			]
		);

		$this->assertSame( 'method=VisitsSummary.get&token_auth=...', $debug );
	}

	public function test_debug_params_cope_with_a_missing_auth_token() {
		$debug = $this->request->build_debug_params_public( [ 'method' => 'VisitsSummary.get' ] );

		$this->assertSame( 'method=VisitsSummary.get', $debug );
	}
}
