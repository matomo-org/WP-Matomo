<?php

namespace WP_Piwik\Tests;

use WP_Piwik\TrackerHosts;

class TrackerHostsTest extends WP_Piwik_TestCase {

	/**
	 * @var TrackerHosts
	 */
	private $tracker_hosts;

	public function set_up() {
		parent::set_up();

		$this->tracker_hosts = new TrackerHosts();
	}

	public function test_parse_should_drop_an_entry_that_names_no_host() {
		$this->assertSame(
			[
				'entries'  => [ 'matomo.example.org', '*.matomo.cloud' ],
				'rejected' => [ '-example.org', '////', 'https://' ],
			],
			$this->tracker_hosts->parse(
				"matomo.example.org\n-example.org\n*.matomo.cloud\n////\nhttps://"
			)
		);
	}

	public function test_parse_should_read_an_entry_written_as_a_url() {
		$actual = $this->tracker_hosts->parse(
			"https://stats.example.org/matomo/\ncdn.example.org/matomo\n*.example.org/"
		)['entries'];

		$this->assertSame(
			[ 'stats.example.org', 'cdn.example.org', '*.example.org' ],
			$actual
		);
	}

	public function test_parse_should_read_an_entry_written_as_a_url_the_way_a_browser_does() {
		$this->assertSame(
			[ 'evil.example.org' ],
			$this->tracker_hosts->parse( 'https://matomo.example.org@evil.example.org/' )['entries']
		);
	}

	public function test_parse_should_read_a_comma_separated_list() {
		$this->assertSame(
			[ 'matomo.example.org', 'stats.example.org' ],
			$this->tracker_hosts->parse( 'matomo.example.org, stats.example.org' )['entries']
		);
	}

	public function test_parse_should_lower_case_and_deduplicate_entries() {
		// a host written twice is a host the list holds, not an entry it dropped
		$this->assertSame(
			[
				'entries'  => [ 'matomo.example.org' ],
				'rejected' => [],
			],
			$this->tracker_hosts->parse( "Matomo.Example.ORG\nmatomo.example.org" )
		);
	}

	public function test_parse_should_reject_an_entry_that_allows_every_host() {
		$this->assertSame(
			[
				'entries'  => [],
				'rejected' => [ '*' ],
			],
			$this->tracker_hosts->parse( '*' )
		);
	}

	public function test_parse_should_cap_the_number_of_entries() {
		$entries = [];
		for ( $i = 0; $i < TrackerHosts::MAX_ENTRIES + 10; $i++ ) {
			$entries[] = 'matomo' . $i . '.example.org';
		}

		$parsed = $this->tracker_hosts->parse( $entries );

		$this->assertCount( TrackerHosts::MAX_ENTRIES, $parsed['entries'] );
		$this->assertSame( array_slice( $entries, TrackerHosts::MAX_ENTRIES ), $parsed['rejected'] );
	}

	public function test_parse_should_keep_every_entry_when_no_cap_is_asked_for() {
		$entries = [];
		for ( $i = 0; $i < TrackerHosts::MAX_ENTRIES + 10; $i++ ) {
			$entries[] = 'matomo' . $i . '.example.org';
		}

		$this->assertSame(
			[
				'entries'  => $entries,
				'rejected' => [],
			],
			$this->tracker_hosts->parse( $entries, null )
		);
	}

	public function test_parse_should_read_an_entry_naming_a_host_in_another_script() {
		$this->skip_without_idn_support();

		// a browser resolves such a host through its ASCII form, so that is the form the
		// entry has to be kept in for anything to match it
		$this->assertSame(
			[ 'xn--r8jz45g.jp', '*.xn--r8jz45g.jp' ],
			$this->tracker_hosts->parse( "例え.jp\n*.例え.jp" )['entries']
		);
	}

	public function test_parse_should_reject_an_entry_longer_than_a_host_name_can_be() {
		$entry = str_repeat( 'a', TrackerHosts::MAX_ENTRY_LENGTH + 1 );

		$this->assertSame(
			[
				'entries'  => [],
				'rejected' => [ $entry ],
			],
			$this->tracker_hosts->parse( $entry )
		);
	}

	public function test_host_from_url_should_read_the_host_of_a_matomo_url() {
		$this->assertSame( 'matomo.example.org', $this->tracker_hosts->host_from_url( 'https://matomo.example.org/matomo/' ) );
	}

	public function test_host_from_url_should_read_the_host_of_a_value_that_carries_no_protocol() {
		// a CDN URL is always stored without one, and a Matomo URL may be
		$this->assertSame( 'cdn.example.org', $this->tracker_hosts->host_from_url( 'cdn.example.org/matomo' ) );
		$this->assertSame( 'cdn.example.org', $this->tracker_hosts->host_from_url( '//cdn.example.org/matomo' ) );
	}

	public function test_host_from_url_should_read_the_host_a_browser_would_connect_to() {
		$this->assertSame( 'evil.example.org', $this->tracker_hosts->host_from_url( 'https://matomo.example.org@evil.example.org/' ) );

		// the generator drops the backslash before the tracking code carries the URL, so
		// what is left names the same host this does
		$this->assertSame( 'evil.example.org', $this->tracker_hosts->host_from_url( 'https://matomo.example.org\\@evil.example.org/' ) );
	}

	public function test_host_from_url_should_read_a_host_named_in_another_script() {
		$this->skip_without_idn_support();

		$this->assertSame( 'stats.xn--r8jz45g.jp', $this->tracker_hosts->host_from_url( 'https://stats.例え.jp/matomo/' ) );
	}

	public function test_host_from_url_should_read_a_host_with_a_sharp_s_the_way_a_browser_does() {
		$this->skip_without_idn_support();

		$this->assertSame( 'matomo.xn--fa-hia.de', $this->tracker_hosts->host_from_url( 'https://matomo.faß.de/' ) );
	}

	/**
	 * @dataProvider get_values_that_do_not_name_a_host
	 */
	public function test_host_from_url_should_reject_a_value_that_does_not_name_a_host( $value ) {
		$this->assertSame( '', $this->tracker_hosts->host_from_url( $value ) );
	}

	public function get_values_that_do_not_name_a_host() {
		return [
			'empty'            => [ '' ],
			'whitespace'       => [ "  \n " ],
			'a path only'      => [ '/matomo/' ],
			'an angle bracket' => [ '//matomo.example.org<script' ],
			'a backslash'      => [ '//matomo.example.org\\.evil.example.org/' ],
			// an allow list entry cannot name one either, so a network has no way to allow
			// an address written this way and a site has no way to be pointed at one
			'an IPv6 literal'  => [ 'https://[2001:db8::1]/matomo/' ],
			'not a string'     => [ null ],
		];
	}

	public function test_allows_should_accept_a_host_the_list_names() {
		$this->store_allow_list( [ 'matomo.example.org' ] );

		$this->assertTrue( $this->tracker_hosts->allows( 'matomo.example.org' ) );
		$this->assertTrue( $this->tracker_hosts->allows( 'MATOMO.example.org' ) );
	}

	public function test_allows_should_accept_a_subdomain_of_a_wildcard_entry() {
		$this->store_allow_list( [ '*.example.org' ] );

		$this->assertTrue( $this->tracker_hosts->allows( 'matomo.example.org' ) );
		$this->assertTrue( $this->tracker_hosts->allows( 'eu.matomo.example.org' ) );
	}

	public function test_allows_should_reject_the_bare_domain_of_a_wildcard_entry() {
		$this->store_allow_list( [ '*.example.org' ] );

		$this->assertFalse( $this->tracker_hosts->allows( 'example.org' ) );
	}

	public function test_allows_should_reject_a_host_that_merely_ends_in_an_entry() {
		$this->store_allow_list( [ 'example.org', '*.matomo.cloud' ] );

		$this->assertFalse( $this->tracker_hosts->allows( 'evilexample.org' ) );
		$this->assertFalse( $this->tracker_hosts->allows( 'evilmatomo.cloud' ) );
	}

	public function test_allows_should_reject_a_host_no_entry_names() {
		$this->store_allow_list( [ 'matomo.example.org' ] );

		$this->assertFalse( $this->tracker_hosts->allows( 'evil.example.org' ) );
		$this->assertFalse( $this->tracker_hosts->allows( '' ) );
	}

	public function test_get_allow_list_should_offer_the_matomo_clouds_by_default() {
		$this->assertSame( [ '*.matomo.cloud', '*.innocraft.cloud' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_not_offer_the_matomo_the_main_site_is_connected_to() {
		$this->skip_unless_multisite();

		// an administrator of the main site stored that URL, and an administrator of the
		// main site is not always a network administrator. so a network whose main site
		// was pointed at somebody else's server before this release will not hand that
		// server to every other site of the network
		update_blog_option( get_main_site_id(), 'wp-piwik_global-piwik_url', 'https://main.example.org/matomo/' );
		update_site_option( 'wp-piwik_global-piwik_url', 'https://matomo.example.org/matomo/' );

		$this->assertSame( [ '*.matomo.cloud', '*.innocraft.cloud' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_use_the_list_a_network_administrator_stored() {
		$this->skip_unless_multisite();
		update_site_option( 'wp-piwik_global-piwik_url', 'https://matomo.example.org/' );
		$this->store_allow_list( [ 'stats.example.org' ] );

		$this->assertSame( [ 'stats.example.org' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_fall_back_to_the_default_when_an_empty_list_is_stored() {
		$this->skip_unless_multisite();
		$this->store_allow_list( [] );

		$this->assertSame( [ '*.matomo.cloud', '*.innocraft.cloud' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_ignore_a_stored_value_that_is_not_a_list() {
		$this->skip_unless_multisite();

		update_site_option( TrackerHosts::OPTION, 'stats.example.org' );

		$this->assertSame( [ '*.matomo.cloud', '*.innocraft.cloud' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_stored_allow_list_should_keep_entries_that_are_host_names_only() {
		$this->skip_unless_multisite();

		update_site_option( TrackerHosts::OPTION, [ 'stats.example.org', 12, [ 'nested' ] ] );

		$this->assertSame( [ 'stats.example.org' ], $this->tracker_hosts->get_stored_allow_list() );
	}

	public function test_allows_should_answer_a_stored_list_holding_something_that_is_not_a_host_name() {
		$this->skip_unless_multisite();
		update_site_option( TrackerHosts::OPTION, [ [ 'nested' ], 'stats.example.org' ] );

		$this->assertTrue( $this->tracker_hosts->allows( 'stats.example.org' ) );
		$this->assertFalse( $this->tracker_hosts->allows( 'evil.example.org' ) );
	}

	public function test_get_allow_list_should_apply_the_filter_a_network_can_shape_it_with() {
		add_filter(
			'wp-piwik_allowed_tracker_hosts',
			function () {
				return [ 'filtered.example.org' ];
			}
		);

		$this->assertSame( [ 'filtered.example.org' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_keep_host_entries_only_from_what_the_filter_returns() {
		add_filter(
			'wp-piwik_allowed_tracker_hosts',
			function () {
				return [ 'Filtered.Example.ORG', '-nope.example.org' ];
			}
		);

		$this->assertSame( [ 'filtered.example.org' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_keep_every_host_the_filter_names() {
		$entries = [];
		for ( $i = 0; $i < TrackerHosts::MAX_ENTRIES + 10; $i++ ) {
			$entries[] = 'matomo' . $i . '.example.org';
		}

		add_filter(
			'wp-piwik_allowed_tracker_hosts',
			function () use ( $entries ) {
				return $entries;
			}
		);

		$this->assertSame( $entries, $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_keep_the_list_it_had_when_the_filter_names_no_host() {
		$this->skip_unless_multisite();
		$this->store_allow_list( [ 'stats.example.org' ] );
		$this->setExpectedIncorrectUsage( 'WP_Piwik\TrackerHosts::get_allow_list' );

		add_filter(
			'wp-piwik_allowed_tracker_hosts',
			function () {
				return [ '*' ];
			}
		);

		$this->assertSame( [ 'stats.example.org' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_get_allow_list_should_keep_the_default_when_the_filter_names_no_host() {
		$this->setExpectedIncorrectUsage( 'WP_Piwik\TrackerHosts::get_allow_list' );

		add_filter(
			'wp-piwik_allowed_tracker_hosts',
			function () {
				return '-nope.example.org';
			}
		);

		$this->assertSame( [ '*.matomo.cloud', '*.innocraft.cloud' ], $this->tracker_hosts->get_allow_list() );
	}

	public function test_update_allow_list_should_store_a_list_of_entries_for_a_network_administrator() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$this->assertTrue( $this->tracker_hosts->update_allow_list( 'Stats.Example.ORG, -nope.example.org' ) );
		$this->assertSame( [ 'stats.example.org' ], $this->tracker_hosts->get_stored_allow_list() );
		$this->assertSame( [ 'stats.example.org' ], get_site_option( TrackerHosts::OPTION ) );
	}

	public function test_update_allow_list_should_report_an_entry_it_could_not_hold() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$this->tracker_hosts->update_allow_list( "stats.example.org\n-nope.example.org\n*" );

		$this->assertSame( [ '-nope.example.org', '*' ], $this->tracker_hosts->get_rejected_entries() );
	}

	public function test_update_allow_list_should_report_an_entry_the_cap_left_no_room_for() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		$entries = [];
		for ( $i = 0; $i <= TrackerHosts::MAX_ENTRIES; $i++ ) {
			$entries[] = 'matomo' . $i . '.example.org';
		}

		$this->tracker_hosts->update_allow_list( $entries );

		$this->assertSame( [ 'matomo' . TrackerHosts::MAX_ENTRIES . '.example.org' ], $this->tracker_hosts->get_rejected_entries() );
	}

	public function test_update_allow_list_should_report_no_rejected_entries_when_it_held_every_entry() {
		$this->skip_unless_multisite();
		$this->log_in_as_a_network_administrator();

		// includes duplicates
		$this->tracker_hosts->update_allow_list( "stats.example.org\nhttps://stats.example.org/\ncdn.example.org" );

		$this->assertSame( [ 'stats.example.org', 'cdn.example.org' ], $this->tracker_hosts->get_stored_allow_list() );
		$this->assertSame( [], $this->tracker_hosts->get_rejected_entries() );
	}

	public function test_update_allow_list_should_report_no_rejected_entries_to_a_user_who_cannot_manage_the_network() {
		$this->log_in_as_a_network_site_administrator();

		$this->assertFalse( $this->tracker_hosts->update_allow_list( '-nope.example.org' ) );
		$this->assertSame( [], $this->tracker_hosts->get_rejected_entries() );
	}

	public function test_update_allow_list_should_store_a_host_named_in_another_script_in_ascii() {
		$this->skip_unless_multisite();
		$this->skip_without_idn_support();
		$this->log_in_as_a_network_administrator();

		$this->tracker_hosts->update_allow_list( '*.例え.jp' );

		$this->assertSame( [ '*.xn--r8jz45g.jp' ], $this->tracker_hosts->get_stored_allow_list() );

		// the entry names the same host whichever way a site of this network writes it
		$this->assertTrue( $this->tracker_hosts->allows( 'stats.例え.jp' ) );
		$this->assertTrue( $this->tracker_hosts->allows( 'stats.xn--r8jz45g.jp' ) );
	}

	public function test_update_allow_list_should_ignore_a_user_who_cannot_manage_the_network() {
		$this->log_in_as_a_network_site_administrator();

		$this->assertFalse( $this->tracker_hosts->update_allow_list( 'evil.example.org' ) );
		$this->assertSame( [], $this->tracker_hosts->get_stored_allow_list() );
	}

	public function test_is_enforced_should_be_false_for_a_network_administrator() {
		$this->log_in_as_a_network_administrator();

		$this->assertFalse( $this->tracker_hosts->is_enforced() );
	}

	public function test_is_enforced_should_be_true_for_an_administrator_of_a_single_site_of_a_network() {
		$this->log_in_as_a_network_site_administrator();

		$this->assertTrue( $this->tracker_hosts->is_enforced() );
	}

	public function test_can_edit_allow_list_should_be_true_only_for_a_network_administrator() {
		$this->skip_unless_multisite();

		$this->log_in_as_a_network_administrator();
		$this->assertTrue( $this->tracker_hosts->can_edit_allow_list() );

		$this->log_in_as_a_network_site_administrator();
		$this->assertFalse( $this->tracker_hosts->can_edit_allow_list() );
	}

	public function test_is_enforced_should_be_false_when_the_plugin_is_activated_for_the_whole_network() {
		$this->network_activate_the_plugin();
		$this->log_in_as_a_network_site_administrator();

		$this->assertFalse( $this->tracker_hosts->is_enforced() );
	}

	public function test_can_edit_allow_list_should_be_false_when_the_plugin_is_activated_for_the_whole_network() {
		$this->network_activate_the_plugin();
		$this->log_in_as_a_network_administrator();

		$this->assertFalse( $this->tracker_hosts->can_edit_allow_list() );
	}

	public function test_is_allowed_for_current_user_should_allow_any_host_for_a_network_administrator() {
		$this->log_in_as_a_network_administrator();
		$this->store_allow_list( [ 'matomo.example.org' ] );

		$this->assertTrue( $this->tracker_hosts->is_allowed_for_current_user( 'https://evil.example.org/' ) );
	}

	public function test_is_allowed_for_current_user_should_reject_a_host_the_network_does_not_allow() {
		$this->log_in_as_a_network_site_administrator();
		$this->store_allow_list( [ 'matomo.example.org' ] );

		$this->assertFalse( $this->tracker_hosts->is_allowed_for_current_user( 'https://evil.example.org/' ) );
		$this->assertTrue( $this->tracker_hosts->is_allowed_for_current_user( 'https://matomo.example.org/matomo/' ) );
	}

	private function skip_without_idn_support() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'Reading a host named in another script needs the intl extension.' );
		}
	}

	private function network_activate_the_plugin() {
		$this->skip_unless_multisite();

		update_site_option( 'active_sitewide_plugins', [ 'wp-piwik/wp-piwik.php' => time() ] );
	}

	private function store_allow_list( $entries ) {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only a network has an allow list.' );
		}
		update_site_option( TrackerHosts::OPTION, $entries );
	}

	private function log_in_as_a_network_administrator() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
	}

	private function log_in_as_a_network_site_administrator() {
		$this->skip_unless_multisite();

		switch_to_blog( self::factory()->blog->create() );

		$user_id = self::factory()->user->create();
		add_user_to_blog( get_current_blog_id(), $user_id, 'administrator' );
		wp_set_current_user( $user_id );

		$this->assertFalse( current_user_can( 'manage_network' ), 'precondition: the user may not manage the network' );
	}
}
