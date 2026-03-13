<?php
/**
 * @package wp-piwik
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

self::$settings->set_global_option( 'plugin_display_name', 'Connect Matomo' );
self::$settings->save();
