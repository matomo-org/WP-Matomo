<?php

namespace WP_Piwik\Logger;

class Screen extends \WP_Piwik\Logger {

	private $logs = array();

	private function format_microtime( $logger_time ) {
		return sprintf( '[%6s sec]', number_format( $logger_time, 3 ) );
	}

	public function __construct( $logger_name ) {
		add_action( is_admin() ? 'admin_footer' : 'wp_footer', array( $this, 'echo_results' ) );
		parent::__construct( $logger_name );
	}

	public function logger_output( $logger_time, $logger_message ) {
		$this->logs[] = $this->format_microtime( $logger_time ) . ' ' . $logger_message;
	}

	public function echo_results() {
		echo '<pre>';
		print_r( $this->logs );
		echo '</pre>';
	}
}
