<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */

/*
 * This script, when included or visited, will send an AI bot tracking
 * request to Matomo in a shutdown function.
 *
 * It will only send this request if the current user agent is for a
 * known AI bot.
 *
 * This script can be added to a user's wp-config.php or be executed
 * via an HTTP request in an <esi:include> directive. It should have as
 * few dependencies as possible, and load as few PHP files as possible.
 */

function wp_piwik_track_if_ai_bot() {
	global $wpdb;

	if (
		( ! defined( 'WP_CACHE' ) || ! WP_CACHE )
		&& empty( $_GET['mtm_esi'] )
	) { // advanced-cache.php not in use and we are not tracking via esi:include
		return;
	}

	if ( isset( $_GET['mtm_esi'] ) ) { // executing via esi:include directive
		$GLOBALS['MATOMO_IN_AI_ESI'] = true;
	}

	require_once __DIR__ . '/../libs/matomo-php-tracker/MatomoTracker.php';

	// check user agent is AI bot first thing, so if it is a normal request we do
	// as little extra work as possible
	$userAgent = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if ( ! \WP_Piwik\MatomoTracker::isUserAgentAIBot( $userAgent ) ) {
		return;
	}

	$GLOBALS['wp_plugin_paths'] = [];

	if ( ! defined( 'ABSPATH' ) ) {
		// being called from a esi:include directive
		define( 'SHORTINIT', true );

		$wpConfigFile = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-config.php';
		if ( ! is_file( $wpConfigFile ) ) {
			$wpConfigFile = dirname( dirname( dirname( dirname( dirname( $_SERVER['SCRIPT_FILENAME'] ) ) ) ) ) . '/wp-config.php';
		}

		require_once $wpConfigFile;
	} else {
		// being called from request that uses advanced-cache.php
		require_once ABSPATH . WPINC . '/class-wp-list-util.php';
		require_once ABSPATH . WPINC . '/class-wp-token-map.php';
		require_once ABSPATH . WPINC . '/formatting.php';
		require_once ABSPATH . WPINC . '/functions.php';
	}

	require_once ABSPATH . WPINC . '/link-template.php';
	require_once ABSPATH . WPINC . '/general-template.php';
	require_once ABSPATH . WPINC . '/http.php';
	require_once ABSPATH . WPINC . '/class-wp-http.php';
	require_once ABSPATH . WPINC . '/class-wp-http-streams.php';
	require_once ABSPATH . WPINC . '/class-wp-http-curl.php';
	require_once ABSPATH . WPINC . '/class-wp-http-proxy.php';
	require_once ABSPATH . WPINC . '/class-wp-http-cookie.php';
	require_once ABSPATH . WPINC . '/class-wp-http-encoding.php';
	require_once ABSPATH . WPINC . '/class-wp-http-response.php';
	require_once ABSPATH . WPINC . '/class-wp-http-requests-response.php';
	require_once ABSPATH . WPINC . '/class-wp-http-requests-hooks.php';

	require_once __DIR__ . '/../wp-piwik.php';

	if ( empty( $wpdb ) ) {
		require_wp_db();
		wp_set_wpdb_vars();
	}

	wp_start_object_cache();

	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		wp_plugin_directory_constants();
	}

	$url = !empty( $_REQUEST['mtm_url'] ) ? $_REQUEST['mtm_url'] : null;

	$wpPiwik       = new \WP_Piwik();
	$settings      = new \WP_Piwik\Settings( $wpPiwik );
	$aiBotTracking = new \WP_Piwik\AIBotTracking( $settings, \WP_Piwik::getLogger() );
	$aiBotTracking->doAiBotTracking( $url );
}

register_shutdown_function( 'wp_piwik_track_if_ai_bot' );
