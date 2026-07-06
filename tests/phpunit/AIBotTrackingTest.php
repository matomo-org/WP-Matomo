<?php

namespace WP_Piwik\Tests;

use WP_Piwik\AIBotTracking;
use WP_Piwik\Logger\Dummy;
use WP_Piwik\Settings;

class AIBotTrackingTest extends WP_Piwik_TestCase {

	public function set_up() {
		parent::set_up();
		$this->reset_tracked_flag();
	}

	public function tear_down() {
		$this->reset_tracked_flag();
		parent::tear_down();
	}

	private function reset_tracked_flag() {
		$property = new \ReflectionProperty( AIBotTracking::class, 'ai_bot_tracked' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	private function create_tracking( $global_options = [], $options = [] ) {
		$global_options += [
			'piwik_mode' => 'http',
			'piwik_url'  => 'https://stats.example.org/',
		];
		$settings        = $this->create_settings( $global_options, $options );
		return new AIBotTracking( $settings, new Dummy( 'test' ) );
	}

	private function create_enabled_tracking( $global_options = [] ) {
		return $this->create_tracking(
			$global_options + [
				Settings::TRACK_AI_BOTS => true,
				'track_mode'            => 'default',
			],
			array( 'site_id' => 5 )
		);
	}

	public function test_register_hooks_attaches_to_footer_and_litespeed_init() {
		$tracking = $this->create_tracking();
		$tracking->register_hooks();

		$this->assertNotFalse( has_action( 'wp_footer', [ $tracking, 'do_ai_bot_tracking' ] ) );
		$this->assertNotFalse( has_action( 'litespeed_init', [ $tracking, 'litespeed_init' ] ) );
	}

	public function test_should_track_current_page_for_regular_page_requests() {
		$_SERVER['REQUEST_URI'] = '/hello-world/';

		$this->assertTrue( $this->create_tracking()->should_track_current_page() );
	}

	public function test_should_track_current_page_for_php_and_html_requests() {
		$tracking = $this->create_tracking();

		$_SERVER['REQUEST_URI'] = '/index.php';
		$this->assertTrue( $tracking->should_track_current_page() );

		$_SERVER['REQUEST_URI'] = '/page.html';
		$this->assertTrue( $tracking->should_track_current_page() );
	}

	public function test_should_not_track_admin_pages() {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		$this->assertFalse( $this->create_tracking()->should_track_current_page() );
	}

	public function test_should_not_track_ajax_requests() {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse( $this->create_tracking()->should_track_current_page() );
	}

	public function test_should_not_track_without_a_request_uri() {
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertFalse( $this->create_tracking()->should_track_current_page() );
	}

	public function test_should_not_track_matomo_tracker_requests() {
		$_SERVER['REQUEST_URI'] = '/wp-content/plugins/wp-piwik/proxy/matomo.php?idsite=1';

		$this->assertFalse( $this->create_tracking()->should_track_current_page() );
	}

	public function test_should_not_track_static_file_requests() {
		$tracking = $this->create_tracking();

		$_SERVER['REQUEST_URI'] = '/wp-content/uploads/image.png';
		$this->assertFalse( $tracking->should_track_current_page() );

		$_SERVER['REQUEST_URI'] = '/assets/app.js?ver=3';
		$this->assertFalse( $tracking->should_track_current_page() );
	}

	public function test_is_js_execution_detected_via_cookie() {
		$tracking = $this->create_tracking();

		$this->assertFalse( $tracking->is_js_execution_detected() );

		$_COOKIE['matomo_has_js'] = '1';
		$this->assertTrue( $tracking->is_js_execution_detected() );
	}

	public function test_do_ai_bot_tracking_outputs_esi_include_when_esi_tracking_is_enabled() {
		$tracking = $this->create_tracking( [ Settings::TRACK_AI_BOTS_USING_ESI => true ] );

		ob_start();
		$tracking->do_ai_bot_tracking();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<esi:include src="', $output );
		$this->assertStringContainsString( 'misc/track_ai_bot.php', $output );
		$this->assertStringContainsString( 'mtm_esi=1', $output );
		$this->assertSame( [], $this->captured_http_requests );
	}

	public function test_do_ai_bot_tracking_sends_a_tracking_request_for_ai_bot_user_agents() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

		$this->create_enabled_tracking()->do_ai_bot_tracking();

		$this->assertCount( 1, $this->captured_http_requests );

		$url = $this->captured_http_requests[0]['url'];
		$this->assertStringContainsString( 'https://stats.example.org/matomo.php?', $url );
		$this->assertStringContainsString( 'idsite=5', $url );
		$this->assertStringContainsString( 'bots=1', $url );
	}

	public function test_do_ai_bot_tracking_only_tracks_once_per_request() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

		$tracking = $this->create_enabled_tracking();
		$tracking->do_ai_bot_tracking();
		$tracking->do_ai_bot_tracking();

		$this->assertCount( 1, $this->captured_http_requests );
	}

	public function test_do_ai_bot_tracking_ignores_regular_user_agents() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0';

		$this->create_enabled_tracking()->do_ai_bot_tracking();

		$this->assertSame( array(), $this->captured_http_requests );
	}

	public function test_do_ai_bot_tracking_skips_bots_that_executed_javascript() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';
		$_COOKIE['matomo_has_js']   = '1';

		$this->create_enabled_tracking()->do_ai_bot_tracking();

		$this->assertSame( [], $this->captured_http_requests );
	}

	public function test_do_ai_bot_tracking_does_nothing_when_the_feature_is_disabled() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

		$tracking = $this->create_tracking(
			[
				Settings::TRACK_AI_BOTS => false,
				'track_mode'            => 'default',
			],
			[ 'site_id' => 5 ]
		);
		$tracking->do_ai_bot_tracking();

		$this->assertSame( [], $this->captured_http_requests );
	}

	public function test_do_ai_bot_tracking_does_nothing_when_tracking_is_disabled() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

		$tracking = $this->create_tracking(
			[
				Settings::TRACK_AI_BOTS => true,
				'track_mode'            => 'disabled',
			],
			[ 'site_id' => 5 ]
		);
		$tracking->do_ai_bot_tracking();

		$this->assertSame( [], $this->captured_http_requests );
	}
}
