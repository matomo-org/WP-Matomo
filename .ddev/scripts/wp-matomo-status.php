<?php
/**
 * Prints the plugin's Matomo connection settings and probes the connection.
 *
 * Run through "wp eval-file" by .ddev/commands/web/wp-matomo_status.
 *
 * @package wp-piwik
 */

$wp_piwik = isset( $GLOBALS['wp-piwik'] ) ? $GLOBALS['wp-piwik'] : null;
if ( ! $wp_piwik instanceof WP_Piwik ) {
	WP_CLI::error( 'The plugin is not loaded. Activate it first: wp plugin activate wp-piwik' );
}

$settings = WP_Piwik::get_settings();
$token    = (string) $settings->get_global_option( 'piwik_token' );

$rows = array(
	'piwik_mode'  => $settings->get_global_option( 'piwik_mode' ),
	'piwik_url'   => $settings->get_global_option( 'piwik_url' ),
	'piwik_token' => ( '' === $token ? 'not set' : str_repeat( '*', 8 ) . substr( $token, -4 ) ),
	'track_mode'  => $settings->get_global_option( 'track_mode' ),
	'site_id'     => $settings->get_option( 'site_id' ),
	'network'     => $settings->check_network_activation() ? 'yes' : 'no',
);

foreach ( $rows as $key => $value ) {
	WP_CLI::line( sprintf( '    %-12s: %s', $key, ( '' === $value || null === $value ) ? '(empty)' : $value ) );
}

if ( 'disabled' === $settings->get_global_option( 'piwik_mode' ) ) {
	WP_CLI::line( '    Matomo is disabled -- run: ddev wp-matomo:connect --token=<token>' );
	return;
}

$version = $wp_piwik->request( 'global.getPiwikVersion' );
$error   = \WP_Piwik\Request::get_last_error();

if ( ! empty( $error ) ) {
	WP_CLI::warning( 'Matomo request failed: ' . ( is_scalar( $error ) ? $error : wp_json_encode( $error ) ) );
	return;
}

WP_CLI::line( sprintf( '    %-12s: %s', 'Matomo', is_scalar( $version ) ? $version : wp_json_encode( $version ) ) );
