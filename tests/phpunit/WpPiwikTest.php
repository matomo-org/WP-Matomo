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

		if ( ! self::is_integration_environment() ) {
			self::markTestSkipped( 'WP_MATOMO_INTEGRATION_TESTS is not set, cannot run this test' );
		}

		$this->set_up_mock_endpoint();
		$this->answer_with_site_urls( [] );
		$this->configure_plugin();
	}

	public function test_update_tracking_code_returns_the_tracking_code_of_the_configured_site() {
		$script = $this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( "_paq.push(['trackPageView']);", $script );
		$this->assertStringContainsString( "_paq.push(['setSiteId', '1']);", $script );
		$this->assertStringContainsString( 'var u="//' . $this->mock_host() . '/";', $script );
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

		$this->assertSame( '//' . $this->mock_host() . '/', get_option( 'wp-piwik_global-proxy_url' ) );
	}

	public function test_update_tracking_code_should_not_ask_matomo_for_the_tracking_code() {
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertSame( [], $this->get_captured_requests() );
	}

	public function test_update_tracking_code_puts_the_configured_tracking_options_into_the_tracking_code() {
		$this->configure_plugin(
			[
				'track_across'              => true,
				'track_across_alias'        => true,
				'disable_cookies'           => true,
				'track_crossdomain_linking' => true,
			]
		);
		$this->answer_with_site_urls( [ 'https://blog.example.org/', 'https://www.example.org/' ] );

		$script = $this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( '_paq.push(["setCookieDomain", "*.blog.example.org"]);', $script );
		$this->assertStringContainsString( '_paq.push(["setDomains", ["*.blog.example.org/","*.www.example.org/"]]);', $script );
		$this->assertStringContainsString( '_paq.push(["enableCrossDomainLinking"]);', $script );
		$this->assertStringContainsString( '_paq.push(["disableCookies"]);', $script );
	}

	public function test_update_tracking_code_asks_matomo_only_for_the_urls_the_site_is_known_by() {
		$this->configure_plugin( [ 'track_across_alias' => true ] );
		$this->answer_with_site_urls( [ 'https://www.example.org/' ] );

		$this->wp_piwik->update_tracking_code( 1 );

		$urls = $this->get_requested_api_urls();

		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'method=SitesManager.getSiteUrlsFromId', $urls[0] );
		$this->assertStringContainsString( 'idSite=1', $urls[0] );
	}

	public function test_update_tracking_code_should_store_nothing_when_matomo_will_not_name_the_urls_of_the_site() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_an_api_error();

		$this->assertFalse( $this->wp_piwik->update_tracking_code( 1 ) );

		$this->assertEmpty( get_option( 'wp-piwik-tracking_code' ) );
	}

	public function test_update_tracking_code_should_keep_the_tracking_code_it_last_built_when_matomo_will_not_name_the_urls_of_the_site() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_site_urls( [ 'https://www.example.org/' ] );
		$built = $this->wp_piwik->update_tracking_code( 1 );

		$this->forget_what_matomo_already_answered();
		$this->answer_with_an_api_error();
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertSame( $built, get_option( 'wp-piwik-tracking_code' ) );
	}

	public function test_update_tracking_code_should_report_that_matomo_will_not_name_the_urls_of_the_site() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_an_api_error();

		$this->wp_piwik->update_tracking_code( 1 );

		$notice = $this->render_notices();

		$this->assertStringContainsString( 'could not ask Matomo what URLs this site is known by', $notice );
		$this->assertStringContainsString( \WP_Piwik::NOTICE_CLASS_ERROR, $notice );
	}

	public function test_update_tracking_code_should_keep_reporting_that_matomo_will_not_name_the_urls_of_the_site() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_an_api_error();
		$this->wp_piwik->update_tracking_code( 1 );

		// the tracking code is rebuilt on every page view for as long as none is stored, so
		// the report has to outlive the request that shows it
		$this->render_notices();

		$this->assertStringContainsString( 'could not ask Matomo what URLs this site is known by', $this->render_notices() );
	}

	public function test_update_tracking_code_should_withdraw_the_report_once_matomo_names_the_urls_of_the_site_again() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_an_api_error();
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( 'could not ask Matomo', $this->render_notices() );

		$this->forget_what_matomo_already_answered();
		$this->answer_with_site_urls( [ 'https://www.example.org/' ] );
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringNotContainsString( 'could not ask Matomo', $this->render_notices() );
	}

	public function test_update_tracking_code_should_withdraw_the_report_once_the_site_stops_generating_a_tracking_code() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_an_api_error();
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( 'could not ask Matomo', $this->render_notices() );

		// turning tracking off is the obvious thing to do about a tracking code that cannot
		// be built
		$this->configure_plugin( [ 'track_mode' => 'disabled' ] );
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringNotContainsString( 'could not ask Matomo', $this->render_notices() );
	}

	public function test_update_tracking_code_should_withdraw_the_report_once_the_site_names_no_matomo() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_an_api_error();
		$this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( 'could not ask Matomo', $this->render_notices() );

		$this->configure_plugin( [ 'piwik_url' => '' ] );
		$this->wp_piwik->update_tracking_code( 1 );

		// the report tells the reader to check that Matomo is reachable, which says nothing
		// to a site that no longer names one
		$this->assertStringNotContainsString( 'could not ask Matomo', $this->render_notices() );
	}

	public function test_update_tracking_code_should_take_an_answer_that_names_no_url_of_the_site() {
		$this->configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site();
		$this->answer_with_site_urls( [] );

		$script = $this->wp_piwik->update_tracking_code( 1 );

		// Matomo answering that it knows the site by no URL is an answer, unlike an error
		$this->assertStringContainsString( "_paq.push(['trackPageView']);", $script );
		$this->assertStringNotContainsString( 'setDomains', $script );
		$this->assertStringNotContainsString( 'setCookieDomain', $script );
	}

	public function test_update_tracking_code_should_not_take_an_answer_that_names_no_host_for_a_url_of_the_site() {
		$this->configure_plugin( [ 'track_across_alias' => true ] );
		$this->answer_with_site_urls( [ 'not a url', 'https://www.example.org/' ] );

		$script = $this->wp_piwik->update_tracking_code( 1 );

		$this->assertStringContainsString( '_paq.push(["setDomains", ["*.www.example.org/"]]);', $script );
	}

	public function test_update_tracking_code_uses_the_site_id_of_the_blog_when_none_is_given() {
		$this->configure_plugin( [], [ 'site_id' => 4 ] );

		$script = $this->wp_piwik->update_tracking_code();

		$this->assertStringContainsString( "_paq.push(['setSiteId', '4']);", $script );
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

	public function test_update_tracking_code_stores_nothing_when_no_matomo_is_configured() {
		$this->configure_plugin( [ 'piwik_url' => '' ] );

		$this->assertFalse( $this->wp_piwik->update_tracking_code( 1 ) );

		$this->assertEmpty( get_option( 'wp-piwik-tracking_code' ) );
		$this->assertEmpty( get_option( 'wp-piwik-last_tracking_code_update' ) );
	}

	public function test_update_tracking_code_stores_nothing_when_the_blog_has_no_matomo_site() {
		$this->configure_plugin( [], [ 'site_id' => '' ] );

		$this->assertFalse( $this->wp_piwik->update_tracking_code() );

		$this->assertEmpty( get_option( 'wp-piwik-tracking_code' ) );
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

		$this->forget_what_matomo_already_answered();
	}

	private function forget_what_matomo_already_answered() {
		( new Rest( $this->wp_piwik, \WP_Piwik::get_settings() ) )->reset();
	}

	private function configure_connect_matomo_to_ask_matomo_for_the_urls_of_the_site() {
		$this->configure_plugin(
			[
				'track_across'       => true,
				'track_across_alias' => true,
			]
		);
	}

	private function answer_with_site_urls( array $site_urls ) {
		$this->answer_with( $site_urls );
	}

	private function answer_with_an_api_error() {
		$this->answer_with(
			[
				'result'  => 'error',
				'message' => 'An unexpected website was found in the request: website id was set to \'1\'',
			]
		);
	}

	private function render_notices() {
		ob_start();
		$this->wp_piwik->show_notices();
		return ob_get_clean();
	}

	private function answer_with( array $answer ) {
		$this->set_mock_response(
			[
				'body' => wp_json_encode(
					[
						$answer,
						[ 'value' => '5.0.0' ],
					]
				),
			]
		);
	}

	private function mock_host() {
		return rtrim( preg_replace( '~^https?://~', '', $this->mock_url() ), '/' );
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
