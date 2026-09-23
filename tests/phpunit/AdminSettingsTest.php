<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Admin\Settings as AdminSettings;

class AdminSettingsTest extends WP_Piwik_TestCase {

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

	public function test_show_select_should_mark_manual_tracking_as_selected_when_the_user_may_not_choose_it_but_it_has_been_selected_previously() {
		$settings = $this->create_settings( [ 'track_mode' => 'manually' ] );

		$this->assertFalse( \WP_Piwik\Settings::can_enter_tracking_code_manually(), 'precondition: the user may not publish script' );

		$html = $this->render_select( $settings, 'track_mode', $settings->get_track_mode_options(), true );

		$this->assertSame( [ 'manually' ], $this->get_selected_option_values( $html ) );
	}

	public function test_show_allowed_tracker_hosts_should_offer_the_stored_list_to_a_network_administrator() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik\TrackerHosts::OPTION, [ 'matomo.example.org' ] );

		$html = $this->render_allowed_tracker_hosts();

		$this->assertStringContainsString( 'name="wp-piwik[allowed_tracker_hosts]"', $html );
		$this->assertStringContainsString( 'matomo.example.org', $html );
	}

	public function test_show_allowed_tracker_hosts_should_name_the_default_list_when_none_is_stored() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$html = $this->render_allowed_tracker_hosts();

		$this->assertStringContainsString( '*.matomo.cloud', $html );
	}

	public function test_show_allowed_tracker_hosts_should_show_nothing_to_a_user_who_cannot_manage_the_network() {
		$this->assertSame( '', $this->render_allowed_tracker_hosts() );
	}

	private function render_allowed_tracker_hosts() {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $this->create_settings() );
		ob_start();
		$admin->show_allowed_tracker_hosts();
		return ob_get_clean();
	}

	private function log_in_as_a_network_administrator() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
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
