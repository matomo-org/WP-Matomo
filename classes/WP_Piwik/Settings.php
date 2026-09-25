<?php

namespace WP_Piwik;

use WP_Piwik\Settings\SaveFailure;

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
	 * The cookie allowlist is allowed to contain valid cookie characters (see
	 * https://www.ietf.org/archive/id/draft-ietf-httpbis-rfc6265bis-10.html) and
	 * an optional trailing '*' wildcard.
	 *
	 * Note: a single '*' is not allowed, as it would allow everything. The setting
	 * should simply be unset if this is desired.
	 */
	const COOKIE_ALLOWLIST_ENTRY_PATTERN = '/^[A-Za-z0-9!#$%&\'+\-.^_`|~]+\*?$/';

	/**
	 * Bounds on the cookie allow list meant to make sure the value length stays
	 * sane.
	 */
	const COOKIE_ALLOWLIST_MAX_ENTRIES      = 50;
	const COOKIE_ALLOWLIST_MAX_ENTRY_LENGTH = 128;
	const COOKIE_ALLOWLIST_MAX_LENGTH       = 4096;

	/**
	 * Network transient holding the blog IDs whose tracking code is entered manually.
	 *
	 * @see \WP_Piwik::show_manual_tracking_review_notice()
	 */
	const MANUAL_TRACKING_SITES_CACHE = 'wp-piwik-manual_tracking_sites';

	/**
	 * Per site option naming the version of Connect Matomo that was running when this site
	 * could first hold a tracking code: the version the site was created at, or the one it
	 * installed the plugin at.
	 *
	 * Every version it can name required unfiltered_html to enter a tracking code by hand,
	 * so a site carrying it has nothing for the manual tracking review to look at.
	 */
	const SITE_CREATED_VERSION_OPTION = 'wp-piwik-site_created_version';

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
		// the tracking code callbacks below branch on the track mode, so it has to be
		// checked first
		'track_mode'              => 'check_track_mode',
		'piwik_url'               => 'check_piwik_url',
		'piwik_user'              => 'check_cloud_subdomain',
		'matomo_user'             => 'check_cloud_subdomain',
		'piwik_token'             => 'check_piwik_token',
		'site_id'                 => 'request_piwik_site_id',
		'tracking_code'           => 'prepare_tracking_code',
		'noscript_code'           => 'prepare_nocscript_code',
		'cookie_allowlist'        => 'check_cookie_allowlist',
		'piwik_mode'              => 'check_piwik_mode',
		'force_protocol'          => 'check_force_protocol',
		'plugin_display_name'     => 'prepare_plugin_display_name',
		'track_cdnurl'            => 'check_cdn_url',
		'track_cdnurlssl'         => 'check_cdn_url',
		'set_download_extensions' => 'check_tracking_code_list',
		'add_download_extensions' => 'check_tracking_code_list',
		'set_download_classes'    => 'check_tracking_code_list',
		'set_link_classes'        => 'check_tracking_code_list',
	);

	/**
	 * @var array default configuration set
	 */
	private $global_settings = array(
		// Plugin settings
		'revision'                    => 0,
		// every version that ran, oldest first. empty for an install whose last version
		// was 1.1.12 or older, none of which recorded one.
		'version_history'             => array(),
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
		'shortcode_author_check'      => true,
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
		'cookie_allowlist'            => '', // proxy cookie allow list; empty = off (proxy forwards all non-WP cookies)
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
	 * @var array<int, SaveFailure> values the last configuration save attempt did not store
	 *                              as they were submitted.
	 */
	private $save_failures = [];

	/**
	 * @var TrackerHosts
	 */
	private $tracker_hosts;

	/**
	 * Constructor class to prepare settings manager
	 *
	 * @param \WP_Piwik $wp_piwik
	 *          active WP-Piwik instance
	 */
	public function __construct( $wp_piwik ) {
		self::$wp_piwik      = $wp_piwik;
		$this->tracker_hosts = new TrackerHosts();
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
			// note: using wp_roles()->add_cap/remove_cap does not affect capabilities cached
			// in WP_Role objects, so the role object needs to be used directly.
			$obj_role = get_role( $str_key );
			if ( ! $obj_role ) { // sanity check
				continue;
			}

			$caps = array( 'stealth', 'read_stats' );
			foreach ( $caps as $str_cap ) {
				$ary_caps = $this->get_global_option( 'capability_' . $str_cap );
				if ( isset( $ary_caps [ $str_key ] ) && $ary_caps [ $str_key ] ) {
					$obj_role->add_cap( 'wp-piwik_' . $str_cap );
				} else {
					$obj_role->remove_cap( 'wp-piwik_' . $str_cap );
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE CONCAT(domain, path) LIKE %s AND spam = 0 AND deleted = 0 ORDER BY blog_id" . $query_limit, $like ), ARRAY_A );
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
		// make sure the version history does not change
		$version_history = $this->get_global_option( 'version_history' );

		// ensure a value for piwik_mode is set so that the check_settings() call below
		// will validate any associated Matomo URLs properly
		if ( ! isset( $in['piwik_mode'] ) ) {
			$in['piwik_mode'] = self::$default_settings['globalSettings']['piwik_mode'];
		}

		$in = $this->check_settings( $in );

		// the connection was turned off while it named a Matomo URL the network does not
		// allow. we remove the URL explicitly so it can't be turned on accidentally afterwards.
		// note: check_piwik_url() keeps a URL that is not changing, so it must be dropped here.
		if ( ! empty( $this->get_save_failures( SaveFailure::HOST_REMOVED ) ) ) {
			$in['piwik_url'] = '';
		}

		self::$wp_piwik->log( 'Apply changed settings:' );
		foreach ( self::$default_settings ['globalSettings'] as $key => $val ) {
			$this->set_global_option( $key, isset( $in [ $key ] ) ? $in [ $key ] : $val );
		}
		foreach ( self::$default_settings ['settings'] as $key => $val ) {
			$this->set_option( $key, isset( $in [ $key ] ) ? $in [ $key ] : $val );
		}
		$this->set_global_option( 'version_history', $version_history );
		$this->set_global_option( 'last_settings_update', (string) time() );
		$this->save();

		if ( is_multisite() ) {
			delete_site_transient( self::MANUAL_TRACKING_SITES_CACHE );
		}
	}

	/**
	 * Apply callback function on new settings
	 *
	 * Every callback is handed the value, the whole configuration set and the key it was
	 * registered under, so one callback can serve several keys.
	 *
	 * @param array $in new configuration set
	 * @return array configuration set after callback functions were applied
	 */
	private function check_settings( $in ) {
		$this->save_failures = [];
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
						$key,
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
		// the URL is stored the way the tracking code writes it out, since the proxy script, the
		// opt-out iframe and the REST client read the stored value rather than the tracking code
		$value = \WP_Piwik\TrackingCode\Generator::encode_what_a_url_cannot_hold( $value );
		if ( null === $value ) {
			// dropping what the host cannot be encoded to would name another server, so
			// we must reject it instead.
			$this->reject_unconvertible_host( 'piwik_url' );
			return (string) $this->get_global_option( 'piwik_url' );
		}

		$value = \WP_Piwik\TrackingCode\Generator::strip_what_a_url_cannot_hold( $value );
		if ( '' === $value ) {
			return ''; // no URL, don't add a slash
		}

		$value = substr( $value, - 1, 1 ) !== '/' ? $value . '/' : $value;

		// an earlier version stored the URL as it was typed, so it is read the way the new
		// one is before the two are compared
		$stored = (string) $this->get_global_option( 'piwik_url' );
		if (
			\WP_Piwik\TrackingCode\Generator::normalize_url( $stored ) !== $value
			&& ! $this->may_current_user_track_via_matomo( $value )
		) {
			return $stored; // keep the Matomo the network allows
		}

		return $value;
	}

	/**
	 * Check the subdomain of a cloud hosted Matomo
	 *
	 * @param mixed  $value new subdomain
	 * @param array  $in configuration set
	 * @param string $key setting the subdomain belongs to
	 * @return string subdomain
	 * @phpstan-ignore method.unused
	 */
	private function check_cloud_subdomain( $value, $in, $key ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( '' === $value ) {
			return '';
		}

		$stored = (string) $this->get_global_option( $key );
		if ( strtolower( trim( $stored ) ) === $value ) {
			return $value; // nothing is changing, so there is nothing to check
		}

		if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $value ) ) {
			// the subdomain looks incorrect or malicious, so reject it
			$this->save_failures[] = new SaveFailure( SaveFailure::INVALID_SUBDOMAIN, $key );
			return $stored;
		}

		$domain = 'piwik_user' === $key ? '.innocraft.cloud' : '.matomo.cloud';
		if ( ! $this->may_current_user_track_via( $key, 'https://' . $value . $domain . '/' ) ) {
			return $stored; // keep the cloud the network allows
		}

		return $value;
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
	 * Normalize the tracker proxy cookie allow list
	 *
	 * @param mixed $value new allow list
	 * @return string normalized comma separated allow list
	 * @phpstan-ignore method.unused
	 */
	private function check_cookie_allowlist( $value ) {
		return implode( ', ', self::parse_cookie_allowlist( $value ) );
	}


	public function check_piwik_mode( $value, $in = array() ) {
		$stored = (string) $this->get_global_option( 'piwik_mode' );

		$options = $this->get_matomo_mode_options();
		if ( ! in_array( $value, array_keys( $options ), true ) ) {
			return $stored; // invalid mode
		}

		if ( $value === $stored ) {
			return $value; // the mode is not changing, so there is nothing to check
		}

		// the associated value the new piwik mode uses was refused earlier in this save, so
		// the stored one was kept. since this value is required for the new piwik mode to work,
		// we also abort changing the piwik mode. turning the connection off is never refused.
		if (
			'disabled' !== $value
			&& ! empty( $this->get_save_failures_of_setting( $this->get_setting_of_mode( $value ) ) )
		) {
			return $stored;
		}

		$matomo = $this->get_matomo_url_of_mode( $value, $in );
		if ( '' === trim( $matomo ) ) {
			return $value; // the method names no Matomo, so the tracker moves nowhere
		}

		if ( trim( $matomo ) === trim( $this->get_matomo_url_of_mode( $stored, $in ) ) ) {
			// the target matomo is not changing. in this case we do not check if the
			// current user can use this target, because it was already saved, and the
			// current user is not trying to change it.
			return $value;
		}

		// turning the connection off is never refused. it names the Matomo URL, which this
		// site was not using, so a URL the network does not allow is removed instead of
		// being kept for the site to connect to later.
		$tracker_url = \WP_Piwik\TrackingCode\Generator::get_tracker_url( $matomo );
		if ( 'disabled' === $value && ! $this->tracker_hosts->is_allowed_for_current_user( $tracker_url ) ) {
			$this->save_failures[] = new SaveFailure(
				SaveFailure::HOST_REMOVED,
				'piwik_mode',
				$this->get_host_to_report( $tracker_url, $matomo )
			);
			return $value;
		}

		if ( ! $this->may_current_user_track_via( 'piwik_mode', $tracker_url, $matomo ) ) {
			// the method names a Matomo the network does not allow, keep the existing value
			return $stored;
		}

		return $value;
	}

	/**
	 * @param string $piwik_mode connection method
	 * @return string the setting that names the Matomo of that connection method
	 */
	private function get_setting_of_mode( $piwik_mode ) {
		if ( 'cloud' === $piwik_mode ) {
			return 'piwik_user';
		}

		if ( 'cloud-matomo' === $piwik_mode ) {
			return 'matomo_user';
		}

		return 'piwik_url';
	}

	/**
	 * @param string $setting setting the failures were recorded for
	 * @return array<int, SaveFailure> failures of that setting in the current save
	 */
	private function get_save_failures_of_setting( $setting ) {
		return array_values(
			array_filter(
				$this->save_failures,
				function ( SaveFailure $failure ) use ( $setting ) {
					return $failure->get_setting() === $setting;
				}
			)
		);
	}

	private function get_matomo_url_of_mode( $piwik_mode, $in ) {
		// a cloud is named the way get_matomo_url() writes it out. read without its
		// protocol, a subdomain such as 'evil.example.org://acme' would be taken for a URL
		// of its own, whose host is the cloud rather than the server the browser loads.
		if ( 'cloud' === $piwik_mode ) {
			$subdomain = $this->get_submitted_option( $in, 'piwik_user' );
			return '' === trim( $subdomain ) ? '' : 'https://' . $subdomain . '.innocraft.cloud/';
		}

		if ( 'cloud-matomo' === $piwik_mode ) {
			$subdomain = $this->get_submitted_option( $in, 'matomo_user' );
			return '' === trim( $subdomain ) ? '' : 'https://' . $subdomain . '.matomo.cloud/';
		}

		// every other connection method names the Matomo by URL, or by a file path that
		// names no host at all
		return $this->get_submitted_option( $in, 'piwik_url' );
	}

	/**
	 * @return boolean
	 */
	public static function can_enter_tracking_code_manually() {
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * Get the tracking code modes the settings page offers
	 *
	 * @return array mode key => descriptive mode name
	 */
	public function get_track_mode_options() {
		$options = array(
			'disabled' => __( 'Disabled', 'wp-piwik' ),
			'default'  => __( 'Default tracking', 'wp-piwik' ),
			'js'       => __( 'Use js/index.php', 'wp-piwik' ),
			'proxy'    => __( 'Use proxy script', 'wp-piwik' ),
		);
		// entering the tracking code by hand publishes unfiltered HTML to every page of
		// the site, which WordPress only allows some users to do
		if ( self::can_enter_tracking_code_manually() || 'manually' === $this->get_global_option( 'track_mode' ) ) {
			$options['manually'] = __( 'Enter manually', 'wp-piwik' );
		}
		return $options;
	}

	/**
	 * Reject a tracking mode the settings page does not offer the current user
	 *
	 * @param mixed $value new tracking mode
	 * @return string tracking mode
	 */
	public function check_track_mode( $value ) {
		if ( ! is_string( $value ) || ! array_key_exists( $value, $this->get_track_mode_options() ) ) {
			return $this->get_global_option( 'track_mode' );
		}
		return $value;
	}

	/**
	 * Get the protocols the settings page offers to force the tracker onto
	 *
	 * @return array protocol key => descriptive protocol name
	 */
	public function get_force_protocol_options() {
		return array(
			'disabled' => __( 'Disabled (default)', 'wp-piwik' ),
			'http'     => __( 'http', 'wp-piwik' ),
			'https'    => __( 'https (SSL)', 'wp-piwik' ),
		);
	}

	/**
	 * Reject a protocol the settings page does not offer.
	 *
	 * @param mixed $value new protocol
	 * @return string protocol
	 */
	public function check_force_protocol( $value ) {
		if ( ! is_string( $value ) || ! array_key_exists( $value, $this->get_force_protocol_options() ) ) {
			return $this->get_global_option( 'force_protocol' );
		}
		return $value;
	}

	/**
	 * Read a CDN URL the way the tracking code writes it out, see
	 * TrackingCode::normalize_cdn_url(), and refuse every host the network does not allow
	 * the current user to serve the tracker from.
	 *
	 * @param mixed  $value new CDN URL
	 * @param array  $in configuration set
	 * @param string $key setting the CDN URL belongs to
	 * @return string CDN URL
	 * @phpstan-ignore method.unused
	 */
	private function check_cdn_url( $value, $in, $key ) {
		if ( is_string( $value ) && null === \WP_Piwik\TrackingCode\Generator::encode_what_a_url_cannot_hold( $value ) ) {
			// dropping what the host cannot be encoded to would name another server
			$this->reject_unconvertible_host( $key );
			return (string) $this->get_global_option( $key );
		}

		$value = \WP_Piwik\TrackingCode::normalize_cdn_url( $value );
		if ( '' === $value ) {
			return '';
		}

		// an earlier version stored the CDN URL as it was typed, so it is read the way the
		// new one is before the two are compared
		$stored = (string) $this->get_global_option( $key );

		// the CDN URL is checked the way the tracking code writes it out, behind a protocol.
		if (
			\WP_Piwik\TrackingCode::normalize_cdn_url( $stored ) !== $value
			&& ! $this->may_current_user_track_via( $key, 'https://' . $value . '/' )
		) {
			return $stored; // keep the CDN the network allows
		}

		return $value;
	}

	/**
	 * @param string $key setting the value was submitted for
	 */
	private function reject_unconvertible_host( $key ) {
		// without the intl extension no host outside ASCII can be converted, otherwise it
		// was the host itself that could not be
		$reason = function_exists( 'idn_to_ascii' ) ? SaveFailure::HOST_NOT_CONVERTIBLE : SaveFailure::HOST_NEEDS_INTL;

		$this->save_failures[] = new SaveFailure( $reason, $key );
	}

	/**
	 * @param string      $key setting the URL was submitted for
	 * @param string      $url tracker URL, CDN URL or bare host
	 * @param string|null $written the value the URL was read from, to report when the URL
	 *                             names no host
	 * @return boolean
	 */
	private function may_current_user_track_via( $key, $url, $written = null ) {
		if ( $this->tracker_hosts->is_allowed_for_current_user( $url ) ) {
			return true;
		}

		$this->save_failures[] = new SaveFailure(
			SaveFailure::HOST_NOT_ALLOWED,
			$key,
			$this->get_host_to_report( $url, $written )
		);

		return false;
	}

	/**
	 * @param string $matomo_url Matomo URL as the settings name it
	 * @return boolean
	 */
	private function may_current_user_track_via_matomo( $matomo_url ) {
		// the Matomo URL is checked the way the tracking code writes it out. eg, read on its
		// own, ' evil.example.org://stats.example.org' would name stats.example.org, while
		// the browser loads the tracker from evil.example.org.
		return $this->may_current_user_track_via(
			'piwik_url',
			\WP_Piwik\TrackingCode\Generator::get_tracker_url( $matomo_url ),
			$matomo_url
		);
	}

	/**
	 * @param string      $url tracker URL, CDN URL or bare host
	 * @param string|null $written the value the URL was read from, to report when the URL
	 *                             names no host
	 * @return string the host the value names, or the value itself when it names none
	 */
	private function get_host_to_report( $url, $written = null ) {
		$host = $this->tracker_hosts->host_from_url( $url );
		if ( '' !== $host ) {
			return $host;
		}

		return trim( (string) ( null === $written ? $url : $written ) );
	}

	/**
	 * Get the hosts a site of this network may load its tracker from
	 *
	 * @return TrackerHosts
	 */
	public function get_tracker_hosts() {
		return $this->tracker_hosts;
	}

	/**
	 * Get the values the last configuration save attempt did not store as they were
	 * submitted.
	 *
	 * @param string|null $reason_filter one of the SaveFailure constants, to get only the failures
	 *                                   of that reason
	 * @return array<int, SaveFailure> failures in the order they occurred, empty when every
	 *                                 value was stored
	 */
	public function get_save_failures( $reason_filter = null ) {
		if ( null === $reason_filter ) {
			return $this->save_failures;
		}

		return array_values(
			array_filter(
				$this->save_failures,
				function ( SaveFailure $failure ) use ( $reason_filter ) {
					return $failure->get_reason() === $reason_filter;
				}
			)
		);
	}

	/**
	 * Drop the angle brackets from a list the tracking code carries.
	 *
	 * @param mixed $value new list
	 * @return string list
	 * @phpstan-ignore method.unused
	 */
	private function check_tracking_code_list( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return str_replace( array( '<', '>' ), '', $value );
	}

	public function get_matomo_mode_options() {
		$options = array(
			'disabled' => __( 'Disabled (WP-Matomo will not connect to Matomo)', 'wp-piwik' ),
			'http'     => __( 'Self-hosted (HTTP API, default)', 'wp-piwik' ),
		);
		// the PHP API is deprecated and is no longer offered, but a site that still uses it keeps
		// the entry, so saving the settings page cannot silently change its connection method.
		if ( 'php' === $this->get_global_option( 'piwik_mode' ) ) {
			$options['php'] = __( 'Self-hosted (PHP API, deprecated)', 'wp-piwik' );
		}
		$options['cloud-matomo'] = __( 'Cloud-hosted (Innocraft Cloud, *.matomo.cloud)', 'wp-piwik' );
		$options['cloud']        = __( 'Cloud-hosted (InnoCraft Cloud, *.innocraft.cloud)', 'wp-piwik' );
		return $options;
	}

	/**
	 * @param mixed $value stored allow list
	 * @return array<int, string> non empty and sanitized allow list entries. empty
	 *                            when the value contains nothing usable
	 */
	public static function parse_cookie_allowlist( $value ) {
		if ( ! is_string( $value ) ) {
			return array();
		}

		if ( strlen( $value ) > self::COOKIE_ALLOWLIST_MAX_LENGTH ) {
			$value          = substr( $value, 0, self::COOKIE_ALLOWLIST_MAX_LENGTH );
			$last_separator = strrpos( $value, ',' );

			// drop the cut off entry, so a truncated name cannot turn into a different one
			$value = false === $last_separator ? '' : substr( $value, 0, $last_separator );
		}

		$entries = array();
		foreach ( explode( ',', $value ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry
				|| strlen( $entry ) > self::COOKIE_ALLOWLIST_MAX_ENTRY_LENGTH
				|| ! preg_match( self::COOKIE_ALLOWLIST_ENTRY_PATTERN, $entry ) ) {
				continue; // invalid cookie entries are discarded
			}

			$entries[] = $entry;
			if ( count( $entries ) >= self::COOKIE_ALLOWLIST_MAX_ENTRIES ) {
				break; // max cookie entry values found, discard the rest
			}
		}

		return array_values( array_unique( $entries ) );
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
		$track_mode = $this->get_submitted_track_mode( $in );
		if ( ! $this->is_stored_code_worth_keeping( $track_mode ) ) {
			return '';
		}

		if ( ! self::can_enter_tracking_code_manually() ) {
			return $this->get_option( 'tracking_code' ); // keep the old tracking code
		}

		$value = stripslashes( $value );
		if ( $this->check_network_activation() ) {
			update_site_option( 'wp-piwik-manually', $value );
		}
		return $value;
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
		$track_mode = $this->get_submitted_track_mode( $in );
		if ( ! $this->is_stored_code_worth_keeping( $track_mode ) ) {
			return '';
		}

		if ( ! self::can_enter_tracking_code_manually() ) {
			return $this->get_option( 'noscript_code' ); // keep the old tracking code
		}

		return stripslashes( $value );
	}

	/**
	 * Whether the code a site has stored outlives being saved in the given tracking mode
	 *
	 * @param string $track_mode tracking mode being saved
	 * @return boolean
	 */
	private function is_stored_code_worth_keeping( $track_mode ) {
		if ( 'manually' === $track_mode ) {
			return true; // switching to manually, keep the code
		}

		if ( 'disabled' !== $track_mode ) {
			return false; // switching to a generated code mode, stored code is unneeded
		}

		// the mode the site is leaving is what says where its stored code came from. this
		// runs before apply_changes() writes, so it is still the stored one.
		$previous_track_mode = $this->get_global_option( 'track_mode' );

		// switching to disabled. keep the code only if the user is authorized to use manual
		// tracking code, and we are switching from a non-generated tracking mode.
		return self::can_enter_tracking_code_manually()
			&& in_array( $previous_track_mode, array( 'manually', 'disabled' ), true );
	}

	/**
	 * Escape the plugin display name.
	 *
	 * @param mixed $value new display name
	 * @return string display name
	 * @phpstan-ignore method.unused
	 */
	private function prepare_plugin_display_name( $value ) {
		if ( ! is_string( $value ) ) {
			return $this->get_global_option( 'plugin_display_name' );
		}
		return htmlspecialchars( $value, ENT_QUOTES, 'utf-8' );
	}

	/**
	 * @param array $in configuration set
	 * @return string tracking mode
	 */
	private function get_submitted_track_mode( $in ) {
		return $this->get_submitted_option( $in, 'track_mode' );
	}

	private function get_submitted_option( $in, $key ) {
		return (string) ( isset( $in[ $key ] ) ? $in[ $key ] : $this->get_global_option( $key ) );
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
		if ( 'cloud' === $this->get_global_option( 'piwik_mode' ) ) {
			return 'https://' . $this->get_global_option( 'piwik_user' ) . '.innocraft.cloud/';
		}

		if ( 'cloud-matomo' === $this->get_global_option( 'piwik_mode' ) ) {
			return 'https://' . $this->get_global_option( 'matomo_user' ) . '.matomo.cloud/';
		}

		// every other mode is a Matomo the site names itself ...
		$matomo_url = (string) $this->get_global_option( 'piwik_url' );
		if ( '' !== $matomo_url ) {
			return $matomo_url;
		}

		// ... except the deprecated PHP API, which runs Matomo off this server's own filesystem
		// and names it by path. a site connected that way was never asked for a URL, so
		// Matomo is the one to ask.
		if ( 'php' === $this->get_global_option( 'piwik_mode' ) ) {
			return Request\Php::get_matomo_url();
		}

		return $matomo_url;
	}

	public function is_tracking_enabled() {
		return 'disabled' !== $this->get_global_option( 'track_mode' );
	}
}
