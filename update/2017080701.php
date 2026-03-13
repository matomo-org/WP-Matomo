<?php
/**
 * @package wp-piwik
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

// Re-write Piwik Pro configuration to default http configuration
if ( $this->is_configured() && 'pro' === self::$settings->get_global_option( 'piwik_mode' ) ) {
	self::$settings->set_global_option( 'piwik_url', 'https://' . self::$settings->get_global_option( 'piwik_user' ) . '.piwik.pro/' );
	self::$settings->set_global_option( 'piwik_mode', 'http' );
}

// If post annotations are already enabled, choose all existing post types
if ( self::$settings->get_global_option( 'add_post_annotations' ) ) {
	self::$settings->set_global_option( 'add_post_annotations', get_post_types() );
}

self::$settings->save();
