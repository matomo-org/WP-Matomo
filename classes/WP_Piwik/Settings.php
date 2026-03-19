<?php

namespace WP_Piwik;

/**
 * Manage WP-Piwik settings
 *
 * @author Andr&eacute; Br&auml;kling
 * @package WP_Piwik
 *
 * TODO: do not disable this at some point
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 */
class Settings {

	const TRACK_AI_BOTS           = 'track_ai_bots';
	const TRACK_AI_BOTS_USING_ESI = 'track_ai_bots_using_esi';

	/**
	 * @var \WP_Piwik variables and default settings container
	 */
	private static $wp_piwik;

	private static $default_settings;

	/**
	 *
	 * @var array Define callback functions for changed settings
	 */
	private $check_settings = array(
		'piwik_url'     => 'check_piwik_url',
		'piwik_token'   => 'check_piwik_token',
		'site_id'       => 'request_piwik_site_id',
		'tracking_code' => 'prepare_tracking_code',
		'noscript_code' => 'prepare_nocscript_code',
	);

	/**
	 * @var array default configuration set
	 */
	private $global_settings = array(
		// Plugin settings
		'revision'                    => 0,
		'last_settings_update'        => 0,
		// User settings: Piwik configuration
		'piwik_mode'                  => 'http',
		'piwik_url'                   => '',
		'piwik_path'                  => '',
		'piwik_user'                  => '',
		'matomo_user'                 => '',
		'piwik_token'                 => '',
		'auto_site_config'            => true,
		// User settings: Stats configuration
		'default_date'                => 'yesterday',
		'stats_seo'                   => false,
		'stats_ecommerce'             => false,
		'dashboard_widget'            => false,
		'dashboard_ecommerce'         => false,
		'dashboard_chart'             => false,
		'dashboard_seo'               => false,
		'toolbar'                     => false,
		'capability_read_stats'       => array(
			'administrator' => true,
		),
		'perpost_stats'               => 'disabled',
		'plugin_display_name'         => 'Connect Matomo',
		'piwik_shortcut'              => false,
		'shortcodes'                  => false,
		// User settings: Tracking configuration
		'track_mode'                  => 'disabled',
		'track_codeposition'          => 'footer',
		'track_noscript'              => false,
		'track_nojavascript'          => false,
		'proxy_url'                   => '',
		'track_content'               => 'disabled',
		'track_search'                => false,
		'track_404'                   => false,
		'add_post_annotations'        => array(),
		'add_customvars_box'          => false,
		'add_download_extensions'     => '',
		'set_download_extensions'     => '',
		'set_link_classes'            => '',
		'set_download_classes'        => '',
		'require_consent'             => 'disabled',
		'disable_cookies'             => false,
		'limit_cookies'               => false,
		'limit_cookies_visitor'       => 34186669, // Piwik default 13 months
		'limit_cookies_session'       => 1800, // Piwik default 30 minutes
		'limit_cookies_referral'      => 15778463, // Piwik default 6 months
		'track_admin'                 => false,
		'capability_stealth'          => array(),
		'track_across'                => false,
		'track_across_alias'          => false,
		'track_crossdomain_linking'   => false,
		'track_feed'                  => false,
		'track_feed_addcampaign'      => false,
		'track_feed_campaign'         => 'feed',
		'track_heartbeat'             => 0,
		'track_user_id'               => 'disabled',
		// User settings: Expert configuration
		'cache'                       => true,
		'http_connection'             => 'curl',
		'http_method'                 => 'post',
		'disable_timelimit'           => false,
		'filter_limit'                => '',
		'connection_timeout'          => 5,
		'disable_ssl_verify'          => false,
		'disable_ssl_verify_host'     => false,
		'piwik_useragent'             => 'php',
		'piwik_useragent_string'      => 'WP-Piwik',
		'dnsprefetch'                 => false,
		'track_datacfasync'           => false,
		'track_cdnurl'                => '',
		'track_cdnurlssl'             => '',
		'force_protocol'              => 'disabled',
		'remove_type_attribute'       => false,
		'update_notice'               => 'enabled',

		self::TRACK_AI_BOTS           => false,
		self::TRACK_AI_BOTS_USING_ESI => false,
	);

	private $settings = array(
		'name'                      => '',
		'site_id'                   => null,
		'noscript_code'             => '',
		'tracking_code'             => '',
		'last_tracking_code_update' => 0,
		'dashboard_revision'        => 0,
	);

	private $settings_changed = false;

	/**
	 * Constructor class to prepare settings manager
	 *
	 * @param \WP_Piwik $wp_piwik
	 *          active WP-Piwik instance
	 */
	public function __construct( $wp_piwik ) {
		self::$wp_piwik = $wp_piwik;
		self::$wp_piwik->log( 'Store default settings' );
		self::$default_settings = array(
			'globalSettings' => $this->global_settings,
			'settings'       => $this->settings,
		);
		self::$wp_piwik->log( 'Load settings' );
		foreach ( $this->global_settings as $key => $default ) {
			$this->global_settings [ $key ] = ( $this->check_network_activation() ? get_site_option( 'wp-piwik_global-' . $key, $default ) : get_option( 'wp-piwik_global-' . $key, $default ) );
		}
		foreach ( $this->settings as $key => $default ) {
			$this->settings [ $key ] = get_option( 'wp-piwik-' . $key, $default );
		}
	}

	/**
	 * Save all settings as WordPress options
	 */
	public function save() {
		global $wp_roles;

		if ( ! $this->settings_changed ) {
			self::$wp_piwik->log( 'No settings changed yet' );
			return;
		}
		self::$wp_piwik->log( 'Save settings' );
		$this->global_settings['plugin_display_name'] = htmlspecialchars( $this->global_settings['plugin_display_name'], ENT_QUOTES, 'utf-8' );
		foreach ( $this->global_settings as $key => $value ) {
			if ( $this->check_network_activation() ) {
				update_site_option( 'wp-piwik_global-' . $key, $value );
			} else {
				update_option( 'wp-piwik_global-' . $key, $value );
			}
		}
		foreach ( $this->settings as $key => $value ) {
			update_option( 'wp-piwik-' . $key, $value );
		}
		foreach ( $wp_roles->role_names as $str_key => $str_name ) {
			$obj_role = get_role( $str_key );
			$caps     = array( 'stealth', 'read_stats' );
			foreach ( $caps as $str_cap ) {
				$ary_caps = $this->get_global_option( 'capability_' . $str_cap );
				if ( isset( $ary_caps [ $str_key ] ) && $ary_caps [ $str_key ] ) {
					$wp_roles->add_cap( $str_key, 'wp-piwik_' . $str_cap );
				} else {
					$wp_roles->remove_cap( $str_key, 'wp-piwik_' . $str_cap );
				}
			}
		}
		$this->settings_changed = false;
	}

	/**
	 * Get a global option's value which should not be empty
	 *
	 * @param string $key
	 *          option key
	 * @return string option value
	 */
	public function get_not_empty_global_option( $key ) {
		return isset( $this->global_settings [ $key ] ) && ! empty( $this->global_settings [ $key ] ) ? $this->global_settings [ $key ] : self::$default_settings ['globalSettings'] [ $key ];
	}

	/**
	 * Get a global option's value
	 *
	 * @param string $key
	 *          option key
	 * @return mixed option value
	 */
	public function get_global_option( $key ) {
		return isset( $this->global_settings [ $key ] ) ? $this->global_settings [ $key ] : self::$default_settings ['globalSettings'] [ $key ];
	}

	/**
	 * Get an option's value related to a specific blog
	 *
	 * @param string $key
	 *          option key
	 * @param int    $blog_id
	 *          blog ID (default: current blog)
	 * @return mixed
	 */
	public function get_option( $key, $blog_id = null ) {
		if ( $this->check_network_activation() && ! empty( $blog_id ) ) {
			return get_blog_option( $blog_id, 'wp-piwik-' . $key );
		}
		return isset( $this->settings [ $key ] ) ? $this->settings [ $key ] : self::$default_settings ['settings'] [ $key ];
	}

	/**
	 * Set a global option's value
	 *
	 * @param string $key
	 *          option key
	 * @param mixed  $value
	 *          new option value
	 */
	public function set_global_option( $key, $value ) {
		$this->settings_changed = true;
		self::$wp_piwik->log( 'Changed global option ' . $key . ': ' . ( is_array( $value ) ? wp_json_encode( $value ) : $value ) );
		$this->global_settings [ $key ] = $value;
	}

	/**
	 * Set an option's value related to a specific blog
	 *
	 * @param string $key
	 *          option key
	 * @param string $value
	 *          new option value
	 * @param int    $blog_id
	 *          blog ID (default: current blog)
	 */
	public function set_option( $key, $value, $blog_id = null ) {
		if ( empty( $blog_id ) ) {
			$blog_id = get_current_blog_id();
		}
		$this->settings_changed = true;
		self::$wp_piwik->log( 'Changed option ' . $key . ': ' . $value );
		if ( $this->check_network_activation() ) {
			update_blog_option( $blog_id, 'wp-piwik-' . $key, $value );
		}
		if ( get_current_blog_id() === $blog_id ) {
			$this->settings [ $key ] = $value;
		}
	}

	/**
	 * Reset settings to default
	 */
	public function reset_settings() {
		self::$wp_piwik->log( 'Reset WP-Piwik settings' );
		global $wpdb;
		if ( $this->check_network_activation() ) {
			$ary_blogs = self::get_blog_list();
			if ( is_array( $ary_blogs ) ) {
				foreach ( $ary_blogs as $ary_blog ) {
					switch_to_blog( $ary_blog['blog_id'] );
					$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wp-piwik-%'" );
					restore_current_blog();
				}
			}
			$wpdb->query( "DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE 'wp-piwik_global-%'" );
		} else {
			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wp-piwik_global-%'" );
		}
	}

	/**
	 * Get blog list
	 */
	public static function get_blog_list( $limit = null, $page = null, $search = '' ) {
		global $wpdb;

		$query_limit = '';
		if ( $limit && $page ) {
			$query_limit = ' LIMIT ' . (int) ( ( $page - 1 ) * $limit ) . ',' . (int) $limit;
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT blog_id FROM %s WHERE CONCAT(domain, path) LIKE %s AND spam = 0 AND deleted = 0 ORDER BY blog_id' . $query_limit, $wpdb->blogs, $like ), ARRAY_A );
	}

	/**
	 * Check if plugin is network activated
	 *
	 * @return boolean Is network activated?
	 */
	public function check_network_activation() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( 'wp-piwik/wp-piwik.php' );
	}

	/**
	 * Apply new configuration
	 *
	 * @param array $in
	 *          new configuration set
	 */
	public function apply_changes( $in ) {
		if ( ! self::$wp_piwik->is_valid_options_post() ) {
			die( 'Invalid config changes.' );
		}
		$in = $this->check_settings( $in );
		self::$wp_piwik->log( 'Apply changed settings:' );
		foreach ( self::$default_settings ['globalSettings'] as $key => $val ) {
			$this->set_global_option( $key, isset( $in [ $key ] ) ? $in [ $key ] : $val );
		}
		foreach ( self::$default_settings ['settings'] as $key => $val ) {
			$this->set_option( $key, isset( $in [ $key ] ) ? $in [ $key ] : $val );
		}
		$this->set_global_option( 'last_settings_update', (string) time() );
		$this->save();
	}

	/**
	 * Apply callback function on new settings
	 *
	 * @param array $in new configuration set
	 * @return array configuration set after callback functions were applied
	 */
	private function check_settings( $in ) {
		foreach ( $this->check_settings as $key => $value ) {
			if ( isset( $in [ $key ] ) ) {
				$in [ $key ] = call_user_func_array(
					array(
						$this,
						$value,
					),
					array(
						$in [ $key ],
						$in,
					)
				);
			}
		}
		return $in;
	}

	/**
	 * Add slash to Piwik URL if necessary
	 *
	 * @param string $value
	 *          Piwik URL
	 * @return string Piwik URL
	 * @phpstan-ignore method.unused
	 */
	private function check_piwik_url( $value ) {
		return substr( $value, - 1, 1 ) !== '/' ? $value . '/' : $value;
	}

	/**
	 * Remove &amp;token_auth= from auth token
	 *
	 * @param string $value
	 *          Piwik auth token
	 * @return string Piwik auth token
	 * @phpstan-ignore method.unused
	 */
	private function check_piwik_token( $value ) {
		return str_replace( '&token_auth=', '', $value );
	}

	/**
	 * Request the site ID (if not set before)
	 *
	 * @param string|int $value
	 *          site ID setting value
	 * @param array      $in
	 *          configuration set
	 * @return int Piwik site ID
	 * @phpstan-ignore method.unused
	 */
	private function request_piwik_site_id( $value, $in ) {
		if ( $in ['auto_site_config'] && ! $value ) {
			return self::$wp_piwik->get_piwik_site_id();
		}
		return intval( $value );
	}

	/**
	 * Prepare the tracking code
	 *
	 * @param string $value
	 *          tracking code
	 * @param array  $in
	 *          configuration set
	 * @return string tracking code
	 * @phpstan-ignore method.unused
	 */
	private function prepare_tracking_code( $value, $in ) {
		if ( 'manually' === $in['track_mode'] || 'disabled' === $in['track_mode'] ) {
			$value = stripslashes( $value );
			if ( $this->check_network_activation() ) {
				update_site_option( 'wp-piwik-manually', $value );
			}
			return $value;
		}

		return '';
	}

	/**
	 * Prepare the nocscript code
	 *
	 * @param string $value
	 *          noscript code
	 * @param array  $in
	 *          configuration set
	 * @return string noscript code
	 * @phpstan-ignore method.unused
	 */
	private function prepare_nocscript_code( $value, $in ) {
		if ( 'manually' === $in['track_mode'] ) {
			return stripslashes( $value );
		}
		return $this->get_option( 'noscript_code' );
	}

	/**
	 * Get debug data
	 *
	 * @return array WP-Piwik settings for debug output
	 */
	public function get_debug_data() {
		$debug                                   = array(
			'global_settings' => $this->global_settings,
			'settings'        => $this->settings,
		);
		$debug['global_settings']['piwik_token'] = ! empty( $debug['global_settings']['piwik_token'] ) ? 'set' : 'not set';
		return $debug;
	}

	public function is_ai_bot_tracking_enabled() {
		return (bool) $this->get_global_option( self::TRACK_AI_BOTS );
	}

	public function is_ai_bot_tracking_enabled_via_esi_includes() {
		return (bool) $this->get_global_option( self::TRACK_AI_BOTS_USING_ESI );
	}

	public function is_track_via_esi_enabled() {
		return true === (bool) $this->get_global_option( 'track_ai_bots_using_esi' );
	}

	public function get_matomo_url() {
		if ( 'http' === $this->get_global_option( 'piwik_mode' ) ) {
			return $this->get_global_option( 'piwik_url' );
		}

		if ( 'cloud' === $this->get_global_option( 'piwik_mode' ) ) {
			return 'https://' . $this->get_global_option( 'piwik_user' ) . '.innocraft.cloud/';
		}

		return 'https://' . $this->get_global_option( 'matomo_user' ) . '.matomo.cloud/';
	}

	public function is_tracking_enabled() {
		return 'disabled' !== $this->get_global_option( 'track_mode' );
	}
}
