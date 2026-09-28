<?php

namespace WP_Piwik\TrackingCode;

/**
 * Build the Matomo JavaScript tracking code in the same way the
 * SitesManager.getJavascriptTag API method works, with three deliberate differences:
 *
 * - a URL is stripped of every character a URL cannot hold before it is put into the script,
 *   and JSON encoded on the way in like every other value
 * - the tracker is always matomo.js/matomo.php. Matomo only serves piwik.js/piwik.php to an
 *   installation older than 3.7.0, and Connect Matomo requires Matomo 4.0.
 * - custom variables are left out. They are deprecated in Matomo, and Connect Matomo never
 *   asked for them.
 */
class Generator {

	const JS_ENDPOINT  = 'matomo.js';
	const PHP_ENDPOINT = 'matomo.php';

	/**
	 * @param int|string $id_site Matomo site ID to track into
	 * @param string     $matomo_url URL of the Matomo instance
	 * @param array      $options    tracking code options, see get_default_options()
	 * @return string tracking code, script element and noscript element
	 */
	public function generate( $id_site, $matomo_url, array $options = array() ) {
		$options = array_merge( $this->get_default_options(), $options );
		$host    = self::get_tracker_host( $matomo_url );
		$id_site = (int) $id_site;

		// no protocol so it uses whatever the page was loaded with
		$tracker_url = '//' . $host . '/';

		$code = '<!-- Matomo -->' . "\n"
			. '<script>' . "\n"
			. '  var _paq = window._paq = window._paq || [];' . "\n"
			. '  /* tracker methods like "setCustomDimension" should be called before "trackPageView" */' . "\n"
			. $this->get_option_lines( $options )
			. "  _paq.push(['trackPageView']);\n"
			. "  _paq.push(['enableLinkTracking']);\n"
			. "  (function() {\n"
			. '    var u=' . wp_json_encode( $tracker_url, JSON_UNESCAPED_SLASHES ) . ";\n"
			. "    _paq.push(['setTrackerUrl', u+'" . self::PHP_ENDPOINT . "']);\n"
			. "    _paq.push(['setSiteId', '" . $id_site . "']);\n"
			. "    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];\n"
			. "    g.async=true; g.src=u+'" . self::JS_ENDPOINT . "'; s.parentNode.insertBefore(g,s);\n"
			. "  })();\n"
			. '</script>' . "\n";

		if ( $options['track_no_script'] ) {
			$code .= '<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="'
				. esc_attr( $tracker_url . self::PHP_ENDPOINT )
				. '?idsite=' . $id_site . '&amp;rec=1" style="border:0;" alt="" /></p></noscript>' . "\n";
		}

		return $code . '<!-- End Matomo Code -->' . "\n";
	}

	/**
	 * @return array option name => default value
	 */
	public function get_default_options() {
		return array(
			// add the domain to the page title
			'group_page_titles_by_domain'      => false,
			// count a visit to a subdomain as a visit to the site
			'merge_subdomains'                 => false,
			// count a visit to one of the site's other URLs as a visit to the site
			'merge_alias_urls'                 => false,
			// follow a visitor from one of the site's domains to another
			'cross_domain'                     => false,
			// the URLs Matomo knows the site by, used by the three options above
			'site_urls'                        => array(),
			// track without setting a cookie
			'disable_cookies'                  => false,
			// honour the browser's Do Not Track header
			'do_not_track'                     => false,
			// ignore every campaign parameter of the visited URL
			'disable_campaign_parameters'      => false,
			// read the campaign name and keyword from these query parameters instead
			'custom_campaign_name_query_param' => '',
			'custom_campaign_keyword_param'    => '',
			// query parameters to drop from a tracked URL, comma separated or a list
			'excluded_query_params'            => array(),
			// referrers not to attribute a visit to, comma separated or a list
			'excluded_referrers'               => array(),
			// also track a visitor whose browser runs no JavaScript
			'track_no_script'                  => false,
		);
	}

	/**
	 * Build the tracker calls that go before trackPageView
	 *
	 * @param array $options tracking code options
	 * @return string lines of JavaScript, each indented and terminated
	 */
	private function get_option_lines( $options ) {
		$lines = '';

		if ( $options['group_page_titles_by_domain'] ) {
			$lines .= $this->line( '_paq.push(["setDocumentTitle", document.domain + "/" + document.title]);' );
		}

		// following a visitor across the site's domains only works when the tracker knows
		// them all, so cross domain linking brings the alias URLs with it
		if ( $options['cross_domain'] ) {
			$options['merge_alias_urls'] = true;
		}

		if ( $options['merge_subdomains'] || $options['merge_alias_urls'] ) {
			$lines .= $this->get_domain_lines( $options );
		}

		if ( $options['cross_domain'] ) {
			$lines .= $this->line( '_paq.push(["enableCrossDomainLinking"]);' );
		}

		if ( $options['disable_campaign_parameters'] ) {
			$lines .= $this->line( '_paq.push(["disableCampaignParameters"]);' );
		}

		if ( $options['custom_campaign_name_query_param'] ) {
			$lines .= $this->line( '_paq.push(["setCampaignNameKey", ' . wp_json_encode( $options['custom_campaign_name_query_param'] ) . ']);' );
		}

		if ( $options['custom_campaign_keyword_param'] ) {
			$lines .= $this->line( '_paq.push(["setCampaignKeywordKey", ' . wp_json_encode( $options['custom_campaign_keyword_param'] ) . ']);' );
		}

		if ( $options['do_not_track'] ) {
			$lines .= $this->line( '_paq.push(["setDoNotTrack", true]);' );
		}

		$excluded_query_params = $this->to_list( $options['excluded_query_params'] );
		if ( $excluded_query_params ) {
			$lines .= $this->line( '_paq.push(["setExcludedQueryParams", ' . wp_json_encode( $excluded_query_params ) . ']);' );
		}

		$excluded_referrers = $this->to_list( $options['excluded_referrers'] );
		if ( $excluded_referrers ) {
			$lines .= $this->line( '_paq.push(["setExcludedReferrers", ' . wp_json_encode( $excluded_referrers ) . ']);' );
		}

		if ( $options['disable_cookies'] ) {
			$lines .= $this->line( '_paq.push(["disableCookies"]);' );
		}

		return $lines;
	}

	/**
	 * Build the tracker calls that name the domains that can be used to reach
	 * this site
	 *
	 * @param array $options tracking code options
	 * @return string lines of JavaScript, empty when Matomo named no usable URL
	 */
	private function get_domain_lines( $options ) {
		$hosts      = array();
		$first_host = null;

		foreach ( (array) $options['site_urls'] as $site_url ) {
			if ( empty( $site_url ) || ! is_string( $site_url ) ) {
				continue;
			}

			$parsed = wp_parse_url( $site_url );
			if ( ! is_array( $parsed ) ) {
				continue;
			}

			if ( null === $first_host && isset( $parsed['host'] ) ) {
				$first_host = self::strip_what_a_url_cannot_hold( $parsed['host'] );
			}

			$host = isset( $parsed['host'] ) ? $parsed['host'] : '';
			if ( ! empty( $parsed['path'] ) ) {
				$host .= $parsed['path'];
			}
			$host = self::strip_what_a_url_cannot_hold( $host );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		$lines = '';

		if ( $options['merge_subdomains'] && ! empty( $first_host ) ) {
			$lines .= $this->line( '_paq.push(["setCookieDomain", ' . wp_json_encode( '*.' . $first_host, JSON_UNESCAPED_SLASHES ) . ']);' );
		}

		if ( $options['merge_alias_urls'] && ! empty( $hosts ) ) {
			$wildcards = array();
			foreach ( $hosts as $host ) {
				$wildcards[] = '*.' . $host;
			}
			$lines .= $this->line( '_paq.push(["setDomains", ' . wp_json_encode( $wildcards, JSON_UNESCAPED_SLASHES ) . ']);' );
		}

		return $lines;
	}

	/**
	 * Get the URL the tracking code loads the tracker from
	 *
	 * @param string $matomo_url URL of the Matomo instance
	 * @return string protocol relative URL, empty when the Matomo URL names no server
	 */
	public static function get_tracker_url( $matomo_url ) {
		$host = self::get_tracker_host( $matomo_url );
		return '' === $host ? '' : '//' . $host . '/';
	}

	/**
	 * @param string $matomo_url URL of the Matomo instance
	 * @return string host and path, without a protocol or a trailing slash
	 */
	private static function get_tracker_host( $matomo_url ) {
		$matomo_url = self::normalize_url( $matomo_url );

		// check if the protocol is in the URL
		if ( ! preg_match( '~^([A-Za-z][A-Za-z0-9+.-]*)://(.*?)$~D', $matomo_url, $matches ) ) {
			return trim( $matomo_url, '/' );
		}

		// the tracker is loaded over the protocol of the page, so a Matomo reachable over
		// neither of the two the browser speaks has no host to name here
		if ( ! in_array( strtolower( $matches[1] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		// the browser skips extra slashes after the protocol as well, so
		// 'https:///stats.example.org' names stats.example.org
		return trim( $matches[2], '/' );
	}

	/**
	 * Drop from a URL every character a URL cannot hold.
	 *
	 * @param string $url the host and path part of a URL
	 * @return string the same, with everything outside the URL character set removed
	 */
	public static function strip_what_a_url_cannot_hold( $url ) {
		// the unreserved and reserved characters of RFC 3986, plus the percent sign of an
		// escape sequence, minus the apostrophe
		return preg_replace( '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&()*+,;=%]/', '', (string) $url );
	}

	/**
	 * Read a URL the way the tracking code writes it out: encoded the way a browser sends
	 * it, then stripped of every character a URL cannot hold.
	 *
	 * @param string $url URL as it was typed or stored
	 * @return string the same URL, empty when it names a host this server cannot write in ASCII
	 */
	public static function normalize_url( $url ) {
		$url = self::encode_what_a_url_cannot_hold( $url );
		return null === $url ? '' : self::strip_what_a_url_cannot_hold( $url );
	}

	/**
	 * Encode the characters of a URL a browser encodes before sending it: a host outside
	 * ASCII is written in its punycode form, and a space or a byte outside ASCII anywhere
	 * else is percent encoded.
	 *
	 * Dropping them the way strip_what_a_url_cannot_hold() does, can end up naming another
	 * server, eg, statistik.bücher.de would become statistik.bcher.de.
	 *
	 * @param string $url URL as it was typed or stored
	 * @return string|null the encoded URL, surrounding whitespace removed. null when it names
	 *                     a host outside ASCII that this server cannot write in ASCII.
	 */
	public static function encode_what_a_url_cannot_hold( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '/[ \x80-\xff]/', $url ) ) {
			return $url; // nothing a browser would encode
		}

		// the host follows the protocol and the slashes after it, and ends where the path,
		// the query or the fragment begins. a browser reads a backslash as a slash.
		preg_match( '~^((?:[A-Za-z][A-Za-z0-9+.\-]*:)?[/\\\\]*)([^/\\\\?#]*)(.*)$~sD', $url, $parts );
		list( , $before_host, $authority, $after_host ) = $parts;

		// a user name and password may precede the host
		$at       = strrpos( $authority, '@' );
		$userinfo = false === $at ? '' : substr( $authority, 0, $at + 1 );
		$host     = false === $at ? $authority : substr( $authority, $at + 1 );

		if ( preg_match( '/[\x80-\xff]/', $host ) ) {
			// a host outside ASCII holds no colon, so the first one begins the port
			preg_match( '/^([^:]*)(.*)$/sD', $host, $host_parts );
			$ascii_host = self::to_ascii_host( $host_parts[1] );
			if ( null === $ascii_host ) {
				return null;
			}
			$host = $ascii_host . $host_parts[2];
		}

		return $before_host . self::percent_encode( $userinfo ) . $host . self::percent_encode( $after_host );
	}

	/**
	 * Write a host name outside ASCII the way a browser resolves it
	 *
	 * @param string $host host name outside ASCII
	 * @return string|null the host in its punycode form, null when there is none
	 */
	public static function to_ascii_host( $host ) {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			// without the intl extension there is no way to write the host the way a browser does
			return null;
		}

		// the flags a browser reads a host with. without IDNA_NONTRANSITIONAL_TO_ASCII, an ICU
		// older than 76 maps characters a browser keeps, eg, faß.de would become fass.de, which
		// is another server.
		$ascii = idn_to_ascii(
			$host,
			IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
			INTL_IDNA_VARIANT_UTS46
		);
		return is_string( $ascii ) && '' !== $ascii ? $ascii : null;
	}

	/**
	 * @param string $value part of a URL
	 * @return string the same, with every space and byte outside ASCII percent encoded
	 */
	private static function percent_encode( $value ) {
		// only encoding specific characters
		return preg_replace_callback(
			'/[ \x80-\xff]/',
			function ( $matches ) {
				return rawurlencode( $matches[0] );
			},
			$value
		);
	}

	/**
	 * Read an option that takes either a comma separated string or an array
	 *
	 * @param mixed $value option value
	 * @return array the entries the value names
	 */
	private function to_list( $value ) {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}
		return explode( ',', $value );
	}

	/**
	 * Lay a tracker call out the way Matomo's tracking code template does.
	 *
	 * @param string $call the JavaScript to add
	 * @return string the indented, terminated line
	 */
	private function line( $call ) {
		return '  ' . $call . "\n";
	}
}
