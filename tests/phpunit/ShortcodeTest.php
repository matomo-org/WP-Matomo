<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request;
use WP_Piwik\Shortcode;

/**
 * Records the requests a shortcode queues without sending any of them.
 */
class Shortcode_Test_Request extends Request {

	protected function request( $id ) {
	}

	public static function get_registered() {
		return self::$requests;
	}
}

class ShortcodeTest extends WP_Piwik_TestCase {

	/**
	 * the name that the bound paragraph of the test synced pattern is addressed by
	 */
	const OVERRIDABLE_NAME = 'stats';

	private $settings_backup = [];

	private $role_caps_to_revoke = [];

	private $permalink_structure_changed = false;

	public function set_up() {
		parent::set_up();

		$settings              = \WP_Piwik::get_settings();
		$this->settings_backup = [
			'piwik_mode'             => $settings->get_global_option( 'piwik_mode' ),
			'piwik_url'              => $settings->get_global_option( 'piwik_url' ),
			'default_date'           => $settings->get_global_option( 'default_date' ),
			'shortcode_author_check' => $settings->get_global_option( 'shortcode_author_check' ),
			'site_id'                => $settings->get_option( 'site_id' ),
		];

		$settings->set_global_option( 'piwik_mode', 'disabled' );
		$settings->set_global_option( 'piwik_url', 'https://matomo.example.org/' );
		$settings->set_global_option( 'default_date', 'yesterday' );
		$settings->set_global_option( 'shortcode_author_check', true );
		$settings->set_option( 'site_id', 7 );

		// constructing the request registers API.getPiwikVersion, reset() clears it
		$request = new Shortcode_Test_Request( new \WP_Piwik_Test_Mock_Plugin(), $settings );
		$request->reset();

		add_shortcode( Shortcode::TAG, array( $GLOBALS['wp-piwik'], 'shortcode' ) );
		add_filter( 'render_block', array( $GLOBALS['wp-piwik'], 'render_shortcodes_in_reusable_block' ), 10, 2 );
		add_filter( 'render_block_data', array( '\WP_Piwik\Shortcode', 'record_open_reusable_block' ), 10, 1 );
		add_filter( 'rest_request_before_callbacks', array( '\WP_Piwik\Shortcode', 'record_open_rest_route' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( '\WP_Piwik\Shortcode', 'close_rest_route' ), 10, 1 );

		wp_set_current_user( 0 );
		$this->set_current_post( null );
	}

	public function tear_down() {
		$settings = \WP_Piwik::get_settings();
		foreach ( [ 'piwik_mode', 'piwik_url', 'default_date', 'shortcode_author_check' ] as $key ) {
			$settings->set_global_option( $key, $this->settings_backup[ $key ] );
		}
		$settings->set_option( 'site_id', $this->settings_backup['site_id'] );

		// role capabilities live in an option that the test transaction rolls back,
		// but also in the wp_roles global, which survives the rollback.
		foreach ( $this->role_caps_to_revoke as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( 'wp-piwik_read_stats' );
			}
		}
		$this->role_caps_to_revoke = [];

		// the permalink structure lives in an option the test transaction rolls back,
		// but also in the wp_rewrite global, which survives the rollback.
		if ( $this->permalink_structure_changed ) {
			$this->set_permalink_structure();
			$this->permalink_structure_changed = false;
		}

		remove_shortcode( Shortcode::TAG );
		remove_shortcode( 'wp_piwik_test_other' );

		$this->set_current_post( null );

		parent::tear_down();
	}

	public function test_render_should_default_to_the_overview_module() {
		$this->render( '' );

		$this->assertSame(
			[ 'VisitsSummary.get' ],
			$this->get_registered_methods(),
			'readme.txt documents [wp-piwik] as equal to [wp-piwik module="overview"]'
		);
	}

	public function test_render_should_return_null_for_an_unknown_module() {
		$this->assertNull( $this->render( 'module=nope' ) );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_pass_supported_attributes_to_the_widget() {
		$output = $this->render( 'module=opt-out language=de width=50% height=90px idsite=9' );

		$this->assertStringContainsString( 'width="50%"', $output );
		$this->assertStringContainsString( 'height="90px"', $output );
		$this->assertStringContainsString( 'idsite=9', $output );
		$this->assertStringContainsString( 'language=de', $output );
	}

	public function test_render_should_drop_unsupported_attributes() {
		$this->render( 'module=overview method=SitesManager.deleteSite urls=http://attacker.example note=x' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame(
			[],
			array_intersect( [ 'method', 'urls', 'note' ], array_keys( $parameters ) )
		);
	}

	public function test_render_should_ignore_an_injected_api_method() {
		$output = $this->render( 'module=opt-out method=SitesManager.deleteSite' );

		$this->assertStringContainsString( '<iframe', $output, 'precondition: the opt-out widget rendered' );
		$this->assertSame(
			[],
			Shortcode_Test_Request::get_registered(),
			'the opt-out widget performs no API call, so it must queue no request at all'
		);
	}

	/**
	 * @dataProvider get_unusable_attributes
	 */
	public function test_render_should_drop_an_attribute_value_matomo_would_not_accept( $attributes, $key, $expected ) {
		$this->render( 'module=overview ' . $attributes );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( $expected, $parameters[ $key ] );
	}

	public function get_unusable_attributes() {
		return [
			'unknown period'         => [ 'period=decade date=today', 'period', 'day' ],
			'period with a segment'  => [ 'period=day;segment=x date=today', 'period', 'day' ],
			'malformed date'         => [ 'period=day date=lastyear', 'date', 'yesterday' ],
			'date with a parameter'  => [ 'period=day date=today&flat=1', 'date', 'yesterday' ],
			'implausible last range' => [ 'period=day date=last99999', 'date', 'yesterday' ],
		];
	}

	/**
	 * @dataProvider get_documented_dates
	 */
	public function test_render_should_keep_a_documented_date( $date ) {
		$this->render( 'module=overview period=range date=' . $date );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( $date, $parameters['date'] );
	}

	public function get_documented_dates() {
		return [
			[ 'today' ],
			[ 'yesterday' ],
			[ 'last30' ],
			[ 'previous7' ],
			[ '2024-01-31' ],
			[ '2024-01-01,2024-01-31' ],
		];
	}

	/**
	 * @dataProvider get_matomo_periods
	 */
	public function test_render_should_keep_a_period_matomo_supports( $period ) {
		$this->render( 'module=overview period=' . $period . ' date=today' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( $period, $parameters['period'] );
	}

	public function get_matomo_periods() {
		return [
			[ 'day' ],
			[ 'week' ],
			[ 'month' ],
			[ 'year' ],
			[ 'range' ],
		];
	}

	public function test_render_should_keep_honouring_the_default_date_setting() {
		\WP_Piwik::get_settings()->set_global_option( 'default_date', 'current_month' );

		$this->render( 'module=overview' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( 'month', $parameters['period'] );
		$this->assertSame( 'today', $parameters['date'] );
	}

	public function test_render_should_let_an_explicit_date_win_over_the_default_date_setting() {
		\WP_Piwik::get_settings()->set_global_option( 'default_date', 'current_month' );

		$this->render( 'module=overview period=day date=last30' );

		$parameters = $this->get_registered_parameters( 'VisitsSummary.get' );
		$this->assertSame( 'day', $parameters['period'] );
		$this->assertSame( 'last30', $parameters['date'] );
	}

	public function test_render_should_drop_a_malformed_language() {
		$output = $this->render( 'module=opt-out language=de&idsite=9' );

		$this->assertStringContainsString( 'language=en', $output, 'the opt-out widget falls back to its own default' );
	}

	public function test_render_should_keep_a_plain_language_code() {
		$output = $this->render( 'module=opt-out language=de' );

		$this->assertStringContainsString( 'language=de', $output );
	}

	public function test_render_should_render_the_overview_when_the_post_author_can_read_stats() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$output = $this->render( 'module=overview' );

		$this->assertStringContainsString( '<table', $output );
		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
	}

	public function test_render_should_not_render_the_overview_when_the_post_author_cannot_read_stats() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$output = $this->render( 'module=overview' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered(), 'no request may be queued for a shortcode that is not allowed to run' );
	}

	public function test_render_should_render_the_post_module_when_the_post_author_can_read_stats() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$this->render( 'module=post key=nb_visits' );

		$this->assertSame( [ 'Actions.getPageUrl' ], $this->get_registered_methods() );
	}

	public function test_render_should_not_render_the_post_module_when_the_post_author_cannot_read_stats() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$output = $this->render( 'module=post key=nb_visits' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_render_the_opt_out_module_when_the_post_author_cannot_read_stats() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$output = $this->render( 'module=opt-out' );

		$this->assertStringContainsString( '<iframe', $output, 'the opt-out iframe requests no data and has to stay reachable' );
	}

	public function test_render_should_render_the_overview_when_the_author_check_is_disabled() {
		\WP_Piwik::get_settings()->set_global_option( 'shortcode_author_check', false );
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$this->render( 'module=overview' );

		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
	}

	public function test_render_should_render_the_overview_when_no_post_can_be_resolved() {
		$this->render( 'module=overview' );

		$this->assertSame(
			[ 'VisitsSummary.get' ],
			$this->get_registered_methods(),
			'a shortcode outside a post comes from a theme or a widget, which needs wider capabilities to edit'
		);
	}

	public function test_render_should_not_render_the_post_module_when_no_post_can_be_resolved() {
		$output = $this->render( 'module=post key=nb_visits' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_render_the_overview_in_the_main_loop_when_the_post_author_can_read_stats() {
		$post_id = $this->create_post_and_set_as_current( $this->create_author( true ) );
		$this->set_current_post( null );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->render( 'module=overview' );

		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
	}

	public function test_render_should_not_render_the_overview_in_the_main_loop_when_the_post_author_cannot_read_stats() {
		$post_id = $this->create_post_and_set_as_current( $this->create_author( false ) );
		$this->set_current_post( null );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertSame( '', $this->render( 'module=overview' ) );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_follow_the_read_stats_capability_of_the_author_role() {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->create_post_and_set_as_current( $author_id );

		$this->assertSame( '', $this->render( 'module=overview' ), 'precondition: the author role may not read stats' );

		$this->grant_read_stats_to_role( 'author' );

		$this->render( 'module=overview' );
		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
	}

	public function test_render_should_use_the_current_post_url_when_no_url_is_given() {
		$post_id = $this->create_post_and_set_as_current( $this->create_author( true ) );

		$this->render( 'module=post' );

		$parameters = $this->get_registered_parameters( 'Actions.getPageUrl' );
		$this->assertSame( get_permalink( $post_id ), $parameters['pageUrl'] );
	}

	public function test_render_should_accept_a_url_the_author_may_read() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$other_id  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$other_url = get_permalink( $other_id );

		$this->render( 'module=post url=' . $other_url );

		$parameters = $this->get_registered_parameters( 'Actions.getPageUrl' );
		$this->assertSame( $other_url, $parameters['pageUrl'] );
	}

	public function test_render_should_accept_a_url_of_this_site_written_with_the_other_scheme() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$other_id  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$other_url = set_url_scheme( get_permalink( $other_id ), 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'http' : 'https' );

		$this->render( 'module=post url=' . $other_url );

		$parameters = $this->get_registered_parameters( 'Actions.getPageUrl' );
		$this->assertSame( $other_url, $parameters['pageUrl'], 'a shortcode written before the site moved to HTTPS keeps working' );
	}

	public function test_render_should_reject_an_off_site_url() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$output = $this->render( 'module=post url=https://attacker.example/some-page/' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_reject_an_admin_url() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$output = $this->render( 'module=post url=' . admin_url( 'index.php' ) );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_reject_a_url_the_author_may_not_read() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$private_id = self::factory()->post->create(
			[
				'post_status' => 'private',
				'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
			]
		);

		$output = $this->render( 'module=post url=' . get_permalink( $private_id ) );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_reject_an_admin_url_naming_a_readable_post_in_its_fragment() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$readable_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$output = $this->render( 'module=post url=' . admin_url( 'index.php' ) . '#?p=' . $readable_id );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_reject_a_url_the_author_may_not_read_naming_a_readable_post_in_its_fragment() {
		$this->set_pretty_permalink_structure();
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$readable_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		self::factory()->post->create(
			[
				'post_status' => 'private',
				'post_name'   => 'embargoed-story',
				'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
			]
		);

		$private_url = home_url( '/embargoed-story/' );

		$output = $this->render( 'module=post url=' . $private_url . '#?p=' . $readable_id );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_reject_a_url_of_a_host_that_only_looks_like_this_site() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$output = $this->render( 'module=post url=' . home_url() . '.attacker.example/?p=1' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_reject_a_url_on_another_port() {
		$this->set_permalink_structure( '' );
		$this->set_home_url_without_a_port();

		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$other_id              = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$other_on_another_port = str_replace(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( home_url(), PHP_URL_HOST ) . ':8080',
			get_permalink( $other_id )
		);
		$output                = $this->render( 'module=post url=' . $other_on_another_port );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_accept_a_url_stating_the_default_port_of_a_scheme() {
		$this->set_permalink_structure( '' );
		$this->set_home_url_without_a_port();
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$other_id  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$other_url = str_replace(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( home_url(), PHP_URL_HOST ) . ':80',
			get_permalink( $other_id )
		);

		$this->render( 'module=post url=' . $other_url );

		$parameters = $this->get_registered_parameters( 'Actions.getPageUrl' );
		$this->assertSame( $other_url, $parameters['pageUrl'] );
	}

	public function test_render_should_authorize_a_reusable_block_against_its_own_author() {
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( false ),
				'post_content' => '[wp-piwik module="overview"]',
			]
		);
		// the embedding post belongs to somebody who may see the statistics
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$output = $this->render_reusable_block( $block_id );

		$this->assertSame( '', $output, 'the block author may not see the statistics, so the block borrowing the embedding author is a bypass' );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_render_a_reusable_block_when_both_authors_may_read_stats() {
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( true ),
				'post_content' => '[wp-piwik module="overview"]',
			]
		);
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$this->render_reusable_block( $block_id );

		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
	}

	public function test_render_should_authorize_a_reusable_block_against_the_embedding_post_author_too() {
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( true ),
				'post_content' => '[wp-piwik module="overview"]',
			]
		);
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$output = $this->render_reusable_block( $block_id );

		$this->assertSame(
			'',
			$output,
			'the block can render text the embedding post supplies, so its author has to pass the gate as well'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_leave_the_global_post_alone_after_rendering_a_reusable_block() {
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( true ),
				'post_content' => '[wp-piwik module="overview"]',
			]
		);
		$post_id  = $this->create_post_and_set_as_current( $this->create_author( true ) );

		$this->render_reusable_block( $block_id );

		$this->assertSame( $post_id, $GLOBALS['post']->ID );
		$this->assertArrayHasKey( 'wp-piwik', $GLOBALS['shortcode_tags'], 'the narrowed tag list has to be restored' );
	}

	public function test_render_should_leave_other_shortcodes_in_a_reusable_block_to_the_usual_pass() {
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( true ),
				'post_content' => '[wp-piwik module="opt-out"][wp_piwik_test_other]',
			]
		);
		add_shortcode( 'wp_piwik_test_other', '__return_empty_string' );
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$output = $this->render_reusable_block( $block_id );

		$this->assertStringContainsString( '<iframe', $output );
		$this->assertStringContainsString( '[wp_piwik_test_other]', $output, 'only this plugin\'s tag is expanded early' );
	}

	/**
	 * @dataProvider get_block_pass_counts
	 */
	public function test_render_should_not_leave_an_escaped_shortcode_in_a_reusable_block_to_the_usual_pass( $block_passes ) {
		$escaped  = str_repeat( '[', $block_passes + 1 ) . 'wp-piwik module="overview"' . str_repeat( ']', $block_passes + 1 );
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( false ),
				'post_content' => $escaped,
			]
		);
		$this->create_post_and_set_as_current( $this->create_author( true ) );

		$block_content = $escaped;
		for ( $pass = 0; $pass < $block_passes; $pass++ ) {
			$block_content = $this->render_reusable_block( $block_id, $block_content );
		}
		$output = do_shortcode( $block_content );

		$this->assertSame(
			[],
			Shortcode_Test_Request::get_registered(),
			'the block author may not see the statistics, so no pass may expand the shortcode'
		);
		$this->assertStringNotContainsString( '<table', $output );
	}

	public function get_block_pass_counts() {
		return [
			'one render_block pass'   => [ 1 ],
			'two render_block passes' => [ 2 ],
		];
	}

	public function test_render_should_leave_an_escaped_shortcode_in_a_reusable_block_to_the_reader_as_text() {
		// an author who escaped the tag on purpose still has to get text rather than a resolved widget.

		$author_id  = $this->create_author( true );
		$pattern_id = $this->create_synced_pattern(
			$author_id,
			[ $this->make_paragraph_block( '[[wp-piwik module=overview]]' ) ]
		);
		$content    = serialize_block( $this->make_synced_pattern_block( $pattern_id ) );
		$this->create_post_and_set_as_current( $author_id, $content );

		$output = $this->render_post_content( $content );

		$this->assertStringNotContainsString(
			'<table',
			$output,
			'the bracket escape has to keep working inside a pattern, as it does in a post'
		);
		// the entity is the one disarm_leftover_tags() writes: &#91; would be turned back
		// into a bracket by the next do_shortcode() rather than left as text
		$this->assertStringContainsString(
			'&#091;wp-piwik module=overview]',
			$output,
			'the escaped shortcode has to reach the reader as text'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_not_expand_a_shortcode_that_appears_in_the_shortcodes_own_denial_output() {
		// a filter denies using the shortcode with an error message that includes the
		// shortcode. the error message shortcode should not be expanded.

		$author_id  = $this->create_author( true );
		$pattern_id = $this->create_synced_pattern(
			$author_id,
			[ $this->make_paragraph_block( '[wp-piwik module=overview]' ) ]
		);
		$content    = serialize_block( $this->make_synced_pattern_block( $pattern_id ) );
		$this->create_post_and_set_as_current( $author_id, $content );

		$this->deny_through_the_authorized_filter( 'the editorial policy forbids stats here, see [wp-piwik module=opt-out]' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$output = $this->render_post_content( $content );

		$this->assertStringNotContainsString(
			'<iframe',
			$output,
			'what the block level pass produced may not be handed back to another lap of it'
		);
		$this->assertStringContainsString(
			'&#091;wp-piwik module=opt-out]',
			$output,
			'the tag has to reach the reader as text instead'
		);
	}

	public function test_render_should_authorize_a_pattern_override_against_the_embedding_post_author() {
		$pattern_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $pattern_id );

		// the shortcode text lives in the embedding post, not in the pattern
		$content = serialize_block( $this->make_synced_pattern_block( $pattern_id, '[wp-piwik module=overview]' ) );
		$this->create_post_and_set_as_current( $this->create_author( false ), $content );

		$output = $this->render_post_content( $content );

		$this->assertStringNotContainsString(
			'<table',
			$output,
			'the overridden text comes from the embedding post, so the pattern author may not authorize it'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_authorize_a_pattern_override_url_against_the_embedding_post_author() {
		$administrator_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		( new \WP_User( $administrator_id ) )->add_cap( 'wp-piwik_read_stats' );
		$private_id = self::factory()->post->create(
			[
				'post_status' => 'private',
				'post_author' => $administrator_id,
			]
		);
		$pattern_id = $this->create_synced_pattern(
			$administrator_id,
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $pattern_id );

		// the embedding author may see the statistics, but not the private post
		$content = serialize_block(
			$this->make_synced_pattern_block(
				$pattern_id,
				'[wp-piwik module=post url=' . get_permalink( $private_id ) . ' period=day date=today key=nb_visits]'
			)
		);
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$this->render_post_content( $content );

		$this->assertSame(
			[],
			Shortcode_Test_Request::get_registered(),
			'the url attribute comes from the embedding post, so it has to be read against its author'
		);
	}

	public function test_render_should_render_the_own_content_of_a_pattern_when_both_authors_may_read_stats() {
		$pattern_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( '[wp-piwik module=overview]' ) ]
		);
		$content    = serialize_block( $this->make_synced_pattern_block( $pattern_id ) );
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$this->render_post_content( $content );

		$this->assertSame(
			[ 'VisitsSummary.get' ],
			$this->get_registered_methods(),
			'closing the override bypass may not stop an ordinary pattern from rendering'
		);
	}

	public function test_render_should_authorize_the_own_content_of_an_overridden_pattern_against_the_pattern_author() {
		// the pattern author cannot see the statistics; the author embedding it can.
		// this is the pattern author writing a shortcode into a block somebody else
		// already uses, so it has to stay authorized against the pattern author even
		// though the embedding post now overrides a different block of the pattern.
		$pattern_id = $this->create_synced_pattern(
			$this->create_author( false ),
			[
				$this->make_paragraph_block( 'an editable note', true ),
				$this->make_paragraph_block( '[wp-piwik module=overview]' ),
			]
		);
		$this->require_working_pattern_overrides( $pattern_id );

		$content = serialize_block( $this->make_synced_pattern_block( $pattern_id, 'a note from the embedding post' ) );
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$output = $this->render_post_content( $content );

		$this->assertStringNotContainsString(
			'<table',
			$output,
			'an override elsewhere in the pattern may not hand the pattern its embedding author'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	/**
	 * @dataProvider get_permissions_for_nested_pattern_tests
	 */
	public function test_render_should_authorize_a_pattern_nested_in_another_pattern_against_every_author_on_the_way_down(
		$inner_author_can_read,
		$outer_author_can_read
	) {
		// all but one author in the chain can read the stats

		$inner_id = $this->create_synced_pattern(
			$this->create_author( $inner_author_can_read ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $inner_id );

		$outer_id = $this->create_synced_pattern(
			$this->create_author( $outer_author_can_read ),
			[ $this->make_synced_pattern_block( $inner_id, 'injected shortcode: [wp-piwik module=overview]' ) ]
		);
		$content  = serialize_block( $this->make_synced_pattern_block( $outer_id ) );
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$output = $this->render_post_content( $content );

		$this->assertStringContainsString(
			'injected shortcode',
			$output,
			'the shortcode override was not processed'
		);
		$this->assertStringNotContainsString(
			'<table',
			$output,
			'the pattern in between supplied the text, so it has to pass the gate as well'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function get_permissions_for_nested_pattern_tests() {
		return [
			'inner can read, outer cannot' => [ true, false ],
			'inner cannot read, outer can' => [ false, true ],
		];
	}

	public function test_render_should_render_a_pattern_nested_in_another_pattern_when_every_author_may_read_stats() {
		$inner_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $inner_id );

		$outer_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_synced_pattern_block( $inner_id, 'injected shortcode: [wp-piwik module=overview]' ) ]
		);
		$content  = serialize_block( $this->make_synced_pattern_block( $outer_id ) );
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$output = $this->render_post_content( $content );

		$this->assertStringContainsString(
			'injected shortcode',
			$output,
			'the shortcode override was not processed'
		);
		$this->assertSame(
			[ 'VisitsSummary.get' ],
			$this->get_registered_methods(),
			'walking the whole chain may not stop a nested pattern from rendering'
		);
	}

	public function test_render_should_authorize_a_nested_pattern_against_an_ancestor_that_rendered_another_pattern_first() {
		$target_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $target_id );

		$sibling_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'nothing to authorize here' ) ]
		);

		// post with two patterns: the first one is fine and has no shortcode, the second has
		// the shortcode as an override
		$outer_id = $this->create_synced_pattern(
			$this->create_author( false ),
			[
				$this->make_synced_pattern_block( $sibling_id ),
				$this->make_synced_pattern_block( $target_id, 'injected shortcode: [wp-piwik module=overview]' ),
			]
		);
		$content  = serialize_block( $this->make_synced_pattern_block( $outer_id ) );
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$output = $this->render_post_content( $content );

		$this->assertStringContainsString(
			'injected shortcode',
			$output,
			'the shortcode override was not processed'
		);
		$this->assertStringNotContainsString(
			'<table',
			$output,
			'the pattern in between supplied the text, so it has to pass the gate as well'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_authorize_a_nested_pattern_against_an_ancestor_that_also_embeds_itself() {
		$target_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $target_id );

		// core does not normalize refs in the recursion guard that stops a pattern from embedding
		// itself by the ref verbatim, so a ref spelled as a numeric string is a different pattern
		// to it and gets rendered rather than halted. closing the block that renders has
		// to tell that copy apart from the one it is nested in, however the two spell
		// the same ref.
		$outer_id = $this->create_synced_pattern( $this->create_author( false ), [] );
		$this->set_pattern_blocks(
			$outer_id,
			[
				$this->make_synced_pattern_block( ' ' . $outer_id ), // ' 5' will be converted to 05 when used in core WordPress
				$this->make_synced_pattern_block( $target_id, 'injected shortcode: [wp-piwik module=overview]' ),
			]
		);

		$content = serialize_block( $this->make_synced_pattern_block( $outer_id ) );
		$this->create_post_and_set_as_current( $this->create_author( true ), $content );

		$output = $this->render_post_content( $content );

		$this->assertStringContainsString(
			'injected shortcode',
			$output,
			'the shortcode override was not processed'
		);
		$this->assertStringNotContainsString(
			'<table',
			$output,
			'the pattern in between supplied the text, so it has to pass the gate as well'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_authorize_a_pattern_rendered_through_the_block_renderer_route_against_the_requester() {
		// the route takes the block attributes from the request, so the override text
		// belongs to whoever asked for the render rather than to the pattern's author

		$pattern_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $pattern_id );

		// edit_posts is all the route asks for when no post_id is passed
		$requester = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$this->assertFalse( user_can( $requester, 'wp-piwik_read_stats' ), 'precondition: the requester may not see the statistics' );
		wp_set_current_user( $requester );
		$this->set_current_post( null );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/block-renderer/core/block' );
		$request->set_param( 'context', 'edit' );
		$request->set_param(
			'attributes',
			[
				'ref'     => $pattern_id,
				'content' => [ self::OVERRIDABLE_NAME => [ 'content' => 'injected shortcode: [wp-piwik module=overview]' ] ],
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$rendered = $response->get_data();
		$rendered = isset( $rendered['rendered'] ) ? $rendered['rendered'] : '';

		$this->assertSame( 200, $response->get_status(), 'precondition: the route rendered the pattern for the requester' );
		$this->assertStringContainsString(
			'injected shortcode',
			$rendered,
			'the shortcode override was not processed'
		);
		$this->assertStringNotContainsString(
			'<table',
			$rendered,
			'the requester supplied the text, so the requester has to pass the gate'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_authorize_the_block_renderer_route_against_the_requester_across_a_nested_rest_request() {
		// anything that renders while the route is being served may itself ask the REST
		// server for something, and the route the gate reads has to survive that.

		$pattern_id = $this->create_synced_pattern(
			$this->create_author( true ),
			[ $this->make_paragraph_block( 'no statistics here', true ) ]
		);
		$this->require_working_pattern_overrides( $pattern_id );

		$requester = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$this->assertFalse( user_can( $requester, 'wp-piwik_read_stats' ), 'precondition: the requester may not see the statistics' );
		wp_set_current_user( $requester );
		$this->set_current_post( null );

		$this->dispatch_a_nested_rest_request_while_a_block_renders();

		$request = new \WP_REST_Request( 'GET', '/wp/v2/block-renderer/core/block' );
		$request->set_param( 'context', 'edit' );
		$request->set_param(
			'attributes',
			[
				'ref'     => $pattern_id,
				'content' => [ self::OVERRIDABLE_NAME => [ 'content' => 'injected shortcode: [wp-piwik module=overview]' ] ],
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$rendered = $response->get_data();
		$rendered = isset( $rendered['rendered'] ) ? $rendered['rendered'] : '';

		$this->assertSame( 200, $response->get_status(), 'precondition: the route rendered the pattern for the requester' );
		$this->assertStringContainsString(
			'injected shortcode',
			$rendered,
			'the shortcode override was not processed'
		);
		$this->assertStringNotContainsString(
			'<table',
			$rendered,
			'the requester still supplied the text, whatever else asked the REST server for something meanwhile'
		);
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_ignore_blocks_that_are_not_reusable() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );
		$shortcode = new Shortcode( $GLOBALS['wp-piwik'], \WP_Piwik::get_settings() );

		$output = $shortcode->render_reusable_block(
			'[wp-piwik module="overview"]',
			[
				'blockName' => 'core/paragraph',
				'attrs'     => [],
			]
		);

		$this->assertSame( '[wp-piwik module="overview"]', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_let_the_authorized_filter_block_an_allowed_shortcode() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		add_filter( 'wp-piwik_shortcode_authorized', '__return_false' );

		$output = $this->render( 'module=overview' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_let_the_authorized_filter_allow_a_blocked_shortcode() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );
		add_filter( 'wp-piwik_shortcode_authorized', '__return_true' );

		$this->render( 'module=overview' );

		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
	}

	public function test_render_should_let_the_authorized_filter_block_with_a_wp_error() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$this->deny_through_the_authorized_filter( 'the editorial policy forbids stats here' );

		$output = $this->render( 'module=overview' );

		$this->assertSame( '', $output );
		$this->assertSame( [], Shortcode_Test_Request::get_registered() );
	}

	public function test_render_should_show_an_administrator_the_message_of_a_wp_error_from_the_authorized_filter() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		$this->deny_through_the_authorized_filter( 'the editorial policy forbids stats here' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$output = $this->render( 'module=overview' );

		$this->assertStringContainsString( 'the editorial policy forbids stats here', $output );
		$this->assertStringNotContainsString( 'Array', $output, 'the WP_Error message is a string, not the array get_error_messages() returns' );
	}

	public function test_render_should_report_only_the_first_message_of_a_multi_message_wp_error() {
		$this->create_post_and_set_as_current( $this->create_author( true ) );
		add_filter(
			'wp-piwik_shortcode_authorized',
			function () {
				$error = new \WP_Error( 'wp_piwik_test_denial', 'first reason' );
				$error->add( 'wp_piwik_test_denial', 'second reason' );
				return $error;
			}
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$output = $this->render( 'module=overview' );

		$this->assertStringContainsString( 'first reason', $output );
		$this->assertStringNotContainsString( 'Array', $output );
	}

	public function test_render_should_pass_the_attributes_and_the_post_to_the_authorized_filter() {
		$post_id = $this->create_post_and_set_as_current( $this->create_author( true ) );
		$seen    = [];
		add_filter(
			'wp-piwik_shortcode_authorized',
			function ( $authorized, $attributes, $post ) use ( &$seen ) {
				$seen = [
					'module'  => $attributes['module'],
					'post_id' => $post instanceof \WP_Post ? $post->ID : null,
				];
				return $authorized;
			},
			10,
			3
		);

		$this->render( 'module=overview' );

		$this->assertSame(
			[
				'module'  => 'overview',
				'post_id' => $post_id,
			],
			$seen
		);
	}

	public function test_render_should_return_nothing_to_a_visitor_when_it_blocks_a_shortcode() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$output = $this->render( 'module=overview' );

		$this->assertSame( '', $output, 'not even an HTML comment, which would tell a visitor which check failed' );
	}

	public function test_render_should_explain_to_an_administrator_why_it_blocked_a_shortcode() {
		$this->create_post_and_set_as_current( $this->create_author( false ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$output = $this->render( 'module=overview' );

		$this->assertStringContainsString( 'wp-piwik-shortcode-denied', $output );
		$this->assertStringContainsString( 'not allowed to see the statistics', $output );
	}

	private function render( $attributes ) {
		$shortcode = new Shortcode( $GLOBALS['wp-piwik'], \WP_Piwik::get_settings() );
		return $shortcode->render( shortcode_parse_atts( $attributes ) );
	}

	private function render_reusable_block( $block_id, $block_content = null ) {
		if ( null === $block_content ) {
			$block_content = get_post( $block_id )->post_content;
		}
		// core hooks callbacks that take the block instance too, so the filter has to be
		// called with all three arguments the block renderer passes
		$parsed_block = [
			'blockName'    => 'core/block',
			'attrs'        => [ 'ref' => $block_id ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
		return apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'render_block',
			$block_content,
			$parsed_block,
			new \WP_Block( $parsed_block )
		);
	}

	/**
	 * @param string $text        text of the paragraph
	 * @param bool   $overridable whether an embedding post may replace that text
	 * @return array parsed block, as serialize_block expects it
	 */
	private function make_paragraph_block( $text, $overridable = false ) {
		$attributes = [];
		if ( $overridable ) {
			$attributes['metadata'] = [
				'name'     => self::OVERRIDABLE_NAME,
				'bindings' => [ 'content' => [ 'source' => 'core/pattern-overrides' ] ],
			];
		}
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => $attributes,
			'innerBlocks'  => [],
			'innerHTML'    => '<p>' . $text . '</p>',
			'innerContent' => [ '<p>' . $text . '</p>' ],
		];
	}

	/**
	 * @param int         $pattern_id wp_block post ID
	 * @param string|null $text
	 *          text the embedding post puts into the overridable paragraph, null to
	 *          leave the pattern to its own text
	 * @return array parsed block, as serialize_block expects it
	 */
	private function make_synced_pattern_block( $pattern_id, $text = null ) {
		$attributes = [ 'ref' => $pattern_id ];
		if ( null !== $text ) {
			$attributes['content'] = [ self::OVERRIDABLE_NAME => [ 'content' => $text ] ];
		}
		return [
			'blockName'    => 'core/block',
			'attrs'        => $attributes,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * @param int     $author_id author of the pattern
	 * @param array[] $blocks    parsed blocks the pattern consists of
	 * @return int wp_block post ID
	 */
	private function create_synced_pattern( $author_id, $blocks ) {
		return self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $author_id,
				'post_content' => serialize_blocks( $blocks ),
			]
		);
	}

	/**
	 * Replace what a pattern consists of. Used for a pattern that has to embed its own ID.
	 *
	 * @param int     $pattern_id wp_block post ID
	 * @param array[] $blocks     parsed blocks the pattern consists of
	 */
	private function set_pattern_blocks( $pattern_id, $blocks ) {
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_content' => serialize_blocks( $blocks ),
			]
		);
	}

	/**
	 * Make one block ask the REST server for something while it renders, the way a
	 * block that fetches its own data would.
	 */
	private function dispatch_a_nested_rest_request_while_a_block_renders() {
		$dispatched = false;
		add_filter(
			// the plugin resolves the shortcodes of a block on the same hook at 10
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'render_block',
			function ( $block_content, $block ) use ( &$dispatched ) {
				if ( ! $dispatched && 'core/block' === ( isset( $block['blockName'] ) ? $block['blockName'] : '' ) ) {
					$dispatched = true;
					rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/types' ) );
				}
				return $block_content;
			},
			9,
			2
		);
	}

	/**
	 * @param int $pattern_id wp_block post ID of a pattern with an overridable paragraph
	 */
	private function require_working_pattern_overrides( $pattern_id ) {
		if (
			! function_exists( 'get_block_bindings_source' )
			|| null === get_block_bindings_source( 'core/pattern-overrides' )
		) {
			$this->markTestSkipped( 'this WordPress does not support pattern overrides' );
		}

		$probe = 'wp-piwik-override-probe';

		$this->assertStringContainsString(
			$probe,
			$this->render_post_content( serialize_block( $this->make_synced_pattern_block( $pattern_id, $probe ) ) ),
			'precondition: the embedding post can put its own text into the pattern'
		);

		// the probe rendered the whole pattern, shortcodes included
		$this->forget_registered_requests();
	}

	private function forget_registered_requests() {
		$request = new Shortcode_Test_Request( new \WP_Piwik_Test_Mock_Plugin(), \WP_Piwik::get_settings() );
		$request->reset();
	}

	/**
	 * Render post content the way the_content does: blocks first, shortcodes after
	 *
	 * @param string $content post content
	 * @return string rendered content
	 */
	private function render_post_content( $content ) {
		return do_shortcode( do_blocks( $content ) );
	}

	/**
	 * Create a user who may or may not see the statistics
	 *
	 * @param bool $can_read_stats whether the user may see the statistics
	 * @return int user ID
	 */
	private function create_author( $can_read_stats ) {
		$user_id = self::factory()->user->create( [ 'role' => 'author' ] );
		if ( $can_read_stats ) {
			$user = new \WP_User( $user_id );
			$user->add_cap( 'wp-piwik_read_stats' );
		}
		return $user_id;
	}

	/**
	 * @param string $message reason the filter reports back
	 */
	private function deny_through_the_authorized_filter( $message ) {
		add_filter(
			'wp-piwik_shortcode_authorized',
			function () use ( $message ) {
				return new \WP_Error( 'wp_piwik_test_denial', $message );
			}
		);
	}

	private function set_pretty_permalink_structure() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->permalink_structure_changed = true;
	}

	private function grant_read_stats_to_role( $role_name ) {
		get_role( $role_name )->add_cap( 'wp-piwik_read_stats' );
		$this->role_caps_to_revoke[] = $role_name;
	}

	private function create_post_and_set_as_current( $author_id, $content = '' ) {
		$post_id = self::factory()->post->create(
			[
				'post_author'  => $author_id,
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
		$this->set_current_post( get_post( $post_id ) );
		return $post_id;
	}

	/**
	 * @param \WP_Post|null $post post the shortcode is rendered in
	 */
	private function set_current_post( $post ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = $post;
	}

	private function get_registered_methods() {
		return array_values( wp_list_pluck( Shortcode_Test_Request::get_registered(), 'method' ) );
	}

	private function get_registered_parameters( $method ) {
		foreach ( Shortcode_Test_Request::get_registered() as $config ) {
			if ( $method === $config['method'] ) {
				return $config['parameter'];
			}
		}
		$this->fail( 'No request was registered for ' . $method );
	}

	private function set_home_url_without_a_port() {
		$home_url = 'http://example.org';
		add_filter(
			'home_url',
			function ( $url, $path ) use ( $home_url ) {
				return $home_url . ( $path ? '/' . ltrim( $path, '/' ) : '' );
			},
			10,
			2
		);
	}
}
