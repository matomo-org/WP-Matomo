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
$useCurl = (
	(function_exists('curl_init') && ini_get('allow_url_fopen') && $settings->get_global_option('http_connection') == 'curl') || (function_exists('curl_init') && !ini_get('allow_url_fopen'))
);

$settings->get_global_option ( 'http_connection' );

ini_set ( 'display_errors', 0 );
