<?php

namespace WP_Piwik;

abstract class Admin {

	/**
	 * @var \WP_Piwik
	 */
	protected static $wp_piwik;
	protected static $page_id;

	/**
	 * @var Settings
	 */
	protected static $settings;

	public function __construct( $wp_piwik, $settings ) {
		self::$wp_piwik = $wp_piwik;
		self::$settings = $settings;
	}

	abstract public function show();

	abstract public function print_admin_scripts();

	public function print_admin_styles() {
		wp_enqueue_style( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'css/wp-piwik.css', array(), self::$wp_piwik->get_plugin_version() );
	}

	public function on_load() {
		// empty
	}
}
