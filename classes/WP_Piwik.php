<?php

use WP_Piwik\Widget\Post;

/**
 * The main Connect Matomo class configures, registers and manages the plugin
 *
 * @author Andr&eacute; Br&auml;kling <webmaster@braekling.de>
 * @package WP_Piwik
 */
class WP_Piwik {

	/**
	 * Option holding the deprecated shortcode modules this site has rendered
	 *
	 * @see record_deprecated_shortcode_use()
	 */
	const DEPRECATED_SHORTCODES_OPTION = 'wp-piwik-deprecated_shortcodes';

	/**
	 * Query argument, and nonce action, of the deprecated shortcode notice's dismiss link
	 *
	 * @see on_deprecated_shortcode_notice_dismissed()
	 */
	const DISMISS_SHORTCODE_NOTICE_ARG = 'wp-piwik-dismiss-shortcode-notice';

	/**
	 * Network option tracking the review of the tracking code of the sites that were
	 * entering it manually before manual track mode required unfiltered_html.
	 *
	 * Holds MANUAL_TRACKING_REVIEW_PENDING while the review is outstanding and
	 * MANUAL_TRACKING_REVIEW_DONE once a network administrator has made it. Unset on a
	 * network that never ran a version storing unreviewed code.
	 *
	 * @see show_manual_tracking_review_notice()
	 */
	const MANUAL_TRACKING_REVIEW_OPTION = 'wp-piwik-manual_tracking_review';

	/**
	 * Value of MANUAL_TRACKING_REVIEW_OPTION while the review is outstanding
	 */
	const MANUAL_TRACKING_REVIEW_PENDING = 'pending';

	/**
	 * Value of MANUAL_TRACKING_REVIEW_OPTION once the review has been made
	 */
	const MANUAL_TRACKING_REVIEW_DONE = 'done';

	/**
	 * Last version whose settings page let the administrator of a single site of a network
	 * enter the tracking code by hand
	 *
	 * @see note_manual_tracking_review_needed()
	 */
	const LAST_UNRESTRICTED_MANUAL_TRACKING_VERSION = '1.1.12';

	/**
	 * How many of the versions an install has run are kept in the version history
	 *
	 * @see record_plugin_version()
	 */
	const VERSION_HISTORY_LENGTH = 25;

	/**
	 * Max number of sites in a network for whom we will look through each site for
	 * ones that use manual tracking mode.
	 *
	 * @see show_manual_tracking_review_notice()
	 */
	const MANUAL_TRACKING_REVIEW_SITE_LIMIT = 500;

	/**
	 * Cached in place of the site list when the network holds more sites than that
	 *
	 * @see get_sites_using_manual_tracking()
	 */
	const MANUAL_TRACKING_NETWORK_TOO_LARGE = 'too-many-sites';

	/**
	 * Query argument, and nonce action, of the manual tracking notice's dismiss link
	 *
	 * @see on_manual_tracking_review_notice_dismissed()
	 */
	const DISMISS_MANUAL_TRACKING_NOTICE_ARG = 'wp-piwik-dismiss-manual-tracking-notice';

	/**
	 * Notice shown when requesting the site URLs from Matomo fails
	 *
	 * @see update_tracking_code()
	 */
	const SITE_URLS_NOTICE = 'matomo_site_urls';

	const NOTICE_CLASS_ERROR   = 'notice notice-error';
	const NOTICE_CLASS_UPDATED = 'updated fade';

	private static $revision_id = 2023092201;
	private static $version     = '1.1.13';
	private static $blog_id;
	private static $plugin_basename = null;
	private static $logger;

	/**
	 * @var \WP_Piwik\Settings
	 */
	private static $settings;

	private static $request;
	private static $options_page_id;

	public $stats_page_id;

	/**
	 * Constructor class to configure and register all WP-Piwik components
	 */
	public function __construct() {
		global $blog_id;
		self::$blog_id = ( isset( $blog_id ) ? $blog_id : 'n/a' );
		$this->open_logger();
		$this->open_settings();
		$this->setup();
		$this->add_filters();
		$this->add_actions();
		$this->add_shortcodes();
		$this->set_up_ai_bot_tracking();
	}

	public static function get_settings() {
		return self::$settings;
	}

	/**
	 * Destructor class to finish logging
	 */
	public function __destruct() {
		$this->close_logger();
	}

	/**
	 * Setup class to prepare settings and check for installation and update
	 */
	private function setup() {
		self::$plugin_basename = plugin_basename( __FILE__ );
		$is_installed          = $this->is_installed();
		if ( ! $is_installed ) {
			$this->install_plugin();
		} elseif ( $this->is_updated() ) {
			$this->update_plugin();
		}

		$previous_version = $this->record_plugin_version();

		if ( $is_installed ) {
			$this->note_manual_tracking_review_needed( $previous_version );
		}

		if ( $this->is_config_submitted() ) {
			$this->apply_settings();
		}
		self::$settings->save();
	}

	/**
	 * Register WordPress actions
	 */
	private function add_actions() {
		if ( is_admin() ) {
			add_action(
				'admin_menu',
				array(
					$this,
					'build_admin_menu',
				)
			);
			add_action(
				'admin_post_save_wp-piwik_stats',
				array(
					$this,
					'on_stats_page_save_changes',
				)
			);
			add_action(
				'load-post.php',
				array(
					$this,
					'add_post_metaboxes',
				)
			);
			add_action(
				'load-post-new.php',
				array(
					$this,
					'add_post_metaboxes',
				)
			);
			if ( $this->is_network_mode() ) {
				add_action(
					'network_admin_notices',
					array(
						$this,
						'show_notices',
					)
				);
				add_action(
					'network_admin_menu',
					array(
						$this,
						'build_network_admin_menu',
					)
				);
				add_action(
					'update_site_option_blogname',
					array(
						$this,
						'on_blog_name_change',
					)
				);
				add_action(
					'update_site_option_siteurl',
					array(
						$this,
						'on_site_url_change',
					)
				);
			} else {
				add_action(
					'admin_notices',
					array(
						$this,
						'show_notices',
					)
				);
				add_action(
					'update_option_blogname',
					array(
						$this,
						'on_blog_name_change',
					)
				);
				add_action(
					'update_option_siteurl',
					array(
						$this,
						'on_site_url_change',
					)
				);
			}
			add_action(
				$this->is_network_mode() ? 'network_admin_notices' : 'admin_notices',
				array(
					$this,
					'show_php_mode_deprecation_notice_if_in_use',
				)
			);
			add_action(
				$this->is_network_mode() ? 'network_admin_notices' : 'admin_notices',
				array(
					$this,
					'show_deprecated_shortcode_notice_if_in_use',
				)
			);
			add_action(
				'admin_init',
				array(
					$this,
					'on_deprecated_shortcode_notice_dismissed',
				)
			);
			add_action(
				'all_admin_notices',
				array(
					$this,
					'show_manual_tracking_review_notice',
				)
			);
			add_action(
				'admin_init',
				array(
					$this,
					'on_manual_tracking_review_notice_dismissed',
				)
			);
			if ( $this->is_dashboard_active() ) {
				add_action(
					'wp_dashboard_setup',
					array(
						$this,
						'extend_word_press_dashboard',
					)
				);
			}
		}
		if ( $this->is_toolbar_active() ) {
			add_action(
				is_admin() ? 'admin_head' : 'wp_head',
				array(
					$this,
					'load_toolbar_requirements',
				)
			);
			add_action(
				'admin_bar_menu',
				array(
					$this,
					'extend_word_press_toolbar',
				),
				1000
			);
		}
		if ( $this->is_tracking_active() ) {
			if ( ! is_admin() || $this->is_admin_tracking_active() ) {
				$prefix = is_admin() ? 'admin' : 'wp';
				add_action(
					'footer' === self::$settings->get_global_option( 'track_codeposition' ) ? $prefix . '_footer' : $prefix . '_head',
					array(
						$this,
						'add_javascript_code',
					)
				);
				if ( self::$settings->get_global_option( 'dnsprefetch' ) ) {
					add_action(
						$prefix . '_head',
						array(
							$this,
							'add_dns_prefetch_tag',
						)
					);
				}
				if ( $this->is_add_no_script_code() ) {
					add_action(
						$prefix . '_footer',
						array(
							$this,
							'add_noscript_code',
						)
					);
				}
			}
			if ( self::$settings->get_global_option( 'add_post_annotations' ) ) {
				add_action(
					'transition_post_status',
					array(
						$this,
						'add_piwik_annotation',
					),
					10,
					3
				);
			}
		}
	}

	/**
	 * Register WordPress filters
	 */
	private function add_filters() {
		if ( is_admin() ) {
			add_filter(
				'plugin_row_meta',
				array(
					$this,
					'set_plugin_meta',
				),
				10,
				2
			);
			add_filter(
				'screen_layout_columns',
				array(
					$this,
					'on_screen_layout_columns',
				),
				10,
				2
			);
		} elseif ( $this->is_tracking_active() ) {
			if ( $this->is_track_feed() ) {
				add_filter(
					'the_excerpt_rss',
					array(
						$this,
						'add_feed_tracking',
					)
				);
				add_filter(
					'the_content',
					array(
						$this,
						'add_feed_tracking',
					)
				);
			}
			if ( $this->is_add_feed_campaign() ) {
				add_filter(
					'post_link',
					array(
						$this,
						'add_feed_campaign',
					)
				);
			}
			if ( $this->is_cross_domain_linking_enabled() ) {
				add_filter(
					'wp_redirect',
					array(
						$this,
						'forward_cross_domain_visitor_id',
					)
				);
			}
		}
	}

	/**
	 * Register WordPress shortcodes
	 */
	private function add_shortcodes() {
		if ( $this->is_add_shortcode() ) {
			add_shortcode(
				\WP_Piwik\Shortcode::TAG,
				array(
					$this,
					'shortcode',
				)
			);
			// a reusable block carries its own author, so its shortcodes are resolved
			// while the block renders rather than with the embedding post's content
			add_filter(
				'render_block',
				array(
					$this,
					'render_shortcodes_in_reusable_block',
				),
				10,
				2
			);
		}
	}

	/**
	 * Install WP-Piwik for the first time
	 */
	private function install_plugin( $is_update = false ) {
		self::$logger->log( 'Running Connect Matomo installation' );
		if ( ! $is_update ) {
			$this->add_notice( 'install', sprintf( __( '%1$s %2$s installed.', 'wp-piwik' ), self::$settings->get_not_empty_global_option( 'plugin_display_name' ), self::$version ), __( 'Next you should connect to Matomo', 'wp-piwik' ) );
		}
		self::$settings->set_global_option( 'revision', self::$revision_id );
		self::$settings->set_global_option( 'last_settings_update', time() );
	}

	/**
	 * Uninstall WP-Piwik
	 */
	public function uninstall_plugin() {
		self::$logger->log( 'Running Connect Matomo uninstallation' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			exit();
		}
		self::delete_word_press_option( 'wp-piwik-notices' );
		self::delete_word_press_option( self::DEPRECATED_SHORTCODES_OPTION );
		self::$settings->reset_settings();
	}

	/**
	 * Update WP-Piwik
	 */
	private function update_plugin() {
		self::$logger->log( 'Upgrade Connect Matomo to ' . self::$version );
		$patches    = glob( __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . '*.php' );
		$is_patched = false;
		if ( is_array( $patches ) ) {
			sort( $patches );
			foreach ( $patches as $patch ) {
				$patch_version = (int) pathinfo( $patch, PATHINFO_FILENAME );
				if ( $patch_version && self::$settings->get_global_option( 'revision' ) < $patch_version ) {
					self::include_file( 'update' . DIRECTORY_SEPARATOR . $patch_version );
					$is_patched = true;
				}
			}
		}
		if (
			'enabled' === self::$settings->get_global_option( 'update_notice' )
			|| ( 'script' === self::$settings->get_global_option( 'update_notice' ) && $is_patched )
		) {
			$this->add_notice( 'update', sprintf( __( '%1$s updated to %2$s.', 'wp-piwik' ), self::$settings->get_not_empty_global_option( 'plugin_display_name' ), self::$version ), __( 'Please validate your configuration', 'wp-piwik' ) );
		}
		$this->install_plugin( true );
	}

	/**
	 * Append the running version to the version history
	 *
	 * @return string the version that ran before this one, empty for an install that
	 *                recorded none
	 */
	private function record_plugin_version() {
		$history          = array_values( (array) self::$settings->get_global_option( 'version_history' ) );
		$previous_version = (string) end( $history );
		if ( self::$version === $previous_version ) {
			return $previous_version;
		}

		$history[] = self::$version;

		// enforce the version history maximum length
		$history = array_slice( $history, -self::VERSION_HISTORY_LENGTH );

		self::$settings->set_global_option( 'version_history', $history );

		return $previous_version;
	}

	/**
	 * Flag the tracking code of this install for review by a network administrator
	 *
	 * Only an install that ran a version accepting manual tracking code from a site
	 * administrator can hold code a network never approved. A version installed fresh at or
	 * after the unfiltered_html requirement has nothing to review, and neither has a single
	 * site, whose administrator may publish script anyway.
	 *
	 * @param string $previous_version
	 *          the version this request replaces, empty for a version that stored none
	 */
	private function note_manual_tracking_review_needed( $previous_version ) {
		if ( ! is_multisite() ) {
			return;
		}

		// a network wide activation has one tracking code for the whole network, which only
		// a user who can manage sites could have entered, so there is nothing to review
		if ( $this->is_network_mode() ) {
			return;
		}

		// no version was stored up to 1.1.12, so an empty one is one of those releases.
		// version_compare() already orders it below every real version.
		if ( ! version_compare( $previous_version, self::LAST_UNRESTRICTED_MANUAL_TRACKING_VERSION, '<=' ) ) {
			return;
		}

		if ( get_site_option( self::MANUAL_TRACKING_REVIEW_OPTION ) ) {
			return;
		}

		update_site_option( self::MANUAL_TRACKING_REVIEW_OPTION, self::MANUAL_TRACKING_REVIEW_PENDING );
	}

	/**
	 * Define a notice
	 *
	 * @param string  $type
	 *          identifier
	 * @param string  $subject
	 *          notice headline
	 * @param string  $text
	 *          notice content
	 * @param boolean $stay
	 *          set to true if the message should persist (default: false)
	 * @param string  $notice_class
	 *          the notice's CSS classes, see the NOTICE_CLASS_ constants
	 */
	private function add_notice( $type, $subject, $text, $stay = false, $notice_class = self::NOTICE_CLASS_UPDATED ) {
		$notices = $this->get_word_press_option( 'wp-piwik-notices', array() );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notice = array(
			'subject' => $subject,
			'text'    => $text,
			'stay'    => $stay,
			'class'   => $notice_class,
		);

		// notice already exists, don't need to update
		if ( isset( $notices[ $type ] ) && $notices[ $type ] === $notice ) {
			return;
		}

		$notices[ $type ] = $notice;
		$this->update_word_press_option( 'wp-piwik-notices', $notices );
	}

	/**
	 * Withdraw a notice before it has been shown
	 *
	 * @param string $type
	 *          identifier the notice was defined under
	 */
	private function remove_notice( $type ) {
		$notices = $this->get_word_press_option( 'wp-piwik-notices', array() );
		if ( ! is_array( $notices ) || ! isset( $notices[ $type ] ) ) {
			return;
		}

		unset( $notices[ $type ] );
		$this->update_word_press_option( 'wp-piwik-notices', $notices );
	}

	/**
	 * Show all notices defined previously
	 *
	 * @see add_notice()
	 */
	public function show_notices() {
		$notices = $this->get_word_press_option( 'wp-piwik-notices' );
		if ( ! empty( $notices ) ) {
			if ( ! is_array( $notices ) ) {
				$notices = array();
			}

			foreach ( $notices as $type => $notice ) {
				printf(
					'<div class="%s"><p>%s <strong>%s:</strong> %s: <a href="%s">%s</a></p></div>',
					esc_attr( isset( $notice ['class'] ) ? $notice ['class'] : self::NOTICE_CLASS_UPDATED ),
					esc_html( $notice ['subject'] ),
					esc_html__( 'Important', 'wp-piwik' ),
					esc_html( $notice ['text'] ),
					esc_attr( $this->get_settings_url() ),
					esc_html__( 'Settings', 'wp-piwik' )
				);
				if ( ! $notice ['stay'] ) {
					unset( $notices [ $type ] );
				}
			}
		}
		$this->update_word_press_option( 'wp-piwik-notices', $notices );
	}

	public static function get_php_mode_deprecation_message() {
		return '<strong>' . esc_html__( 'The "Self-hosted (PHP API)" connection method is deprecated and will be removed by November 2026 at the latest.', 'wp-piwik' ) . '</strong><br/><br/>'
			. esc_html__( 'It loads Matomo into WordPress instead of requesting the reports over http(s), which requires having Matomo code locally, gives the Matomo code full access to your WordPress installation and offers nothing the HTTP API does not. It will be removed in the next major release of WP-Matomo.', 'wp-piwik' ) . ' '
			. esc_html__( 'Please switch this site to "Self-hosted (HTTP API)" and enter the URL of your Matomo instance.', 'wp-piwik' );
	}

	public function show_php_mode_deprecation_notice_if_in_use() {
		if ( ! $this->is_php_mode() ) {
			return;
		}

		// only show to users that can actually change the setting
		if ( ! current_user_can( $this->is_network_mode() ? 'manage_sites' : 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <br/><br/><a href="%s">%s</a></p></div>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_php_mode_deprecation_message()
			self::get_php_mode_deprecation_message(),
			esc_url( $this->get_settings_url() ),
			esc_html__( 'Open the WP-Matomo settings', 'wp-piwik' )
		);
	}

	/**
	 * Record that a deprecated shortcode module was rendered on this site.
	 *
	 * Nothing about a site says where its shortcodes are: they can sit in a post, a
	 * reusable block, a sidebar widget or a theme template. So the admin notice is driven
	 * by what actually renders.
	 *
	 * @param string $module deprecated shortcode module that was rendered
	 */
	public function record_deprecated_shortcode_use( $module ) {
		$record          = $this->get_deprecated_shortcode_record();
		$dismissed_until = $record['dismissed_until'];
		$last_seen       = isset( $record['modules'][ $module ] ) ? $record['modules'][ $module ] : 0;

		if ( $last_seen > $dismissed_until ) {
			return; // record already showing
		}

		if ( time() <= $dismissed_until ) {
			return; // notice is dismissed, no need to record
		}

		// the dismissal has run out and a deprecated shortcode rendered again, so this
		// render is what brings the notice back
		$record['modules'][ $module ] = time();
		$this->update_word_press_option( self::DEPRECATED_SHORTCODES_OPTION, $record );
	}

	/**
	 * Get the deprecated shortcode modules whose use this site should be warned about.
	 *
	 * A module drops off the list while the notice is dismissed, and stays off unless it
	 * renders again once the dismissal has run out.
	 *
	 * @return string[] module names, in the order \WP_Piwik\Shortcode declares them
	 */
	public function get_recorded_deprecated_shortcodes() {
		$record  = $this->get_deprecated_shortcode_record();
		$modules = array();
		foreach ( $record['modules'] as $module => $last_seen ) {
			if ( $last_seen > $record['dismissed_until'] ) {
				$modules[] = $module;
			}
		}
		return $modules;
	}

	/**
	 * Keep the deprecated shortcode notice away for a week.
	 *
	 * It comes back when a deprecated shortcode renders again after that, so a site that
	 * removed its shortcodes in the meantime is not warned a second time.
	 *
	 * @see record_deprecated_shortcode_use()
	 */
	public function dismiss_deprecated_shortcode_notice() {
		$record                    = $this->get_deprecated_shortcode_record();
		$record['dismissed_until'] = time() + WEEK_IN_SECONDS;
		$this->update_word_press_option( self::DEPRECATED_SHORTCODES_OPTION, $record );
	}

	/**
	 * Handle the dismiss link of the deprecated shortcode notice
	 */
	public function on_deprecated_shortcode_notice_dismissed() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::DISMISS_SHORTCODE_NOTICE_ARG ] ) ) {
			return;
		}

		check_admin_referer( self::DISMISS_SHORTCODE_NOTICE_ARG );

		if ( current_user_can( $this->is_network_mode() ? 'manage_sites' : 'activate_plugins' ) ) {
			$this->dismiss_deprecated_shortcode_notice();
		}

		// reload the current page without the dismissal query param
		if ( wp_safe_redirect( remove_query_arg( array( self::DISMISS_SHORTCODE_NOTICE_ARG, '_wpnonce', 'clear', 'testscript' ) ) ) ) {
			exit;
		}
	}

	/**
	 * Read what this site has recorded about the deprecated shortcodes
	 *
	 * @return array modules (module name => timestamp of the render that armed the notice)
	 *          and dismissed_until (timestamp, 0 if the notice was never dismissed)
	 */
	private function get_deprecated_shortcode_record() {
		$stored          = $this->get_word_press_option( self::DEPRECATED_SHORTCODES_OPTION, array() );
		$stored          = is_array( $stored ) ? $stored : array();
		$stored_modules  = isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array();
		$dismissed_until = isset( $stored['dismissed_until'] ) && is_numeric( $stored['dismissed_until'] ) ? (int) $stored['dismissed_until'] : 0;

		// make sure the module entries in the option are valid modules
		$modules = array();
		foreach ( \WP_Piwik\Shortcode::get_deprecated_modules() as $module ) {
			if ( isset( $stored_modules[ $module ] ) && is_numeric( $stored_modules[ $module ] ) ) {
				$modules[ $module ] = (int) $stored_modules[ $module ];
			}
		}

		return array(
			'modules'         => $modules,
			'dismissed_until' => $dismissed_until,
		);
	}

	/**
	 * @param string[] $modules deprecated shortcode modules the site has rendered
	 * @return string message HTML, already escaped
	 */
	public static function get_deprecated_shortcode_message( $modules ) {
		$tags = array();
		foreach ( $modules as $module ) {
			$tags[] = self::get_shortcode_example( $module );
		}

		return '<strong>'
			. esc_html__( 'The statistics shortcodes are deprecated and will be removed by November 2026 at the latest.', 'wp-piwik' )
			. '</strong><br/><br/>'
			. sprintf(
				/* translators: %s: comma separated list of shortcodes, e.g. [wp-piwik module="overview"] */
				esc_html__( 'This site renders %s, which display(s) Matomo data in a page using the auth token WP-Matomo stores for the whole site. They will be removed in the next major release of WP-Matomo.', 'wp-piwik' ),
				implode( ', ', $tags )
			)
			. ' '
			. sprintf(
				/* translators: %1$s: opening link tag to the Matomo Widgetize documentation, %2$s: closing link tag */
				esc_html__( 'Matomo\'s %1$sWidgetize%2$s feature is the supported replacement: it serves the same reports from Matomo itself. Create a dedicated read-only auth token in Matomo to use with it, so that an embedded report cannot reach anything else.', 'wp-piwik' ),
				'<a href="https://matomo.org/docs/embed-piwik-report/" target="_blank" rel="noreferrer noopener">',
				'</a>'
			)
			. '<br/><br/>'
			. sprintf(
				/* translators: %s: the opt-out shortcode, e.g. [wp-piwik module="opt-out"] */
				esc_html__( 'The %s shortcode is not deprecated and will continue to function.', 'wp-piwik' ),
				self::get_shortcode_example( 'opt-out' )
			)
			. '<br/><br/>';
	}

	/**
	 * @param string $module module the example uses
	 * @return string shortcode HTML, already escaped
	 */
	private static function get_shortcode_example( $module ) {
		return '<code>' . esc_html( '[' . \WP_Piwik\Shortcode::TAG . ' module="' . $module . '"]' ) . '</code>';
	}

	public function show_deprecated_shortcode_notice_if_in_use() {
		$modules = $this->get_recorded_deprecated_shortcodes();
		if ( empty( $modules ) ) {
			return;
		}

		// only show to users who can act on it
		if ( ! current_user_can( $this->is_network_mode() ? 'manage_sites' : 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p><p><a href="%s">%s</a> | <a href="%s" target="_blank" rel="noreferrer noopener">%s</a></p></div>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_deprecated_shortcode_message()
			self::get_deprecated_shortcode_message( $modules ),
			esc_url( wp_nonce_url( add_query_arg( self::DISMISS_SHORTCODE_NOTICE_ARG, '1' ), self::DISMISS_SHORTCODE_NOTICE_ARG ) ),
			esc_html__( 'Dismiss this notice for a week', 'wp-piwik' ),
			esc_url( 'https://matomo.org/faq/reports/embed-a-matomo-report-in-a-html-page/' ),
			esc_html__( 'Learn how to embed a Matomo report', 'wp-piwik' )
		);
	}

	/**
	 * Ask the network administrator to review the tracking code of the sites entering it
	 * manually. Only required for versions updating from at or before 1.1.12, since those
	 * versions did not require unfiltered_html to use manual tracking mode.
	 */
	public function show_manual_tracking_review_notice() {
		$review = $this->get_manual_tracking_review_message();
		if ( '' === $review ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p>%s</div>',
			esc_html__( 'Connect Matomo: please review your manually entered tracking code', 'wp-piwik' ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$review
		);
	}

	private function get_manual_tracking_review_message() {
		// only the network administrator can review another site's tracking code.
		if ( ! is_multisite() || ! current_user_can( 'manage_network' ) ) {
			return '';
		}

		// a network wide activation can only use manual tracking code if a user with manage
		// sites enables it, so we don't need to ask for a review here
		if ( $this->is_network_mode() ) {
			return '';
		}

		if ( self::MANUAL_TRACKING_REVIEW_PENDING !== get_site_option( self::MANUAL_TRACKING_REVIEW_OPTION ) ) {
			// plugin was updated from a version that required unfiltered_html, so there is nothing to check for
			return '';
		}

		$blog_ids = $this->get_sites_using_manual_tracking();

		// a network too large to look through in a single request is asked to review without
		// a list of sites to look at
		if ( self::MANUAL_TRACKING_NETWORK_TOO_LARGE === $blog_ids ) {
			$sites = '<li>' . esc_html__( 'Your network is too large to list its sites here. Please check the stored tracking code of every site whose tracking mode is "Enter manually" or "Disabled".', 'wp-piwik' ) . '</li>';
		} else {
			if ( empty( $blog_ids ) ) {
				// no site of this network holds a tracking code entered by hand
				update_site_option( self::MANUAL_TRACKING_REVIEW_OPTION, self::MANUAL_TRACKING_REVIEW_DONE );
				return '';
			}

			$sites = '';
			foreach ( $blog_ids as $blog_id ) {
				$sites .= sprintf(
					'<li><a href="%s">%s</a></li>',
					esc_url( get_admin_url( $blog_id, 'options-general.php?page=wp-matomo-settings' ) ),
					esc_html( get_blog_option( $blog_id, 'blogname', (string) $blog_id ) )
				);
			}
		}

		return sprintf(
			'<p>%s</p><ul>%s</ul><p><a href="%s">%s</a></p>',
			esc_html__( 'These sites hold a tracking code that was entered by hand, either printing it to every one of their pages as it was entered or holding it for the next time tracking is turned on. Earlier versions of Connect Matomo accepted it from a site administrator, who a network does not allow to publish HTML or JavaScript. Please check that the stored code is what you expect. Only a network administrator can change it from now on.', 'wp-piwik' ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			$sites,
			esc_url( wp_nonce_url( add_query_arg( self::DISMISS_MANUAL_TRACKING_NOTICE_ARG, '1' ), self::DISMISS_MANUAL_TRACKING_NOTICE_ARG ) ),
			esc_html__( 'I have reviewed these sites, do not show this again', 'wp-piwik' )
		);
	}

	/**
	 * Handle the dismiss link of the manual tracking code notice
	 */
	public function on_manual_tracking_review_notice_dismissed() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::DISMISS_MANUAL_TRACKING_NOTICE_ARG ] ) ) {
			return;
		}

		check_admin_referer( self::DISMISS_MANUAL_TRACKING_NOTICE_ARG );

		if ( current_user_can( 'manage_network' ) ) {
			update_site_option( self::MANUAL_TRACKING_REVIEW_OPTION, self::MANUAL_TRACKING_REVIEW_DONE );
		}

		// reload the current page without the dismissal query param
		if ( wp_safe_redirect( remove_query_arg( array( self::DISMISS_MANUAL_TRACKING_NOTICE_ARG, '_wpnonce', 'clear', 'testscript' ) ) ) ) {
			exit;
		}
	}

	/**
	 * Get the sites of the network whose tracking code is entered manually.
	 *
	 * @return int[]|string blog IDs, or MANUAL_TRACKING_NETWORK_TOO_LARGE when the network
	 *                      holds more sites than one request looks through
	 */
	private function get_sites_using_manual_tracking() {
		$cached = get_site_transient( WP_Piwik\Settings::MANUAL_TRACKING_SITES_CACHE );
		if ( is_array( $cached ) || self::MANUAL_TRACKING_NETWORK_TOO_LARGE === $cached ) {
			return $cached;
		}

		$settings_by_blog = wp_is_large_network() ? null : self::read_tracking_settings_of_every_site();

		if ( null === $settings_by_blog ) {
			set_site_transient( WP_Piwik\Settings::MANUAL_TRACKING_SITES_CACHE, self::MANUAL_TRACKING_NETWORK_TOO_LARGE, DAY_IN_SECONDS );
			return self::MANUAL_TRACKING_NETWORK_TOO_LARGE;
		}

		$blog_ids = array();
		foreach ( $settings_by_blog as $blog_id => $settings ) {
			if ( self::holds_hand_entered_tracking_code( $settings ) ) {
				$blog_ids[] = $blog_id;
			}
		}

		set_site_transient( WP_Piwik\Settings::MANUAL_TRACKING_SITES_CACHE, $blog_ids, DAY_IN_SECONDS );
		return $blog_ids;
	}

	/**
	 * Read the tracking settings the review looks at from every site of the network
	 *
	 * A site keeps its settings in its own options table, so there is no one table holding
	 * them all. Asking for them through get_blog_option() would switch to each site in turn
	 * and pull that site's whole set of autoloaded options into memory for the sake of three
	 * rows, so the tables are read in a single statement instead.
	 *
	 * @return array|null blog ID => ( option name => option value ). null when there are too
	 *                    many sites to read.
	 */
	private static function read_tracking_settings_of_every_site() {
		global $wpdb;

		$blog_ids = array();
		foreach ( (array) WP_Piwik\Settings::get_blog_list() as $blog ) {
			$blog_ids[ (int) $blog['blog_id'] ] = array();
		}
		if ( count( $blog_ids ) > self::MANUAL_TRACKING_REVIEW_SITE_LIMIT ) {
			return null;
		}
		if ( empty( $blog_ids ) ) {
			return array();
		}

		$option_names = array(
			'wp-piwik_global-track_mode',
			'wp-piwik-tracking_code',
			'wp-piwik-noscript_code',
		);
		$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );

		$selects = array();
		$values  = array();
		foreach ( array_keys( $blog_ids ) as $blog_id ) {
			$options_table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
			$selects[]     = "SELECT {$blog_id} AS blog_id, option_name, option_value FROM {$options_table} WHERE option_name IN ( {$placeholders} )";
			$values        = array_merge( $values, $option_names );
		}

		$failed = true;

		$suppress = $wpdb->suppress_errors();
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows   = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $selects ), $values ), ARRAY_A );
			$failed = (bool) $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		if ( $failed ) {
			// if the query fails, get the options the slow way
			foreach ( array_keys( $blog_ids ) as $blog_id ) {
				foreach ( $option_names as $option_name ) {
					$blog_ids[ $blog_id ][ $option_name ] = get_blog_option( $blog_id, $option_name );
				}
			}
			return $blog_ids;
		}

		foreach ( (array) $rows as $row ) {
			$blog_id = (int) $row['blog_id'];
			if ( isset( $blog_ids[ $blog_id ] ) ) {
				$blog_ids[ $blog_id ][ $row['option_name'] ] = $row['option_value'];
			}
		}

		return $blog_ids;
	}

	/**
	 * Check whether a site of the network holds a tracking code that was not generated.
	 *
	 * Looking at the tracking mode alone would miss a site that stopped tracking while
	 * keeping its code, which every version up to 1.1.12 let a site administrator do in one
	 * save. The code is kept for the next time tracking is turned on, so it is also
	 * worth reviewing.
	 *
	 * @param array $settings the site's tracking settings, see read_tracking_settings_of_every_site()
	 * @return boolean whether the site holds a tracking code that was entered by hand
	 */
	private static function holds_hand_entered_tracking_code( $settings ) {
		$track_mode = isset( $settings['wp-piwik_global-track_mode'] ) ? $settings['wp-piwik_global-track_mode'] : '';

		// the remaining modes generate the code they store on the next request, so whatever
		// they hold is Connect Matomo's own work
		if ( 'manually' !== $track_mode && 'disabled' !== $track_mode ) {
			return false;
		}

		foreach ( array( 'wp-piwik-tracking_code', 'wp-piwik-noscript_code' ) as $option_name ) {
			$code = isset( $settings[ $option_name ] ) ? $settings[ $option_name ] : '';
			if ( '' !== trim( (string) $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the settings page URL
	 *
	 * @return string settings page URL
	 */
	private function get_settings_url() {
		return ( self::$settings->check_network_activation() ? 'settings' : 'options-general' ) . '.php?page=wp-matomo-settings';
	}

	/**
	 * Echo javascript tracking code
	 */
	public function add_javascript_code() {
		if ( $this->is_hidden_user() ) {
			self::$logger->log( 'Do not add tracking code to site (user should not be tracked) Blog ID: ' . self::$blog_id . ' Site ID: ' . self::$settings->get_option( 'site_id' ) );
			return;
		}
		$tracking_code                  = new WP_Piwik\TrackingCode( $this );
		$tracking_code->is_404          = ( is_404() && self::$settings->get_global_option( 'track_404' ) );
		$tracking_code->is_usertracking = 'disabled' !== self::$settings->get_global_option( 'track_user_id' );
		$tracking_code->is_search       = ( is_search() && self::$settings->get_global_option( 'track_search' ) );
		self::$logger->log( 'Add tracking code. Blog ID: ' . self::$blog_id . ' Site ID: ' . self::$settings->get_option( 'site_id' ) );
		if ( $this->is_network_mode() && 'manually' === self::$settings->get_global_option( 'track_mode' ) ) {
			$site_id = $this->get_piwik_site_id();
			if ( 'n/a' !== $site_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo str_replace( '{ID}', $site_id, $tracking_code->get_tracking_code() );
			} else {
				echo '<!-- Site will be created and tracking code added on next request -->';
			}
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $tracking_code->get_tracking_code();
		}
	}

	/**
	 * Echo DNS prefetch tag
	 */
	public function add_dns_prefetch_tag() {
		echo '<link rel="dns-prefetch" href="' . esc_attr( $this->get_piwik_domain() ) . '" />';
	}

	/**
	 * Get Piwik Domain
	 */
	public function get_piwik_domain() {
		switch ( self::$settings->get_global_option( 'piwik_mode' ) ) {
			case 'php':
				return '//' . wp_parse_url( self::$settings->get_global_option( 'proxy_url' ), PHP_URL_HOST );
			case 'cloud':
				return '//' . self::$settings->get_global_option( 'piwik_user' ) . '.innocraft.cloud';
			case 'cloud-matomo':
				return '//' . self::$settings->get_global_option( 'matomo_user' ) . '.matomo.cloud';
			default:
				return '//' . wp_parse_url( self::$settings->get_global_option( 'piwik_url' ), PHP_URL_HOST );
		}
	}

	/**
	 * Echo noscript tracking code
	 */
	public function add_noscript_code() {
		if ( 'proxy' === self::$settings->get_global_option( 'track_mode' ) ) {
			return;
		}
		if ( $this->is_hidden_user() ) {
			self::$logger->log( 'Do not add noscript code to site (user should not be tracked) Blog ID: ' . self::$blog_id . ' Site ID: ' . self::$settings->get_option( 'site_id' ) );
			return;
		}
		self::$logger->log( 'Add noscript code. Blog ID: ' . self::$blog_id . ' Site ID: ' . self::$settings->get_option( 'site_id' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::$settings->get_option( 'noscript_code' ) . "\n";
	}

	/**
	 * Register post view meta boxes
	 */
	public function add_post_metaboxes() {
		if ( self::$settings->get_global_option( 'add_customvars_box' ) ) {
			add_action(
				'add_meta_boxes',
				array(
					new WP_Piwik\Template\MetaBoxCustomVars( $this, self::$settings ),
					'addMetabox',
				)
			);
			add_action(
				'save_post',
				array(
					new WP_Piwik\Template\MetaBoxCustomVars( $this, self::$settings ),
					'saveCustomVars',
				),
				10,
				2
			);
		}
		if ( 'disabled' !== self::$settings->get_global_option( 'perpost_stats' ) ) {
			add_action(
				'add_meta_boxes',
				array(
					$this,
					'onload_post_page',
				)
			);
		}
	}

	/**
	 * Register admin menu components
	 */
	public function build_admin_menu() {
		if ( self::is_configured() ) {
			$cap = 'wp-piwik_read_stats';
			if ( self::$settings->check_network_activation() ) {
				global $current_user;
				$user_roles = $current_user->roles;
				$allowed    = self::$settings->get_global_option( 'capability_read_stats' );
				if ( is_array( $user_roles ) && is_array( $allowed ) ) {
					foreach ( $user_roles as $user_role ) {
						if ( isset( $allowed[ $user_role ] ) && $allowed[ $user_role ] ) {
							$cap = 'read';
							break;
						}
					}
				}
			}
			$stats_page          = new WP_Piwik\Admin\Statistics( $this, self::$settings );
			$this->stats_page_id = add_dashboard_page(
				__( 'Matomo Statistics', 'wp-piwik' ),
				self::$settings->get_not_empty_global_option( 'plugin_display_name' ),
				$cap,
				'wp-piwik_stats',
				array(
					$stats_page,
					'show',
				)
			);
			$this->load_admin_stats_header( $this->stats_page_id, $stats_page );
		}
		if ( ! self::$settings->check_network_activation() ) {
			$options_page          = new WP_Piwik\Admin\Settings( $this, self::$settings );
			self::$options_page_id = add_options_page(
				self::$settings->get_not_empty_global_option( 'plugin_display_name' ),
				self::$settings->get_not_empty_global_option( 'plugin_display_name' ),
				'activate_plugins',
				'wp-matomo-settings',
				array(
					$options_page,
					'show',
				)
			);
			$this->load_admin_settings_header( self::$options_page_id, $options_page );
		}
	}

	/**
	 * Register network admin menu components
	 */
	public function build_network_admin_menu() {
		if ( self::is_configured() ) {
			$stats_page          = new WP_Piwik\Admin\Network( $this, self::$settings );
			$this->stats_page_id = add_dashboard_page(
				__( 'Matomo Statistics', 'wp-piwik' ),
				self::$settings->get_not_empty_global_option( 'plugin_display_name' ),
				'manage_sites',
				'wp-piwik_stats',
				array(
					$stats_page,
					'show',
				)
			);
			$this->load_admin_stats_header( $this->stats_page_id, $stats_page );
		}
		$options_page          = new WP_Piwik\Admin\Settings( $this, self::$settings );
		self::$options_page_id = add_submenu_page(
			'settings.php',
			self::$settings->get_not_empty_global_option( 'plugin_display_name' ),
			self::$settings->get_not_empty_global_option( 'plugin_display_name' ),
			'manage_sites',
			'wp-matomo-settings',
			array(
				$options_page,
				'show',
			)
		);
		$this->load_admin_settings_header( self::$options_page_id, $options_page );
	}

	/**
	 * Register admin header extensions for stats page
	 *
	 * @param mixed $stats_page_id options page id
	 * @param mixed $stats_page options page object
	 */
	public function load_admin_stats_header( $stats_page_id, $stats_page ) {
		add_action(
			'admin_print_scripts-' . $stats_page_id,
			array(
				$stats_page,
				'print_admin_scripts',
			)
		);
		add_action(
			'admin_print_styles-' . $stats_page_id,
			array(
				$stats_page,
				'print_admin_styles',
			)
		);
		add_action(
			'load-' . $stats_page_id,
			array(
				$this,
				'onload_stats_page',
			)
		);
	}

	/**
	 * Register admin header extensions for settings page
	 *
	 * @param string $options_page_id options page id
	 * @param mixed  $options_page options page object
	 */
	public function load_admin_settings_header( $options_page_id, $options_page ) {
		add_action(
			'admin_head-' . $options_page_id,
			array(
				$options_page,
				'extend_admin_header',
			)
		);
		add_action(
			'admin_print_styles-' . $options_page_id,
			array(
				$options_page,
				'print_admin_styles',
			)
		);
	}

	/**
	 * Register WordPress dashboard widgets
	 */
	public function extend_word_press_dashboard() {
		if ( current_user_can( 'wp-piwik_read_stats' ) ) {
			if ( 'disabled' !== self::$settings->get_global_option( 'dashboard_widget' ) ) {
				new WP_Piwik\Widget\Overview(
					$this,
					self::$settings,
					'dashboard',
					'side',
					'default',
					array(
						'date'   => self::$settings->get_global_option( 'dashboard_widget' ),
						'period' => 'day',
					)
				);
			}
			if ( self::$settings->get_global_option( 'dashboard_chart' ) ) {
				new WP_Piwik\Widget\Chart( $this, self::$settings );
			}
			if ( self::$settings->get_global_option( 'dashboard_ecommerce' ) ) {
				new WP_Piwik\Widget\Ecommerce( $this, self::$settings );
			}
			if ( self::$settings->get_global_option( 'dashboard_seo' ) ) {
				new WP_Piwik\Widget\Seo( $this, self::$settings );
			}
		}
	}

	/**
	 * Register WordPress toolbar components
	 */
	public function extend_word_press_toolbar( $toolbar ) {
		if ( current_user_can( 'wp-piwik_read_stats' ) && is_admin_bar_showing() ) {
			$id      = WP_Piwik\Request::register(
				'VisitsSummary.getUniqueVisitors',
				array(
					'period' => 'day',
					'date'   => 'last30',
				)
			);
			$unique  = $this->request( $id );
			$url     = is_network_admin() ? $this->get_settings_url() : false;
			$content = is_network_admin() ? __( 'Configure WP-Matomo', 'wp-piwik' ) : '';
			// Leave if result array does contain a message instead of valid data
			if ( isset( $unique['result'] ) ) {
				$content .= '<!-- ' . esc_html( $unique['result'] ) . ': ' . esc_html( $unique['message'] ? $unique['message'] : '...' ) . ' -->';
			} elseif ( is_array( $unique ) ) {
				$unique_count = count( $unique );

				$labels = array();
				for ( $i = 0; $i < $unique_count; $i++ ) {
					$labels [] = $i;
				}
				ob_start();
				?>
					<div style="width:100px; height:100%;">
						<canvas id="wpPiwikSparkline" style="max-width:100%; max-height:100%;padding-top:4px; padding-bottom:4px;"></canvas>
					</div>
					<script>
						function showWpPiwikSparkline() {
							new Chart(document.getElementById('wpPiwikSparkline').getContext('2d'), {
								type: 'bar',
								data: {
									labels: <?php echo wp_json_encode( $labels ); ?>,
									datasets: [
										{
											borderColor: "rgb(240, 240, 241)",
											backgroundColor: "rgb(240, 240, 241)",
											borderWidth:1,
											radius:0,
											data: <?php echo wp_json_encode( array_values( $unique ) ); ?>
										}
									]
								},
								options: {
									responsive: true,
									plugins: {
										legend: { display: false },
										tooltip: { enabled: false }
									},
									scales: {
										y: { display: false },
										x: { display: false }
									}
								}
							});
						}
						jQuery(showWpPiwikSparkline);
					</script>
				<?php
				$content .= ob_get_contents();
				ob_end_clean();
				$url = $this->get_stats_url();
			}
			$toolbar->add_menu(
				array(
					'id'    => 'wp-piwik_stats',
					'title' => $content,
					'href'  => $url,
				)
			);
		}
	}

	/**
	 * Add plugin meta data
	 *
	 * @param array  $links
	 *          list of already defined plugin meta data
	 * @param string $file
	 *          handled file
	 * @return array complete list of plugin meta data
	 */
	public function set_plugin_meta( $links, $file ) {
		if ( 'wp-piwik/wp-piwik.php' === $file && ( ! $this->is_network_mode() || is_network_admin() ) ) {
			return array_merge(
				$links,
				array(
					sprintf( '<a href="%s">%s</a>', esc_attr( self::get_settings_url() ), esc_html__( 'Settings', 'wp-piwik' ) ),
				)
			);
		}
		return $links;
	}

	/**
	 * Prepare toolbar widget requirements
	 */
	public function load_toolbar_requirements() {
		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'wp-piwik-chartjs', $this->get_plugin_url() . 'js/chartjs/chart.min.js', array(), $this->get_plugin_version(), false );
		}
	}

	/**
	 * Add tracking pixels to feed content
	 *
	 * @param string $content
	 *          post content
	 * @return string|false post content extended by tracking pixel
	 */
	public function add_feed_tracking( $content ) {
		global $post;
		if ( is_feed() ) {
			self::$logger->log( 'Add tracking image to feed entry.' );
			if ( ! self::$settings->get_option( 'site_id' ) ) {
				$site_id = $this->request_piwik_site_id();
				if ( 'n/a' !== $site_id ) {
					self::$settings->set_option( 'site_id', $site_id );
				} else {
					return false;
				}
			}
			$title   = the_title( '', '', false );
			$posturl = get_permalink( $post->ID );
			$urlref  = get_bloginfo( 'rss2_url' );
			if ( 'proxy' === self::$settings->get_global_option( 'track_mode' ) ) {
				$url = plugins_url( 'wp-piwik' ) . '/proxy/matomo.php';
			} else {
				$url = self::$settings->get_global_option( 'piwik_url' );
				if ( '/index.php' === substr( $url, -10, 10 ) ) {
					$url = str_replace( '/index.php', '/matomo.php', $url );
				} else {
					$url .= 'piwik.php';
				}
			}
			$tracking_image = $url . '?idsite=' . self::$settings->get_option( 'site_id' ) . '&rec=1&url=' . rawurlencode( $posturl ) . '&action_name=' . rawurlencode( $title ) . '&urlref=' . rawurlencode( $urlref );
			$content       .= '<img src="' . esc_attr( $tracking_image ) . '" style="border:0;width:0;height:0" width="0" height="0" alt="" />';
		}
		return $content;
	}

	/**
	 * Add a campaign parameter to feed permalink
	 *
	 * @param string $permalink
	 *          permalink
	 * @return string permalink extended by campaign parameter
	 */
	public function add_feed_campaign( $permalink ) {
		global $post;
		if ( is_feed() ) {
			self::$logger->log( 'Add campaign to feed permalink.' );
			$sep        = ( false === strpos( $permalink, '?' ) ? '?' : '&' );
			$permalink .= $sep . 'pk_campaign=' . rawurlencode( self::$settings->get_global_option( 'track_feed_campaign' ) ) . '&pk_kwd=' . rawurlencode( $post->post_name );
		}
		return $permalink;
	}

	/**
	 * Forwards the cross domain parameter pk_vid if the URL parameter is set and a user is about to be redirected.
	 * When another website links to WooCommerce with a pk_vid parameter, and WooCommerce redirects the user to another
	 * URL, the pk_vid parameter would get lost and the visitorId would later not be applied by the tracking code
	 * due to the lost pk_vid URL parameter. If the URL parameter is set, we make sure to forward this parameter.
	 *
	 * @param string $location
	 *
	 * @return string location extended by pk_vid URL parameter if the URL parameter is set
	 */
	public function forward_cross_domain_visitor_id( $location ) {
		$pk_vid = ! empty( $_GET['pk_vid'] ) ? sanitize_key( wp_unslash( $_GET['pk_vid'] ) ) : null;
		if ( ! empty( $pk_vid )
			&& preg_match( '/^[a-zA-Z0-9]{24,48}$/', $pk_vid )
		) {
			// currently, the pk_vid parameter is 32 characters long, but it may vary over time.
			$location = add_query_arg( 'pk_vid', $pk_vid, $location );
		}

		return $location;
	}

	/**
	 * Apply settings update
	 *
	 * @return boolean settings update applied
	 * @phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	private function apply_settings() {
		// TODO: shouldn't have to disable these
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::$settings->apply_changes( wp_unslash( $_POST['wp-piwik'] ) );
		self::$settings->set_global_option( 'revision', self::$revision_id );
		self::$settings->set_global_option( 'last_settings_update', time() );
		return true;
	}

	/**
	 * Check if WP-Piwik is configured
	 *
	 * @return boolean Is WP-Piwik configured?
	 */
	public static function is_configured() {
		return self::$settings->get_global_option( 'piwik_token' )
			&& 'disabled' !== self::$settings->get_global_option( 'piwik_mode' )
			&& (
				( 'http' === self::$settings->get_global_option( 'piwik_mode' ) && self::$settings->get_global_option( 'piwik_url' ) )
				|| ( 'php' === self::$settings->get_global_option( 'piwik_mode' ) && self::$settings->get_global_option( 'piwik_path' ) )
				|| ( 'cloud' === self::$settings->get_global_option( 'piwik_mode' ) && self::$settings->get_global_option( 'piwik_user' ) )
				|| ( 'cloud-matomo' === self::$settings->get_global_option( 'piwik_mode' ) && self::$settings->get_global_option( 'matomo_user' ) )
			);
	}

	/**
	 * Check if WP-Piwik was updated
	 *
	 * @return boolean Was WP-Piwik updated?
	 */
	private function is_updated() {
		return self::$settings->get_global_option( 'revision' )
			&& self::$settings->get_global_option( 'revision' ) < self::$revision_id;
	}

	/**
	 * Check if WP-Piwik is already installed
	 *
	 * @return boolean Is WP-Piwik installed?
	 */
	private function is_installed() {
		$old_settings = $this->get_word_press_option( 'wp-piwik_global-settings', false );
		if ( $old_settings && isset( $old_settings['revision'] ) ) {
			self::log( 'Save old settings' );
			self::$settings->set_global_option( 'revision', $old_settings['revision'] );
		} else {
			self::log( 'Current revision ' . self::$settings->get_global_option( 'revision' ) );
		}
		return self::$settings->get_global_option( 'revision' ) > 0;
	}

	/**
	 * Check if new settings were submitted
	 *
	 * @return boolean Are new settings submitted?
	 * @phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	public static function is_config_submitted() {
		return isset( $_POST ['wp-piwik'] ) && self::is_valid_options_post();
	}

	/**
	 * Check if PHP mode is chosen
	 *
	 * @deprecated 1.1.11 the PHP API is deprecated and will be removed in the next major
	 *             release, see get_php_mode_deprecation_message().
	 *
	 * @return bool Is PHP mode chosen?
	 */
	public function is_php_mode() {
		return self::$settings->get_global_option( 'piwik_mode' ) && 'php' === self::$settings->get_global_option( 'piwik_mode' );
	}

	/**
	 * Check if WordPress is running in network mode
	 *
	 * @return boolean Is WordPress running in network mode?
	 */
	public function is_network_mode() {
		return self::$settings->check_network_activation();
	}

	/**
	 * Check if a WP-Piwik dashboard widget is enabled
	 *
	 * @return boolean Is a dashboard widget enabled?
	 */
	private function is_dashboard_active() {
		return self::$settings->get_global_option( 'dashboard_widget' ) || self::$settings->get_global_option( 'dashboard_chart' ) || self::$settings->get_global_option( 'dashboard_seo' );
	}

	/**
	 * Check if a WP-Piwik toolbar widget is enabled
	 *
	 * @return boolean Is a toolbar widget enabled?
	 */
	private function is_toolbar_active() {
		return self::$settings->get_global_option( 'toolbar' );
	}

	/**
	 * Check if WP-Piwik tracking code insertion is enabled
	 *
	 * @return boolean Insert tracking code?
	 */
	private function is_tracking_active() {
		return 'disabled' !== self::$settings->get_global_option( 'track_mode' );
	}

	/**
	 * Check if admin tracking is enabled
	 *
	 * @return boolean Is admin tracking enabled?
	 */
	private function is_admin_tracking_active() {
		return self::$settings->get_global_option( 'track_admin' ) && is_admin();
	}

	/**
	 * Check if WP-Piwik noscript code insertion is enabled
	 *
	 * @return boolean Insert noscript code?
	 */
	private function is_add_no_script_code() {
		return self::$settings->get_global_option( 'track_noscript' );
	}

	/**
	 * Check if feed tracking is enabled
	 *
	 * @return boolean Is feed tracking enabled?
	 */
	private function is_track_feed() {
		return self::$settings->get_global_option( 'track_feed' );
	}

	/**
	 * Check if feed permalinks get a campaign parameter
	 *
	 * @return boolean Add campaign parameter to feed permalinks?
	 */
	private function is_add_feed_campaign() {
		return self::$settings->get_global_option( 'track_feed_addcampaign' );
	}

	/**
	 * Check if feed permalinks get a campaign parameter
	 *
	 * @return boolean Add campaign parameter to feed permalinks?
	 */
	private function is_cross_domain_linking_enabled() {
		return self::$settings->get_global_option( 'track_crossdomain_linking' );
	}

	/**
	 * Check if WP-Piwik shortcodes are enabled
	 *
	 * @return boolean Are shortcodes enabled?
	 */
	private function is_add_shortcode() {
		return self::$settings->get_global_option( 'shortcodes' );
	}

	/**
	 * Define Piwik constants for PHP reporting API
	 *
	 * @deprecated 1.1.11 the PHP API is deprecated and will be removed in the next major
	 *             release, see get_php_mode_deprecation_message().
	 *
	 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	 */
	public static function define_piwik_constants() {
		if ( ! defined( 'PIWIK_INCLUDE_PATH' ) ) {
			define( 'PIWIK_INCLUDE_PATH', self::$settings->get_global_option( 'piwik_path' ) );
			define( 'PIWIK_USER_PATH', self::$settings->get_global_option( 'piwik_path' ) );
			define( 'PIWIK_ENABLE_DISPATCH', false );
			define( 'PIWIK_ENABLE_ERROR_HANDLER', false );
			define( 'PIWIK_ENABLE_SESSION_START', false );
		}
	}

	/**
	 * Start chosen logging method
	 */
	private function open_logger() {
		self::$logger = \WP_Piwik\Logger::make_logger();
	}

	public static function get_logger() {
		return self::$logger;
	}

	/**
	 * Log a message
	 *
	 * @param string $message
	 *          logger message
	 */
	public static function log( $message ) {
		self::$logger->log( $message );
	}

	/**
	 * End logging
	 */
	private function close_logger() {
		self::$logger = null;
	}

	/**
	 * Load WP-Piwik settings
	 */
	private function open_settings() {
		self::$settings = new WP_Piwik\Settings( $this );
		if ( ! $this->is_config_submitted() && $this->is_php_mode() && ! defined( 'PIWIK_INCLUDE_PATH' ) ) {
			self::define_piwik_constants();
		}
	}

	/**
	 * Include a WP-Piwik file
	 */
	private function include_file( $str_file ) {
		self::$logger->log( 'Include ' . $str_file . '.php' );
		if ( is_file( WP_PIWIK_PATH . $str_file . '.php' ) ) {
			include WP_PIWIK_PATH . $str_file . '.php';
		}
	}

	/**
	 * Check if user should not be tracked
	 *
	 * @return boolean Do not track user?
	 */
	private function is_hidden_user() {
		if ( is_multisite() ) {
			foreach ( self::$settings->get_global_option( 'capability_stealth' ) as $key => $val ) {
				if ( $val && current_user_can( $key ) ) {
					return true;
				}
			}
		}
		return current_user_can( 'wp-piwik_stealth' );
	}

	/**
	 * Check if tracking code is up to date
	 *
	 * @return boolean Is tracking code up to date?
	 */
	public function is_current_tracking_code() {
		return ( self::$settings->get_option( 'last_tracking_code_update' ) && self::$settings->get_option( 'last_tracking_code_update' ) > self::$settings->get_global_option( 'last_settings_update' ) );
	}

	/**
	 * DEPRECTAED Add javascript code to site header
	 *
	 * @deprecated
	 *
	 */
	public function site_header() {
		self::$logger->log( 'Using deprecated function site_header' );
		$this->add_javascript_code();
	}

	/**
	 * DEPRECTAED Add javascript code to site footer
	 *
	 * @deprecated
	 *
	 */
	public function site_footer() {
		self::$logger->log( 'Using deprecated function site_footer' );
		$this->add_noscript_code();
	}

	/**
	 * Identify new posts if an annotation is required
	 * and create Piwik annotation
	 *
	 * @param string $new_status
	 *          new post status
	 * @param string $old_status
	 *          new post status
	 * @param object $post
	 *          current post object
	 */
	public function add_piwik_annotation( $new_status, $old_status, $post ) {
		$enabled_post_types = self::$settings->get_global_option( 'add_post_annotations' );
		if ( isset( $enabled_post_types[ $post->post_type ] ) && $enabled_post_types[ $post->post_type ] && 'publish' !== $new_status && 'publish' !== $old_status ) {
			$note   = 'Published: ' . $post->post_title . ' - URL: ' . get_permalink( $post->ID );
			$id     = WP_Piwik\Request::register(
				'Annotations.add',
				array(
					'date' => gmdate( 'Y-m-d' ),
					'note' => $note,
				),
				$this->get_piwik_site_id()
			);
			$result = $this->request( $id );
			self::$logger->log( 'Add post annotation. ' . $note . ' - ' . wp_json_encode( $result ) );
		}
	}

	/**
	 * Get WP-Piwik's URL
	 */
	public function get_plugin_url() {
		return trailingslashit( plugin_dir_url( __DIR__ ) );
	}

	/**
	 * Get WP-Piwik's version
	 */
	public function get_plugin_version() {
		return self::$version;
	}

	/**
	 * Enable three columns for WP-Piwik stats screen
	 *
	 * @param array $columns full list of column settings
	 * @param mixed $screen current screen id
	 * @return array updated list of column settings
	 */
	public function on_screen_layout_columns( $columns, $screen ) {
		if ( isset( $this->stats_page_id ) && (string) $screen === $this->stats_page_id ) {
			$columns [ $this->stats_page_id ] = 3;
		}
		return $columns;
	}

	/**
	 * Add tracking code to admin header.
	 */
	public function add_admin_header_tracking() {
		$this->add_javascript_code();
	}

	/**
	 * Get option value
	 *
	 * @param string $key
	 *          option key
	 * @return mixed option value
	 */
	public function get_option( $key ) {
		return self::$settings->get_option( $key );
	}

	/**
	 * Get global option value
	 *
	 * @param string $key
	 *          global option key
	 * @return mixed global option value
	 */
	public function get_global_option( $key ) {
		return self::$settings->get_global_option( $key );
	}

	/**
	 * Get stats page URL
	 *
	 * @return string stats page URL
	 */
	public function get_stats_url() {
		return admin_url() . '?page=wp-piwik_stats';
	}

	/**
	 * Perform a Piwik request
	 *
	 * @param string $id
	 *          request ID
	 * @return mixed request result
	 */
	public function request( $id, $debug = false ) {
		if ( 'disabled' === self::$settings->get_global_option( 'piwik_mode' ) ) {
			return 'n/a';
		}
		if ( ! isset( self::$request ) || empty( self::$request ) ) {
			self::$request = ( 'http' === self::$settings->get_global_option( 'piwik_mode' ) || 'cloud' === self::$settings->get_global_option( 'piwik_mode' ) || 'cloud-matomo' === self::$settings->get_global_option( 'piwik_mode' ) ? new WP_Piwik\Request\Rest( $this, self::$settings ) : new WP_Piwik\Request\Php( $this, self::$settings ) );
		}
		if ( $debug ) {
			return self::$request->get_debug( $id );
		}
		return self::$request->perform( $id );
	}

	/**
	 * Reset request object
	 */
	public function reset_request() {
		if ( is_object( self::$request ) ) {
			self::$request->reset();
		}
		self::$request = null;
	}

	/**
	 * Execute WP-Piwik shortcode
	 *
	 * @param array $attributes
	 *          attribute list
	 * @return string|null
	 */
	public function shortcode( $attributes ) {
		$shortcode = new \WP_Piwik\Shortcode( $this, self::$settings );
		return $shortcode->render( $attributes );
	}

	public function render_shortcodes_in_reusable_block( $block_content, $block ) {
		$shortcode = new \WP_Piwik\Shortcode( $this, self::$settings );
		return $shortcode->render_reusable_block( $block_content, $block );
	}

	/**
	 * Get Piwik site ID by blog ID
	 *
	 * @param int|string $blog_id
	 *          which blog's Piwik site ID to get, default is the current blog
	 * @return mixed Piwik site ID or n/a
	 */
	public function get_piwik_site_id( $blog_id = null, $force_fetch = false ) {
		if ( ! $blog_id && $this->is_network_mode() ) {
			$blog_id = get_current_blog_id();
		}
		$result = self::$settings->get_option( 'site_id' );
		self::$logger->log( 'Database result: ' . $result );
		return ( ! empty( $result ) && ! $force_fetch ? $result : $this->request_piwik_site_id( $blog_id ) );
	}

	/**
	 * Get a detailed list of all Piwik sites
	 *
	 * @return mixed Piwik sites
	 */
	public function get_piwik_site_details() {
		$id                 = WP_Piwik\Request::register( 'SitesManager.getSitesWithAtLeastViewAccess', array() );
		$piwik_site_details = $this->request( $id );
		return $piwik_site_details;
	}

	/**
	 * Estimate a Piwik site ID by blog ID
	 *
	 * @param int $blog_id
	 *          which blog's Piwik site ID to estimate, default is the current blog
	 * @return mixed Piwik site ID or n/a
	 */
	private function request_piwik_site_id( $blog_id = null ) {
		$is_current = ! self::$settings->check_network_activation() || empty( $blog_id );
		if ( self::$settings->get_global_option( 'auto_site_config' ) ) {
			$id     = WP_Piwik\Request::register(
				'SitesManager.getSitesIdFromSiteUrl',
				array(
					'url' => $is_current ? get_bloginfo( 'url' ) : get_blog_details( $blog_id )->siteurl,
				)
			);
			$result = $this->request( $id );
			$this->log( 'Tried to identify current site, result: ' . wp_json_encode( $result ) );
			if ( is_array( $result ) && empty( $result ) ) {
				$result = $this->add_piwik_site( $blog_id );
			} elseif ( 'n/a' !== $result && isset( $result [0] ) ) {
				$result = $result [0] ['idsite'];
			} else {
				$result = null;
			}
		} else {
			$result = null;
		}
		self::$logger->log( 'Get Matomo ID: WordPress site ' . ( $is_current ? get_bloginfo( 'url' ) : get_blog_details( $blog_id )->siteurl ) . ' = Matomo ID ' . $result );
		if ( null !== $result ) {
			self::$settings->set_option( 'site_id', $result, $blog_id );
			if ( 'disabled' !== self::$settings->get_global_option( 'track_mode' ) && 'manually' !== self::$settings->get_global_option( 'track_mode' ) ) {
				$code = $this->update_tracking_code( $result, $blog_id );
			}
			$this::$settings->save();
			return $result;
		}
		return 'n/a';
	}

	/**
	 * Add a new Piwik
	 *
	 * @param int $blog_id
	 *          which blog's Piwik site to create, default is the current blog
	 * @return int|null Piwik site ID
	 * @phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores
	 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	 */
	public function add_piwik_site( $blog_id = null ) {
		$is_current = ! self::$settings->check_network_activation() || empty( $blog_id );
		// Do not add site if Piwik connection is unreliable
		if ( ! $this->request( 'global.getPiwikVersion' ) ) {
			return null;
		}
		$id     = WP_Piwik\Request::register(
			'SitesManager.addSite',
			array(
				'urls'     => $is_current ? get_bloginfo( 'url' ) : get_blog_details( $blog_id )->siteurl,
				'siteName' => $is_current ? get_bloginfo( 'name' ) : get_blog_details( $blog_id )->blogname,
			)
		);
		$result = $this->request( $id );
		if ( is_array( $result ) && isset( $result['value'] ) ) {
			$result = (int) $result['value'];
		} else {
			$result = (int) $result;
		}
		self::$logger->log( 'Create Matomo ID: WordPress site ' . ( $is_current ? get_bloginfo( 'url' ) : get_blog_details( $blog_id )->siteurl ) . ' = Matomo ID ' . $result );
		if ( empty( $result ) ) {
			return null;
		} else {
			// check disabled for bacwards compatibility
			do_action( 'wp-piwik_site_created', $result );
			return $result;
		}
	}

	/**
	 * Update a Piwik site's detail information
	 *
	 * @param int $site_id
	 *          which Piwik site to updated
	 * @param int $blog_id
	 *          which blog's Piwik site ID to get, default is the current blog
	 */
	private function update_piwik_site( $site_id, $blog_id = null ) {
		$is_current = ! self::$settings->check_network_activation() || empty( $blog_id );
		$id         = WP_Piwik\Request::register(
			'SitesManager.updateSite',
			array(
				'urls'     => $is_current ? get_bloginfo( 'url' ) : get_blog_details( $blog_id )->siteurl,
				'siteName' => $is_current ? get_bloginfo( 'name' ) : get_blog_details( $blog_id )->blogname,
			),
			$site_id
		);
		$this->request( $id );
		self::$logger->log( 'Update Matomo site: WordPress site ' . ( $is_current ? get_bloginfo( 'url' ) : get_blog_details( $blog_id )->siteurl ) );
	}

	/**
	 * Update a site's tracking code
	 *
	 * @param int|false $site_id
	 *          which Piwik site to updated
	 * @param int|null  $blog_id
	 *          which blog's Piwik site ID to get, default is the current blog
	 * @return string|false tracking code
	 */
	public function update_tracking_code( $site_id = false, $blog_id = null ) {
		if ( ! $site_id ) {
			$site_id = $this->get_piwik_site_id();
		}
		if ( 'disabled' === self::$settings->get_global_option( 'track_mode' ) || 'manually' === self::$settings->get_global_option( 'track_mode' ) ) {
			// remove an existing SITE_URLS_NOTICE in case one is there
			$this->remove_notice( self::SITE_URLS_NOTICE );
			return false;
		}

		$matomo_url = self::$settings->get_matomo_url();
		if ( ! is_numeric( $site_id ) || empty( $matomo_url ) ) {
			// there is nothing to point the tracker at, and a tracking code naming the wrong
			// site is worse than none
			self::$logger->log( 'Cannot generate tracking code: Matomo site ' . wp_json_encode( $site_id ) . ' at Matomo URL ' . wp_json_encode( $matomo_url ) );
			$this->remove_notice( self::SITE_URLS_NOTICE );
			return false;
		}

		$merge_subdomains = (bool) self::$settings->get_global_option( 'track_across' );
		$merge_alias_urls = (bool) self::$settings->get_global_option( 'track_across_alias' );
		$cross_domain     = (bool) self::$settings->get_global_option( 'track_crossdomain_linking' );

		$site_urls = array();
		if ( $merge_subdomains || $merge_alias_urls || $cross_domain ) {
			$site_urls = $this->get_matomo_site_urls( $site_id );

			if ( null === $site_urls ) {
				$this->inform_user_tracking_code_generation_failed_site_url_request_failure();
				return false;
			}
		}

		$generator = new WP_Piwik\TrackingCode\Generator();
		$result    = $generator->generate(
			$site_id,
			$matomo_url,
			array(
				'merge_subdomains' => $merge_subdomains,
				'merge_alias_urls' => $merge_alias_urls,
				'cross_domain'     => $cross_domain,
				'disable_cookies'  => (bool) self::$settings->get_global_option( 'disable_cookies' ),
				'track_no_script'  => true,
				'site_urls'        => $site_urls,
			)
		);
		self::$logger->log( 'Generated tracking code: ' . $result );
		$result = WP_Piwik\TrackingCode::prepare_tracking_code( $result, self::$settings, self::$logger );
		if ( isset( $result ['script'] ) && ! empty( $result ['script'] ) ) {
			self::$settings->set_option( 'tracking_code', $result ['script'], $blog_id );
			self::$settings->set_option( 'noscript_code', $result ['noscript'], $blog_id );
			self::$settings->set_global_option( 'proxy_url', $result ['proxy'] );
			self::$settings->save();
			$this->remove_notice( self::SITE_URLS_NOTICE );
			return $result['script'];
		}
		return false;
	}

	private function inform_user_tracking_code_generation_failed_site_url_request_failure() {
		$this->add_notice(
			self::SITE_URLS_NOTICE,
			__( 'Connect Matomo could not ask Matomo what URLs this site is known by, so the tracking code was not rebuilt.', 'wp-piwik' ),
			__( 'Tracking across subdomains, alias URLs and domains is built from those URLs. The tracking code last built is still in use. Check that Matomo is reachable and that its auth token is still valid, then try again.', 'wp-piwik' ),
			true,
			self::NOTICE_CLASS_ERROR
		);
	}

	/**
	 * @param int $site_id Matomo site to ask about
	 * @return string[]|null the site's URLs, null when Matomo did not answer with a list
	 *                       of them at all
	 */
	private function get_matomo_site_urls( $site_id ) {
		$id     = WP_Piwik\Request::register( 'SitesManager.getSiteUrlsFromId', array(), $site_id );
		$answer = $this->request( $id );

		// check for an API error
		if ( ! is_array( $answer ) || isset( $answer['result'] ) ) {
			self::$logger->log( 'Matomo did not name the URLs of site ' . $site_id . ': ' . wp_json_encode( $answer ) );
			return null;
		}

		$urls = array();
		foreach ( $answer as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$parsed = wp_parse_url( $url );
			// a site is known by an absolute URL, so anything without a host is not one
			if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
				continue;
			}
			$urls[] = $url;
		}

		self::$logger->log( 'Matomo site ' . $site_id . ' is known by ' . wp_json_encode( $urls ) );
		return $urls;
	}

	/**
	 * Update Piwik site if blog name changes
	 */
	public function on_blog_name_change() {
		$this->update_piwik_site( self::$settings->get_option( 'site_id' ) );
	}

	/**
	 * Update Piwik site if blog URL changes
	 */
	public function on_site_url_change() {
		$this->update_piwik_site( self::$settings->get_option( 'site_id' ) );
	}

	/**
	 * Register stats page meta boxes
	 */
	public function onload_stats_page() {
		if ( self::$settings->get_global_option( 'disable_timelimit' ) ) {
			set_time_limit( 0 );
		}
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'wp-piwik', $this->get_plugin_url() . 'js/wp-piwik.js', array(), self::$version, true );
		wp_enqueue_script( 'wp-piwik-chartjs', $this->get_plugin_url() . 'js/chartjs/chart.min.js', array(), self::$version, false );
		new \WP_Piwik\Widget\Chart( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Visitors( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Overview( $this, self::$settings, $this->stats_page_id );
		if ( self::$settings->get_global_option( 'stats_ecommerce' ) ) {
			new \WP_Piwik\Widget\Ecommerce( $this, self::$settings, $this->stats_page_id );
			new \WP_Piwik\Widget\Items( $this, self::$settings, $this->stats_page_id );
			new \WP_Piwik\Widget\ItemsCategory( $this, self::$settings, $this->stats_page_id );
		}
		if ( self::$settings->get_global_option( 'stats_seo' ) ) {
			new \WP_Piwik\Widget\Seo( $this, self::$settings, $this->stats_page_id );
		}
		new \WP_Piwik\Widget\Pages( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Keywords( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Referrers( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Plugins( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Search( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Noresult( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Browsers( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\BrowserDetails( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Screens( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Types( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Models( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Systems( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\SystemDetails( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\City( $this, self::$settings, $this->stats_page_id );
		new \WP_Piwik\Widget\Country( $this, self::$settings, $this->stats_page_id );
	}

	/**
	 * Add per post statistics to a post's page
	 * @phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores
	 * @phpcs:disable WordPress.NamingConventions.ValidHookName.NonPrefixedHooknameFound
	 */
	public function onload_post_page() {
		global $post;
		$post_url = get_permalink( $post->ID );
		$this->log( 'Load per post statistics: ' . $post_url );
		$locations = apply_filters( 'wp-piwik_meta_boxes_locations', get_post_types( array( 'public' => true ), 'names' ) );
		array(
			new Post(
				$this,
				self::$settings,
				$locations,
				'side',
				'default',
				array(
					'date'   => self::$settings->get_global_option( 'perpost_stats' ),
					'period' => 'day',
					'url'    => $post_url,
				)
			),
			'show',
		);
	}

	/**
	 * Stats page changes by POST submit
	 *
	 * @see http://tinyurl.com/5r5vnzs
	 */
	public function on_stats_page_save_changes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Cheatin&#8217; uh?', 'wp-piwik' ) );
		}
		check_admin_referer( 'wp-piwik_stats' );
		if ( ! empty( $_POST['_wp_http_referer'] ) ) {
			wp_safe_redirect( sanitize_url( wp_unslash( $_POST['_wp_http_referer'] ) ) );
		}
	}

	/**
	 * Get option value, choose method depending on network mode
	 *
	 * @param string $option option key
	 * @return string|array|null $default_value option value
	 */
	private function get_word_press_option( $option, $default_value = null ) {
		return ( $this->is_network_mode() ? get_site_option( $option, $default_value ) : get_option( $option, $default_value ) );
	}

	/**
	 * Delete option, choose method depending on network mode
	 *
	 * @param string $option option key
	 */
	private function delete_word_press_option( $option ) {
		if ( $this->is_network_mode() ) {
			delete_site_option( $option );
		} else {
			delete_option( $option );
		}
	}

	/**
	 * Set option value, choose method depending on network mode
	 *
	 * @param string $option option key
	 * @param mixed  $value option value
	 */
	private function update_word_press_option( $option, $value ) {
		if ( $this->is_network_mode() ) {
			update_site_option( $option, $value );
		} else {
			update_option( $option, $value );
		}
	}

	/**
	 * Check if WP-Piwik options page
	 *
	 * @return boolean True if current page is WP-Piwik's option page
	 */
	public static function is_valid_options_post() {
		// when the plugin is network activated the settings are stored as network wide site options
		// and the settings screen is registered with 'manage_sites', so saving has to require the
		// same capability instead of the per site 'manage_options'.
		$capability = self::$settings->check_network_activation() ? 'manage_sites' : 'manage_options';
		return is_admin() && check_admin_referer( 'wp-piwik_settings' ) && current_user_can( $capability );
	}

	private function set_up_ai_bot_tracking() {
		$ai_bot_tracking = new \WP_Piwik\AIBotTracking( self::$settings, self::$logger );
		$ai_bot_tracking->register_hooks();
	}
}
