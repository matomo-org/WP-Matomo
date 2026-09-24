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

	public function test_show_allowed_tracker_hosts_should_offer_the_stored_list_without_whitespace_around_it() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik\TrackerHosts::OPTION, [ 'matomo.example.org', 'stats.example.org' ] );

		$html = $this->render_allowed_tracker_hosts();

		$this->assertStringContainsString( ">matomo.example.org\nstats.example.org</textarea>", $html );
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

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_show_rejected_settings_should_name_a_cloud_subdomain_the_save_could_not_store( $key, $label, $example_url ) {
		$settings = $this->create_settings( [ $key => 'acme' ] );
		$settings->apply_changes( [ $key => 'evil.example.org/' ] );

		$html = $this->render_rejected_settings( $settings );

		$this->assertStringContainsString( $label, $html );
		$this->assertStringContainsString( $example_url, $html );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_show_rejected_settings_should_show_nothing_when_the_save_stored_every_value( $key ) {
		$settings = $this->create_settings();
		$settings->apply_changes( [ $key => 'acme' ] );

		$this->assertSame( '', $this->render_rejected_settings( $settings ) );
	}

	public function get_cloud_subdomain_settings() {
		return [
			'InnoCraft Cloud subdomain' => [ 'piwik_user', 'Innocraft subdomain', 'https://acme.innocraft.cloud/' ],
			'Matomo Cloud subdomain'    => [ 'matomo_user', 'Matomo subdomain', 'https://acme.matomo.cloud/' ],
		];
	}

	public function test_show_rejected_settings_should_name_a_matomo_url_whose_host_cannot_be_converted_to_ascii() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'Without the intl extension no host outside ASCII is accepted at all.' );
		}

		$settings = $this->create_settings( [ 'piwik_url' => 'https://matomo.example.org/' ] );
		$settings->apply_changes( [ 'piwik_url' => "https://matomo.b\xc3\xbccher..example/" ] );

		$html = $this->render_rejected_settings( $settings );

		$this->assertStringContainsString( 'Matomo URL', $html );
		$this->assertStringContainsString( 'could not be converted to its ASCII form', $html );
		$this->assertStringNotContainsString( 'intl', $html );
	}

	public function test_show_rejected_settings_should_show_nothing_when_nothing_was_saved() {
		$this->assertSame( '', $this->render_rejected_settings( $this->create_settings() ) );
	}

	public function test_show_rejected_tracker_hosts_should_name_the_host_a_save_was_not_allowed_to_use() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => 'https://matomo.example.org/' ] );
		$settings->apply_changes( [ 'piwik_url' => 'https://evil.example.org/' ] );

		$html = $this->render_rejected_tracker_hosts( $settings );

		$this->assertStringContainsString( 'evil.example.org', $html );

		$this->assertStringContainsString( 'matomo.example.org', $html );
	}

	public function test_show_rejected_tracker_hosts_should_show_nothing_when_the_save_named_hosts_the_network_allows() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings();
		$settings->apply_changes( [ 'piwik_url' => 'https://matomo.example.org/' ] );

		$this->assertSame( '', $this->render_rejected_tracker_hosts( $settings ) );
	}

	public function test_show_rejected_tracker_hosts_should_show_nothing_when_nothing_was_saved() {
		$this->assertSame( '', $this->render_rejected_tracker_hosts( $this->create_settings() ) );
	}

	public function test_show_removed_tracker_hosts_should_name_the_host_a_save_removed() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( '*.matomo.cloud' );

		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);
		$settings->apply_changes(
			[
				'piwik_mode'  => 'disabled',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		$html = $this->render_removed_tracker_hosts( $settings );

		$this->assertStringContainsString( 'evil.example.org', $html );
		$this->assertStringContainsString( '*.matomo.cloud', $html );
	}

	public function test_show_removed_tracker_hosts_should_show_nothing_when_the_save_removed_nothing() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_mode' => 'http' ] );
		$settings->apply_changes(
			[
				'piwik_mode' => 'disabled',
				'piwik_url'  => 'https://matomo.example.org/',
			]
		);

		$this->assertSame( '', $this->render_removed_tracker_hosts( $settings ) );
	}

	public function test_show_removed_tracker_hosts_should_show_nothing_when_nothing_was_saved() {
		$this->assertSame( '', $this->render_removed_tracker_hosts( $this->create_settings() ) );
	}

	public function test_show_rejected_tracker_host_entries_should_name_an_entry_the_list_could_not_hold() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$settings = $this->create_settings();
		$settings->get_tracker_hosts()->update_allow_list( "stats.example.org\n-nope.example.org" );

		$html = $this->render_rejected_tracker_host_entries( $settings );

		$this->assertStringContainsString( '-nope.example.org', $html );
		$this->assertStringNotContainsString( 'stats.example.org', $html );
	}

	public function test_show_rejected_tracker_host_entries_should_say_that_the_default_hosts_apply_when_nothing_was_stored() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$settings = $this->create_settings();
		$settings->get_tracker_hosts()->update_allow_list( '-nope.example.org' );

		$this->assertStringContainsString( '*.matomo.cloud', $this->render_rejected_tracker_host_entries( $settings ) );
	}

	public function test_show_rejected_tracker_host_entries_should_show_nothing_when_the_list_held_every_entry() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$settings = $this->create_settings();
		$settings->get_tracker_hosts()->update_allow_list( 'stats.example.org' );

		$this->assertSame( '', $this->render_rejected_tracker_host_entries( $settings ) );
	}

	public function test_show_rejected_tracker_host_entries_should_show_nothing_when_nothing_was_saved() {
		$this->assertSame( '', $this->render_rejected_tracker_host_entries( $this->create_settings() ) );
	}

	private function render_rejected_tracker_hosts( $settings ) {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		ob_start();
		$admin->show_rejected_tracker_hosts();
		return ob_get_clean();
	}

	private function render_removed_tracker_hosts( $settings ) {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		ob_start();
		$admin->show_removed_tracker_hosts();
		return ob_get_clean();
	}

	private function render_rejected_tracker_host_entries( $settings ) {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		ob_start();
		$admin->show_rejected_tracker_host_entries();
		return ob_get_clean();
	}

	private function allow_tracker_hosts( $entries ) {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only a network has an allow list.' );
		}
		update_site_option( \WP_Piwik\TrackerHosts::OPTION, (array) $entries );
	}

	private function log_in_as_a_network_site_administrator() {
		$this->skip_unless_multisite();

		switch_to_blog( self::factory()->blog->create() );

		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'administrator' );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( 'manage_network' ), 'precondition: the user may not manage the network' );
	}

	private function render_rejected_settings( $settings ) {
		$admin = new AdminSettings( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		ob_start();
		$admin->show_rejected_settings();
		return ob_get_clean();
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
