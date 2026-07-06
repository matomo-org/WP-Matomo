<?php
// phpcs:ignoreFile -- mock types, not WordPress plugin code.

class WP_Piwik_Test_Fake_Plugin {

	public $options               = [];
	public $global_options        = [];
	public $logs                  = [];
	public $current_tracking_code = true;
	public $network_mode          = false;
	public $valid_options_post    = true;
	public $piwik_site_id         = 0;
	public $tracking_code_updates = 0;

	public function log( $message ) {
		$this->logs[] = $message;
	}

	public function get_option( $key ) {
		return isset( $this->options[ $key ] ) ? $this->options[ $key ] : null;
	}

	public function get_global_option( $key ) {
		return isset( $this->global_options[ $key ] ) ? $this->global_options[ $key ] : null;
	}

	public function is_current_tracking_code() {
		return $this->current_tracking_code;
	}

	public function update_tracking_code() {
		++$this->tracking_code_updates;
	}

	public function is_network_mode() {
		return $this->network_mode;
	}

	public function is_valid_options_post() {
		return $this->valid_options_post;
	}

	public function get_piwik_site_id() {
		return $this->piwik_site_id;
	}
}
