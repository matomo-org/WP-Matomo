<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Admin\Settings as AdminSettings;

class AdminSettingsTest extends WP_Piwik_TestCase {

	public function test_get_matomo_mode_options_should_not_offer_the_deprecated_php_api() {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $this->create_settings( [ 'piwik_mode' => 'http' ] ) );

		$this->assertSame(
			[ 'disabled', 'http', 'cloud-matomo', 'cloud' ],
			array_keys( $admin->get_matomo_mode_options() )
		);
	}

	public function test_get_matomo_mode_options_should_keep_the_php_api_for_a_site_still_using_it() {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $this->create_settings( [ 'piwik_mode' => 'php' ] ) );

		$options = $admin->get_matomo_mode_options();

		// dropping the entry would make saving the settings page silently switch the
		// site to another connection method
		$this->assertSame( [ 'disabled', 'http', 'php', 'cloud-matomo', 'cloud' ], array_keys( $options ) );
		$this->assertStringContainsString( 'deprecated', $options['php'] );
	}

	public function test_show_select_marks_the_stored_option_as_selected_for_integer_keys() {
		// site_id stored as an integer.
		update_option( 'wp-piwik-site_id', 2 );

		// drop the options cache to simulate a fresh page load
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'wp-piwik-site_id', 'options' );

		$settings = new \WP_Piwik\Settings( new \WP_Piwik_Test_Mock_Plugin() );
		$this->assertSame( '2', $settings->get_option( 'site_id' ), 'precondition: stored site_id reads back as a string' );

		// the site list is keyed by idsite, so the keys are integers.
		$options = [
			1 => 'website1 (http://website1.com)',
			2 => 'website2 (http://website2.com)',
		];

		$html = $this->render_select( $settings, 'site_id', $options, false );

		$this->assertSame( [ '2' ], $this->get_selected_option_values( $html ), 'the stored site must be the only selected option' );
	}

	public function test_show_select_selects_nothing_when_no_value_is_stored() {
		$settings = $this->create_settings();

		$options = [
			1 => 'website1 (http://website1.com)',
			2 => 'website2 (http://website2.com)',
		];

		$html = $this->render_select( $settings, 'site_id', $options, false );

		// nothing stored yet, so no option should be forced selected.
		$this->assertSame( [], $this->get_selected_option_values( $html ) );
	}

	public function test_show_select_marks_the_stored_option_as_selected_for_string_keys() {
		$settings = $this->create_settings( [ 'default_date' => 'last_month' ] );

		$options = [
			'today'      => 'Today',
			'yesterday'  => 'Yesterday',
			'last_month' => 'Last month',
		];

		$html = $this->render_select( $settings, 'default_date', $options, true );

		$this->assertSame( [ 'last_month' ], $this->get_selected_option_values( $html ) );
	}

	private function render_select( $settings, $id, array $options, $is_global ) {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		ob_start();
		$admin->show_select( $id, 'Select site', $options, '', '', false, '', true, $is_global );
		return ob_get_clean();
	}

	private function get_selected_option_values( $html ) {
		preg_match_all( '/<option value="([^"]*)"[^>]*selected="selected"/', $html, $matches );
		return $matches[1];
	}
}
