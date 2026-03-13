<?php

namespace WP_Piwik\Logger;

class Dummy extends \WP_Piwik\Logger {

	public function logger_output( $logger_time, $logger_message ) {
		// empty
	}
}
