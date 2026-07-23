<?php
// phpcs:ignoreFile -- mock types, not WordPress plugin code.

class WP_Piwik_Test_Mock_Settings {

	public $global_options = [];
	public $options        = [];

	public function get_global_option( $key ) {
		return isset( $this->global_options[ $key ] ) ? $this->global_options[ $key ] : null;
	}

	public function get_option( $key ) {
		return isset( $this->options[ $key ] ) ? $this->options[ $key ] : null;
	}
}
