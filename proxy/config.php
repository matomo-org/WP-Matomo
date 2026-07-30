<?php
// Get the install directory of WP.
// Usefull for immutable WP install, like : https://github.com/zorglube/clever-wordpress OR https://github.com/CleverCloud/wordpress-bedrock-example where WP core and Plugins are in separate directories
$wpRootDir = getenv('WP_MATOMO_WP_ROOT_DIR');
$wpRootDir = !empty($wpRootDir)?$wpRootDir:'../../../../';
require ($wpRootDir.'wp-load.php');

require_once ('../classes/WP_Piwik/Settings.php');
require_once ('../classes/WP_Piwik/Logger.php');
require_once ('../classes/WP_Piwik/Logger/Dummy.php');

// suppress error output as early as possible. This file runs before proxy.php sends its response
// headers, so a notice or warning printed from here would leak the install path and corrupt the
// tracker response. it cannot be set before wp-load.php, which overrides it in wp_debug_mode().
ini_set( 'display_errors', 0 );

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
if ( ! isset( $COOKIE_ALLOWLIST ) ) {
	$cookie_allowlist_setting = $settings->get_global_option( 'cookie_allowlist' );

	// an empty setting turns the allow list off, so every cookie that is not blocked below is
	// forwarded. anything else means the allow list is meant to be active.
	//
	// if the value cannot be parsed into usable entries (",,,", a bare "*", an array written
	// straight into the option by wp-cli or another plugin) the list is set to [], and no cookie
	// is forwarded, so a broken value can never look like a configured filter while silently
	// forwarding everything.
	$cookie_allowlist_is_off = is_string( $cookie_allowlist_setting )
		? trim( $cookie_allowlist_setting ) === ''
		: empty( $cookie_allowlist_setting );
	if ( ! $cookie_allowlist_is_off ) {
		$COOKIE_ALLOWLIST = WP_Piwik\Settings::parse_cookie_allowlist( $cookie_allowlist_setting );
	}
} elseif ( ! is_array( $COOKIE_ALLOWLIST ) ) {
	error_log('$COOKIE_ALLOWLIST must be an array; treating it as empty (no cookies forwarded, except opt out cookies) until fixed.');
	$COOKIE_ALLOWLIST = []; // handle malformed value in config.local.php
}

// make sure opt out cookies are never blocked, or visitors who opted out would silently be tracked.
// note: it is possible to customize these cookie names, so we can only handle the default case here.
if ( isset( $COOKIE_ALLOWLIST ) && is_array( $COOKIE_ALLOWLIST ) ) {
	$COOKIE_ALLOWLIST = array_values( array_unique( array_merge( $COOKIE_ALLOWLIST, [ 'matomo_ignore', 'piwik_ignore' ] ) ) );
}

// strip known WordPress cookies (login/session, settings, comment author, etc.) so the proxy never
// forwards them to Matomo, even when no $COOKIE_ALLOWLIST is set.
function wp_matomo_is_blocked_cookie( $name ) {
	// WordPress cookies carry a per-site/per-user hash suffix, so they are matched by prefix.
	$prefixes = [ 'wordpress_', 'wp-settings-', 'wp-postpass_', 'wp-resetpass-', 'comment_author_' ];

	// WordPress cookie names can be customized via constants, so we check for these as well.
	$constants = [ 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE', 'USER_COOKIE', 'PASS_COOKIE', 'TEST_COOKIE', 'RECOVERY_MODE_COOKIE' ];
	foreach ( $constants as $constant ) {
		if ( ! defined( $constant ) ) {
			continue;
		}

		$constant_value = constant( $constant );
		if ( is_string( $constant_value ) && '' !== $constant_value ) {
			$prefixes[] = $constant_value;
		}
	}

	foreach ( $prefixes as $prefix ) {
		if ( strncmp( $name, $prefix, strlen( $prefix ) ) === 0 ) {
			return true;
		}
	}

	// the PHP session cookie must never be forwarded either
	$exact = [ 'PHPSESSID' ];
	$session_cookie_name = (string) ini_get( 'session.name' );
	if ( '' !== $session_cookie_name ) {
		$exact[] = $session_cookie_name;
	}
	return in_array( $name, $exact, true );
}
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
