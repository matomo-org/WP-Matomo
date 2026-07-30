<?php

namespace WP_Piwik\Tests;

/**
 * Covers the capability gate on the settings form post.
 */
class OptionsPostTest extends WP_Piwik_TestCase {

	public function set_up() {
		parent::set_up();
		set_current_screen( 'options-general' );
	}

	public function tear_down() {
		unset( $_REQUEST['_wpnonce'] );
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	public function test_options_post_is_rejected_for_users_without_manage_options() {
		$this->sign_in_as( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertFalse( \WP_Piwik::is_valid_options_post() );
	}

	public function test_options_post_is_accepted_for_administrators() {
		$this->sign_in_as( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertTrue( \WP_Piwik::is_valid_options_post() );
	}

	public function test_options_post_requires_a_super_admin_when_network_activated() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		// network activated settings are stored as network wide site options and the settings screen
		// is registered with 'manage_sites', so a single site administrator must not be able to
		// write them even though they do have 'manage_options'
		update_site_option( 'active_sitewide_plugins', [ 'wp-piwik/wp-piwik.php' => time() ] );

		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->sign_in_as( $user );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertFalse( \WP_Piwik::is_valid_options_post() );

		grant_super_admin( $user );
		$this->assertTrue( \WP_Piwik::is_valid_options_post() );
		revoke_super_admin( $user );
	}

	private function sign_in_as( $user_id ) {
		wp_set_current_user( $user_id );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp-piwik_settings' );
	}
}
