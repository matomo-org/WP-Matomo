<?php
/**
 * Configuration for the WordPress PHPUnit test library.
 *
 * Referenced by WP_PHPUNIT__TESTS_CONFIG in .ddev/config.yaml. The .ddev
 * directory is mounted read-only inside the web container at /mnt/ddev_config.
 *
 * @package wp-piwik
 */

// Core files for the test run. "ddev wp-matomo:install --only=tests" downloads
// the WordPress release that matches the installed wp-phpunit/wp-phpunit.
define( 'ABSPATH', '/var/www/html/wp/tests/' );

define( 'DB_NAME', 'db' );
define( 'DB_USER', 'db' );
define( 'DB_PASSWORD', 'db' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

/*
 * Every table with this prefix is dropped and recreated on each run, so it must
 * differ from the wp_ and wpms_ prefixes the browsable installs use.
 *
 * It must match wp/tests/wp-config.php: Proxy_Test_Harness calls wp-cli
 * from ABSPATH to set the plugin's options, and the tracker proxy then reads
 * them back through a real HTTP request that Apache serves from wp/tests.
 */
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.com' );
define( 'WP_TESTS_TITLE', 'Connect Matomo tests' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'WP_DEBUG', true );
