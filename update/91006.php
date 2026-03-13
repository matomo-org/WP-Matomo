<?php
/**
 * @package wp-piwik
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */

$ary_remove_options = array(
	'wp-piwik_siteid',
	'wp-piwik_404',
	'wp-piwik_scriptupdate',
	'wp-piwik_dashboardid',
	'wp-piwik_jscode',
);
foreach ( $ary_remove_options as $str_remove_option ) {
	delete_option( $str_remove_option );
}
