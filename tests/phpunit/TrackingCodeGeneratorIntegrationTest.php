<?php

namespace WP_Piwik\Tests;

use WP_Piwik\TrackingCode\Generator;

/**
 * Ensure the tracking code generator in Connect Matomo behaves the same as the tracking
 * code generator in Matomo.
 *
 * Note: this class needs a real live Matomo up to run. "ddev wp-matomo:matomo up" installs
 * that Matomo and records how to reach it. Without it every case below skips, which is
 * what should happen for anyone running the suite who has no interest in this comparison.
 */
class TrackingCodeGeneratorIntegrationTest extends WP_Piwik_TestCase {

	/**
	 * @var array
	 */
	private $matomo;

	/**
	 * @var Generator
	 */
	private $generator;

	public function set_up() {
		parent::set_up();

		$matomo = self::read_live_matomo();
		if ( ! $matomo ) {
			$missing = 'No Matomo to compare against, install one with "ddev wp-matomo:matomo up"';
			if ( getenv( 'WP_MATOMO_REQUIRE_LIVE_MATOMO' ) ) {
				self::fail( $missing );
			}
			self::markTestSkipped( $missing );
		}
		$this->matomo = $matomo;

		// the base class answers every outgoing request itself which we don't want here
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ] );

		$this->generator = new Generator();
	}

	/**
	 * @dataProvider get_tracking_code_options
	 */
	public function test_generate_should_answer_what_a_running_matomo_answers( array $matomo_params, array $options ) {
		$expected = $this->ask_matomo_for_the_tracking_code( $matomo_params );

		$actual = $this->generator->generate(
			$this->matomo['id_site'],
			$this->matomo['url'],
			array_merge( [ 'site_urls' => $this->get_site_urls_matomo_knows() ], $options )
		);

		$this->assertSame(
			$expected,
			$actual,
			sprintf( 'The generated tracking code is not the one Matomo %s answers with.', $this->matomo['version'] )
		);
	}

	/**
	 * The options getJavascriptTag takes as request parameters, paired with the generator
	 * option that means the same thing. Anything Matomo reads from the site's own settings
	 * instead, the excluded query parameters and referrers among them, would have to be
	 * configured on the Matomo first.
	 */
	public function get_tracking_code_options() {
		$every_matomo_option = [
			'groupPageTitlesByDomain' => 1,
			'mergeSubdomains'         => 1,
			'mergeAliasUrls'          => 1,
			'crossDomain'             => 1,
			'doNotTrack'              => 1,
			'disableCookies'          => 1,
			'trackNoScript'           => 1,
		];
		$every_option        = [
			'group_page_titles_by_domain' => true,
			'merge_subdomains'            => true,
			'merge_alias_urls'            => true,
			'cross_domain'                => true,
			'do_not_track'                => true,
			'disable_cookies'             => true,
			'track_no_script'             => true,
		];

		return [
			'no option set'                 => [ [], [] ],
			'page titles grouped by domain' => [ [ 'groupPageTitlesByDomain' => 1 ], [ 'group_page_titles_by_domain' => true ] ],
			'subdomains merged'             => [ [ 'mergeSubdomains' => 1 ], [ 'merge_subdomains' => true ] ],
			'alias urls merged'             => [ [ 'mergeAliasUrls' => 1 ], [ 'merge_alias_urls' => true ] ],
			'domains linked to each other'  => [ [ 'crossDomain' => 1 ], [ 'cross_domain' => true ] ],
			'do not track honoured'         => [ [ 'doNotTrack' => 1 ], [ 'do_not_track' => true ] ],
			'cookies disabled'              => [ [ 'disableCookies' => 1 ], [ 'disable_cookies' => true ] ],
			'visitors without javascript'   => [ [ 'trackNoScript' => 1 ], [ 'track_no_script' => true ] ],
			'every option set'              => [ $every_matomo_option, $every_option ],
		];
	}

	public function test_generate_should_track_the_site_matomo_was_asked_about() {
		$code = $this->generator->generate( $this->matomo['id_site'], $this->matomo['url'] );

		$this->assertStringContainsString(
			"_paq.push(['setSiteId', '" . $this->matomo['id_site'] . "']);",
			$code
		);
	}

	private static function read_live_matomo() {
		$file = getenv( 'WP_MATOMO_LIVE_MATOMO_FILE' );
		$file = $file ? $file : '/tmp/wp-matomo-live-matomo.json';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$matomo = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $matomo ) || empty( $matomo['url'] ) || empty( $matomo['token'] ) ) {
			return null;
		}
		return $matomo;
	}

	/**
	 * @param array $params the SitesManager.getJavascriptTag options to ask for
	 * @return string the tracking code, as a page would carry it
	 */
	private function ask_matomo_for_the_tracking_code( array $params ) {
		$answer = $this->ask_matomo(
			array_merge(
				[
					'method' => 'SitesManager.getJavascriptTag',
					'idSite' => $this->matomo['id_site'],
				],
				$params
			)
		);

		$this->assertArrayHasKey( 'value', $answer );

		return $answer['value'];
	}

	private function get_site_urls_matomo_knows() {
		$urls = $this->ask_matomo(
			[
				'method' => 'SitesManager.getSiteUrlsFromId',
				'idSite' => $this->matomo['id_site'],
			]
		);

		$this->assertNotEmpty( $urls, 'Matomo does not know the site by any URL' );
		return $urls;
	}

	private function ask_matomo( array $params ) {
		$response = wp_remote_post(
			$this->matomo['url'] . 'index.php',
			[
				'timeout' => 30,
				'body'    => array_merge(
					[
						'module'            => 'API',
						'format'            => 'json',
						'token_auth'        => $this->matomo['token'],
						'force_api_session' => 0,
					],
					$params
				),
			]
		);

		$this->assertNotWPError( $response );
		$this->assertSame(
			200,
			wp_remote_retrieve_response_code( $response ),
			sprintf(
				'Matomo at %s did not answer the request. A 401 means the token recorded for it no longer works: install it again with "ddev wp-matomo:matomo up".',
				$this->matomo['url']
			)
		);

		$answer = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertIsArray( $answer, 'Matomo did not answer with JSON: ' . wp_remote_retrieve_body( $response ) );
		$this->assertNotSame(
			'error',
			isset( $answer['result'] ) ? $answer['result'] : '',
			isset( $answer['message'] ) ? $answer['message'] : ''
		);

		return $answer;
	}
}
