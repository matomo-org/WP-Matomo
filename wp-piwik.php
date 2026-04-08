<?php
/**
 * Plugin Name: Connect Matomo
 * Plugin URI: http://wordpress.org/extend/plugins/wp-piwik/
 * Description: Adds Matomo statistics to your WordPress dashboard and is also able to add the Matomo Tracking Code to your blog.
 * Version: 1.1.3
 * Author: Matomo, Andr&eacute; Br&auml;kling
 * Author URI: https://matomo.org
 * Text Domain: wp-piwik
 * Domain Path: /languages
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package wp-piwik
 */

if ( ! function_exists( 'add_action' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
if ( ! defined( 'NAMESPACE_SEPARATOR' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	define( 'NAMESPACE_SEPARATOR', '\\' );
}

/**
 * Define WP-Piwik autoloader
 *
 * @param string $class_name
 *          class name
 */
function wp_piwik_autoloader( $class_name ) {
	if ( 'WP_Piwik' . NAMESPACE_SEPARATOR === substr( $class_name, 0, 9 ) ) {
		$class_name = str_replace( '.', '', str_replace( NAMESPACE_SEPARATOR, DIRECTORY_SEPARATOR, substr( $class_name, 9 ) ) );
		$file       = 'classes' . DIRECTORY_SEPARATOR . 'WP_Piwik' . DIRECTORY_SEPARATOR . $class_name . '.php';
		if ( is_file( __DIR__ . '/' . $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Show notice about outdated PHP version
 */
function wp_piwik_phperror() {
	echo '<div class="error"><p>';
	printf( esc_html__( 'WP-Matomo requires at least PHP 5.3. You are using the deprecated version %s. Please update PHP to use WP-Matomo.', 'wp-piwik' ), PHP_VERSION );
	echo '</p></div>';
}

function wp_piwik_load_textdomain() {
	load_plugin_textdomain( 'wp-piwik', false, plugin_basename( __DIR__ ) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR );
}
add_action( 'plugins_loaded', 'wp_piwik_load_textdomain' );

if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
	add_action( 'admin_notices', 'wp_piwik_phperror' );
} else {
	define( 'WP_PIWIK_FILE', __FILE__ );
	define( 'WP_PIWIK_PATH', __DIR__ . DIRECTORY_SEPARATOR );
	require_once WP_PIWIK_PATH . 'config.php';
	require_once WP_PIWIK_PATH . 'classes' . DIRECTORY_SEPARATOR . 'WP_Piwik.php';
	spl_autoload_register( 'wp_piwik_autoloader' );
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$GLOBALS['wp-piwik_debug'] = false;
	if ( class_exists( 'WP_Piwik' ) ) {
		add_action( 'setup_theme', 'wp_piwik_loader' );
	}
}

function wp_piwik_loader() {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$GLOBALS['wp-piwik'] = new WP_Piwik();
}
