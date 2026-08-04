<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Request\Rest;

class WpPiwikTest extends WP_Piwik_TestCase {

	use Mock_Matomo_Endpoint;

	/**
	 * Higher than WP_Piwik's own revision id, so the constructor treats the site as up to date
	 * and skips the install and upgrade routines.
	 */
	const INSTALLED_REVISION = 99999999999;

	/**
	 * @var \WP_Piwik
	 */
	private $wp_piwik;

	public function set_up() {
		parent::set_up();

		if ( ! self::is_in_wp_env_environment() ) {
			self::markTestSkipped( 'Not in wp-env environment, cannot run this test' );
		}

		$this->set_up_mock_endpoint();
		$this->answer_with_tracking_code( $this->get_matomo_tracking_code() );
		$this->configure_plugin();
	}

	public function test_update_tracking_code_returns_the_tracking_code_matomo_sent() {
		$script = $this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( "_paq.push(['trackPageView']);", $script );
		$this->assertStringNotContainsString( '<noscript>', $script );
	}

	public function test_update_tracking_code_stores_the_tracking_code_for_the_next_request() {
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString(
			"_paq.push(['trackPageView']);",
			get_option( 'wp-piwik-tracking_code' )
		);
		$this->assertStringContainsString( '<noscript>', get_option( 'wp-piwik-noscript_code' ) );
		$this->assertNotEmpty( get_option( 'wp-piwik-last_tracking_code_update' ) );
	}

	public function test_update_tracking_code_stores_the_proxy_url_for_the_next_request() {
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertSame( '//stats.example.org/', get_option( 'wp-piwik_global-proxy_url' ) );
	}

	public function test_update_tracking_code_asks_matomo_for_the_javascript_tag_of_the_configured_site() {
		$this->wp_piwik->update_tracking_code( 1 );

		$urls = $this->get_requested_api_urls();

		$this->assertNotEmpty( $urls );
		$this->assertStringContainsString( 'method=SitesManager.getJavascriptTag', $urls[0] );
		$this->assertStringContainsString( 'idSite=1', $urls[0] );
	}

	public function test_update_tracking_code_passes_the_configured_tracking_options_to_matomo() {
		$this->configure_plugin(
			[
				'track_across'              => true,
				'track_across_alias'        => true,
				'disable_cookies'           => true,
				'track_crossdomain_linking' => true,
			]
		);

		$this->wp_piwik->update_tracking_code( 1 );

		$urls = $this->get_requested_api_urls();

		$this->assertNotEmpty( $urls );
		$this->assertStringContainsString( 'mergeSubdomains=1', $urls[0] );
		$this->assertStringContainsString( 'mergeAliasUrls=1', $urls[0] );
		$this->assertStringContainsString( 'disableCookies=1', $urls[0] );
		$this->assertStringContainsString( 'crossDomain=1', $urls[0] );
	}

	public function test_update_tracking_code_uses_the_site_id_of_the_blog_when_none_is_given() {
		$this->configure_plugin( [], [ 'site_id' => 4 ] );

		$this->wp_piwik->update_tracking_code();

		$urls = $this->get_requested_api_urls();

		$this->assertNotEmpty( $urls );
		$this->assertStringContainsString( 'idSite=4', $urls[0] );
	}

	/**
	 * @dataProvider get_track_modes_that_do_not_use_a_matomo_tag
	 */
	public function test_update_tracking_code_does_not_ask_matomo_when_the_tag_is_not_used( $track_mode ) {
		$this->configure_plugin( [ 'track_mode' => $track_mode ] );

		$this->assertFalse( $this->wp_piwik->update_tracking_code( 1 ) );
		$this->assertSame( [], $this->get_captured_requests() );
	}

	public function get_track_modes_that_do_not_use_a_matomo_tag() {
		return [
			'disabled' => [ 'disabled' ],
			'manually' => [ 'manually' ],
		];
	}

	public function test_update_tracking_code_stores_nothing_when_matomo_returns_no_tag() {
		$this->answer_with_tracking_code( '' );

		$this->assertFalse( $this->wp_piwik->update_tracking_code( 1 ) );

		$this->assertEmpty( get_option( 'wp-piwik-tracking_code' ) );
		$this->assertEmpty( get_option( 'wp-piwik-last_tracking_code_update' ) );
	}

	private function configure_plugin( array $global_options = [], array $options = [] ) {
		$defaults = [
			'revision'           => self::INSTALLED_REVISION,
			'piwik_mode'         => 'http',
			'piwik_url'          => $this->mock_url(),
			'piwik_token'        => 'secret-token',
			'track_mode'         => 'default',
			// cURL cannot reach the endpoint from inside the test container, and
			// RestIntegrationTest already covers both transports
			'http_connection'    => 'fopen',
			'http_method'        => 'post',
			'cache'              => false,
			'connection_timeout' => 15,
			'auto_site_config'   => false,
		];

		foreach ( array_merge( $defaults, $global_options ) as $key => $value ) {
			update_option( 'wp-piwik_global-' . $key, $value );
		}
		foreach ( array_merge( [ 'site_id' => 1 ], $options ) as $key => $value ) {
			update_option( 'wp-piwik-' . $key, $value );
		}

		$this->wp_piwik = new \WP_Piwik();

		// request keeps its results in static properties, so make sure to clear them
		// before running a test
		( new Rest( $this->wp_piwik, \WP_Piwik::get_settings() ) )->reset();
	}

	/**
	 * Make the endpoint answer a bulk request with the given tracking code.
	 */
	private function answer_with_tracking_code( $tracking_code ) {
		$this->set_mock_response(
			[
				'body' => wp_json_encode(
					[
						[ 'value' => $tracking_code ],
						[ 'value' => '5.0.0' ],
					]
				),
			]
		);
	}

	private function get_matomo_tracking_code() {
		return '<!-- Matomo -->' . "\n"
			. '<script type="text/javascript">' . "\n"
			. 'var _paq = window._paq = window._paq || [];' . "\n"
			. "_paq.push(['trackPageView']);\n"
			. "(function() {\n"
			. 'var u="//stats.example.org/";' . "\n"
			. "_paq.push(['setTrackerUrl', u+'matomo.php']);\n"
			. "_paq.push(['setSiteId', '1']);\n"
			. "})();\n"
			. '</script>' . "\n"
			. '<noscript><p><img src="//stats.example.org/matomo.php?idsite=1" style="border:0;" alt="" /></p></noscript>';
	}

	private function get_requested_api_urls() {
		$urls = [];
		foreach ( $this->get_captured_requests() as $request ) {
			$submitted = isset( $request['post']['urls'] ) ? $request['post']['urls'] : [];
			foreach ( (array) $submitted as $url ) {
				$urls[] = urldecode( $url );
			}
		}
		return $urls;
	}
}
