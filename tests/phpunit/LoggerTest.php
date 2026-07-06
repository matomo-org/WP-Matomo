<?php

namespace WP_Piwik\Tests;

use WP_Piwik\Logger;
use WP_Piwik\Logger\Dummy;
use WP_Piwik\Logger\File;
use WP_Piwik\Logger\Screen;

class LoggerTest extends WP_Piwik_TestCase {

	public function test_make_logger_returns_dummy_logger_by_default() {
		$logger = Logger::make_logger();

		$this->assertInstanceOf( Dummy::class, $logger );
		$this->assertSame( 'WP_Piwik\Logger', $logger->get_name() );
	}

	public function test_logger_keeps_its_name() {
		$logger = new Dummy( 'my-logger' );

		$this->assertSame( 'my-logger', $logger->get_name() );
	}

	public function test_elapsed_microtime_grows_from_construction_time() {
		$logger = new Dummy( 'my-logger' );

		$this->assertGreaterThanOrEqual( 0, $logger->get_elapsed_microtime() );
		$this->assertLessThan( 60, $logger->get_elapsed_microtime() );
	}

	public function test_dummy_logger_produces_no_output() {
		$logger = new Dummy( 'my-logger' );

		$this->expectOutputString( '' );
		$logger->log( 'something happened' );
	}

	public function test_screen_logger_registers_footer_action() {
		$logger = new Screen( 'my-logger' );

		$this->assertNotFalse( has_action( 'wp_footer', [ $logger, 'echo_results' ] ) );
	}

	public function test_screen_logger_registers_admin_footer_action_in_admin_context() {
		set_current_screen( 'dashboard' );

		$logger = new Screen( 'my-logger' );

		$this->assertNotFalse( has_action( 'admin_footer', [ $logger, 'echo_results' ] ) );
	}

	public function test_screen_logger_collects_messages_and_echoes_them() {
		$logger = new Screen( 'my-logger' );
		$logger->log( 'first message' );
		$logger->log( 'second message' );

		ob_start();
		$logger->echo_results();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<pre>', $output );
		$this->assertStringContainsString( 'Logging started', $output );
		$this->assertStringContainsString( 'first message', $output );
		$this->assertStringContainsString( 'second message', $output );
	}

	public function test_file_logger_writes_messages_to_a_log_file() {
		$file = WP_PIWIK_PATH . 'logs' . DIRECTORY_SEPARATOR . gmdate( 'Ymd' ) . '_filetest.log';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		$logger = new File( 'filetest' );
		$logger->log( 'a file log entry' );

		$this->assertFileExists( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test log file
		$content = file_get_contents( $file );
		$this->assertStringContainsString( 'Logging started', $content );
		$this->assertStringContainsString( 'a file log entry', $content );

		// destroy the logger first: its destructor writes a final log line,
		// which would recreate the file after deletion
		unset( $logger );
		wp_delete_file( $file );
	}

	public function test_file_logger_strips_unsafe_characters_from_the_file_name() {
		$file = WP_PIWIK_PATH . 'logs' . DIRECTORY_SEPARATOR . gmdate( 'Ymd' ) . '_my_ogger.log';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		// uppercase characters, spaces and separators are removed / replaced
		$logger = new File( 'my Logger!' );
		$logger->log( 'entry' );

		$this->assertFileExists( $file );

		unset( $logger );
		wp_delete_file( $file );
	}
}
