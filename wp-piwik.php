<?php
/*
Plugin Name: Connect Matomo

Plugin URI: http://wordpress.org/extend/plugins/wp-piwik/

Description: Adds Matomo statistics to your WordPress dashboard and is also able to add the Matomo Tracking Code to your blog.

Version: 1.0.30
Author: Andr&eacute; Br&auml;kling
Author URI: https://www.braekling.de
Text Domain: wp-piwik
Domain Path: /languages
License: GPL3

****************************************************************************************** 
	Copyright (C) 2009-today Andre Braekling (email: webmaster@braekling.de)

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*******************************************************************************************/

if (! function_exists ( 'add_action' )) {
	header ( 'Status: 403 Forbidden' );
	header ( 'HTTP/1.1 403 Forbidden' );
	exit ();
}

if (! defined ( 'NAMESPACE_SEPARATOR' ))
	define ( 'NAMESPACE_SEPARATOR', '\\' );

/**
 * Define WP-Piwik autoloader
 *
 * @param string $class
 *        	class name
 */
function wp_piwik_autoloader($class) {
	if (substr ( $class, 0, 9 ) == 'WP_Piwik' . NAMESPACE_SEPARATOR) {
		$class = str_replace ( '.', '', str_replace ( NAMESPACE_SEPARATOR, DIRECTORY_SEPARATOR, substr ( $class, 9 ) ) );
		require_once ('classes' . DIRECTORY_SEPARATOR . 'WP_Piwik' . DIRECTORY_SEPARATOR . $class . '.php');
	}
}

/**
 * Show notice about outdated PHP version
 */
function wp_piwik_phperror() {
	echo '<div class="error"><p>';
	printf ( __ ( 'WP-Matomo requires at least PHP 5.3. You are using the deprecated version %s. Please update PHP to use WP-Matomo.', 'wp-piwik' ), PHP_VERSION );
	echo '</p></div>';
}

function wp_piwik_load_textdomain() {
    load_plugin_textdomain( 'wp-piwik', false, plugin_basename( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR );
}
add_action( 'plugins_loaded', 'wp_piwik_load_textdomain' );

if (version_compare ( PHP_VERSION, '5.3.0', '<' ))
	add_action ( 'admin_notices', 'wp_piwik_phperror' );
else {
	define ( 'WP_PIWIK_PATH', dirname ( __FILE__ ) . DIRECTORY_SEPARATOR );
	require_once (WP_PIWIK_PATH . 'config.php');
	require_once (WP_PIWIK_PATH . 'classes' . DIRECTORY_SEPARATOR . 'WP_Piwik.php');
	spl_autoload_register ( 'wp_piwik_autoloader' );
	$GLOBALS ['wp-piwik_debug'] = false;
	if (class_exists ( 'WP_Piwik' ))
		add_action( 'init', 'wp_piwik_loader' );
}

function wp_piwik_loader() {
	$GLOBALS ['wp-piwik'] = new WP_Piwik ();
}
