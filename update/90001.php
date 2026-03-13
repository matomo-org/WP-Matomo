<?php
/**
 * @package wp-piwik
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

$ary_wpmu_config = get_site_option( 'wpmu-piwik_global-settings', false );
if ( self::$settings->check_network_activation() && $ary_wpmu_config ) {
	foreach ( $ary_wpmu_config as $key => $value ) {
		self::$settings->set_global_option( $key, $value );
	}
	delete_site_option( 'wpmu-piwik_global-settings' );
	self::$settings->set_global_option( 'auto_site_config', true );
} else {
	self::$settings->set_global_option( 'auto_site_config', false );
}
