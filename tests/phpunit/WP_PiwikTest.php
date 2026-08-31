<?php

namespace WP_Piwik\Tests;

class WP_PiwikTest extends WP_Piwik_TestCase {

	private $settings_backup = array();

	public function set_up() {
		parent::set_up();

		$settings              = \WP_Piwik::get_settings();
		$this->settings_backup = array(
			'piwik_url'  => $settings->get_global_option( 'piwik_url' ),
			'piwik_mode' => $settings->get_global_option( 'piwik_mode' ),
		);

		$settings->set_global_option( 'piwik_url', 'https://matomo.example.org/' );
	}

	public function tear_down() {
		$settings = \WP_Piwik::get_settings();
		$settings->set_global_option( 'piwik_url', $this->settings_backup['piwik_url'] );
		$settings->set_global_option( 'piwik_mode', $this->settings_backup['piwik_mode'] );

		parent::tear_down();
	}

	public function test_shortcode_should_delegate_to_the_shortcode_class() {
		// the opt-out module needs no authorization and performs no API request, so
		// rendering it only proves the callback reaches \WP_Piwik\Shortcode
		$output = $GLOBALS['wp-piwik']->shortcode( shortcode_parse_atts( 'module=opt-out' ) );

		$this->assertStringContainsString( '<iframe', $output );
	}

	public function test_show_php_mode_deprecation_notice_if_in_use_should_warn_a_site_still_connecting_through_the_php_api() {
		\WP_Piwik::get_settings()->set_global_option( 'piwik_mode', 'php' );
		$this->log_in_as_settings_administrator();

		$output = $this->render_php_mode_deprecation_notice();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'The &quot;Self-hosted (PHP API)&quot; connection method is deprecated.', $output );
		$this->assertStringContainsString( 'November 2026', $output );
		$this->assertStringContainsString( 'page=wp-matomo-settings', $output );
	}

	/**
	 * @dataProvider get_connection_methods_that_are_not_the_php_api
	 */
	public function test_show_php_mode_deprecation_notice_if_in_use_should_stay_silent_for_every_other_connection_method( $piwik_mode ) {
		\WP_Piwik::get_settings()->set_global_option( 'piwik_mode', $piwik_mode );
		$this->log_in_as_settings_administrator();

		$this->assertSame( '', $this->render_php_mode_deprecation_notice() );
	}

	public function get_connection_methods_that_are_not_the_php_api() {
		return array(
			'disabled'     => array( 'disabled' ),
			'http'         => array( 'http' ),
			'cloud'        => array( 'cloud' ),
			'cloud-matomo' => array( 'cloud-matomo' ),
		);
	}

	public function test_show_php_mode_deprecation_notice_if_in_use_should_stay_silent_for_users_who_cannot_change_the_setting() {
		\WP_Piwik::get_settings()->set_global_option( 'piwik_mode', 'php' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render_php_mode_deprecation_notice() );
	}

	private function render_php_mode_deprecation_notice() {
		ob_start();
		$GLOBALS['wp-piwik']->show_php_mode_deprecation_notice_if_in_use();
		return ob_get_clean();
	}

	private function log_in_as_settings_administrator() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			// on a network, activate_plugins is reserved for super admins
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
	}
}
