<?php

namespace WP_Piwik\Tests;

use WP_Piwik\AjaxTracker;
use WP_Piwik\Logger\Dummy;
use WP_Piwik\TrackingCode;

class TrackingCodeTest extends WP_Piwik_TestCase {

	const TRACK_PAGEVIEW = "_paq.push(['trackPageView']);";

	public function test_prepare_tracking_code_splits_script_and_noscript_parts() {
		$result = $this->prepare();

		$this->assertStringContainsString( self::TRACK_PAGEVIEW, $result['script'] );
		$this->assertStringNotContainsString( '<noscript>', $result['script'] );
		$this->assertStringContainsString( '<noscript><p><img src="//stats.example.org/matomo.php?idsite=1"', $result['noscript'] );
	}

	public function test_prepare_tracking_code_extracts_the_proxy_url() {
		$result = $this->prepare();

		$this->assertSame( '//stats.example.org/', $result['proxy'] );
	}

	public function test_prepare_tracking_code_removes_type_attribute_when_configured() {
		$result = $this->prepare( [ 'remove_type_attribute' => true ] );

		$this->assertStringNotContainsString( ' type="text/javascript"', $result['script'] );
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

	public function test_prepare_tracking_code_uses_cdn_url_when_configured() {
		$result = $this->prepare( [ 'track_cdnurl' => 'cdn.example.org' ] );

		$this->assertStringContainsString( "'https://cdn.example.org/' : 'http://cdn.example.org/'", $result['script'] );
		$this->assertStringContainsString( 'g.src=ucdn+', $result['script'] );
	}

	public function test_prepare_tracking_code_adds_cfasync_attribute_when_configured() {
		$result = $this->prepare( [ 'track_datacfasync' => true ] );

		$this->assertStringContainsString( '<script data-cfasync="false" type', $result['script'] );
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
			$plugin = new \WP_Piwik_Test_Fake_Plugin();
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
		$plugin                        = new \WP_Piwik_Test_Fake_Plugin();
		$plugin->current_tracking_code = false;

		$this->create_tracking_code( $plugin );

		$this->assertSame( 1, $plugin->tracking_code_updates );
	}

	public function test_constructor_refreshes_tracking_code_containing_an_error() {
		$plugin                           = new \WP_Piwik_Test_Fake_Plugin();
		$plugin->options['tracking_code'] = '{"result":"error","message":"no access"}';

		$this->create_tracking_code( $plugin );

		$this->assertSame( 1, $plugin->tracking_code_updates );
	}

	public function test_constructor_uses_site_option_in_network_mode_with_manual_tracking() {
		$plugin                               = new \WP_Piwik_Test_Fake_Plugin();
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

	public function test_get_tracking_code_applies_user_id_tracking() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'user@example.org' ] );
		wp_set_current_user( $user_id );

		$plugin                                  = new \WP_Piwik_Test_Fake_Plugin();
		$plugin->global_options['track_user_id'] = 'email';

		$tracking_code                  = $this->create_tracking_code( $plugin );
		$tracking_code->is_usertracking = true;

		$result = $tracking_code->get_tracking_code();

		$this->assertStringContainsString( "_paq.push(['setUserId', 'user@example.org']);", $result );
	}

	public function test_get_tracking_code_skips_user_id_tracking_for_anonymous_visitors() {
		wp_set_current_user( 0 );

		$plugin                                  = new \WP_Piwik_Test_Fake_Plugin();
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
		return '<!-- Matomo -->' . "\n"
			. '<script type="text/javascript">' . "\n"
			. 'var _paq = window._paq = window._paq || [];' . "\n"
			. "_paq.push(['trackPageView']);\n"
			. "_paq.push(['enableLinkTracking']);\n"
			. "(function() {\n"
			. 'var u="//stats.example.org/";' . "\n"
			. "_paq.push(['setTrackerUrl', u+'matomo.php']);\n"
			. "_paq.push(['setSiteId', '1']);\n"
			. "var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];\n"
			. "g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);\n"
			. "})();\n"
			. '</script>' . "\n"
			. '<noscript><p><img src="//stats.example.org/matomo.php?idsite=1" style="border:0;" alt="" /></p></noscript>';
	}

	private function prepare( $global_options = array() ) {
		$settings = $this->create_settings( $global_options );
		return TrackingCode::prepare_tracking_code( $this->get_sample_code(), $settings, new Dummy( 'test' ) );
	}
}
