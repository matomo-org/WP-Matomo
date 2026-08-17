<?php
/**
 * Points the plugin at an external Matomo and verifies the connection.
 *
 * Run through "wp eval-file" by .ddev/commands/web/wp-matomo_connect, so
 * WordPress and the plugin are fully loaded.
 *
 * $args: [ matomo url, auth token, track mode, insecure (0|1) ]
 *
 * The settings go through the plugin's own Settings object rather than raw
 * "wp option update" so that Settings::check_network_activation() decides
 * between update_site_option() and update_option(), and so the in-memory
 * settings used by the verification requests below are the ones just written.
 *
 * @package wp-piwik
 */

list( $url, $token, $track_mode, $insecure ) = array_pad( $args, 4, '' );

$insecure = (bool) (int) $insecure;

$wp_piwik = isset( $GLOBALS['wp-piwik'] ) ? $GLOBALS['wp-piwik'] : null;
if ( ! $wp_piwik instanceof WP_Piwik ) {
	WP_CLI::error( 'The plugin is not loaded. Activate it first: wp plugin activate wp-piwik' );
}

$settings = WP_Piwik::get_settings();

$values = array(
	'piwik_mode'              => 'http',
	'piwik_url'               => rtrim( $url, '/' ) . '/',
	'piwik_token'             => $token,
	'auto_site_config'        => true,
	'http_connection'         => 'curl',
	'http_method'             => 'post',
	'connection_timeout'      => 15,
	'cache'                   => false,
	'track_mode'              => $track_mode,
	'disable_ssl_verify'      => $insecure,
	'disable_ssl_verify_host' => $insecure,
);

foreach ( $values as $key => $value ) {
	$settings->set_global_option( $key, $value );
}
$settings->save();

// any Request object built from the previous settings is now stale.
$wp_piwik->reset_request();

WP_CLI::line( '    piwik_mode  : http' );
WP_CLI::line( '    piwik_url   : ' . $settings->get_global_option( 'piwik_url' ) );
WP_CLI::line( '    piwik_token : ' . ( '' === $token ? 'not set' : str_repeat( '*', 8 ) . substr( $token, -4 ) ) );
WP_CLI::line( '    track_mode  : ' . $track_mode );

$version = $wp_piwik->request( 'global.getPiwikVersion' );
$error   = \WP_Piwik\Request::get_last_error();

if ( ! empty( $error ) ) {
	WP_CLI::error( 'Matomo request failed: ' . ( is_scalar( $error ) ? $error : wp_json_encode( $error ) ) );
}

WP_CLI::line( '    Matomo      : ' . ( is_scalar( $version ) ? $version : wp_json_encode( $version ) ) );

// with auto_site_config on, this looks the blog up in Matomo by URL and creates
// it when it is missing, which is what populates the wp-piwik-site_id option.
$site_id = $wp_piwik->get_piwik_site_id( null, true );

if ( 'n/a' === $site_id || empty( $site_id ) ) {
	WP_CLI::warning( 'No Matomo site id for ' . get_bloginfo( 'url' ) . '. The token probably lacks admin access.' );
} else {
	WP_CLI::line( '    site id     : ' . $site_id . '  (' . get_bloginfo( 'url' ) . ')' );
}
