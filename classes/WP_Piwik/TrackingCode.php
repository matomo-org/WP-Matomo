<?php

namespace WP_Piwik;

class TrackingCode {

	/**
	 * @var \WP_Piwik
	 */
	private static $wp_piwik;

	private $tracking_code;

	public $is_404          = false;
	public $is_search       = false;
	public $is_usertracking = false;

	public function __construct( $wp_piwik ) {
		self::$wp_piwik = $wp_piwik;
		if ( ! self::$wp_piwik->is_current_tracking_code() || ! self::$wp_piwik->get_option( 'tracking_code' ) || strpos( self::$wp_piwik->get_option( 'tracking_code' ), '{"result":"error",' ) !== false ) {
			self::$wp_piwik->update_tracking_code();
		}
		$this->tracking_code = ( self::$wp_piwik->is_network_mode() && self::$wp_piwik->get_global_option( 'track_mode' ) === 'manually' ) ? get_site_option( 'wp-piwik-manually' ) : self::$wp_piwik->get_option( 'tracking_code' );
	}

	public function get_tracking_code() {
		if ( $this->is_usertracking ) {
			$this->apply_user_tracking();
		}
		if ( $this->is_404 ) {
			$this->apply_404_changes();
		}
		if ( $this->is_search ) {
			$this->apply_search_changes();
		}
		if ( is_single() || is_page() ) {
			$this->add_custom_values();
		}
		// ignoring for BC
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->tracking_code = apply_filters( 'wp-piwik_tracking_code', $this->tracking_code );
		return $this->tracking_code;
	}

	/**
	 * @param string   $code
	 * @param Settings $settings
	 * @param Logger   $logger
	 * @return array
	 */
	public static function prepare_tracking_code( $code, $settings, $logger ) {
		global $current_user;
		$logger->log( 'Apply tracking code changes:' );
		$settings->set_option( 'last_tracking_code_update', (string) time() );
		if ( preg_match( '/var u="([^"]*)";/', $code, $hits ) ) {
			$fetched_proxy_url = $hits [1];
		} else {
			$fetched_proxy_url = '';
		}
		if ( $settings->get_global_option( 'remove_type_attribute' ) ) {
			$code = str_replace(
				array( ' type="text/javascript"', " type='text/javascript'" ),
				'',
				$code
			);
		}
		if ( 'js' === $settings->get_global_option( 'track_mode' ) ) {
			$code = str_replace(
				array(
					'piwik.js',
					'piwik.php',
					'matomo.js',
					'matomo.php',
				),
				'js/index.php',
				$code
			);
		} elseif ( 'proxy' === $settings->get_global_option( 'track_mode' ) ) {
			$code  = str_replace( 'piwik.js', 'matomo.php', $code );
			$code  = str_replace( 'matomo.js', 'matomo.php', $code );
			$code  = str_replace( 'piwik.php', 'matomo.php', $code );
			$proxy = str_replace(
				array(
					'https://',
					'http://',
				),
				'//',
				plugins_url( 'wp-piwik' ) . '/proxy'
			) . '/';
			$code  = preg_replace( '/var u="([^"]*)";/', 'var u="' . $proxy . '"', $code );
			$code  = preg_replace(
				'/(<img\s[^>]*?src=")[^"]*(?:piwik|matomo)\.php/',
				'${1}' . $proxy . 'matomo.php',
				$code
			);
		}
		$cdn_url     = self::strip_what_the_html_parser_reads( $settings->get_global_option( 'track_cdnurl' ) );
		$cdn_url_ssl = self::strip_what_the_html_parser_reads( $settings->get_global_option( 'track_cdnurlssl' ) );
		if ( $cdn_url || $cdn_url_ssl ) {
			$secure_url   = wp_json_encode( 'https://' . ( $cdn_url_ssl ? $cdn_url_ssl : $cdn_url ) . '/' );
			$insecure_url = wp_json_encode( 'http://' . ( $cdn_url ? $cdn_url : $cdn_url_ssl ) . '/' );

			$code = str_replace(
				array(
					'var d=doc',
					'g.src=u+',
				),
				array(
					"var ucdn=(('https:' == document.location.protocol) ? " . $secure_url . ' : ' . $insecure_url . ");\nvar d=doc",
					'g.src=ucdn+',
				),
				$code
			);
		}

		if ( $settings->get_global_option( 'track_datacfasync' ) ) {
			$code = str_replace( '<script', '<script data-cfasync="false"', $code );
		}

		if ( $settings->is_ai_bot_tracking_enabled() ) {
			// recMode is a temporary parameter introduced in core to conditionally
			// enable AI bot tracking. if AI bot tracking is enabled in Connect Matomo,
			// we set it to `2` here, to enable "auto" mode when doing JS tracking. in
			// this mode, tracking requests with AI bot user agents will be tracked as
			// bots instead of visits, while all other requests will be tracked normally
			// as visits.
			$code = str_replace(
				"_paq.push(['trackPageView']);",
				"_paq.push(['appendToTrackingUrl', 'recMode=2']);\n_paq.push(['trackPageView']);",
				$code
			);

			// set cookie via javascript cookie for known AI bots so we can skip tracking server side
			// for them.
			// NOTE: this must be done ONLY for known AI bots to be compliant with privacy regulations.
			$user_agent_substrings = wp_json_encode( AjaxTracker::AI_BOT_USER_AGENT_SUBSTRINGS );

			$cookie_set_fn = <<<EOF
_paq.push([ function () {
  var userAgentSubstrings = $user_agent_substrings;
  for (var i = 0; i < userAgentSubstrings.length; ++i) {
  	var isAiBotUserAgent = navigator.userAgent.toLowerCase().indexOf(userAgentSubstrings[i].toLowerCase()) !== -1;
  	if (isAiBotUserAgent) {
      var path = this.getCookiePath();
      var domain = this.getCookieDomain();
      var sameSite = 'Lax';
      document.cookie = 'matomo_has_js=1;path=' +
      	(path || '/') +
      	(domain ? ';domain=' + domain : '') +
		';SameSite=' + sameSite
		;
  	  return;
  	}
  }
} ]);
EOF;

			$code = str_replace(
				"_paq.push(['trackPageView']);",
				$cookie_set_fn . "\n_paq.push(['trackPageView']);",
				$code
			);
		}

		if ( $settings->get_global_option( 'set_download_extensions' ) ) {
			$set_download_extensions = self::strip_what_the_html_parser_reads(
				$settings->get_global_option( 'set_download_extensions' )
			);
			$code                    = str_replace(
				"_paq.push(['trackPageView']);",
				"_paq.push(['setDownloadExtensions', "
					. wp_json_encode( $set_download_extensions )
					. "]);\n_paq.push(['trackPageView']);",
				$code
			);
		}
		if ( $settings->get_global_option( 'add_download_extensions' ) ) {
			$add_download_extensions = self::strip_what_the_html_parser_reads(
				$settings->get_global_option( 'add_download_extensions' )
			);
			$code                    = str_replace(
				"_paq.push(['trackPageView']);",
				"_paq.push(['addDownloadExtensions', "
					. wp_json_encode( $add_download_extensions )
					. "]);\n_paq.push(['trackPageView']);",
				$code
			);
		}
		if ( $settings->get_global_option( 'set_download_classes' ) ) {
			$set_download_classes = self::strip_what_the_html_parser_reads(
				$settings->get_global_option( 'set_download_classes' )
			);
			$code                 = str_replace(
				"_paq.push(['trackPageView']);",
				"_paq.push(['setDownloadClasses', "
					. wp_json_encode( $set_download_classes )
					. "]);\n_paq.push(['trackPageView']);",
				$code
			);
		}
		if ( $settings->get_global_option( 'set_link_classes' ) ) {
			$set_link_classes = self::strip_what_the_html_parser_reads( $settings->get_global_option( 'set_link_classes' ) );
			$code             = str_replace(
				"_paq.push(['trackPageView']);",
				"_paq.push(['setLinkClasses', "
					. wp_json_encode( $set_link_classes )
					. "]);\n_paq.push(['trackPageView']);",
				$code
			);
		}
		if ( $settings->get_global_option( 'limit_cookies' ) ) {
			$code = str_replace(
				"_paq.push(['trackPageView']);",
				"_paq.push(['setVisitorCookieTimeout', "
					. (int) $settings->get_global_option( 'limit_cookies_visitor' )
					. "]);\n_paq.push(['setSessionCookieTimeout', "
					. (int) $settings->get_global_option( 'limit_cookies_session' )
					. "]);\n_paq.push(['setReferralCookieTimeout', "
					. (int) $settings->get_global_option( 'limit_cookies_referral' )
					. "]);\n_paq.push(['trackPageView']);",
				$code
			);
		}
		// only add the protocol if it's one of the ones the settings page offers
		$force_protocol = $settings->get_global_option( 'force_protocol' );
		if (
			'disabled' !== $force_protocol
			&& in_array( $force_protocol, array_keys( $settings->get_force_protocol_options() ), true )
		) {
			$code = str_replace( '"//', '"' . $force_protocol . '://', $code );
		}
		if ( 'all' === $settings->get_global_option( 'track_content' ) ) {
			$code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['trackPageView']);\n_paq.push(['trackAllContentImpressions']);", $code );
		} elseif ( 'visible' === $settings->get_global_option( 'track_content' ) ) {
			$code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['trackPageView']);\n_paq.push(['trackVisibleContentImpressions']);", $code );
		}
		if ( (int) $settings->get_global_option( 'track_heartbeat' ) > 0 ) {
			$code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['trackPageView']);\n_paq.push(['enableHeartBeatTimer', " . (int) $settings->get_global_option( 'track_heartbeat' ) . ']);', $code );
		}
		if ( 'consent' === $settings->get_global_option( 'require_consent' ) ) {
			$code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['requireConsent']);\n_paq.push(['trackPageView']);", $code );
		} elseif ( 'cookieconsent' === $settings->get_global_option( 'require_consent' ) ) {
			$code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['requireCookieConsent']);\n_paq.push(['trackPageView']);", $code );
		}

		$no_script = array();
		preg_match( '/<noscript>(.*)<\/noscript>/', $code, $no_script );
		if ( isset( $no_script [0] ) ) {
			if ( $settings->get_global_option( 'track_nojavascript' ) ) {
				$no_script [0] = str_replace( '?idsite', '?rec=1&idsite', $no_script [0] );
			}
			$no_script = $no_script [0];
		} else {
			$no_script = '';
		}
		$script = preg_replace( '/<noscript>(.*)<\/noscript>/', '', $code );
		$script = preg_replace( '/\s+(\r\n|\r|\n)/', '$1', $script );
		$logger->log( 'Finished tracking code: ' . $script );
		$logger->log( 'Finished noscript code: ' . $no_script );
		return array(
			'script'   => $script,
			'noscript' => $no_script,
			'proxy'    => $fetched_proxy_url,
		);
	}

	/**
	 * Drop the angle brackets from a value written into the tracking code
	 *
	 * JSON encoding keeps a value inside the JavaScript string it stands in, but the HTML
	 * parser reads the script element before JavaScript ever sees it: a '<!--' followed by
	 * a '<script' makes the rest of the page part of the script.
	 *
	 * @param mixed $value value to write into the tracking code
	 * @return string the same value, without the characters the HTML parser acts on
	 */
	private static function strip_what_the_html_parser_reads( $value ) {
		return str_replace( array( '<', '>' ), '', (string) $value );
	}

	private function apply_404_changes() {
		self::$wp_piwik->log( 'Apply 404 changes. Blog ID: ' . get_current_blog_id() . ' Site ID: ' . self::$wp_piwik->get_option( 'site_id' ) );
		$this->tracking_code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['setDocumentTitle', '404/URL = '+String(document.location.pathname+document.location.search).replace(/\//g,'%2f') + '/From = ' + String(document.referrer).replace(/\//g,'%2f')]);\n_paq.push(['trackPageView']);", $this->tracking_code );
	}

	private function apply_search_changes() {
		global $wp_query;
		self::$wp_piwik->log( 'Apply search tracking changes. Blog ID: ' . get_current_blog_id() . ' Site ID: ' . self::$wp_piwik->get_option( 'site_id' ) );
		$int_result_count    = (int) $wp_query->found_posts;
		$search_query        = esc_js( get_search_query( false ) );
		$this->tracking_code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['trackSiteSearch','" . $search_query . "', false, " . $int_result_count . "]);\n_paq.push(['trackPageView']);", $this->tracking_code );
	}

	private function apply_user_tracking() {
		$pk_user_id = null;
		if ( \is_user_logged_in() ) {
			// Get the User ID Admin option, and the current user's data
			$uid_from     = self::$wp_piwik->get_global_option( 'track_user_id' );
			$current_user = wp_get_current_user(); // current user
			// Get the user ID based on the admin setting
			if ( 'uid' === $uid_from ) {
				$pk_user_id = $current_user->ID;
			} elseif ( 'email' === $uid_from ) {
				$pk_user_id = $current_user->user_email;
			} elseif ( 'username' === $uid_from ) {
				$pk_user_id = $current_user->user_login;
			} elseif ( 'displayname' === $uid_from ) {
				$pk_user_id = $current_user->display_name;
			}
		}
		// ignoring for BC
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$pk_user_id = apply_filters( 'wp-piwik_tracking_user_id', $pk_user_id );
		// Check we got a User ID to track, and track it
		if ( isset( $pk_user_id ) && ! empty( $pk_user_id ) ) {
			$this->tracking_code = str_replace( "_paq.push(['trackPageView']);", "_paq.push(['setUserId', '" . esc_js( $pk_user_id ) . "']);\n_paq.push(['trackPageView']);", $this->tracking_code );
		}
	}

	private function add_custom_values() {
		$custom_vars = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$post_id  = get_the_ID();
			$meta_key = get_post_meta( $post_id, 'wp-piwik_custom_cat' . $i, true );
			$meta_val = get_post_meta( $post_id, 'wp-piwik_custom_val' . $i, true );
			if ( ! empty( $meta_key ) && ! empty( $meta_val ) ) {
				$custom_vars .= "_paq.push(['setCustomVariable'," . $i . ', '
					. wp_json_encode( self::strip_what_the_html_parser_reads( $meta_key ) ) . ', '
					. wp_json_encode( self::strip_what_the_html_parser_reads( $meta_val ) )
					. ", 'page']);\n";
			}
		}
		if ( ! empty( $custom_vars ) ) {
			$this->tracking_code = str_replace( "_paq.push(['trackPageView']);", $custom_vars . "_paq.push(['trackPageView']);", $this->tracking_code );
		}
	}
}
