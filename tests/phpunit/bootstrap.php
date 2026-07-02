<?php
// phpcs:ignoreFile -- test bootstrap, follows the WordPress plugin test scaffold conventions.
/**
 * PHPUnit bootstrap for the wp-piwik plugin.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// default location of the WordPress PHPUnit test library inside the wp-env tests container
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test library in ' . $_tests_dir . '.' . PHP_EOL;
	echo 'The test suite must run inside the wp-env tests container:' . PHP_EOL;
	echo '  npm run wp-env start' . PHP_EOL;
	echo '  npm run test:php' . PHP_EOL;
	exit( 1 );
}

// use the PHPUnit polyfills installed by composer.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && false === getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * load the plugin itself; the WordPress test library does not activate plugins.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__, 2 ) . '/wp-piwik.php';
	}
);

// boot the WordPress test environment.
require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/fake/Plugin.php';
require_once __DIR__ . '/fake/Settings.php';
require_once __DIR__ . '/WP_Piwik_TestCase.php';
