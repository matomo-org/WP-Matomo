<?php

namespace WP_Piwik\Admin;

class Network extends \WP_Piwik\Admin\Statistics {

	public function print_admin_scripts() {
		$version = self::$wp_piwik->get_plugin_version();
		wp_enqueue_script( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'js/wp-piwik.js', array(), $version, true );
		wp_enqueue_script( 'wp-piwik-chartjs', self::$wp_piwik->get_plugin_url() . 'js/chartjs/chart.min.js', array(), $version, false );
	}

	public function on_load() {
		self::$wp_piwik->onload_stats_page();
	}
}
