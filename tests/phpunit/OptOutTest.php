<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request;
use WP_Piwik\Widget\OptOut;

class OptOut_Test_Request extends Request {

	protected function request( $id ) {
	}

	public static function get_registered() {
		return self::$requests;
	}
}

class OptOutTest extends WP_Piwik_TestCase {

	public function test_construct_should_register_no_api_request() {
		$this->create_opt_out( array( 'language' => 'de' ) );

		$this->assertSame( array(), OptOut_Test_Request::get_registered() );
	}

	public function test_show_should_render_the_documented_default_size() {
		$output = $this->create_opt_out( array() )->get();

		// readme.txt documents 100% and 200px as the defaults
		$this->assertStringContainsString( 'width="100%"', $output );
		$this->assertStringContainsString( 'height="200px"', $output );
	}

	public function test_show_should_not_url_encode_the_size() {
		$output = $this->create_opt_out( array( 'width' => '100%' ) )->get();

		$this->assertStringNotContainsString( '100%25', $output );
	}

	public function test_show_should_use_the_supplied_language_and_site_id() {
		$output = $this->create_opt_out(
			array(
				'language' => 'de',
				'idsite'   => '9',
			)
		)->get();

		$this->assertStringContainsString( 'idsite=9', $output );
		$this->assertStringContainsString( 'language=de', $output );
	}

	public function test_show_should_omit_the_site_id_when_none_was_given() {
		$output = $this->create_opt_out( array() )->get();

		$this->assertStringNotContainsString( 'idsite=', $output );
		$this->assertStringContainsString( 'language=en', $output );
	}

	public function test_show_should_point_the_iframe_at_the_configured_matomo() {
		$output = $this->create_opt_out( array() )->get();

		$this->assertStringContainsString( 'src="https://matomo.example.org/index.php?module=CoreAdminHome', $output );
	}

	private function create_opt_out( array $params ) {
		$settings = $this->create_settings(
			array(
				'piwik_mode' => 'http',
				'piwik_url'  => 'https://matomo.example.org/',
			),
			array( 'site_id' => 7 )
		);

		$request = new OptOut_Test_Request( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		$request->reset();

		$widget = new OptOut( new \WP_Piwik_Test_Mock_Plugin(), $settings, null, null, null, $params, true );
		$widget->show();

		return $widget;
	}
}
