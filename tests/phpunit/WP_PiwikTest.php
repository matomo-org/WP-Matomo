<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request;

class Shortcode_Test_Request extends Request {

	protected function request( $id ) {
	}

	public static function get_registered() {
		return self::$requests;
	}
}

class WP_PiwikTest extends WP_Piwik_TestCase {

	private $settings_backup = array();

	public function set_up() {
		parent::set_up();

		$settings              = \WP_Piwik::get_settings();
		$this->settings_backup = array(
			'piwik_mode'   => $settings->get_global_option( 'piwik_mode' ),
			'piwik_url'    => $settings->get_global_option( 'piwik_url' ),
			'default_date' => $settings->get_global_option( 'default_date' ),
			'site_id'      => $settings->get_option( 'site_id' ),
		);

		$settings->set_global_option( 'piwik_mode', 'disabled' );
		$settings->set_global_option( 'piwik_url', 'https://matomo.example.org/' );
		$settings->set_option( 'site_id', 7 );

		$request = new Shortcode_Test_Request( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		$request->reset();
	}

	public function tear_down() {
		$settings = \WP_Piwik::get_settings();
		foreach ( array( 'piwik_mode', 'piwik_url', 'default_date' ) as $key ) {
			$settings->set_global_option( $key, $this->settings_backup[ $key ] );
		}
		$settings->set_option( 'site_id', $this->settings_backup['site_id'] );

		parent::tear_down();
	}

	public function test_shortcode_should_ignore_an_injected_api_method() {
		$output = $this->render( 'module=opt-out method=SitesManager.deleteSite' );

		$this->assertStringContainsString( '<iframe', $output, 'precondition: the opt-out widget rendered' );
		$this->assertSame(
			array(),
			Shortcode_Test_Request::get_registered(),
			'the opt-out widget performs no API call, so it must queue no request at all'
		);
	}

	public function test_shortcode_should_drop_unsupported_attributes() {
		$this->render( 'module=overview method=SitesManager.deleteSite urls=http://attacker.example note=x' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame(
			array(),
			array_intersect( array( 'method', 'urls', 'note' ), array_keys( $parameters ) )
		);
	}

	public function test_shortcode_should_default_to_the_overview_module() {
		$this->render( '' );

		$this->assertSame(
			array( 'VisitsSummary.get' ),
			$this->get_registered_methods(),
			'readme.txt documents [wp-piwik] as equal to [wp-piwik module="overview"]'
		);
	}

	public function test_shortcode_should_pass_supported_attributes_to_the_widget() {
		$output = $this->render( 'module=opt-out language=de width=50% height=90px idsite=9' );

		$this->assertStringContainsString( 'width="50%"', $output );
		$this->assertStringContainsString( 'height="90px"', $output );
		$this->assertStringContainsString( 'idsite=9', $output );
		$this->assertStringContainsString( 'language=de', $output );
	}

	public function test_shortcode_should_keep_honouring_the_default_date_setting() {
		\WP_Piwik::get_settings()->set_global_option( 'default_date', 'current_month' );

		$this->render( 'module=overview' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( 'month', $parameters['period'] );
		$this->assertSame( 'today', $parameters['date'] );
	}

	public function test_shortcode_should_let_an_explicit_date_win_over_the_default_date_setting() {
		\WP_Piwik::get_settings()->set_global_option( 'default_date', 'current_month' );

		$this->render( 'module=overview period=day date=last30' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( 'day', $parameters['period'] );
		$this->assertSame( 'last30', $parameters['date'] );
	}

	public function test_shortcode_should_keep_the_post_url_attribute() {
		$this->render( 'module=post url=https://example.org/some-post/' );

		$parameters = $this->get_registered_parameters( 'Actions.getPageUrl' );
		$this->assertSame( 'https://example.org/some-post/', $parameters['pageUrl'] );
	}

	private function render( $attributes ) {
		return $GLOBALS['wp-piwik']->shortcode( shortcode_parse_atts( $attributes ) );
	}

	private function get_registered_methods() {
		return array_values( wp_list_pluck( Shortcode_Test_Request::get_registered(), 'method' ) );
	}

	private function get_registered_parameters( $method ) {
		foreach ( Shortcode_Test_Request::get_registered() as $config ) {
			if ( $method === $config['method'] ) {
				return $config['parameter'];
			}
		}
		$this->fail( 'No request was registered for ' . $method );
	}
}
