<?php
/**
 * @package wp-piwik
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

// Set range for per post stats
if ( self::$settings->get_global_option( 'perpost_stats' ) ) {
	self::$settings->set_global_option( 'perpost_stats', 'last30' );
} else {
	self::$settings->set_global_option( 'perpost_stats', 'disabled' );
}

self::$settings->save();
