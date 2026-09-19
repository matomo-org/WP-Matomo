<?php

namespace WP_Piwik\Tests;

class WP_PiwikTest extends WP_Piwik_TestCase {

	/**
	 * @var array global settings the tests below change on the shared settings instance.
	 *            Changing one of them only takes effect in memory, so it has to be put
	 *            back by hand.
	 */
	private $restored_global_options = array( 'piwik_url', 'piwik_mode', 'track_mode', 'last_settings_update', 'revision', 'version_history' );

	/**
	 * @var array per site settings the tests below change on the shared settings instance
	 */
	private $restored_options = array( 'tracking_code', 'last_tracking_code_update' );

	private $settings_backup = array();

	private $options_backup = array();

	/**
	 * @var \WP_Piwik[] plugin instances the tests below booted. The destructor of an
	 *                  instance closes the logger every instance shares, so they are kept
	 *                  alive until the whole run ends.
	 */
	private static $booted_plugins = array();

	public function set_up() {
		parent::set_up();

		$settings = \WP_Piwik::get_settings();
		foreach ( $this->restored_global_options as $key ) {
			$this->settings_backup[ $key ] = $settings->get_global_option( $key );
		}
		foreach ( $this->restored_options as $key ) {
			$this->options_backup[ $key ] = $settings->get_option( $key );
		}

		$settings->set_global_option( 'piwik_url', 'https://matomo.example.org/' );
	}

	public function tear_down() {
		$settings = \WP_Piwik::get_settings();
		foreach ( $this->settings_backup as $key => $value ) {
			$settings->set_global_option( $key, $value );
		}
		foreach ( $this->options_backup as $key => $value ) {
			$settings->set_option( $key, $value );
		}

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

	public function test_add_javascript_code_should_print_manual_tracking_code() {
		$this->store_manual_tracking_code( SettingsTest::CROSS_SITE_PAYLOAD );

		// only a user allowed to publish script can put a tracking code here in the first
		// place, so what is stored is printed as it is stored
		$this->assertSame( SettingsTest::CROSS_SITE_PAYLOAD, $this->render_javascript_code() );
	}

	public function test_show_manual_tracking_review_notice_should_name_a_site_entering_its_tracking_code_manually() {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$notice = $this->render_manual_tracking_review_notice();

		$this->assertStringContainsString( 'please review your manually entered tracking code', $notice );
		$this->assertStringContainsString( get_blog_option( $blog_id, 'blogname' ), $notice );
	}

	public function test_show_manual_tracking_review_notice_should_name_a_site_that_stopped_tracking_while_holding_its_tracking_code() {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site( 'disabled', SettingsTest::CROSS_SITE_PAYLOAD );
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// every version up to 1.1.12 let a site administrator park a code in the disabled
		// mode in one save, out of reach of a review that only looked at the tracking mode
		$this->assertStringContainsString( get_blog_option( $blog_id, 'blogname' ), $this->render_manual_tracking_review_notice() );
	}

	/**
	 * @dataProvider get_tracking_codes_that_are_no_tracking_code
	 */
	public function test_show_manual_tracking_review_notice_should_not_name_a_site_that_holds_no_tracking_code( $tracking_code ) {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site( 'disabled', $tracking_code );
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->assertStringNotContainsString( get_blog_option( $blog_id, 'blogname' ), $this->render_manual_tracking_review_notice() );
	}

	public function get_tracking_codes_that_are_no_tracking_code() {
		return array(
			'nothing stored'                       => array( '' ),
			// the settings form lays its textareas out indented, so an empty code comes back
			// from it as the indentation, which the disabled mode then stores
			'the indentation of the settings form' => array( "\t\t\t\t\t\n\t\t\t\t" ),
		);
	}

	public function test_show_manual_tracking_review_notice_should_not_name_a_site_whose_tracking_code_connect_matomo_generated() {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site( 'default', SettingsTest::CROSS_SITE_PAYLOAD );
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// a generated mode overwrites what it stores on the next request, so nothing a user
		// entered survives there to be reviewed
		$this->assertStringNotContainsString( get_blog_option( $blog_id, 'blogname' ), $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_not_show_when_no_site_enters_its_tracking_code_manually() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->assertSame( '', $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_record_the_review_as_done_when_no_site_enters_its_tracking_code_manually() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->render_manual_tracking_review_notice();

		// a site a network administrator switches to manual mode later is their own doing,
		// so it must not be reported back to them as code an earlier version let through
		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_DONE, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_show_manual_tracking_review_notice_should_show_on_the_dashboard_of_a_site_the_plugin_is_active_on() {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// the dashboard is where a notice is most likely to be read, and under the per site
		// activation this review is about the network's own dashboard never loads the plugin
		set_current_screen( 'dashboard' );

		$this->assertStringContainsString( get_blog_option( $blog_id, 'blogname' ), $this->render_manual_tracking_review_notice() );
	}

	public function test_construct_should_ask_for_the_manual_tracking_review_on_every_kind_of_admin_screen() {
		set_current_screen( 'dashboard' );

		$plugin = $this->reload_the_plugin();

		$this->assertNotFalse(
			has_action( 'all_admin_notices', array( $plugin, 'show_manual_tracking_review_notice' ) )
		);
	}

	public function test_show_manual_tracking_review_notice_should_offer_to_record_the_review_as_made() {
		$this->skip_unless_multisite();
		$this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->assertStringContainsString( \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG, $this->render_manual_tracking_review_notice() );
	}

	public function test_on_manual_tracking_review_notice_dismissed_should_record_the_review_as_made() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->request_a_manual_tracking_review_dismissal( wp_create_nonce( \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG ) );

		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_DONE, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_on_manual_tracking_review_notice_dismissed_should_not_record_the_review_for_a_site_administrator() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// the review is the network's to make, and the administrator of a site is who may
		// have entered the code it is about
		$this->request_a_manual_tracking_review_dismissal( wp_create_nonce( \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG ) );

		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_on_manual_tracking_review_notice_dismissed_should_not_record_the_review_without_a_valid_nonce() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		try {
			$this->request_a_manual_tracking_review_dismissal( 'not-the-nonce' );
			$this->fail( 'the dismissal was accepted without a valid nonce' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
		}
	}

	public function test_on_manual_tracking_review_notice_dismissed_should_leave_a_request_that_does_not_ask_for_it() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// every admin request runs this, so one without the query argument must pass
		// through it without checking a nonce it was never given
		$GLOBALS['wp-piwik']->on_manual_tracking_review_notice_dismissed();

		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_show_manual_tracking_review_notice_should_not_show_to_a_site_administrator() {
		$this->skip_unless_multisite();
		$this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_site_administrator_who_can_activate_plugins();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// the administrator of a site is who may have entered the code, and only a network
		// administrator can review another site's settings
		$this->assertSame( '', $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_not_show_once_dismissed() {
		$this->skip_unless_multisite();
		$this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();

		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_DONE );

		$this->assertSame( '', $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_not_show_while_the_plugin_is_network_activated() {
		$this->network_activate_the_plugin();
		$this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		// a network too large to walk is asked to review without a list of sites, so the
		// activation is all this notice has left to go on
		add_filter( 'wp_is_large_network', '__return_true' );

		// one tracking code for the whole network, which lives in the network's own options
		// and only a user who can manage sites could have saved
		$this->assertSame( '', $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_not_walk_a_network_larger_than_it_can_list() {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->pretend_the_network_is_too_large_to_walk();

		$notice = $this->render_manual_tracking_review_notice();

		// the review is still asked for, it just does not iterate through every blog
		$this->assertStringContainsString( 'please review your manually entered tracking code', $notice );
		$this->assertStringNotContainsString( get_blog_option( $blog_id, 'blogname' ), $notice );
	}

	public function test_show_manual_tracking_review_notice_should_name_the_sites_of_a_network_whose_recorded_site_count_is_not_its_own() {
		$this->skip_unless_multisite();
		$blog_id = $this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING );

		$this->pretend_the_network_recorded_a_site_count( \WP_Piwik::MANUAL_TRACKING_REVIEW_SITE_LIMIT + 1 );

		$this->assertStringContainsString( get_blog_option( $blog_id, 'blogname' ), $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_not_show_to_a_network_that_never_ran_an_affected_version() {
		$this->skip_unless_multisite();
		$this->create_a_site_using_manual_tracking();
		$this->log_in_as_a_network_administrator();

		$this->assertSame( '', $this->render_manual_tracking_review_notice() );
	}

	public function test_show_manual_tracking_review_notice_should_not_show_outside_a_network() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Only a network has sites another administrator configured.' );
		}

		$this->store_manual_tracking_code( SettingsTest::CROSS_SITE_PAYLOAD );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( '', $this->render_manual_tracking_review_notice() );
	}

	public function test_construct_should_append_the_running_version_to_the_version_history() {
		$this->simulate_an_install_running_version( '1.1.11' );

		$this->reload_the_plugin();

		$this->assertSame( array( '1.1.11', $GLOBALS['wp-piwik']->get_plugin_version() ), get_option( 'wp-piwik_global-version_history' ) );
	}

	public function test_construct_should_record_the_running_version_of_a_fresh_install() {
		$this->assertFalse( get_option( 'wp-piwik_global-version_history' ), 'precondition: nothing is installed' );

		$this->reload_the_plugin();

		$this->assertSame( array( $GLOBALS['wp-piwik']->get_plugin_version() ), get_option( 'wp-piwik_global-version_history' ) );
	}

	public function test_construct_should_ask_for_a_manual_tracking_review_when_the_previous_version_accepted_the_code_from_a_site_administrator() {
		$this->skip_unless_multisite();
		$this->simulate_an_install_running_version( \WP_Piwik::LAST_UNRESTRICTED_MANUAL_TRACKING_VERSION );

		$this->reload_the_plugin();

		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_construct_should_ask_for_a_manual_tracking_review_when_no_previous_version_was_recorded() {
		$this->skip_unless_multisite();

		// up to 1.1.12 no version was recorded at all, so an install with a revision but no
		// history is one of those releases
		update_option( 'wp-piwik_global-revision', 2023092201 );

		$this->reload_the_plugin();

		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_PENDING, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_construct_should_not_ask_for_a_manual_tracking_review_of_a_fresh_install() {
		$this->skip_unless_multisite();

		$this->reload_the_plugin();

		// nothing was installed before this version, so no site can hold code entered by a
		// user who was not allowed to
		$this->assertFalse( get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_construct_should_not_ask_for_a_manual_tracking_review_when_the_previous_version_required_unfiltered_html() {
		$this->skip_unless_multisite();
		$this->simulate_an_install_running_version( $GLOBALS['wp-piwik']->get_plugin_version() );

		$this->reload_the_plugin();

		$this->assertFalse( get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_construct_should_not_ask_for_a_manual_tracking_review_when_a_later_version_ran_before() {
		$this->skip_unless_multisite();
		$this->simulate_an_install_running_version( '99.0.0' );

		$this->reload_the_plugin();

		// downgrading from a version that already required the capability leaves nothing to
		// review either
		$this->assertFalse( get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_construct_should_not_ask_for_a_manual_tracking_review_while_the_plugin_is_network_activated() {
		$this->network_activate_the_plugin();

		// the revision of the release under test with no version history, which is the shape
		// of an install whose last version was 1.1.12 or older
		update_site_option( 'wp-piwik_global-revision', 2023092201 );

		$this->reload_the_plugin();

		$this->assertFalse( get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	public function test_construct_should_not_ask_for_a_manual_tracking_review_that_has_already_been_made() {
		$this->skip_unless_multisite();
		$this->simulate_an_install_running_version( \WP_Piwik::LAST_UNRESTRICTED_MANUAL_TRACKING_VERSION );
		update_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION, \WP_Piwik::MANUAL_TRACKING_REVIEW_DONE );

		$this->reload_the_plugin();

		$this->assertSame( \WP_Piwik::MANUAL_TRACKING_REVIEW_DONE, get_site_option( \WP_Piwik::MANUAL_TRACKING_REVIEW_OPTION ) );
	}

	private function render_javascript_code() {
		ob_start();
		$GLOBALS['wp-piwik']->add_javascript_code();
		return ob_get_clean();
	}

	private function store_manual_tracking_code( $code ) {
		$settings = \WP_Piwik::get_settings();
		$settings->set_global_option( 'track_mode', 'manually' );
		$settings->set_global_option( 'last_settings_update', 1 );
		$settings->set_option( 'tracking_code', $code );
		// keeps the tracking code from being treated as outdated and refetched from Matomo
		$settings->set_option( 'last_tracking_code_update', time() + HOUR_IN_SECONDS );

		$this->assertFalse( $GLOBALS['wp-piwik']->is_network_mode(), 'precondition: the plugin is not network activated' );
	}

	private function render_deprecated_shortcode_notice() {
		ob_start();
		$GLOBALS['wp-piwik']->show_deprecated_shortcode_notice_if_in_use();
		return ob_get_clean();
	}

	private function render_manual_tracking_review_notice() {
		ob_start();
		$GLOBALS['wp-piwik']->show_manual_tracking_review_notice();
		return ob_get_clean();
	}

	/**
	 * @param string $nonce nonce the request carries
	 */
	private function request_a_manual_tracking_review_dismissal( $nonce ) {
		$_GET[ \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG ]     = '1';
		$_REQUEST[ \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG ] = '1';
		$_REQUEST['_wpnonce']                                      = $nonce;

		// blocking the redirect keeps the handler from exiting the test run
		add_filter( 'wp_redirect', '__return_false' );
		try {
			$GLOBALS['wp-piwik']->on_manual_tracking_review_notice_dismissed();
		} finally {
			unset(
				$_GET[ \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG ],
				$_REQUEST[ \WP_Piwik::DISMISS_MANUAL_TRACKING_NOTICE_ARG ],
				$_REQUEST['_wpnonce']
			);
		}
	}

	private function create_a_site_using_manual_tracking() {
		return $this->create_a_site( 'manually', SettingsTest::CROSS_SITE_PAYLOAD );
	}

	/**
	 * @param string $track_mode    tracking mode to configure the site with
	 * @param string $tracking_code tracking code to store for the site
	 * @return int the new site's blog ID
	 */
	private function create_a_site( $track_mode, $tracking_code ) {
		$blog_id = self::factory()->blog->create();
		update_blog_option( $blog_id, 'wp-piwik_global-track_mode', $track_mode );
		update_blog_option( $blog_id, 'wp-piwik-tracking_code', $tracking_code );

		return $blog_id;
	}

	private function simulate_an_install_running_version( $version ) {
		$this->assertFalse( $GLOBALS['wp-piwik']->is_network_mode(), 'precondition: the plugin is not network activated' );

		// the revision of the release under test, so the plugin counts as installed without
		// the update patches of an older revision running
		update_option( 'wp-piwik_global-revision', 2023092201 );
		update_option( 'wp-piwik_global-version_history', array( $version ) );
	}

	/**
	 * Boot the plugin again, the way the next request to WordPress does.
	 *
	 * @return \WP_Piwik the instance that just booted
	 */
	private function reload_the_plugin() {
		$plugin                 = new \WP_Piwik();
		self::$booted_plugins[] = $plugin;

		return $plugin;
	}

	private function pretend_the_network_is_too_large_to_walk() {
		add_filter( 'wp_is_large_network', '__return_true' );
	}

	/**
	 * @param int $count sites the network says it has, whatever it holds
	 */
	private function pretend_the_network_recorded_a_site_count( $count ) {
		add_filter(
			'site_option_blog_count',
			function () use ( $count ) {
				return $count;
			}
		);
	}

	private function log_in_as_a_network_administrator() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$this->assertTrue( current_user_can( 'manage_network' ), 'precondition: the user administrates the network' );
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
