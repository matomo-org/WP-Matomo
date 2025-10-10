<?php

namespace WP_Piwik;

/**
 * Manage WP-Piwik settings
 *
 * @author Andr&eacute; Br&auml;kling
 * @package WP_Piwik
 */
class Settings {

	/**
	 *
	 * @var Environment variables and default settings container
	 */
	private static $wpPiwik, $defaultSettings;

	/**
	 *
	 * @var Define callback functions for changed settings
	 */
	private $checkSettings = array (
			'piwik_url' => 'checkPiwikUrl',
			'piwik_token' => 'checkPiwikToken',
			'site_id' => 'requestPiwikSiteID',
			'tracking_code' => 'prepareTrackingCode',
			'noscript_code' => 'prepareNocscriptCode'
	);

	/**
	 *
	 * @var Register default configuration set
	 */
	private $globalSettings = array (
			// Plugin settings
			'revision' => 0,
			'last_settings_update' => 0,
			// User settings: Piwik configuration
			'piwik_mode' => 'http',
			'piwik_url' => '',
			'piwik_path' => '',
			'piwik_user' => '',
			'matomo_user' => '',
			'piwik_token' => '',
			'auto_site_config' => true,
			// User settings: Stats configuration
			'default_date' => 'yesterday',
			'stats_seo' => false,
            'stats_ecommerce' => false,
			'dashboard_widget' => false,
			'dashboard_ecommerce' => false,
			'dashboard_chart' => false,
			'dashboard_seo' => false,
			'toolbar' => false,
			'capability_read_stats' => array (
					'administrator' => true
			),
			'perpost_stats' => "disabled",
			'plugin_display_name' => 'Connect Matomo',
			'piwik_shortcut' => false,
			'shortcodes' => false,
			// User settings: Tracking configuration
			'track_mode' => 'disabled',
			'track_codeposition' => 'footer',
			'track_noscript' => false,
			'track_nojavascript' => false,
			'proxy_url' => '',
			'track_content' => 'disabled',
			'track_search' => false,
			'track_404' => false,
			'add_post_annotations' => array(),
			'add_customvars_box' => false,
			'add_download_extensions' => '',
			'set_download_extensions' => '',
			'set_link_classes' => '',
			'set_download_classes' => '',
            'require_consent' => 'disabled',
			'disable_cookies' => false,
			'limit_cookies' => false,
			'limit_cookies_visitor' => 34186669, // Piwik default 13 months
			'limit_cookies_session' => 1800, // Piwik default 30 minutes
			'limit_cookies_referral' => 15778463, // Piwik default 6 months
			'track_admin' => false,
			'capability_stealth' => array (),
			'track_across' => false,
			'track_across_alias' => false,
			'track_crossdomain_linking' => false,
			'track_feed' => false,
			'track_feed_addcampaign' => false,
			'track_feed_campaign' => 'feed',
			'track_heartbeat' => 0,
			'track_user_id' => 'disabled',
			// User settings: Expert configuration
			'cache' => true,
			'http_connection' => 'curl',
			'http_method' => 'post',
			'disable_timelimit' => false,
			'filter_limit' => '',
			'connection_timeout' => 5,
			'disable_ssl_verify' => false,
			'disable_ssl_verify_host' => false,
			'piwik_useragent' => 'php',
			'piwik_useragent_string' => 'WP-Piwik',
            'dnsprefetch' => false,
			'track_datacfasync' => false,
			'track_cdnurl' => '',
			'track_cdnurlssl' => '',
			'force_protocol' => 'disabled',
			'remove_type_attribute' => false,
			'update_notice' => 'enabled'
	), $settings = array (
			'name' => '',
			'site_id' => NULL,
			'noscript_code' => '',
			'tracking_code' => '',
			'last_tracking_code_update' => 0,
			'dashboard_revision' => 0
	), $settingsChanged = false;

	/**
	 * Constructor class to prepare settings manager
	 *
	 * @param WP_Piwik $wpPiwik
	 *        	active WP-Piwik instance
	 */
	public function __construct($wpPiwik) {
		self::$wpPiwik = $wpPiwik;
		self::$wpPiwik->log ( 'Store default settings' );
		self::$defaultSettings = array (
				'globalSettings' => $this->globalSettings,
				'settings' => $this->settings
		);
		self::$wpPiwik->log ( 'Load settings' );
		foreach ( $this->globalSettings as $key => $default ) {
			$this->globalSettings [$key] = ($this->checkNetworkActivation () ? get_site_option ( 'wp-piwik_global-' . $key, $default ) : get_option ( 'wp-piwik_global-' . $key, $default ));
		}
		foreach ( $this->settings as $key => $default )
			$this->settings [$key] = get_option ( 'wp-piwik-' . $key, $default );
	}

	/**
	 * Save all settings as WordPress options
	 */
	public function save() {
		if (! $this->settingsChanged) {
			self::$wpPiwik->log ( 'No settings changed yet' );
			return;
		}
		self::$wpPiwik->log ( 'Save settings' );
        $this->globalSettings['plugin_display_name'] = htmlspecialchars($this->globalSettings['plugin_display_name'], ENT_QUOTES, 'utf-8');
		foreach ( $this->globalSettings as $key => $value ) {
			if ( $this->checkNetworkActivation() )
				update_site_option ( 'wp-piwik_global-' . $key, $value );
			else
				update_option ( 'wp-piwik_global-' . $key, $value );
		}
		foreach ( $this->settings as $key => $value ) {
			update_option ( 'wp-piwik-' . $key, $value );
		}
		global $wp_roles;
		if (! is_object ( $wp_roles ))
			$wp_roles = new \WP_Roles ();
		if (! is_object ( $wp_roles ))
			die ( "STILL NO OBJECT" );
		foreach ( $wp_roles->role_names as $strKey => $strName ) {
			$objRole = get_role ( $strKey );
			foreach ( array (
					'stealth',
					'read_stats'
			) as $strCap ) {
				$aryCaps = $this->getGlobalOption ( 'capability_' . $strCap );
				if (isset ( $aryCaps [$strKey] ) && $aryCaps [$strKey])
					$wp_roles->add_cap ( $strKey, 'wp-piwik_' . $strCap );
				else $wp_roles->remove_cap ( $strKey, 'wp-piwik_' . $strCap );
			}
		}
		$this->settingsChanged = false;
	}

    /**
     * Get a global option's value which should not be empty
     *
     * @param string $key
     *        	option key
     * @return string option value
     */
    public function getNotEmptyGlobalOption($key) {
        return isset ( $this->globalSettings [$key] ) && !empty($this->globalSettings [$key]) ? $this->globalSettings [$key] : self::$defaultSettings ['globalSettings'] [$key];
    }

	/**
	 * Get a global option's value
	 *
	 * @param string $key
	 *        	option key
	 * @return string option value
	 */
	public function getGlobalOption($key) {
		return isset ( $this->globalSettings [$key] ) ? $this->globalSettings [$key] : self::$defaultSettings ['globalSettings'] [$key];
	}

	/**
	 * Get an option's value related to a specific blog
	 *
	 * @param string $key
	 *        	option key
	 * @param int $blogID
	 *        	blog ID (default: current blog)
	 * @return \WP_Piwik\Register
	 */
	public function getOption($key, $blogID = null) {
		if ($this->checkNetworkActivation () && ! empty ( $blogID )) {
			return get_blog_option ( $blogID, 'wp-piwik-'.$key );
		}
		return isset ( $this->settings [$key] ) ? $this->settings [$key] : self::$defaultSettings ['settings'] [$key];
	}

	/**
	 * Set a global option's value
	 *
	 * @param string $key
	 *        	option key
	 * @param string $value
	 *        	new option value
	 */
	public function setGlobalOption($key, $value) {
		$this->settingsChanged = true;
		self::$wpPiwik->log ( 'Changed global option ' . $key . ': ' . (is_array ( $value ) ? serialize ( $value ) : $value) );
		$this->globalSettings [$key] = $value;
	}

	/**
	 * Set an option's value related to a specific blog
	 *
	 * @param string $key
	 *        	option key
	 * @param string $value
	 *        	new option value
	 * @param int $blogID
	 *        	blog ID (default: current blog)
	 */
	public function setOption($key, $value, $blogID = null) {
		if (empty( $blogID )) {
			$blogID = get_current_blog_id();
		}
		$this->settingsChanged = true;
		self::$wpPiwik->log ( 'Changed option ' . $key . ': ' . $value );
		if ($this->checkNetworkActivation ()) {
			update_blog_option ( $blogID, 'wp-piwik-'.$key, $value );
		}
		if ($blogID == get_current_blog_id()) {
			$this->settings [$key] = $value;
		}
	}

	/**
	 * Reset settings to default
	 */
	public function resetSettings() {
		self::$wpPiwik->log ( 'Reset WP-Piwik settings' );
		global $wpdb;
		if ( $this->checkNetworkActivation() ) {
			$aryBlogs = self::getBlogList();
			if (is_array($aryBlogs))
				foreach ($aryBlogs as $aryBlog) {
                    switch_to_blog($aryBlog['blog_id']);
					$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'wp-piwik-%'");
					restore_current_blog();
				}
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE 'wp-piwik_global-%'");
		}
		else $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'wp-piwik_global-%'");
	}

	/**
	 * Get blog list
	 */
	public static function getBlogList($limit = null, $page = null, $search = '') {
		if ($limit && $page)
			$queryLimit = ' LIMIT '.(int) (($page - 1) * $limit).','.(int) $limit;
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare('SELECT blog_id FROM '.$wpdb->blogs.' WHERE CONCAT(domain, path) LIKE "%%%s%%" AND spam = 0 AND deleted = 0 ORDER BY blog_id'.$queryLimit, $search), ARRAY_A);
	}

	/**
	 * Check if plugin is network activated
	 *
	 * @return boolean Is network activated?
	 */
	public function checkNetworkActivation() {
		if (! function_exists ( "is_plugin_active_for_network" ))
			require_once (ABSPATH . 'wp-admin/includes/plugin.php');
		return is_plugin_active_for_network ( 'wp-piwik/wp-piwik.php' );
	}

	/**
	 * Apply new configuration
	 *
	 * @param array $in
	 *        	new configuration set
	 */
	public function applyChanges($in) {
		if (!self::$wpPiwik->isValidOptionsPost())
			die("Invalid config changes.");
		$in = $this->checkSettings ( $in );
		self::$wpPiwik->log ( 'Apply changed settings:' );
		foreach ( self::$defaultSettings ['globalSettings'] as $key => $val )
			$this->setGlobalOption ( $key, isset ( $in [$key] ) ? $in [$key] : $val );
		foreach ( self::$defaultSettings ['settings'] as $key => $val )
			$this->setOption ( $key, isset ( $in [$key] ) ? $in [$key] : $val );
		$this->setGlobalOption ( 'last_settings_update', time () );
		$this->save ();
	}

	/**
	 * Apply callback function on new settings
	 *
	 * @param array $in
	 *        	new configuration set
	 * @return array configuration set after callback functions were applied
	 */
	private function checkSettings($in) {
		foreach ( $this->checkSettings as $key => $value )
			if (isset ( $in [$key] ))
				$in [$key] = call_user_func_array ( array (
						$this,
						$value
				), array (
						$in [$key],
						$in
				) );
		return $in;
	}

	/**
	 * Add slash to Piwik URL if necessary
	 *
	 * @param string $value
	 *        	Piwik URL
	 * @param array $in
	 *        	configuration set
	 * @return string Piwik URL
	 */
	private function checkPiwikUrl($value, $in) {
		return substr ( $value, - 1, 1 ) != '/' ? $value . '/' : $value;
	}

	/**
	 * Remove &amp;token_auth= from auth token
	 *
	 * @param string $value
	 *        	Piwik auth token
	 * @param array $in
	 *        	configuration set
	 * @return string Piwik auth token
	 */
	private function checkPiwikToken($value, $in) {
		return str_replace ( '&token_auth=', '', $value );
	}

	/**
	 * Request the site ID (if not set before)
	 *
	 * @param string $value
	 *        	tracking code
	 * @param array $in
	 *        	configuration set
	 * @return int Piwik site ID
	 */
	private function requestPiwikSiteID($value, $in) {
		if ($in ['auto_site_config'] && ! $value)
			return self::$wpPiwik->getPiwikSiteId();
		return $value;
	}

	/**
	 * Prepare the tracking code
	 *
	 * @param string $value
	 *        	tracking code
	 * @param array $in
	 *        	configuration set
	 * @return string tracking code
	 */
	private function prepareTrackingCode($value, $in) {
		if ($in ['track_mode'] == 'manually' || $in ['track_mode'] == 'disabled') {
			$value = stripslashes ( $value );
			if ($this->checkNetworkActivation ())
				update_site_option ( 'wp-piwik-manually', $value );
			return $value;
		}
		/*$result = self::$wpPiwik->updateTrackingCode ();
		echo '<pre>'; print_r($result); echo '</pre>';
		$this->setOption ( 'noscript_code', $result ['noscript'] );*/
		return; // $result ['script'];
	}

	/**
	 * Prepare the nocscript code
	 *
	 * @param string $value
	 *        	noscript code
	 * @param array $in
	 *        	configuration set
	 * @return string noscript code
	 */
	private function prepareNocscriptCode($value, $in) {
		if ($in ['track_mode'] == 'manually')
			return stripslashes ( $value );
		return $this->getOption ( 'noscript_code' );
	}

	/**
	 * Get debug data
	 *
	 * @return array WP-Piwik settings for debug output
	 */
	public function getDebugData() {
		$debug = array(
			'global_settings' => $this->globalSettings,
			'settings' => $this->settings
		);
		$debug['global_settings']['piwik_token'] = !empty($debug['global_settings']['piwik_token'])?'set':'not set';
		return $debug;
	}
}
