<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Settings;

class SettingsTest extends WP_Piwik_TestCase {

	public function test_global_option_defaults_are_used_when_nothing_is_stored() {
		$settings = $this->create_settings();

		$this->assertSame( 'http', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( 'disabled', $settings->get_global_option( 'track_mode' ) );
		$this->assertSame( 'Connect Matomo', $settings->get_global_option( 'plugin_display_name' ) );
		$this->assertTrue( $settings->get_global_option( 'cache' ) );
	}

	public function test_option_defaults_are_used_when_nothing_is_stored() {
		$settings = $this->create_settings();

		$this->assertNull( $settings->get_option( 'site_id' ) );
		$this->assertSame( '', $settings->get_option( 'tracking_code' ) );
	}

	public function test_stored_options_override_defaults() {
		$settings = $this->create_settings(
			[ 'piwik_url' => 'https://stats.example.org/' ],
			[ 'site_id' => 5 ]
		);

		$this->assertSame( 'https://stats.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertEquals( 5, $settings->get_option( 'site_id' ) );
	}

	public function test_set_global_option_changes_value_without_saving() {
		$settings = $this->create_settings();

		$settings->set_global_option( 'piwik_url', 'https://stats.example.org/' );

		$this->assertSame( 'https://stats.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertFalse( get_option( 'wp-piwik_global-piwik_url' ) );
	}

	public function test_set_option_changes_value_for_current_blog() {
		$settings = $this->create_settings();

		$settings->set_option( 'site_id', 7 );

		$this->assertSame( 7, $settings->get_option( 'site_id' ) );
	}

	public function test_save_persists_changed_settings_as_options() {
		$settings = $this->create_settings();

		$settings->set_global_option( 'piwik_url', 'https://stats.example.org/' );
		$settings->save();

		$this->assertSame( 'https://stats.example.org/', get_option( 'wp-piwik_global-piwik_url' ) );
	}

	public function test_save_does_nothing_when_no_settings_changed() {
		$settings = $this->create_settings();

		$settings->save();

		$this->assertFalse( get_option( 'wp-piwik_global-piwik_mode' ) );
	}

	public function test_save_updates_role_capabilities() {
		$settings = $this->create_settings();
		$settings->set_global_option( 'capability_read_stats', [ 'administrator' => true ] );
		$settings->save();

		$administrator = get_role( 'administrator' );
		$this->assertTrue( $administrator->has_cap( 'wp-piwik_read_stats' ) );
		$this->assertFalse( $administrator->has_cap( 'wp-piwik_stealth' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'wp-piwik_read_stats' ) );

		// the database is rolled back automatically, but the in-memory role
		// objects are not, so remove the capability explicitly
		wp_roles()->remove_cap( 'administrator', 'wp-piwik_read_stats' );
	}

	public function test_get_not_empty_global_option_falls_back_to_default() {
		$settings = $this->create_settings();

		$settings->set_global_option( 'plugin_display_name', '' );

		$this->assertSame( 'Connect Matomo', $settings->get_not_empty_global_option( 'plugin_display_name' ) );
	}

	public function test_get_matomo_url_in_http_mode() {
		$settings = $this->create_settings(
			[
				'piwik_mode' => 'http',
				'piwik_url'  => 'https://stats.example.org/',
			]
		);

		$this->assertSame( 'https://stats.example.org/', $settings->get_matomo_url() );
	}

	public function test_get_matomo_url_in_cloud_mode() {
		$settings = $this->create_settings(
			[
				'piwik_mode' => 'cloud',
				'piwik_user' => 'myuser',
			]
		);

		$this->assertSame( 'https://myuser.innocraft.cloud/', $settings->get_matomo_url() );
	}

	public function test_get_matomo_url_in_matomo_cloud_mode() {
		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'mymatomo',
			]
		);

		$this->assertSame( 'https://mymatomo.matomo.cloud/', $settings->get_matomo_url() );
	}

	public function test_is_tracking_enabled() {
		$settings = $this->create_settings();
		$this->assertFalse( $settings->is_tracking_enabled() );

		$settings = $this->create_settings( [ 'track_mode' => 'default' ] );
		$this->assertTrue( $settings->is_tracking_enabled() );
	}

	public function test_is_ai_bot_tracking_enabled() {
		$settings = $this->create_settings();
		$this->assertFalse( $settings->is_ai_bot_tracking_enabled() );

		$settings = $this->create_settings( [ Settings::TRACK_AI_BOTS => true ] );
		$this->assertTrue( $settings->is_ai_bot_tracking_enabled() );
	}

	public function test_is_ai_bot_tracking_enabled_via_esi_includes() {
		$settings = $this->create_settings();
		$this->assertFalse( $settings->is_ai_bot_tracking_enabled_via_esi_includes() );

		$settings = $this->create_settings( [ Settings::TRACK_AI_BOTS_USING_ESI => true ] );
		$this->assertTrue( $settings->is_ai_bot_tracking_enabled_via_esi_includes() );
		$this->assertTrue( $settings->is_track_via_esi_enabled() );
	}

	public function test_get_debug_data_masks_the_auth_token() {
		$settings = $this->create_settings( [ 'piwik_token' => 'secret-token' ] );
		$debug    = $settings->get_debug_data();
		$this->assertSame( 'set', $debug['global_settings']['piwik_token'] );

		$settings = $this->create_settings( [ 'piwik_token' => '' ] );
		$debug    = $settings->get_debug_data();
		$this->assertSame( 'not set', $debug['global_settings']['piwik_token'] );
	}

	public function test_apply_changes_normalizes_and_persists_the_configuration() {
		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'piwik_mode'       => 'http',
				'piwik_url'        => 'https://stats.example.org', // no trailing slash
				'piwik_token'      => '&token_auth=abc123',
				'auto_site_config' => false,
				'site_id'          => '9',
				'track_mode'       => 'manually',
				'tracking_code'    => "<script>alert(\\'x\\');</script>",
				'noscript_code'    => '<noscript>manual</noscript>',
			]
		);

		$this->assertSame( 'https://stats.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( 'abc123', $settings->get_global_option( 'piwik_token' ) );
		$this->assertSame( 9, $settings->get_option( 'site_id' ) );
		$this->assertSame( "<script>alert('x');</script>", $settings->get_option( 'tracking_code' ) );
		$this->assertSame( '<noscript>manual</noscript>', $settings->get_option( 'noscript_code' ) );

		// unlisted settings fall back to their defaults and everything is persisted
		$this->assertSame( 'manually', get_option( 'wp-piwik_global-track_mode' ) );
		$this->assertSame( 'yesterday', get_option( 'wp-piwik_global-default_date' ) );
		$this->assertNotEmpty( $settings->get_global_option( 'last_settings_update' ) );
	}

	public function test_apply_changes_requests_site_id_when_auto_config_is_enabled() {
		$plugin                = new \WP_Piwik_Test_Mock_Plugin();
		$plugin->piwik_site_id = 42;
		$settings              = new Settings( $plugin );

		$settings->apply_changes(
			[
				'auto_site_config' => true,
				'site_id'          => '',
				'track_mode'       => 'disabled',
			]
		);

		$this->assertSame( 42, $settings->get_option( 'site_id' ) );
	}

	public function test_apply_changes_normalizes_the_cookie_allowlist() {
		$settings = $this->create_settings();

		$settings->apply_changes( [ 'cookie_allowlist' => "  _pk_* ,mtm_*,\n_pk_*  " ] );

		$this->assertSame( '_pk_*, mtm_*', $settings->get_global_option( 'cookie_allowlist' ) );
	}

	/**
	 * @dataProvider get_unusable_cookie_allowlists
	 *
	 * @param mixed $value allow list to store
	 */
	public function test_apply_changes_turns_an_unusable_cookie_allowlist_off( $value ) {
		$settings = $this->create_settings();

		$settings->apply_changes( [ 'cookie_allowlist' => $value ] );

		// storing it as an empty value keeps the settings screen honest: an allow list that matches
		// nothing must not look like an active filter
		$this->assertSame( '', $settings->get_global_option( 'cookie_allowlist' ) );
	}

	public function get_unusable_cookie_allowlists() {
		return [
			'only separators'   => [ ',,,' ],
			'whitespace'        => [ " ,\t, " ],
			'bare wildcard'     => [ '*' ],
			'invalid names'     => [ 'bad;entry, "quoted", a=b, foo bar' ],
			'array'             => [ [ '_pk_*' ] ],
			'null'              => [ null ],
			'too long an entry' => [ str_repeat( 'a', 129 ) ],
		];
	}

	public function test_parse_cookie_allowlist_keeps_valid_entries_only() {
		$this->assertSame(
			[ '_pk_*', 'mtm_consent', '_pk_id.1.1fff' ],
			Settings::parse_cookie_allowlist( '_pk_*, bad;entry, mtm_consent, *, , _pk_id.1.1fff' )
		);
	}

	public function test_parse_cookie_allowlist_caps_the_number_of_entries() {
		$value = implode( ',', array_map( fn ( $i ) => 'cookie_' . $i, range( 1, 200 ) ) );

		$entries = Settings::parse_cookie_allowlist( $value );

		$this->assertCount( Settings::COOKIE_ALLOWLIST_MAX_ENTRIES, $entries );
		$this->assertSame( 'cookie_1', $entries[0] );
	}

	public function test_parse_cookie_allowlist_caps_the_overall_length_without_truncating_an_entry() {
		// stay under the entry count limit so that the length limit is what cuts the list short
		$names   = array_map( fn ( $i ) => str_pad( 'cookie_' . $i . '_', 120, 'x' ), range( 1, 40 ) );
		$entries = Settings::parse_cookie_allowlist( implode( ',', $names ) . ',never_read' );

		$this->assertGreaterThan( 0, count( $entries ) );
		$this->assertLessThan( count( $names ), count( $entries ) );
		$this->assertNotContains( 'never_read', $entries );
		foreach ( $entries as $entry ) {
			// a cut off entry would be a different, shorter cookie name, so it is dropped entirely
			$this->assertContains( $entry, $names );
		}
	}

	public function test_check_network_activation_is_false_when_not_network_activated() {
		$settings = $this->create_settings();

		$this->assertFalse( $settings->check_network_activation() );
	}

	public function test_network_activation_reads_global_options_from_site_options() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		update_site_option( 'active_sitewide_plugins', [ 'wp-piwik/wp-piwik.php' => time() ] );
		update_site_option( 'wp-piwik_global-piwik_url', 'https://network.example.org/' );
		update_option( 'wp-piwik_global-piwik_url', 'https://single.example.org/' );

		$settings = new Settings( new \WP_Piwik_Test_Mock_Plugin() );

		$this->assertSame( 'https://network.example.org/', $settings->get_global_option( 'piwik_url' ) );
	}
}
