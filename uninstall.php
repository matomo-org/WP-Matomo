<?php
/**
 * Uninstall script.
 *
 * @package wp-piwik
 */

// Check if uninstall call is valid
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

/**
 * @return void
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
function wp_matomo_uninstall() {
	global $wpdb;

	$global_settings = array(
		'revision',
		'last_settings_update',
		'piwik_mode',
		'piwik_url',
		'piwik_path',
		'piwik_user',
		'matomo_user',
		'piwik_token',
		'auto_site_config',
		'default_date',
		'stats_seo',
		'dashboard_widget',
		'dashboard_chart',
		'dashboard_seo',
		'toolbar',
		'capability_read_stats',
		'perpost_stats',
		'plugin_display_name',
		'piwik_shortcut',
		'shortcodes',
		'track_mode',
		'track_codeposition',
		'track_noscript',
		'track_nojavascript',
		'proxy_url',
		'track_content',
		'track_search',
		'track_404',
		'add_post_annotations',
		'add_customvars_box',
		'add_download_extensions',
		'disable_cookies',
		'limit_cookies',
		'limit_cookies_visitor',
		'limit_cookies_session',
		'limit_cookies_referral',
		'track_admin',
		'capability_stealth',
		'track_across',
		'track_across_alias',
		'track_crossdomain_linking',
		'track_feed',
		'track_feed_addcampaign',
		'track_feed_campaign',
		'cache',
		'disable_timelimit',
		'connection_timeout',
		'disable_ssl_verify',
		'disable_ssl_verify_host',
		'piwik_useragent',
		'piwik_useragent_string',
		'track_datacfasync',
		'track_cdnurl',
		'track_cdnurlssl',
		'force_protocol',
	);

	$settings = array(
		'name',
		'site_id',
		'noscript_code',
		'tracking_code',
		'last_tracking_code_update',
		'dashboard_revision',
	);

	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		if ( isset( $limit ) && isset( $page ) ) {
			$query_limit = 'LIMIT ' . (int) ( ( $page - 1 ) * $limit ) . ',' . (int) $limit . ' ';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ary_blogs = $wpdb->get_results( $wpdb->prepare( 'SELECT blog_id FROM %s ' . $query_limit . 'ORDER BY blog_id', $wpdb->blogs ), ARRAY_A );
		if ( is_array( $ary_blogs ) ) {
			foreach ( $ary_blogs as $ary_blog ) {
				foreach ( $settings as $key ) {
					delete_blog_option( $ary_blog['blog_id'], 'wp-piwik-' . $key );
				}
				switch_to_blog( $ary_blog['blog_id'] );
				$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE 'wp-piwik_%'" );
				$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_wp-piwik_%'" );
				$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_wp-piwik_%'" );
				restore_current_blog();
			}
		}
		foreach ( $global_settings as $key ) {
			delete_site_option( 'wp-piwik_global-' . $key );
		}
		delete_site_option( 'wp-piwik-manually' );
		delete_site_option( 'wp-piwik-notices' );
	}

	foreach ( $settings as $key ) {
		delete_option( 'wp-piwik-' . $key );
	}

	foreach ( $global_settings as $key ) {
		delete_option( 'wp-piwik_global-' . $key );
	}

	delete_option( 'wp-piwik-manually' );
	delete_option( 'wp-piwik-notices' );

	$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE 'wp-piwik-%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_wp-piwik_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_wp-piwik_%'" );
}

wp_matomo_uninstall();
