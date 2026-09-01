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
		$this->assertStringContainsString( 'The &quot;Self-hosted (PHP API)&quot; connection method is deprecated', $output );
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

	public function test_record_deprecated_shortcode_use_should_keep_one_entry_per_module() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'post' );
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'post' );

		$this->assertSame(
			array( 'overview', 'post' ),
			$GLOBALS['wp-piwik']->get_recorded_deprecated_shortcodes(),
			'the modules are reported in the order the shortcode class declares them'
		);
	}

	public function test_get_recorded_deprecated_shortcodes_should_ignore_modules_that_are_not_deprecated() {
		update_option(
			\WP_Piwik::DEPRECATED_SHORTCODES_OPTION,
			array(
				'modules' => array(
					'overview' => time(),
					'opt-out'  => time(),
					'nope'     => time(),
				),
			)
		);

		$this->assertSame( array( 'overview' ), $GLOBALS['wp-piwik']->get_recorded_deprecated_shortcodes() );
	}

	/**
	 * @dataProvider get_option_values_the_plugin_never_wrote
	 */
	public function test_get_recorded_deprecated_shortcodes_should_ignore_a_value_it_did_not_write( $value ) {
		update_option( \WP_Piwik::DEPRECATED_SHORTCODES_OPTION, $value );

		$this->assertSame( array(), $GLOBALS['wp-piwik']->get_recorded_deprecated_shortcodes() );
	}

	public function get_option_values_the_plugin_never_wrote() {
		return array(
			'a string'                => array( 'overview' ),
			'the flat list of a beta' => array( array( 'overview', 'post' ) ),
			'timestamps that are not' => array( array( 'modules' => array( 'overview' => 'yesterday' ) ) ),
		);
	}

	public function test_dismiss_deprecated_shortcode_notice_should_hide_a_notice_that_was_showing() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$GLOBALS['wp-piwik']->dismiss_deprecated_shortcode_notice();

		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_record_deprecated_shortcode_use_should_leave_the_notice_dismissed_within_the_dismissal_period() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$GLOBALS['wp-piwik']->dismiss_deprecated_shortcode_notice();
		$this->log_in_as_settings_administrator();

		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );

		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_dismiss_deprecated_shortcode_notice_should_keep_the_notice_away_for_a_week() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );

		$GLOBALS['wp-piwik']->dismiss_deprecated_shortcode_notice();

		$record = get_option( \WP_Piwik::DEPRECATED_SHORTCODES_OPTION );
		$this->assertEqualsWithDelta( time() + WEEK_IN_SECONDS, $record['dismissed_until'], 5 );
	}

	public function test_record_deprecated_shortcode_use_should_show_the_notice_again_once_the_dismissal_ran_out() {
		$this->set_up_a_dismissal_that_ran_out();
		$this->log_in_as_settings_administrator();
		$this->assertSame( '', $this->render_deprecated_shortcode_notice(), 'precondition: nothing has rendered since' );

		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );

		$this->assertStringContainsString(
			'The statistics shortcodes are deprecated',
			$this->render_deprecated_shortcode_notice()
		);
	}

	public function test_get_recorded_deprecated_shortcodes_should_stay_empty_when_nothing_rendered_after_the_dismissal_ran_out() {
		$this->set_up_a_dismissal_that_ran_out();

		$this->assertSame(
			array(),
			$GLOBALS['wp-piwik']->get_recorded_deprecated_shortcodes(),
			'a site that removed its shortcodes in the meantime is not warned again'
		);
	}

	public function test_on_deprecated_shortcode_notice_dismissed_should_dismiss_the_notice() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$this->request_a_notice_dismissal( wp_create_nonce( \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ) );

		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_on_deprecated_shortcode_notice_dismissed_should_reject_a_request_without_a_valid_nonce() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$this->expectException( \WPDieException::class );
		$this->request_a_notice_dismissal( 'not-a-nonce' );
	}

	public function test_on_deprecated_shortcode_notice_dismissed_should_not_let_a_user_who_cannot_change_the_setting_dismiss_it() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->request_a_notice_dismissal( wp_create_nonce( \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ) );

		$this->log_in_as_settings_administrator();
		$this->assertStringContainsString(
			'The statistics shortcodes are deprecated',
			$this->render_deprecated_shortcode_notice()
		);
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_warn_a_site_rendering_a_deprecated_shortcode() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$output = $this->render_deprecated_shortcode_notice();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'The statistics shortcodes are deprecated', $output );
		$this->assertStringContainsString( 'November 2026', $output );
		$this->assertStringContainsString( 'Widgetize', $output );
		$this->assertStringContainsString( 'read-only auth token', $output );
		$this->assertStringContainsString( 'https://matomo.org/docs/embed-piwik-report/', $output );
		$this->assertStringContainsString( \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG, $output, 'the notice can be dismissed' );
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_name_only_the_modules_the_site_rendered() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'post' );
		$this->log_in_as_settings_administrator();

		$output = $this->render_deprecated_shortcode_notice();

		$this->assertStringContainsString( '<code>[wp-piwik module=&quot;post&quot;]</code>', $output );
		$this->assertStringNotContainsString( 'module=&quot;overview&quot;', $output );
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_say_the_opt_out_shortcode_keeps_working() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$this->assertStringContainsString(
			'The <code>[wp-piwik module=&quot;opt-out&quot;]</code> shortcode is not deprecated and will continue to function.',
			$this->render_deprecated_shortcode_notice()
		);
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_stay_silent_when_no_deprecated_shortcode_was_rendered() {
		$this->log_in_as_settings_administrator();

		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_stay_silent_for_users_who_cannot_change_the_setting() {
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_record_deprecated_shortcode_use_should_store_the_record_for_the_whole_network() {
		$this->network_activate_the_plugin();

		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );

		$record = get_site_option( \WP_Piwik::DEPRECATED_SHORTCODES_OPTION );
		$this->assertArrayHasKey( 'overview', $record['modules'] );
		$this->assertFalse(
			get_option( \WP_Piwik::DEPRECATED_SHORTCODES_OPTION ),
			'a network wide plugin keeps the record out of the individual sites'
		);
	}

	public function test_get_recorded_deprecated_shortcodes_should_report_a_network_wide_record_from_another_site() {
		$this->network_activate_the_plugin();
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );

		switch_to_blog( self::factory()->blog->create() );
		try {
			$this->assertSame( array( 'overview' ), $GLOBALS['wp-piwik']->get_recorded_deprecated_shortcodes() );
		} finally {
			restore_current_blog();
		}
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_warn_a_super_admin_on_a_network() {
		$this->network_activate_the_plugin();
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$this->assertStringContainsString(
			'The statistics shortcodes are deprecated',
			$this->render_deprecated_shortcode_notice()
		);
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_stay_silent_for_a_network_user_who_cannot_manage_sites() {
		$this->network_activate_the_plugin();
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();

		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_show_deprecated_shortcode_notice_if_in_use_should_warn_a_site_administrator_when_the_plugin_is_not_network_activated() {
		$this->skip_unless_multisite();
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();

		$this->assertStringContainsString(
			'The statistics shortcodes are deprecated',
			$this->render_deprecated_shortcode_notice()
		);
	}

	public function test_on_deprecated_shortcode_notice_dismissed_should_dismiss_the_notice_for_the_whole_network() {
		$this->network_activate_the_plugin();
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_settings_administrator();

		$this->request_a_notice_dismissal( wp_create_nonce( \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ) );

		$record = get_site_option( \WP_Piwik::DEPRECATED_SHORTCODES_OPTION );
		$this->assertGreaterThan( time(), $record['dismissed_until'] );
		$this->assertSame( '', $this->render_deprecated_shortcode_notice() );
	}

	public function test_on_deprecated_shortcode_notice_dismissed_should_not_let_a_network_user_who_cannot_manage_sites_dismiss_it() {
		$this->network_activate_the_plugin();
		$GLOBALS['wp-piwik']->record_deprecated_shortcode_use( 'overview' );
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();

		$this->request_a_notice_dismissal( wp_create_nonce( \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ) );

		$this->log_in_as_settings_administrator();
		$this->assertStringContainsString(
			'The statistics shortcodes are deprecated',
			$this->render_deprecated_shortcode_notice()
		);
	}

	public function test_show_php_mode_deprecation_notice_if_in_use_should_warn_a_super_admin_on_a_network() {
		$this->network_activate_the_plugin();
		\WP_Piwik::get_settings()->set_global_option( 'piwik_mode', 'php' );
		$this->log_in_as_settings_administrator();

		$this->assertStringContainsString(
			'The &quot;Self-hosted (PHP API)&quot; connection method is deprecated',
			$this->render_php_mode_deprecation_notice()
		);
	}

	public function test_show_php_mode_deprecation_notice_if_in_use_should_stay_silent_for_a_network_user_who_cannot_manage_sites() {
		$this->network_activate_the_plugin();
		\WP_Piwik::get_settings()->set_global_option( 'piwik_mode', 'php' );
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();

		$this->assertSame( '', $this->render_php_mode_deprecation_notice() );
	}

	public function test_show_php_mode_deprecation_notice_if_in_use_should_warn_a_site_administrator_when_the_plugin_is_not_network_activated() {
		$this->skip_unless_multisite();
		\WP_Piwik::get_settings()->set_global_option( 'piwik_mode', 'php' );
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();

		$this->assertStringContainsString(
			'connection method is deprecated',
			$this->render_php_mode_deprecation_notice()
		);
	}

	private function render_deprecated_shortcode_notice() {
		ob_start();
		$GLOBALS['wp-piwik']->show_deprecated_shortcode_notice_if_in_use();
		return ob_get_clean();
	}

	private function skip_unless_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network mode requires a multisite installation.' );
		}
	}

	private function network_activate_the_plugin() {
		$this->skip_unless_multisite();

		update_site_option( 'active_sitewide_plugins', array( 'wp-piwik/wp-piwik.php' => time() ) );

		$this->assertTrue( $GLOBALS['wp-piwik']->is_network_mode(), 'precondition: the plugin runs in network mode' );
	}

	private function log_in_as_a_site_administrator_who_can_activate_plugins() {
		update_site_option( 'menu_items', array( 'plugins' => 1 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( current_user_can( 'activate_plugins' ), 'precondition: the user can activate plugins' );
		$this->assertFalse( current_user_can( 'manage_sites' ), 'precondition: the user cannot manage the network' );
	}

	private function set_up_a_dismissal_that_ran_out() {
		update_option(
			\WP_Piwik::DEPRECATED_SHORTCODES_OPTION,
			array(
				'modules'         => array( 'overview' => time() - 3 * WEEK_IN_SECONDS ),
				'dismissed_until' => time() - WEEK_IN_SECONDS,
			)
		);
	}

	/**
	 * @param string $nonce nonce the request carries
	 */
	private function request_a_notice_dismissal( $nonce ) {
		$_GET[ \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ]     = '1';
		$_REQUEST[ \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ] = '1';
		$_REQUEST['_wpnonce']                                = $nonce;

		// blocking the redirect keeps the handler from exiting the test run
		add_filter( 'wp_redirect', '__return_false' );
		try {
			$GLOBALS['wp-piwik']->on_deprecated_shortcode_notice_dismissed();
		} finally {
			unset(
				$_GET[ \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ],
				$_REQUEST[ \WP_Piwik::DISMISS_SHORTCODE_NOTICE_ARG ],
				$_REQUEST['_wpnonce']
			);
		}
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
