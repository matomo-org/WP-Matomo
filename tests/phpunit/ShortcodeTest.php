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
		$output = $this->render( 'module=post url=' . $other_on_another_port );

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

		print "$other_id\n";
		print get_permalink($other_id)."\n";
		print $other_url."\n";
		print home_url()."\n";
		print home_url('/')."\n";
		print wp_parse_url($other_url, PHP_URL_HOST)."\n";
		print wp_parse_url(home_url(), PHP_URL_HOST)."\n";
		@ob_flush();

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

	public function test_render_should_render_a_reusable_block_whose_author_may_read_stats() {
		$block_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $this->create_author( true ),
				'post_content' => '[wp-piwik module="overview"]',
			]
		);
		$this->create_post_and_set_as_current( $this->create_author( false ) );

		$this->render_reusable_block( $block_id );

		$this->assertSame( [ 'VisitsSummary.get' ], $this->get_registered_methods() );
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

	private function create_post_and_set_as_current( $author_id ) {
		$post_id = self::factory()->post->create(
			[
				'post_author' => $author_id,
				'post_status' => 'publish',
			]
		);
		print "permalink: ". get_permalink($post_id)."\n";@ob_flush();
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
			function () use ( $home_url ) {
				return $home_url;
			}
		);
	}
}
