<?php

namespace WP_Piwik\Tests;

class WP_PiwikTest extends WP_Piwik_TestCase {

	private $settings_backup = array();

	public function set_up() {
		parent::set_up();

		$settings              = \WP_Piwik::get_settings();
		$this->settings_backup = array(
			'piwik_url' => $settings->get_global_option( 'piwik_url' ),
		);

		$settings->set_global_option( 'piwik_url', 'https://matomo.example.org/' );
	}

	public function tear_down() {
		\WP_Piwik::get_settings()->set_global_option( 'piwik_url', $this->settings_backup['piwik_url'] );

		parent::tear_down();
	}

	public function test_shortcode_should_delegate_to_the_shortcode_class() {
		// the opt-out module needs no authorization and performs no API request, so
		// rendering it only proves the callback reaches \WP_Piwik\Shortcode
		$output = $GLOBALS['wp-piwik']->shortcode( shortcode_parse_atts( 'module=opt-out' ) );

		$this->assertStringContainsString( '<iframe', $output );
	}
}
