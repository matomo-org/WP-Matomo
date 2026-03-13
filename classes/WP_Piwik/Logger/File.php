<?php

namespace WP_Piwik\Logger;

/**
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 */
class File extends \WP_Piwik\Logger {

	private $logger_file = null;

	private function encode_filename( $file_name ) {
		$file_name = str_replace( ' ', '_', $file_name );
		preg_replace( '/[^0-9^a-z^_^.]/', '', $file_name );
		return $file_name;
	}

	private function set_filename() {
		$this->logger_file = WP_PIWIK_PATH . 'logs' . DIRECTORY_SEPARATOR .
			gmdate( 'Ymd' ) . '_' . $this->encode_filename( $this->get_name() ) . '.log';
	}

	private function get_filename() {
		return $this->logger_file;
	}

	private function open_file() {
		if ( ! $this->logger_file ) {
			$this->set_filename();
		}
		return fopen( $this->get_filename(), 'a' );
	}

	private function close_file( $file_handle ) {
		fclose( $file_handle );
	}

	private function write_file( $file_handle, $file_content ) {
		fwrite( $file_handle, $file_content . "\n" );
	}

	private function format_microtime( $logger_time ) {
		return sprintf( '[%6s sec]', number_format( $logger_time, 3 ) );
	}

	public function logger_output( $logger_time, $logger_message ) {
		$file_handle = $this->open_file();
		if ( $file_handle ) {
			$this->write_file( $file_handle, $this->format_microtime( $logger_time ) . ' ' . $logger_message );
			$this->close_file( $file_handle );
		}
	}
}
