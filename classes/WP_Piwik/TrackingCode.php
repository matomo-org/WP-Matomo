<?php

namespace WP_Piwik;

class TrackingCode {

	private static $wpPiwik, $piwikUrl = false;

	private $trackingCode;

	public $is404 = false, $isSearch = false, $isUsertracking = false;

	public function __construct($wpPiwik) {
		self::$wpPiwik = $wpPiwik;
		if (! self::$wpPiwik->isCurrentTrackingCode () || ! self::$wpPiwik->getOption ( 'tracking_code' ) || strpos( self::$wpPiwik->getOption ( 'tracking_code' ), '{"result":"error",' ) !== false )
			self::$wpPiwik->updateTrackingCode ();
		$this->trackingCode = (self::$wpPiwik->isNetworkMode () && self::$wpPiwik->getGlobalOption ( 'track_mode' ) == 'manually') ? get_site_option ( 'wp-piwik-manually' ) : self::$wpPiwik->getOption ( 'tracking_code' );
	}

	public function getTrackingCode() {
		if ($this->isUsertracking)
			$this->applyUserTracking ();
		if ($this->is404)
			$this->apply404Changes ();
		if ($this->isSearch)
			$this->applySearchChanges ();
		if (is_single () || is_page())
			$this->addCustomValues ();
		$this->trackingCode = apply_filters('wp-piwik_tracking_code', $this->trackingCode);
		return $this->trackingCode;
	}

	public static function prepareTrackingCode($code, $settings, $logger) {
		global $current_user;
		$logger->log ( 'Apply tracking code changes:' );
		$settings->setOption ( 'last_tracking_code_update', time () );
        if (preg_match ( '/var u="([^"]*)";/', $code, $hits )) {
            $fetchedProxyUrl = $hits [1];
        } else $fetchedProxyUrl = '';
        if ($settings->getGlobalOption ( 'remove_type_attribute')) {
            $code = str_replace (
                array( ' type="text/javascript"', " type='text/javascript'" ),
                '',
                $code
            );
        }
		if ($settings->getGlobalOption ( 'track_mode' ) == 'js')
			$code = str_replace ( array (
					'piwik.js',
					'piwik.php',
					'matomo.js',
					'matomo.php'
			), 'js/index.php', $code );
		elseif ($settings->getGlobalOption ( 'track_mode' ) == 'proxy') {
			$code = str_replace ( 'piwik.js', 'matomo.php', $code );
			$code = str_replace ( 'matomo.js', 'matomo.php', $code );
            $code = str_replace ( 'piwik.php', 'matomo.php', $code );
			$proxy = str_replace ( array (
					'https://',
					'http://'
			), '//', plugins_url ( 'wp-piwik' ) . '/proxy' ) . '/';
			$code = preg_replace ( '/var u="([^"]*)";/', 'var u="' . $proxy . '"', $code );
			$code = preg_replace ( '/img src="([^"]*)piwik.php/', 'img src="' . $proxy . 'matomo.php', $code );
			$code = preg_replace ( '/img src="([^"]*)matomo.php/', 'img src="' . $proxy . 'matomo.php', $code );
		}
		if ($settings->getGlobalOption ( 'track_cdnurl' ) || $settings->getGlobalOption ( 'track_cdnurlssl' ))
			$code = str_replace ( array (
					"var d=doc",
					"g.src=u+"
			), array (
					"var ucdn=(('https:' == document.location.protocol) ? 'https://" . ($settings->getGlobalOption ( 'track_cdnurlssl' ) ? $settings->getGlobalOption ( 'track_cdnurlssl' ) : $settings->getGlobalOption ( 'track_cdnurl' )) . "/' : 'http://" . ($settings->getGlobalOption ( 'track_cdnurl' ) ? $settings->getGlobalOption ( 'track_cdnurl' ) : $settings->getGlobalOption ( 'track_cdnurlssl' )) . "/');\nvar d=doc",
					"g.src=ucdn+"
			), $code );

		if ($settings->getGlobalOption ( 'track_datacfasync' ))
			$code = str_replace ( '<script type', '<script data-cfasync="false" type', $code );
		if ($settings->getGlobalOption ( 'set_download_extensions' ))
			$code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['setDownloadExtensions', '" . ($settings->getGlobalOption ( 'set_download_extensions' )) . "']);\n_paq.push(['trackPageView']);", $code );
		if ($settings->getGlobalOption ( 'add_download_extensions' ))
			$code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['addDownloadExtensions', '" . ($settings->getGlobalOption ( 'add_download_extensions' )) . "']);\n_paq.push(['trackPageView']);", $code );
        if ($settings->getGlobalOption ( 'set_download_classes' ))
            $code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['setDownloadClasses', '" . ($settings->getGlobalOption ( 'set_download_classes' )) . "']);\n_paq.push(['trackPageView']);", $code );
        if ($settings->getGlobalOption ( 'set_link_classes' ))
            $code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['setLinkClasses', '" . ($settings->getGlobalOption ( 'set_link_classes' )) . "']);\n_paq.push(['trackPageView']);", $code );
        if ($settings->getGlobalOption ( 'limit_cookies' ))
			$code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['setVisitorCookieTimeout', '" . $settings->getGlobalOption ( 'limit_cookies_visitor' ) . "']);\n_paq.push(['setSessionCookieTimeout', '" . $settings->getGlobalOption ( 'limit_cookies_session' ) . "']);\n_paq.push(['setReferralCookieTimeout', '" . $settings->getGlobalOption ( 'limit_cookies_referral' ) . "']);\n_paq.push(['trackPageView']);", $code );
		if ($settings->getGlobalOption ( 'force_protocol' ) != 'disabled')
			$code = str_replace ( '"//', '"' . $settings->getGlobalOption ( 'force_protocol' ) . '://', $code );
		if ($settings->getGlobalOption ( 'track_content' ) == 'all')
			$code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['trackPageView']);\n_paq.push(['trackAllContentImpressions']);", $code );
		elseif ($settings->getGlobalOption ( 'track_content' ) == 'visible')
			$code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['trackPageView']);\n_paq.push(['trackVisibleContentImpressions']);", $code );
		if ((int) $settings->getGlobalOption ( 'track_heartbeat' ) > 0)
			$code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['trackPageView']);\n_paq.push(['enableHeartBeatTimer', ".(int) $settings->getGlobalOption ( 'track_heartbeat' )."]);", $code );
        if ($settings->getGlobalOption ( 'require_consent' ) == 'consent') {
            $code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['requireConsent']);\n_paq.push(['trackPageView']);", $code );
        } elseif ($settings->getGlobalOption ( 'require_consent' ) == 'cookieconsent') {
            $code = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['requireCookieConsent']);\n_paq.push(['trackPageView']);", $code );
        }

		$noScript = array ();
		preg_match ( '/<noscript>(.*)<\/noscript>/', $code, $noScript );
		if (isset ( $noScript [0] )) {
			if ($settings->getGlobalOption ( 'track_nojavascript' ))
				$noScript [0] = str_replace ( '?idsite', '?rec=1&idsite', $noScript [0] );
			$noScript = $noScript [0];
		} else
			$noScript = '';
		$script = preg_replace ( '/<noscript>(.*)<\/noscript>/', '', $code );
		$script = preg_replace ( '/\s+(\r\n|\r|\n)/', '$1', $script );
		$logger->log ( 'Finished tracking code: ' . $script );
		$logger->log ( 'Finished noscript code: ' . $noScript );
		return array (
				'script' => $script,
				'noscript' => $noScript,
				'proxy' => $fetchedProxyUrl
		);
	}

	private function apply404Changes() {
		self::$wpPiwik->log ( 'Apply 404 changes. Blog ID: ' . get_current_blog_id () . ' Site ID: ' . self::$wpPiwik->getOption ( 'site_id' ) );
		$this->trackingCode = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['setDocumentTitle', '404/URL = '+String(document.location.pathname+document.location.search).replace(/\//g,'%2f') + '/From = ' + String(document.referrer).replace(/\//g,'%2f')]);\n_paq.push(['trackPageView']);", $this->trackingCode );
	}

	private function applySearchChanges() {
		global $wp_query;
		self::$wpPiwik->log ( 'Apply search tracking changes. Blog ID: ' . get_current_blog_id () . ' Site ID: ' . self::$wpPiwik->getOption ( 'site_id' ) );
		$intResultCount = $wp_query->found_posts;
		$this->trackingCode = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['trackSiteSearch','" . get_search_query () . "', false, " . $intResultCount . "]);\n_paq.push(['trackPageView']);", $this->trackingCode );
	}

	private function applyUserTracking() {
		$pkUserId = null;
		if (\is_user_logged_in()) {
			// Get the User ID Admin option, and the current user's data
			$uidFrom = self::$wpPiwik->getGlobalOption ( 'track_user_id' );
			$current_user = wp_get_current_user(); // current user
			// Get the user ID based on the admin setting
			if ( $uidFrom == 'uid' ) {
				$pkUserId = $current_user->ID;
			} elseif ( $uidFrom == 'email' ) {
				$pkUserId = $current_user->user_email;
			} elseif ( $uidFrom == 'username' ) {
				$pkUserId = $current_user->user_login;
			} elseif ( $uidFrom == 'displayname' ) {
				$pkUserId = $current_user->display_name;
			}
		}
		$pkUserId = apply_filters('wp-piwik_tracking_user_id', $pkUserId);
         	// Check we got a User ID to track, and track it
         	if ( isset( $pkUserId ) && ! empty( $pkUserId ))
			$this->trackingCode = str_replace ( "_paq.push(['trackPageView']);", "_paq.push(['setUserId', '" . esc_js( $pkUserId ) . "']);\n_paq.push(['trackPageView']);", $this->trackingCode );
	}
	
	private function addCustomValues() {
		$customVars = '';
		for($i = 1; $i <= 5; $i ++) {
			$postId = get_the_ID ();
			$metaKey = get_post_meta ( $postId, 'wp-piwik_custom_cat' . $i, true );
			$metaVal = get_post_meta ( $postId, 'wp-piwik_custom_val' . $i, true );
			if (! empty ( $metaKey ) && ! empty ( $metaVal ))
				$customVars .= "_paq.push(['setCustomVariable'," . $i . ", '" . $metaKey . "', '" . $metaVal . "', 'page']);\n";
		}
		if (! empty ( $customVars ))
			$this->trackingCode = str_replace ( "_paq.push(['trackPageView']);", $customVars . "_paq.push(['trackPageView']);", $this->trackingCode );
	}
}
