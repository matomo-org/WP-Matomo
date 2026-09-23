<?php

namespace WP_Piwik\Tests;

use WP_Piwik\AjaxTracker;
use WP_Piwik\Logger\Dummy;
use WP_Piwik\TrackingCode;
use WP_Piwik\TrackingCode\Generator;

class TrackingCodeTest extends WP_Piwik_TestCase {

	const TRACK_PAGEVIEW = "_paq.push(['trackPageView']);";

	public function test_prepare_tracking_code_splits_script_and_noscript_parts() {
		$result = $this->prepare();

		$this->assertStringContainsString( self::TRACK_PAGEVIEW, $result['script'] );
		$this->assertStringNotContainsString( '<noscript>', $result['script'] );
		$this->assertStringContainsString( 'src="//stats.example.org/matomo.php?idsite=1', $result['noscript'] );
	}

	public function test_prepare_tracking_code_extracts_the_proxy_url() {
		$result = $this->prepare();

		$this->assertSame( '//stats.example.org/', $result['proxy'] );
	}

	public function test_prepare_tracking_code_should_leave_the_script_element_without_a_type_attribute() {
		$this->assertStringNotContainsString( ' type=', $this->prepare()['script'] );
		$this->assertStringNotContainsString( ' type=', $this->prepare( [ 'remove_type_attribute' => true ] )['script'] );
	}

	public function test_prepare_tracking_code_rewrites_urls_in_js_mode() {
		$result = $this->prepare( [ 'track_mode' => 'js' ] );

		$this->assertStringContainsString( "g.src=u+'js/index.php'", $result['script'] );
		$this->assertStringNotContainsString( 'matomo.js', $result['script'] );
		$this->assertStringNotContainsString( "u+'matomo.php'", $result['script'] );
	}

	public function test_prepare_tracking_code_rewrites_urls_in_proxy_mode() {
		$expected_proxy = str_replace(
			[ 'https://', 'http://' ],
			'//',
			plugins_url( 'wp-piwik' )
		) . '/proxy/';

		$result = $this->prepare( [ 'track_mode' => 'proxy' ] );

		$this->assertStringContainsString( 'var u="' . $expected_proxy . '"', $result['script'] );
		$this->assertStringNotContainsString( 'matomo.js', $result['script'] );
	}

	public function test_prepare_tracking_code_should_send_the_noscript_image_through_the_proxy() {
		$expected_proxy = str_replace(
			[ 'https://', 'http://' ],
			'//',
			plugins_url( 'wp-piwik' )
		) . '/proxy/';

		$result = $this->prepare( [ 'track_mode' => 'proxy' ] );

		$this->assertStringContainsString( 'src="' . $expected_proxy . 'matomo.php?idsite=1', $result['noscript'] );
		$this->assertStringNotContainsString( 'stats.example.org', $result['noscript'] );
	}

	public function test_prepare_tracking_code_uses_cdn_url_when_configured() {
		$result = $this->prepare( [ 'track_cdnurl' => 'cdn.example.org' ] );

		$this->assertStringContainsString( '"https:\/\/cdn.example.org\/" : "http:\/\/cdn.example.org\/"', $result['script'] );
		$this->assertStringContainsString( 'g.src=ucdn+', $result['script'] );
	}

	public function test_prepare_tracking_code_should_not_let_a_cdn_url_end_the_script_element() {
		$result = $this->prepare( [ 'track_cdnurl' => '</script><script>alert(1)</script>' ] );

		$this->assertStringContainsString( '"https:\/\/\/scriptscriptalert(1)\/script\/"', $result['script'] );
		$this->assertSame( 1, substr_count( $result['script'], '</script>' ), 'the tracking code has one script element' );
	}

	public function test_prepare_tracking_code_should_not_let_a_cdn_url_end_the_string_it_is_put_in() {
		$result = $this->prepare( [ 'track_cdnurlssl' => 'cdn.example.org/"+alert(1)+"' ] );

		$this->assertStringNotContainsString( '"+alert(1)+"', $result['script'] );
		$this->assertStringContainsString( '\"+alert(1)+\"', $result['script'] );
	}

	public function test_prepare_tracking_code_should_not_let_a_cdn_url_start_a_script_element() {
		// a '<!--' followed by a '<script' makes the rest of the page part of the script
		// element, which JSON encoding does nothing about
		$result = $this->prepare( [ 'track_cdnurl' => 'cdn.example.org/<!--<script>' ] );

		$this->assertStringContainsString( '"https:\/\/cdn.example.org\/!--script\/"', $result['script'] );
		$this->assertSame( 1, substr_count( $result['script'], '<script' ), 'the tracking code opens one script element' );
	}

	public function test_prepare_tracking_code_should_not_let_a_download_class_start_a_script_element() {
		$result = $this->prepare( [ 'set_download_classes' => 'download|<!--<script>' ] );

		$this->assertStringContainsString( "_paq.push(['setDownloadClasses', \"download|!--script\"]);", $result['script'] );
	}

	public function test_prepare_tracking_code_adds_cfasync_attribute_when_configured() {
		$result = $this->prepare( [ 'track_datacfasync' => true ] );

		$this->assertStringContainsString( '<script data-cfasync="false">', $result['script'] );
		$this->assertStringNotContainsString( '</script data-cfasync', $result['script'] );
	}

	public function test_prepare_tracking_code_limits_cookie_lifetimes_when_configured() {
		$result = $this->prepare( [ 'limit_cookies' => true ] );

		$this->assertStringContainsString( "_paq.push(['setVisitorCookieTimeout', 34186669]);", $result['script'] );
		$this->assertStringContainsString( "_paq.push(['setSessionCookieTimeout', 1800]);", $result['script'] );
		$this->assertStringContainsString( "_paq.push(['setReferralCookieTimeout', 15778463]);", $result['script'] );
	}

	public function test_prepare_tracking_code_forces_the_configured_protocol() {
		$result = $this->prepare( [ 'force_protocol' => 'https' ] );

		$this->assertStringContainsString( 'var u="https://stats.example.org/";', $result['script'] );
	}

	public function test_prepare_tracking_code_should_not_let_a_forced_protocol_end_the_script_element() {
		$result = $this->prepare( [ 'force_protocol' => '</script><script>alert(1)</script><x a="' ] );

		$this->assertStringContainsString( 'var u="//stats.example.org/";', $result['script'] );
		$this->assertSame( 1, substr_count( $result['script'], '</script>' ), 'the tracking code has one script element' );
	}

	public function test_prepare_tracking_code_adds_content_tracking_when_configured() {
		$result = $this->prepare( [ 'track_content' => 'all' ] );
		$this->assertStringContainsString( "_paq.push(['trackAllContentImpressions']);", $result['script'] );

		$result = $this->prepare( [ 'track_content' => 'visible' ] );
		$this->assertStringContainsString( "_paq.push(['trackVisibleContentImpressions']);", $result['script'] );
	}

	public function test_prepare_tracking_code_adds_heartbeat_timer_when_configured() {
		$result = $this->prepare( [ 'track_heartbeat' => 30 ] );

		$this->assertStringContainsString( "_paq.push(['enableHeartBeatTimer', 30]);", $result['script'] );
	}

	public function test_prepare_tracking_code_adds_consent_requirement_when_configured() {
		$result = $this->prepare( [ 'require_consent' => 'consent' ] );
		$this->assertStringContainsString( "_paq.push(['requireConsent']);", $result['script'] );

		$result = $this->prepare( [ 'require_consent' => 'cookieconsent' ] );
		$this->assertStringContainsString( "_paq.push(['requireCookieConsent']);", $result['script'] );
	}

	public function test_prepare_tracking_code_adds_download_extensions_when_configured() {
		$result = $this->prepare( [ 'set_download_extensions' => 'zip|tar' ] );
		$this->assertStringContainsString( "_paq.push(['setDownloadExtensions', \"zip|tar\"]);", $result['script'] );

		$result = $this->prepare( [ 'add_download_extensions' => 'apk' ] );
		$this->assertStringContainsString( "_paq.push(['addDownloadExtensions', \"apk\"]);", $result['script'] );
	}

	public function test_prepare_tracking_code_enables_ai_bot_tracking_when_configured() {
		$result = $this->prepare( [ \WP_Piwik\Settings::TRACK_AI_BOTS => true ] );

		$this->assertStringContainsString( "_paq.push(['appendToTrackingUrl', 'recMode=2']);", $result['script'] );
		$this->assertStringContainsString( 'matomo_has_js=1', $result['script'] );
		$this->assertStringContainsString( wp_json_encode( AjaxTracker::AI_BOT_USER_AGENT_SUBSTRINGS ), $result['script'] );
	}

	public function test_prepare_tracking_code_enables_noscript_tracking_when_configured() {
		$result = $this->prepare( [ 'track_nojavascript' => true ] );

		$this->assertStringContainsString( 'matomo.php?rec=1&idsite=1', $result['noscript'] );
	}

	public function test_prepare_tracking_code_updates_the_last_update_timestamp() {
		$settings = $this->create_settings();

		TrackingCode::prepare_tracking_code( $this->get_sample_code(), $settings, new Dummy( 'test' ) );

		$this->assertGreaterThan( 0, (int) $settings->get_option( 'last_tracking_code_update' ) );
	}

	private function create_tracking_code( $plugin = null ) {
		if ( null === $plugin ) {
			$plugin = new \WP_Piwik_Test_Mock_Plugin();
		}
		if ( ! isset( $plugin->options['tracking_code'] ) ) {
			$plugin->options['tracking_code'] = $this->get_sample_code();
		}
		return new TrackingCode( $plugin );
	}

	public function test_get_tracking_code_returns_the_unmodified_code_by_default() {
		$tracking_code = $this->create_tracking_code();

		$this->assertSame( $this->get_sample_code(), $tracking_code->get_tracking_code() );
	}

	public function test_constructor_refreshes_outdated_tracking_code() {
		$plugin                        = new \WP_Piwik_Test_Mock_Plugin();
		$plugin->current_tracking_code = false;

		$this->create_tracking_code( $plugin );

		$this->assertSame( 1, $plugin->tracking_code_updates );
	}

	public function test_constructor_refreshes_tracking_code_containing_an_error() {
		$plugin                           = new \WP_Piwik_Test_Mock_Plugin();
		$plugin->options['tracking_code'] = '{"result":"error","message":"no access"}';

		$this->create_tracking_code( $plugin );

		$this->assertSame( 1, $plugin->tracking_code_updates );
	}

	public function test_constructor_uses_site_option_in_network_mode_with_manual_tracking() {
		$plugin                               = new \WP_Piwik_Test_Mock_Plugin();
		$plugin->network_mode                 = true;
		$plugin->global_options['track_mode'] = 'manually';
		$plugin->options['tracking_code']     = 'blog level code';

		update_site_option( 'wp-piwik-manually', 'network level code' );

		$tracking_code = $this->create_tracking_code( $plugin );

		$this->assertSame( 'network level code', $tracking_code->get_tracking_code() );
	}

	public function test_get_tracking_code_applies_404_changes() {
		$tracking_code         = $this->create_tracking_code();
		$tracking_code->is_404 = true;

		$result = $tracking_code->get_tracking_code();

		$this->assertStringContainsString( "_paq.push(['setDocumentTitle', '404/URL = '", $result );
		$this->assertStringContainsString( self::TRACK_PAGEVIEW, $result );
	}

	public function test_get_tracking_code_applies_search_changes() {
		self::factory()->post->create( [ 'post_title' => 'All about Matomo' ] );
		self::factory()->post->create( [ 'post_title' => 'Matomo tips and tricks' ] );

		$this->go_to( '/?s=matomo' );
		$found_posts = $GLOBALS['wp_query']->found_posts;

		$tracking_code            = $this->create_tracking_code();
		$tracking_code->is_search = true;

		$result = $tracking_code->get_tracking_code();

		$this->assertStringContainsString(
			"_paq.push(['trackSiteSearch','matomo', false, " . $found_posts . ']);',
			$result
		);
	}

	public function test_get_tracking_code_should_not_let_a_search_term_end_the_string_it_is_put_in() {
		$this->go_to( '/?s=' . rawurlencode( "matomo');alert(1);//" ) );

		$tracking_code            = $this->create_tracking_code();
		$tracking_code->is_search = true;

		$result = $tracking_code->get_tracking_code();

		$this->assertStringContainsString(
			"_paq.push(['trackSiteSearch','matomo\\');alert(1);//', false, " . $GLOBALS['wp_query']->found_posts . ']);',
			$result
		);
	}

	public function test_get_tracking_code_should_not_let_a_search_term_start_a_script_element() {
		$this->go_to( '/?s=' . rawurlencode( '<!--<script>' ) );

		$tracking_code            = $this->create_tracking_code();
		$tracking_code->is_search = true;

		$result = $tracking_code->get_tracking_code();

		$this->assertStringContainsString(
			"_paq.push(['trackSiteSearch','&lt;!--&lt;script&gt;', false, " . $GLOBALS['wp_query']->found_posts . ']);',
			$result
		);
		$this->assertSame( 1, substr_count( $result, '</script>' ), 'the tracking code has one script element' );
	}

	public function test_get_tracking_code_applies_user_id_tracking() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'user@example.org' ] );
		wp_set_current_user( $user_id );

		$plugin                                  = new \WP_Piwik_Test_Mock_Plugin();
		$plugin->global_options['track_user_id'] = 'email';

		$tracking_code                  = $this->create_tracking_code( $plugin );
		$tracking_code->is_usertracking = true;

		$result = $tracking_code->get_tracking_code();

		$this->assertStringContainsString( "_paq.push(['setUserId', 'user@example.org']);", $result );
	}

	public function test_get_tracking_code_skips_user_id_tracking_for_anonymous_visitors() {
		wp_set_current_user( 0 );

		$plugin                                  = new \WP_Piwik_Test_Mock_Plugin();
		$plugin->global_options['track_user_id'] = 'email';

		$tracking_code                  = $this->create_tracking_code( $plugin );
		$tracking_code->is_usertracking = true;

		$this->assertStringNotContainsString( 'setUserId', $tracking_code->get_tracking_code() );
	}

	public function test_get_tracking_code_adds_custom_variables_on_single_posts() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'wp-piwik_custom_cat1', 'category' );
		update_post_meta( $post_id, 'wp-piwik_custom_val1', 'news' );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_single() );

		$result = $this->create_tracking_code()->get_tracking_code();

		$this->assertStringContainsString( "_paq.push(['setCustomVariable',1, \"category\", \"news\", 'page']);", $result );
	}

	public function test_get_tracking_code_should_not_let_a_custom_variable_start_a_script_element() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'wp-piwik_custom_cat1', '<!--<script>' );
		update_post_meta( $post_id, 'wp-piwik_custom_val1', 'news</script>' );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_single() );

		$result = $this->create_tracking_code()->get_tracking_code();

		$this->assertStringContainsString( "_paq.push(['setCustomVariable',1, \"!--script\", \"news\/script\", 'page']);", $result );
		$this->assertSame( 1, substr_count( $result, '</script>' ), 'the tracking code has one script element' );
	}

	public function test_get_tracking_code_applies_the_tracking_code_filter() {
		add_filter(
			'wp-piwik_tracking_code',
			function ( $code ) {
				return $code . "\n<!-- filtered -->";
			}
		);

		$result = $this->create_tracking_code()->get_tracking_code();

		$this->assertStringContainsString( '<!-- filtered -->', $result );
	}

	private function get_sample_code() {
		$generator = new Generator();
		return $generator->generate( 1, 'stats.example.org', array( 'track_no_script' => true ) );
	}

	private function prepare( $global_options = array() ) {
		$settings = $this->create_settings( $global_options );
		return TrackingCode::prepare_tracking_code( $this->get_sample_code(), $settings, new Dummy( 'test' ) );
	}
}
