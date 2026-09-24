<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Settings;

class SettingsTest extends WP_Piwik_TestCase {

	const CROSS_SITE_PAYLOAD = '<script>alert(1);</script>';

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

	public function test_get_matomo_url_in_php_mode_should_use_the_url_of_a_site_that_names_one() {
		$settings = $this->create_settings(
			[
				'piwik_mode' => 'php',
				'piwik_url'  => 'https://stats.example.org/',
			]
		);

		$this->assertSame( 'https://stats.example.org/', $settings->get_matomo_url() );
	}

	public function test_get_matomo_url_in_php_mode_should_ask_matomo_when_the_site_names_no_url() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'php' ] );

		// a site connected this way names Matomo by path, so Matomo is the one that knows
		// the URL it answers under. there is none on this server's filesystem to ask.
		$this->assertSame( '', $settings->get_matomo_url() );
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
		$this->log_in_as_a_user_who_may_publish_script();

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

	public function test_apply_changes_should_keep_the_version_history_the_settings_form_does_not_carry() {
		$settings = $this->create_settings( [ 'version_history' => [ '1.1.11', '1.1.12' ] ] );

		$settings->apply_changes( [ 'piwik_mode' => 'http' ] );

		$this->assertSame( [ '1.1.11', '1.1.12' ], $settings->get_global_option( 'version_history' ) );
		$this->assertSame( [ '1.1.11', '1.1.12' ], get_option( 'wp-piwik_global-version_history' ) );
	}

	public function test_apply_changes_should_ignore_a_version_history_the_request_carries() {
		$settings = $this->create_settings( [ 'version_history' => [ '1.1.12' ] ] );

		$settings->apply_changes(
			[
				'piwik_mode'      => 'http',
				'version_history' => [ '9.9.9' ],
			]
		);

		$this->assertSame( [ '1.1.12' ], $settings->get_global_option( 'version_history' ) );
	}

	public function test_get_global_option_defaults_the_shortcode_author_check_to_enabled() {
		$settings = $this->create_settings();

		$this->assertTrue( $settings->get_global_option( 'shortcode_author_check' ) );
	}

	public function test_apply_changes_persists_a_disabled_shortcode_author_check() {
		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'shortcodes'             => 1,
				'shortcode_author_check' => 0,
			]
		);

		$this->assertFalse( (bool) $settings->get_global_option( 'shortcode_author_check' ) );
		$this->assertSame( '0', (string) get_option( 'wp-piwik_global-shortcode_author_check' ) );
	}

	public function test_apply_changes_re_enables_a_shortcode_author_check_the_form_did_not_submit() {
		$settings = $this->create_settings( [ 'shortcode_author_check' => 0 ] );

		// apply_changes() writes the default for every setting missing from the
		// submitted configuration, so a settings form that stops rendering this row
		// silently turns the check back on
		$settings->apply_changes( [ 'shortcodes' => 1 ] );

		$this->assertTrue( (bool) $settings->get_global_option( 'shortcode_author_check' ) );
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

	public function test_get_matomo_mode_options_should_not_offer_the_deprecated_php_api() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'http' ] );

		$this->assertSame(
			[ 'disabled', 'http', 'cloud-matomo', 'cloud' ],
			array_keys( $settings->get_matomo_mode_options() )
		);
	}

	public function test_get_matomo_mode_options_should_keep_the_php_api_for_a_site_still_using_it() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'php' ] );

		$options = $settings->get_matomo_mode_options();

		// dropping the entry would make saving the settings page silently switch the
		// site to another connection method
		$this->assertSame( [ 'disabled', 'http', 'php', 'cloud-matomo', 'cloud' ], array_keys( $options ) );
		$this->assertStringContainsString( 'deprecated', $options['php'] );
	}

	/**
	 * @dataProvider get_offered_connection_methods
	 */
	public function test_check_piwik_mode_should_keep_a_connection_method_the_settings_page_offers( $piwik_mode ) {
		$settings = $this->create_settings();

		$this->assertSame( $piwik_mode, $settings->check_piwik_mode( $piwik_mode ) );
	}

	public function get_offered_connection_methods() {
		return [
			'disabled'     => [ 'disabled' ],
			'http'         => [ 'http' ],
			'cloud'        => [ 'cloud' ],
			'cloud-matomo' => [ 'cloud-matomo' ],
		];
	}

	public function test_check_piwik_mode_should_keep_the_deprecated_php_api_for_a_site_still_using_it() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'php' ] );
		$this->assertSame( 'php', $settings->check_piwik_mode( 'php' ) );
	}

	public function test_check_piwik_mode_should_reject_the_deprecated_php_api_for_a_site_not_using_it() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'cloud' ] );
		$this->assertSame( 'cloud', $settings->check_piwik_mode( 'php' ) );
	}

	/**
	 * @dataProvider get_values_that_are_not_a_connection_method
	 */
	public function test_check_piwik_mode_should_reject_a_value_that_is_not_a_connection_method( $value ) {
		$settings = $this->create_settings( [ 'piwik_mode' => 'cloud' ] );
		$this->assertSame( 'cloud', $settings->check_piwik_mode( $value ) );
	}

	public function get_values_that_are_not_a_connection_method() {
		return [
			'an unknown method' => [ 'ftp' ],
			'the option label'  => [ 'Self-hosted (HTTP API, default)' ],
			'an empty string'   => [ '' ],
			'null'              => [ null ],
			'an array'          => [ [ 'http' ] ],
		];
	}

	public function test_apply_changes_should_reject_a_connection_method_the_settings_page_does_not_offer() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'cloud' ] );

		$settings->apply_changes( [ 'piwik_mode' => 'php' ] );

		$this->assertSame( 'cloud', $settings->get_global_option( 'piwik_mode' ) );
	}

	public function test_apply_changes_should_keep_the_deprecated_php_api_for_a_site_still_using_it() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'php' ] );

		$settings->apply_changes(
			[
				'piwik_mode' => 'php',
				'piwik_path' => '/var/www/matomo/',
			]
		);

		$this->assertSame( 'php', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( '/var/www/matomo/', $settings->get_global_option( 'piwik_path' ) );
	}

	public function test_apply_changes_should_leave_a_site_that_names_no_matomo_with_an_empty_url() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'php' ] );

		$settings->apply_changes(
			[
				'piwik_mode' => 'php',
				'piwik_path' => '/var/www/matomo/',
				'piwik_url'  => '',
			]
		);

		$this->assertSame( '', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( '', $settings->get_matomo_url() );
	}

	public function test_apply_changes_should_slash_a_matomo_url_that_does_not_end_in_one() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => 'https://stats.example.org' ] );

		$this->assertSame( 'https://stats.example.org/', $settings->get_global_option( 'piwik_url' ) );
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

	public function test_apply_changes_should_not_store_a_script_in_manual_tracking_code_for_a_user_without_unfiltered_html() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'track_mode'    => 'manually',
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$this->assertStringNotContainsString( '<script', $settings->get_option( 'tracking_code' ) );
	}

	public function test_apply_changes_should_not_store_a_script_in_manual_noscript_code_for_a_user_without_unfiltered_html() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'track_mode'    => 'manually',
				'tracking_code' => '',
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$this->assertStringNotContainsString( '<script', $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_store_manual_tracking_code_for_a_user_with_unfiltered_html() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'track_mode'    => 'manually',
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'tracking_code' ) );
	}

	public function test_apply_changes_should_not_enable_manual_track_mode_for_a_user_without_unfiltered_html() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings( [ 'track_mode' => 'default' ] );

		$settings->apply_changes( [ 'track_mode' => 'manually' ] );

		$this->assertSame( 'default', $settings->get_global_option( 'track_mode' ) );
	}

	public function test_apply_changes_should_reject_a_tracking_mode_the_settings_page_does_not_offer() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings( [ 'track_mode' => 'default' ] );

		$settings->apply_changes( [ 'track_mode' => 'no-such-mode' ] );

		$this->assertSame( 'default', $settings->get_global_option( 'track_mode' ) );
	}

	public function test_get_track_mode_options_should_offer_manual_mode_to_a_user_with_unfiltered_html() {
		$this->log_in_as_a_user_who_may_publish_script();

		$this->assertArrayHasKey( 'manually', $this->create_settings()->get_track_mode_options() );
	}

	public function test_get_track_mode_options_should_not_offer_manual_mode_to_a_user_without_unfiltered_html() {
		$this->log_in_as_a_network_site_administrator();

		$options = $this->create_settings()->get_track_mode_options();

		$this->assertArrayNotHasKey( 'manually', $options );
		// the modes whose code Connect Matomo generates itself stay available
		$this->assertArrayHasKey( 'default', $options );
		$this->assertArrayHasKey( 'proxy', $options );
	}

	public function test_get_track_mode_options_should_keep_manual_mode_for_a_site_already_using_it() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings( [ 'track_mode' => 'manually' ] );

		$this->assertArrayHasKey( 'manually', $settings->get_track_mode_options() );
	}

	public function test_apply_changes_should_keep_configuring_ordinary_settings_for_a_user_without_unfiltered_html() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'stats.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'piwik_mode'       => 'http',
				'piwik_url'        => 'https://stats.example.org/',
				'auto_site_config' => false,
				'site_id'          => '3',
				'track_mode'       => 'default',
				'track_search'     => true,
			]
		);

		// the delegated administrator is still meant to run analytics on their own site
		$this->assertSame( 'https://stats.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( 3, $settings->get_option( 'site_id' ) );
		$this->assertSame( 'default', $settings->get_global_option( 'track_mode' ) );
		$this->assertTrue( (bool) $settings->get_global_option( 'track_search' ) );
	}

	public function test_apply_changes_should_keep_the_manual_tracking_code_when_the_request_omits_the_track_mode() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings(
			[ 'track_mode' => 'manually' ],
			[
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$settings->apply_changes(
			[
				'tracking_code' => '',
				'noscript_code' => '',
			]
		);

		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'tracking_code' ) );
		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_escape_html_in_the_plugin_display_name() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'plugin_display_name' => '<b>Stats</b> & "more"' ] );

		$this->assertSame( '&lt;b&gt;Stats&lt;/b&gt; &amp; &quot;more&quot;', $settings->get_global_option( 'plugin_display_name' ) );
	}

	public function test_save_should_not_escape_the_plugin_display_name_again() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();
		$settings->apply_changes( [ 'plugin_display_name' => 'Stats & Insights' ] );

		// a save carrying any other change used to add a layer of escaping to the name
		$settings->set_global_option( 'revision', 1 );
		$settings->save();

		$this->assertSame( 'Stats &amp; Insights', $settings->get_global_option( 'plugin_display_name' ) );
		$this->assertSame( 'Stats &amp; Insights', get_option( 'wp-piwik_global-plugin_display_name' ) );
	}

	public function test_apply_changes_should_clear_the_tracking_code_when_a_user_without_unfiltered_html_leaves_manual_mode() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings(
			[ 'track_mode' => 'manually' ],
			[
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$settings->apply_changes(
			[
				'track_mode'    => 'default',
				'tracking_code' => '',
				'noscript_code' => '',
			]
		);

		// code an earlier version accepted from this user would otherwise stay stored while
		// the site no longer reports the mode the review notice goes looking for
		$this->assertSame( '', $settings->get_option( 'tracking_code' ) );
		$this->assertSame( '', $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_keep_the_tracking_code_of_a_user_without_unfiltered_html_who_stays_in_manual_mode() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings(
			[ 'track_mode' => 'manually' ],
			[
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$settings->apply_changes(
			[
				'track_mode'    => 'manually',
				'tracking_code' => '',
				'noscript_code' => '',
			]
		);

		// the fields are read only for this user, so they cannot throw away code a privileged
		// user entered either
		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'tracking_code' ) );
		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_clear_the_tracking_code_when_a_user_with_unfiltered_html_leaves_manual_mode() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings(
			[ 'track_mode' => 'manually' ],
			[
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$settings->apply_changes(
			[
				'track_mode'    => 'default',
				'tracking_code' => '',
				'noscript_code' => '',
			]
		);

		// a generated mode writes both of them itself on the next request
		$this->assertSame( '', $settings->get_option( 'tracking_code' ) );
		$this->assertSame( '', $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_clear_the_tracking_code_when_a_user_without_unfiltered_html_stops_tracking() {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings(
			[ 'track_mode' => 'manually' ],
			[
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$settings->apply_changes(
			[
				'track_mode'    => 'disabled',
				'tracking_code' => '',
				'noscript_code' => '',
			]
		);

		// the disabled mode holds the code for the next time tracking is turned on. leaving
		// it there would park code an earlier version let this very user enter, out of sight
		// of the review notice and ready for the settings form to turn it back on again.
		$this->assertSame( '', $settings->get_option( 'tracking_code' ) );
		$this->assertSame( '', $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_clear_a_generated_tracking_code_when_tracking_is_stopped() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings(
			[ 'track_mode' => 'default' ],
			[
				'tracking_code' => '<!-- Matomo -->',
				'noscript_code' => '<noscript>generated</noscript>',
			]
		);

		$settings->apply_changes(
			[
				'track_mode'    => 'disabled',
				'tracking_code' => '<!-- Matomo -->',
				'noscript_code' => '<noscript>generated</noscript>',
			]
		);

		// Connect Matomo wrote this itself and writes a new one whenever tracking is turned
		// back on, so the disabled mode has nothing to hold it for
		$this->assertSame( '', $settings->get_option( 'tracking_code' ) );
		$this->assertSame( '', $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_keep_the_tracking_code_of_a_user_with_unfiltered_html_who_stops_tracking() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings(
			[ 'track_mode' => 'manually' ],
			[
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		$settings->apply_changes(
			[
				'track_mode'    => 'disabled',
				'tracking_code' => self::CROSS_SITE_PAYLOAD,
				'noscript_code' => self::CROSS_SITE_PAYLOAD,
			]
		);

		// a user who may publish script is allowed to configure the code and come back to it
		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'tracking_code' ) );
		$this->assertSame( self::CROSS_SITE_PAYLOAD, $settings->get_option( 'noscript_code' ) );
	}

	public function test_apply_changes_should_reject_a_forced_protocol_the_settings_page_does_not_offer() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings( [ 'force_protocol' => 'https' ] );

		// the protocol is written into the tracker URL of the generated tracking code
		$settings->apply_changes( [ 'force_protocol' => 'https"+alert(1)+"' ] );

		$this->assertSame( 'https', $settings->get_global_option( 'force_protocol' ) );
	}

	public function test_apply_changes_should_store_a_forced_protocol_the_settings_page_offers() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'force_protocol' => 'https' ] );

		$this->assertSame( 'https', $settings->get_global_option( 'force_protocol' ) );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_strip_what_a_url_cannot_hold_from_a_cdn_url( $key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'cdn.example.org/<!--<script>' ] );

		$this->assertSame( 'cdn.example.org/!--script', $settings->get_global_option( $key ) );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_store_a_cdn_url_without_the_protocol_it_was_typed_with( $key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'https://cdn.example.org/matomo/' ] );

		$this->assertSame( 'cdn.example.org/matomo', $settings->get_global_option( $key ) );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_keep_a_cdn_url_an_earlier_version_stored_as_it_was_typed( $key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ $key => 'cdn.elsewhere.example.org ' ] );

		// saving the form posts the field back unchanged
		$settings->apply_changes( [ $key => 'cdn.elsewhere.example.org ' ] );

		$this->assertSame( 'cdn.elsewhere.example.org', $settings->get_global_option( $key ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function get_cdn_url_settings() {
		return [
			'plain CDN URL' => [ 'track_cdnurl' ],
			'SSL CDN URL'   => [ 'track_cdnurlssl' ],
		];
	}

	/**
	 * @dataProvider get_tracking_code_list_settings
	 */
	public function test_apply_changes_should_strip_angle_brackets_from_a_list_the_tracking_code_carries( $key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'zip|<!--<script>|tar' ] );

		$this->assertSame( 'zip|!--script|tar', $settings->get_global_option( $key ) );
	}

	public function get_tracking_code_list_settings() {
		return [
			'download extensions replaced' => [ 'set_download_extensions' ],
			'download extensions added'    => [ 'add_download_extensions' ],
			'download classes'             => [ 'set_download_classes' ],
			'link classes'                 => [ 'set_link_classes' ],
		];
	}

	public function test_get_force_protocol_options_should_offer_the_protocols_the_tracking_code_understands() {
		$this->assertSame(
			[ 'disabled', 'http', 'https' ],
			array_keys( $this->create_settings()->get_force_protocol_options() )
		);
	}

	public function test_apply_changes_should_not_point_a_site_of_a_network_at_a_matomo_the_network_does_not_allow() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => 'https://matomo.example.org/' ] );

		$settings->apply_changes( [ 'piwik_url' => 'https://evil.example.org/' ] );

		$this->assertSame( 'https://matomo.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_point_a_site_of_a_network_at_a_matomo_the_network_allows() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( '*.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => 'https://matomo.example.org/' ] );

		$this->assertSame( 'https://matomo.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_let_a_site_of_a_network_stop_naming_a_matomo() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => 'https://matomo.example.org/' ] );

		$settings->apply_changes( [ 'piwik_url' => '' ] );

		$this->assertSame( '', $settings->get_global_option( 'piwik_url' ) );
	}

	public function test_apply_changes_should_let_a_network_administrator_name_any_matomo() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_user_who_may_publish_script();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => 'https://elsewhere.example.org/' ] );

		$this->assertSame( 'https://elsewhere.example.org/', $settings->get_global_option( 'piwik_url' ) );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_not_load_the_tracker_of_a_site_of_a_network_from_a_cdn_it_does_not_allow( $key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'cdn.example.org' );

		$settings = $this->create_settings( [ $key => 'cdn.example.org/matomo' ] );

		$settings->apply_changes( [ $key => 'evil.example.org/matomo' ] );

		$this->assertSame( 'cdn.example.org/matomo', $settings->get_global_option( $key ) );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_load_the_tracker_of_a_site_of_a_network_from_a_cdn_it_allows( $key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'cdn.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'cdn.example.org/matomo' ] );

		$this->assertSame( 'cdn.example.org/matomo', $settings->get_global_option( $key ) );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_load_the_tracker_of_a_site_of_a_network_from_a_cdn_it_allows_typed_with_its_protocol( $key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'cdn.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'https://cdn.example.org/matomo' ] );

		$this->assertSame( 'cdn.example.org/matomo', $settings->get_global_option( $key ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	/**
	 * @dataProvider get_matomo_urls_hiding_the_protocol_behind_a_server
	 */
	public function test_apply_changes_should_not_point_a_site_of_a_network_at_a_matomo_url_naming_a_server_before_a_host_it_allows( $matomo_url, $reported ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => 'https://matomo.example.org/' ] );

		$settings->apply_changes( [ 'piwik_url' => $matomo_url ] );

		$this->assertSame( 'https://matomo.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [ $reported ], $settings->get_rejected_tracker_hosts() );
	}

	public function get_matomo_urls_hiding_the_protocol_behind_a_server() {
		return [
			'behind a space'       => [ ' evil.example.org://matomo.example.org', 'evil.example.org://matomo.example.org/' ],
			'behind a tab'         => [ "\tevil.example.org://matomo.example.org", 'evil.example.org://matomo.example.org/' ],
			'holding a line break' => [ "evil.example.org://matomo.example.org/\nmatomo", 'evil.example.org://matomo.example.org/matomo/' ],
		];
	}

	public function test_apply_changes_should_not_let_a_site_of_a_network_change_to_a_connection_method_naming_a_server_before_a_host_it_allows() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( [ 'matomo.example.org', '*.matomo.cloud' ] );

		// a Matomo URL stored by an earlier version, kept out of use while the site connects
		// to the cloud
		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'acme',
				'piwik_url'   => ' evil.example.org://matomo.example.org/',
			]
		);

		$settings->apply_changes( [ 'piwik_mode' => 'http' ] );

		$this->assertSame( 'cloud-matomo', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [ 'evil.example.org://matomo.example.org/' ], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_store_a_matomo_url_without_the_whitespace_around_it() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => " https://stats.example.org/matomo\n" ] );

		$this->assertSame( 'https://stats.example.org/matomo/', $settings->get_global_option( 'piwik_url' ) );
	}

	public function test_apply_changes_should_point_a_site_of_a_network_at_a_protocol_relative_matomo_url_it_allows() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => '//matomo.example.org/matomo/' ] );

		$this->assertSame( '//matomo.example.org/matomo/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_not_report_a_matomo_url_a_site_of_a_network_keeps_that_was_stored_with_whitespace_around_it() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => '   https://stats.example.org/' ] );

		$settings->apply_changes( [ 'piwik_url' => ' https://stats.example.org/' ] );

		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	/**
	 * @dataProvider get_matomo_urls_the_tracking_code_rewrites
	 */
	public function test_apply_changes_should_store_a_matomo_url_the_way_the_tracking_code_loads_it( $matomo_url, $expected ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( [ 'matomo.example.org', 'xn--bcher-kva.example' ] );

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => $matomo_url ] );

		$this->assertSame( $expected, $settings->get_global_option( 'piwik_url' ) );
	}

	public function get_matomo_urls_the_tracking_code_rewrites() {
		return [
			'a host outside ASCII' => [ "https://b\xc3\xbccher.example/matomo/", 'https://xn--bcher-kva.example/matomo/' ],
			'a path outside ASCII' => [ "https://matomo.example.org/st\xc3\xa4ts/", 'https://matomo.example.org/st%C3%A4ts/' ],
			'a space in the path'  => [ 'https://matomo.example.org/my matomo/', 'https://matomo.example.org/my%20matomo/' ],
			'a backslash'          => [ 'https://evil.example.org\\@matomo.example.org/', 'https://evil.example.org@matomo.example.org/' ],
		];
	}

	public function test_apply_changes_should_store_a_matomo_url_with_a_host_outside_ascii_in_its_ascii_form() {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => "https://statistik.b\xc3\xbccher.example/" ] );

		$this->assertSame( 'https://statistik.xn--bcher-kva.example/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_rejected_settings() );
	}

	public function test_apply_changes_should_not_point_a_site_of_a_network_at_a_matomo_url_whose_host_outside_ascii_it_does_not_allow() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => 'https://matomo.example.org/' ] );

		// a different server than matomo.example.org, however similar the two look
		$settings->apply_changes( [ 'piwik_url' => "https://matomo.example\xc3\xa9.org/" ] );

		$this->assertSame( 'https://matomo.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [ 'matomo.xn--example-hya.org' ], $settings->get_rejected_tracker_hosts() );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_store_a_cdn_url_with_a_host_outside_ascii_in_its_ascii_form( $key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => "cdn.b\xc3\xbccher.example/matomo" ] );

		$this->assertSame( 'cdn.xn--bcher-kva.example/matomo', $settings->get_global_option( $key ) );
	}

	public function test_apply_changes_should_not_report_a_matomo_url_a_site_of_a_network_keeps_that_was_stored_with_a_host_outside_ascii() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_url' => "https://stats.example\xc3\xa9.org/" ] );

		$settings->apply_changes( [ 'piwik_url' => "https://stats.example\xc3\xa9.org/" ] );

		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_point_a_site_of_a_network_at_a_matomo_url_it_allows_written_with_extra_slashes_after_the_protocol() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ 'piwik_url' => 'https:///matomo.example.org/matomo/' ] );

		$this->assertSame( 'https:///matomo.example.org/matomo/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	/**
	 * @dataProvider get_cdn_url_settings
	 */
	public function test_apply_changes_should_not_load_the_tracker_of_a_site_of_a_network_from_a_cdn_naming_a_host_it_allows_after_one_it_does_not( $key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'cdn.example.org' );

		$settings = $this->create_settings( [ $key => 'cdn.example.org/matomo' ] );

		$settings->apply_changes( [ $key => 'evil.example.org://cdn.example.org' ] );

		$this->assertSame( 'cdn.example.org/matomo', $settings->get_global_option( $key ) );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_apply_changes_should_not_point_a_site_of_a_network_at_a_cloud_the_network_does_not_allow( $key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'attacker' ] );

		$this->assertSame( '', $settings->get_global_option( $key ) );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_apply_changes_should_point_a_site_of_a_network_at_the_cloud_it_allows_by_default( $key ) {
		$this->log_in_as_a_network_site_administrator();

		$settings = $this->create_settings();

		$settings->apply_changes( [ $key => 'acme' ] );

		$this->assertSame( 'acme', $settings->get_global_option( $key ) );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_apply_changes_should_reject_a_cloud_subdomain_that_is_not_a_host_name_label( $key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings( [ $key => 'acme' ] );

		// a value carrying a path would name a server of its own once it is pasted into
		// the tracker URL
		$settings->apply_changes( [ $key => 'evil.example.org/' ] );

		$this->assertSame( 'acme', $settings->get_global_option( $key ) );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_apply_changes_should_name_a_cloud_subdomain_setting_it_could_not_store( $key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings( [ $key => 'acme' ] );

		$settings->apply_changes( [ $key => 'evil.example.org/' ] );

		// keeping the stored subdomain without saying so would look like the save took it
		$this->assertSame( [ $key ], $settings->get_rejected_settings() );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_apply_changes_should_name_no_setting_for_a_cloud_subdomain_it_stored( $setting_key ) {
		$this->log_in_as_a_user_who_may_publish_script();

		$settings = $this->create_settings();

		$settings->apply_changes( [ $setting_key => 'acme' ] );

		$this->assertSame( [], $settings->get_rejected_settings() );
	}

	/**
	 * @dataProvider get_cloud_subdomain_settings
	 */
	public function test_apply_changes_should_keep_a_cloud_subdomain_an_earlier_version_stored_as_it_was_typed( $setting_key ) {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ $setting_key => ' Acme' ] );

		// saving the form posts the field back unchanged
		$settings->apply_changes( [ $setting_key => ' Acme' ] );

		$this->assertSame( 'acme', $settings->get_global_option( $setting_key ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function get_cloud_subdomain_settings() {
		return [
			'InnoCraft Cloud subdomain' => [ 'piwik_user' ],
			'Matomo Cloud subdomain'    => [ 'matomo_user' ],
		];
	}

	public function test_apply_changes_should_not_connect_a_site_of_a_network_to_a_matomo_url_it_does_not_allow_by_changing_the_connection_method() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( '*.matomo.cloud' );

		// a URL stored before the network named its allowed hosts, kept out of use while
		// the site connects to the cloud
		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		$settings->apply_changes(
			[
				'piwik_mode'  => 'http',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		$this->assertSame( 'cloud-matomo', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_not_connect_a_site_of_a_network_to_a_cloud_subdomain_an_earlier_version_stored_naming_a_server_of_its_own() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( [ 'matomo.example.org', '*.matomo.cloud' ] );

		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'http',
				'piwik_url'   => 'https://matomo.example.org/',
				'matomo_user' => 'evil.example.org://acme',
			]
		);

		$settings->apply_changes(
			[
				'piwik_mode'  => 'cloud-matomo',
				'piwik_url'   => 'https://matomo.example.org/',
				'matomo_user' => 'evil.example.org://acme',
			]
		);

		$this->assertSame( 'http', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_let_a_site_of_a_network_change_to_a_connection_method_naming_a_matomo_it_allows() {
		$this->log_in_as_a_network_site_administrator();

		// the two Matomo cloud domains are allowed by default
		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'http',
				'matomo_user' => 'acme',
			]
		);

		$settings->apply_changes(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'acme',
			]
		);

		$this->assertSame( 'cloud-matomo', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_let_a_site_of_a_network_change_to_a_connection_method_naming_no_matomo() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings( [ 'piwik_mode' => 'http' ] );

		$settings->apply_changes( [ 'piwik_mode' => 'disabled' ] );

		$this->assertSame( 'disabled', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_let_a_network_administrator_change_to_any_connection_method() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_user_who_may_publish_script();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'http',
				'matomo_user' => 'elsewhere',
			]
		);

		$settings->apply_changes(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'elsewhere',
			]
		);

		$this->assertSame( 'cloud-matomo', $settings->get_global_option( 'piwik_mode' ) );
	}

	public function test_apply_changes_should_not_connect_a_site_of_a_network_to_a_matomo_url_it_does_not_allow_by_naming_no_connection_method() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( '*.matomo.cloud' );

		// a URL stored before the network named its allowed hosts, kept out of use while
		// the site connects to the cloud
		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		// a configuration set that names no connection method is stored as the default
		// one (http)
		$settings->apply_changes(
			[
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		$this->assertSame( 'cloud-matomo', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( 'https://testuser.matomo.cloud/', $settings->get_matomo_url() );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_store_the_default_connection_method_when_the_configuration_set_names_none() {
		$settings = $this->create_settings( [ 'piwik_mode' => 'cloud-matomo' ] );

		$settings->apply_changes( [] );

		$this->assertSame( 'http', $settings->get_global_option( 'piwik_mode' ) );
	}

	public function test_apply_changes_should_let_a_site_of_a_network_change_to_a_connection_method_naming_the_matomo_it_already_uses() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		// a URL stored before the network named its allowed hosts, in use since
		$settings = $this->create_settings(
			[
				'piwik_mode' => 'http',
				'piwik_url'  => 'https://evil.example.org/',
			]
		);

		// note: if we don't specify piwik_url again, it will default to ''
		$settings->apply_changes(
			[
				'piwik_mode' => 'disabled',
				'piwik_url'  => 'https://evil.example.org/',
			]
		);

		$this->assertSame( 'disabled', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_keep_the_matomo_url_a_site_of_a_network_uses_when_it_turns_its_connection_off() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		// a URL stored before the network named its allowed hosts, in use since. the site
		// may keep using it, so turning the connection off does not take it away.
		$settings = $this->create_settings(
			[
				'piwik_mode' => 'http',
				'piwik_url'  => 'https://evil.example.org/',
			]
		);

		$settings->apply_changes(
			[
				'piwik_mode' => 'disabled',
				'piwik_url'  => 'https://evil.example.org/',
			]
		);

		$this->assertSame( 'https://evil.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_removed_tracker_hosts() );
	}

	public function test_apply_changes_should_let_a_site_of_a_network_turn_its_connection_off_while_it_holds_a_matomo_url_the_network_does_not_allow() {
		$settings = $this->create_a_cloud_site_holding_a_matomo_url_the_network_does_not_allow();

		$settings->apply_changes(
			[
				'piwik_mode'  => 'disabled',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		$this->assertSame( 'disabled', $settings->get_global_option( 'piwik_mode' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_remove_a_matomo_url_the_network_does_not_allow_when_a_site_of_a_network_turns_its_connection_off() {
		$settings = $this->create_a_cloud_site_holding_a_matomo_url_the_network_does_not_allow();

		// with the connection off the site names its Matomo URL, which it was not using,
		// so keeping it would let the site turn it on later as the Matomo it already uses
		$settings->apply_changes(
			[
				'piwik_mode'  => 'disabled',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		$this->assertSame( '', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_removed_tracker_hosts() );
	}

	public function test_apply_changes_should_not_connect_a_site_of_a_network_to_a_matomo_url_it_does_not_allow_by_turning_its_connection_off_and_on_again() {
		$settings = $this->create_a_cloud_site_holding_a_matomo_url_the_network_does_not_allow();

		$settings->apply_changes(
			[
				'piwik_mode'  => 'disabled',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);
		$settings->apply_changes(
			[
				'piwik_mode'  => 'http',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);

		// the URL is refused as the new one it now is, which leaves the connection method
		// naming no Matomo at all
		$this->assertSame( '', $settings->get_matomo_url() );
		$this->assertSame( [ 'evil.example.org' ], $settings->get_rejected_tracker_hosts() );
	}

	public function test_apply_changes_should_keep_a_matomo_url_the_network_allows_when_a_site_of_a_network_turns_its_connection_off() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( [ '*.matomo.cloud', 'matomo.example.org' ] );

		$settings = $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://matomo.example.org/',
			]
		);

		$settings->apply_changes(
			[
				'piwik_mode'  => 'disabled',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://matomo.example.org/',
			]
		);

		$this->assertSame( 'https://matomo.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_removed_tracker_hosts() );
	}

	public function test_apply_changes_should_keep_any_matomo_url_when_a_network_administrator_turns_the_connection_off() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_user_who_may_publish_script();
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

		$this->assertSame( 'https://evil.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_removed_tracker_hosts() );
	}

	public function test_get_removed_tracker_hosts_should_be_empty_before_a_configuration_is_applied() {
		$this->assertSame( [], $this->create_settings()->get_removed_tracker_hosts() );
	}

	public function test_apply_changes_should_keep_a_matomo_url_a_site_had_before_the_network_named_its_allowed_hosts() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		// an upgrade must not stop a site from tracking, so a host it already has stored
		// stays, and saving the settings again is not a change to complain about
		$settings = $this->create_settings( [ 'piwik_url' => 'https://elsewhere.example.org/' ] );

		$settings->apply_changes( [ 'piwik_url' => 'https://elsewhere.example.org/' ] );

		$this->assertSame( 'https://elsewhere.example.org/', $settings->get_global_option( 'piwik_url' ) );
		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_get_rejected_tracker_hosts_should_be_empty_for_a_configuration_the_network_allows() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( '*.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'piwik_url'       => 'https://matomo.example.org/',
				'track_cdnurl'    => 'cdn.example.org',
				'track_cdnurlssl' => 'cdn.example.org',
			]
		);

		$this->assertSame( [], $settings->get_rejected_tracker_hosts() );
	}

	public function test_get_rejected_tracker_hosts_should_name_every_host_the_network_refused() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( 'matomo.example.org' );

		$settings = $this->create_settings();

		$settings->apply_changes(
			[
				'piwik_url'       => 'https://evil.example.org/',
				'track_cdnurl'    => 'cdn.evil.example.org',
				'track_cdnurlssl' => 'cdn.evil.example.org',
			]
		);

		$this->assertSame(
			[ 'evil.example.org', 'cdn.evil.example.org' ],
			$settings->get_rejected_tracker_hosts()
		);
	}

	private function create_a_cloud_site_holding_a_matomo_url_the_network_does_not_allow() {
		$this->log_in_as_a_network_site_administrator();
		$this->allow_tracker_hosts( '*.matomo.cloud' );

		// a URL stored before the network named its allowed hosts, kept out of use while
		// the site connects to the cloud
		return $this->create_settings(
			[
				'piwik_mode'  => 'cloud-matomo',
				'matomo_user' => 'testuser',
				'piwik_url'   => 'https://evil.example.org/',
			]
		);
	}

	private function allow_tracker_hosts( $entries ) {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only a network has an allow list.' );
		}
		update_site_option( \WP_Piwik\TrackerHosts::OPTION, (array) $entries );
	}

	private function log_in_as_a_user_who_may_publish_script() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'precondition: the user may publish script' );
	}

	private function log_in_as_a_network_site_administrator() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only a network withholds unfiltered_html from an administrator.' );
		}

		switch_to_blog( self::factory()->blog->create() );

		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'administrator' );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'manage_options' ), 'precondition: the user may open the settings screen' );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'precondition: the user may not publish script' );
	}
}
