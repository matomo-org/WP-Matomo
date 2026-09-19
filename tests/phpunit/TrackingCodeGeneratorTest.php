<?php

namespace WP_Piwik\Tests;

use WP_Piwik\TrackingCode\Generator;

/**
 * Note: Matomo's API hands the code out HTML encoded and Connect Matomo used to decode it on
 * arrival, so the expectations are decoded here the same way before being compared.
 */
class TrackingCodeGeneratorTest extends WP_Piwik_TestCase {

	/**
	 * @var Generator
	 */
	private $generator;

	public function set_up() {
		parent::set_up();

		$this->generator = new Generator();
	}

	public function test_generate_should_answer_what_matomo_answers_for_a_site_with_no_options_set() {
		$expected = "&lt;!-- Matomo --&gt;
&lt;script&gt;
  var _paq = window._paq = window._paq || [];
  /* tracker methods like &quot;setCustomDimension&quot; should be called before &quot;trackPageView&quot; */
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u=&quot;//localhost/piwik/&quot;;
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '1']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
&lt;/script&gt;
&lt;!-- End Matomo Code --&gt;
";

		$this->assertSame(
			$this->as_matomo_delivers_it( $expected ),
			$this->generator->generate( 1, 'http://localhost/piwik' )
		);
	}

	public function test_generate_should_answer_what_matomo_answers_for_a_site_with_every_option_set() {
		$expected = "&lt;!-- Matomo --&gt;
&lt;script&gt;
  var _paq = window._paq = window._paq || [];
  /* tracker methods like &quot;setCustomDimension&quot; should be called before &quot;trackPageView&quot; */
  _paq.push([\"setDocumentTitle\", document.domain + \"/\" + document.title]);
  _paq.push([\"setCookieDomain\", \"*.localhost\"]);
  _paq.push([\"setDomains\", [\"*.localhost/piwik\",\"*.another-domain/piwik\",\"*.another-domain/piwik\"]]);
  _paq.push([\"enableCrossDomainLinking\"]);
  _paq.push([\"disableCampaignParameters\"]);
  _paq.push([\"setCampaignNameKey\", \"campaignKey\"]);
  _paq.push([\"setCampaignKeywordKey\", \"keywordKey\"]);
  _paq.push([\"setDoNotTrack\", true]);
  _paq.push([\"setExcludedQueryParams\", [\"uid\",\"aid\"]]);
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u=&quot;//piwik-server/piwik/&quot;;
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '1']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
&lt;/script&gt;
&lt;noscript&gt;&lt;p&gt;&lt;img referrerpolicy=&quot;no-referrer-when-downgrade&quot; src=&quot;//piwik-server/piwik/matomo.php?idsite=1&amp;amp;rec=1&quot; style=&quot;border:0;&quot; alt=&quot;&quot; /&gt;&lt;/p&gt;&lt;/noscript&gt;
&lt;!-- End Matomo Code --&gt;
";

		$actual = $this->generator->generate(
			1,
			'http://piwik-server/piwik',
			[
				'group_page_titles_by_domain'      => true,
				'merge_subdomains'                 => true,
				'merge_alias_urls'                 => true,
				'cross_domain'                     => true,
				'site_urls'                        => [
					'http://localhost/piwik',
					'http://another-domain/piwik',
					'https://another-domain/piwik',
				],
				'disable_cookies'                  => false,
				'do_not_track'                     => true,
				'disable_campaign_parameters'      => true,
				'custom_campaign_name_query_param' => 'campaignKey',
				'custom_campaign_keyword_param'    => 'keywordKey',
				'excluded_query_params'            => [ 'uid', 'aid' ],
				'excluded_referrers'               => [],
				'track_no_script'                  => true,
			]
		);

		$this->assertSame( $this->as_matomo_delivers_it( $expected ), $actual );
	}

	public function test_generate_should_disable_cookies_the_way_matomo_does() {
		$code = $this->generator->generate( 1, 'http://localhost/piwik', [ 'disable_cookies' => true ] );

		$this->assertStringContainsString( '  _paq.push(["disableCookies"]);' . "\n", $code );
	}

	public function test_generate_should_exclude_referrers_the_way_matomo_does() {
		$code = $this->generator->generate( 1, 'http://localhost/piwik', [ 'excluded_referrers' => 'example.org,example.com' ] );

		$this->assertStringContainsString( '  _paq.push(["setExcludedReferrers", ["example.org","example.com"]]);' . "\n", $code );
	}

	public function test_generate_should_not_let_a_matomo_url_end_the_string_it_stands_in() {
		// this is where the generator parts with Matomo on purpose: Matomo answers
		// 'var u="//abc"def/";' for the same URL, which ends the string after //abc
		$code = $this->generator->generate( 1, 'abc"def' );

		$this->assertStringContainsString( 'var u="//abcdef/";', $code );
	}

	public function test_generate_should_not_let_a_matomo_url_end_the_script_element() {
		$code = $this->generator->generate( 1, 'stats.example.org/</script><script>alert(1)</script>', [ 'track_no_script' => true ] );

		$this->assertSame( 1, substr_count( $code, '</script>' ), 'the tracking code has one script element' );
		$this->assertStringNotContainsString( 'alert(1)<', $code );
	}

	public function test_generate_should_strip_the_host_of_a_site_url_matomo_names_like_every_other_url() {
		$code = $this->generator->generate(
			1,
			'https://stats.example.org/',
			[
				'merge_subdomains' => true,
				'merge_alias_urls' => true,
				'site_urls'        => [ 'https://ex"am<ple.com' ],
			]
		);

		$this->assertStringContainsString( '  _paq.push(["setCookieDomain", "*.example.com"]);' . "\n", $code );
		$this->assertStringContainsString( '  _paq.push(["setDomains", ["*.example.com"]]);' . "\n", $code );
	}

	public function test_generate_should_not_let_a_site_url_matomo_names_end_the_script_element() {
		$code = $this->generator->generate(
			1,
			'https://stats.example.org/',
			[
				'merge_subdomains' => true,
				'merge_alias_urls' => true,
				'site_urls'        => [ 'https://example.org/</script><script>alert(1)</script>' ],
			]
		);

		$this->assertSame( 1, substr_count( $code, '</script>' ) );
	}

	public function test_generate_should_track_into_a_matomo_named_without_a_protocol() {
		$code = $this->generator->generate( 1, 'stats.example.org/' );

		$this->assertStringContainsString( 'var u="//stats.example.org/";', $code );
	}

	public function test_generate_should_track_into_a_matomo_whose_host_begins_with_the_letters_of_a_protocol() {
		$code = $this->generator->generate( 1, 'httpstats.example.org/' );

		$this->assertStringContainsString( 'var u="//httpstats.example.org/";', $code );
	}

	public function test_generate_should_name_no_tracker_host_for_a_matomo_a_browser_cannot_reach() {
		$code = $this->generator->generate( 1, 'ftp://stats.example.org/' );

		$this->assertStringContainsString( 'var u="///";', $code );
	}

	public function test_generate_should_track_the_site_matomo_knows_the_blog_as() {
		$code = $this->generator->generate( '7 or 1=1', 'https://stats.example.org/', [ 'track_no_script' => true ] );

		$this->assertStringContainsString( "_paq.push(['setSiteId', '7']);", $code );
		$this->assertStringContainsString( 'matomo.php?idsite=7&amp;rec=1', $code );
	}

	/**
	 * Decode a Matomo tracking code expectation the way Connect Matomo decoded the answers
	 * of SitesManager.getJavascriptTag
	 *
	 * @param string $code tracking code as Matomo's API hands it out
	 * @return string the same code, ready to be printed to a page
	 */
	private function as_matomo_delivers_it( $code ) {
		return html_entity_decode( $code );
	}
}
