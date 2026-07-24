<?php
// Get the install directory of WP.
// Usefull for immutable WP install, like : https://github.com/zorglube/clever-wordpress OR https://github.com/CleverCloud/wordpress-bedrock-example where WP core and Plugins are in separate directories
$wpRootDir = getenv('WP_MATOMO_WP_ROOT_DIR');
$wpRootDir = !empty($wpRootDir)?$wpRootDir:'../../../../';
require ($wpRootDir.'wp-load.php');

require_once ('../classes/WP_Piwik/Settings.php');
require_once ('../classes/WP_Piwik/Logger.php');
require_once ('../classes/WP_Piwik/Logger/Dummy.php');

$logger = new WP_Piwik\Logger\Dummy ( __CLASS__ );
$settings = new WP_Piwik\Settings ( $logger );

$protocol = (isset ( $_SERVER ['HTTPS'] ) && $_SERVER ['HTTPS'] != 'off') ? 'https' : 'http';

switch ($settings->get_global_option ( 'piwik_mode' )) {
	case 'php' :
		$PIWIK_URL = $settings->get_global_option ( 'proxy_url' );
		break;
	case 'cloud' :
		$PIWIK_URL = 'https://' . $settings->get_global_option ( 'piwik_user' ) . '.innocraft.cloud/';
		break;
    case 'cloud-matomo' :
        $PIWIK_URL = 'https://' . $settings->get_global_option ( 'matomo_user' ) . '.matomo.cloud/';
        break;
	default :
		$PIWIK_URL = $settings->get_global_option ( 'piwik_url' );
		break;
}

if ( substr ( $PIWIK_URL, 0, 2 ) == '//' ) {
	$PIWIK_URL = $protocol . ':' . $PIWIK_URL;
}

$TOKEN_AUTH = $settings->get_global_option ( 'piwik_token' );
$timeout = $settings->get_global_option ( 'connection_timeout' );

// set the cookie allow list: only forward the listed cookie names to Matomo. proxy.php reads the global
// $COOKIE_ALLOWLIST array.
$cookie_allowlist_setting = trim( (string) $settings->get_global_option( 'cookie_allowlist' ) );
if ( $cookie_allowlist_setting !== '' ) { // setting is off, forward all cookies
	$cookie_allowlist_entries = array_values( array_filter( array_map( 'trim', explode( ',', $cookie_allowlist_setting ) ), 'strlen' ) );
	if ( ! empty( $cookie_allowlist_entries ) ) {
		$COOKIE_ALLOWLIST = $cookie_allowlist_entries;
	}
}

// strip known WordPress cookies (login/session, settings, comment author, etc.) so the proxy never
// forwards them to Matomo, even when no $COOKIE_ALLOWLIST is set.
function wp_matomo_is_blocked_cookie( $name ) {
	// WordPress cookies carry a per-site/per-user hash suffix, so they are matched by prefix.
	$prefixes = [ 'wordpress_', 'wp-settings-', 'wp-postpass_', 'wp-resetpass-', 'comment_author_' ];
	foreach ( $prefixes as $prefix ) {
		if ( strncmp( $name, $prefix, strlen( $prefix ) ) === 0 ) {
			return true;
		}
	}

	// the PHP session cookie must never be forwarded either
	$exact = [ 'PHPSESSID' ];
	$session_cookie_name = (string) ini_get( 'session.name' );
	if ( $session_cookie_name !== '' ) {
		$exact[] = $session_cookie_name;
	}
	return in_array( $name, $exact, true );
};
if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
	$wp_matomo_kept_cookies = [];
	foreach ( explode( ';', $_SERVER['HTTP_COOKIE'] ) as $wp_matomo_cookie ) {
		$wp_matomo_cookie = trim( $wp_matomo_cookie );
		if ( $wp_matomo_cookie === '' ) {
			continue;
		}
		$wp_matomo_cookie_parts = explode( '=', $wp_matomo_cookie, 2 );
		if ( ! wp_matomo_is_blocked_cookie( trim( $wp_matomo_cookie_parts[0] ) ) ) {
			$wp_matomo_kept_cookies[] = $wp_matomo_cookie;
		}
	}
	if ( empty( $wp_matomo_kept_cookies ) ) {
		unset( $_SERVER['HTTP_COOKIE'] );
	} else {
		$_SERVER['HTTP_COOKIE'] = implode( '; ', $wp_matomo_kept_cookies );
	}
}
if ( isset( $_COOKIE ) && is_array( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $wp_matomo_cookie_name ) {
		if ( wp_matomo_is_blocked_cookie( $wp_matomo_cookie_name ) ) {
			unset( $_COOKIE[ $wp_matomo_cookie_name ] );
		}
	}
}
$useCurl = (
	(function_exists('curl_init') && ini_get('allow_url_fopen') && $settings->get_global_option('http_connection') == 'curl') || (function_exists('curl_init') && !ini_get('allow_url_fopen'))
);

$settings->get_global_option ( 'http_connection' );

ini_set ( 'display_errors', 0 );
