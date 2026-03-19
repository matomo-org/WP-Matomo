<?php

namespace WP_Piwik;

abstract class Logger {

	private $logger_name     = 'unnamed';
	private $start_microtime = null;

	abstract public function logger_output( $logger_time, $logger_message );

	public function __construct( $logger_name ) {
		$this->set_name( $logger_name );
		$this->set_start_microtime( microtime( true ) );
		$this->log( 'Logging started -------------------------------' );
	}

	public static function make_logger() {
		$logger = defined( 'WP_PIWIK_ACTIVATE_LOGGER' ) ? WP_PIWIK_ACTIVATE_LOGGER : 0;
		switch ( $logger ) {
			case 1:
				return new \WP_Piwik\Logger\Screen( __CLASS__ );
			case 2:
				return new \WP_Piwik\Logger\File( __CLASS__ );
			default:
				return new \WP_Piwik\Logger\Dummy( __CLASS__ );
		}
	}

	public function __destruct() {
		$this->log( 'Logging finished ------------------------------' );
	}

	public function log( $logger_message ) {
		$this->logger_output( $this->get_elapsed_microtime(), $logger_message );
	}

	private function set_name( $logger_name ) {
		$this->logger_name = $logger_name;
	}

	public function get_name() {
		return $this->logger_name;
	}

	private function set_start_microtime( $start_microtime ) {
		$this->start_microtime = $start_microtime;
	}

	public function get_start_microtime() {
		return $this->start_microtime;
	}

	public function get_elapsed_microtime() {
		return microtime( true ) - $this->get_start_microtime();
	}
}
