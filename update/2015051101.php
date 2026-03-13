<?php
/**
 * @package wp-piwik
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

// Get & delete old version's options
if ( self::$settings->check_network_activation() ) {
	$old_global_options = get_site_option( 'wp-piwik_global-settings', array() );
	delete_site_option( 'wp-piwik_global-settings' );
} else {
	$old_global_options = get_option( 'wp-piwik_global-settings', array() );
	delete_option( 'wp-piwik_global-settings' );
}

$old_options = get_option( 'wp-piwik_settings', array() );
delete_option( 'wp-piwik_settings' );

if ( self::$settings->check_network_activation() ) {
	global $wpdb;
	$ary_blogs = \WP_Piwik\Settings::get_blog_list();
	if ( is_array( $ary_blogs ) ) {
		foreach ( $ary_blogs as $ary_blog ) {
			$old_options = get_blog_option( $ary_blog['blog_id'], 'wp-piwik_settings', array() );
			if ( ! $this->is_configured() ) {
				foreach ( $old_options as $key => $value ) {
					self::$settings->set_option( $key, $value, $ary_blog['blog_id'] );
				}
			}
			delete_blog_option( $ary_blog['blog_id'], 'wp-piwik_settings' );
		}
	}
}

if ( ! $this->is_configured() ) {
	if ( ! $old_global_options['add_tracking_code'] ) {
		$old_global_options['track_mode'] = 'disabled';
	} elseif ( ! $old_global_options['track_mode'] ) {
		$old_global_options['track_mode'] = 'default';
	} elseif ( 1 === (int) $old_global_options['track_mode'] ) {
		$old_global_options['track_mode'] = 'js';
	} elseif ( 2 === (int) $old_global_options['track_mode'] ) {
		$old_global_options['track_mode'] = 'proxy';
	}

	// Store old values in new settings
	foreach ( $old_global_options as $key => $value ) {
		self::$settings->set_global_option( $key, $value );
	}
	foreach ( $old_options as $key => $value ) {
		self::$settings->set_option( $key, $value );
	}
}

self::$settings->save();
