<?php

namespace WP_Piwik;

class Template {

	/**
	 * @var Logger
	 */
	public static $logger;

	/**
	 * @var Settings
	 */
	public static $settings;

	/**
	 * @var \WP_Piwik
	 */
	public static $wp_piwik;

	public function __construct( $wp_piwik, $settings ) {
		self::$settings = $settings;
		self::$wp_piwik = $wp_piwik;
	}

	public function output( $values, $key, $default_value = '' ) {
		if ( isset( $values[ $key ] ) ) {
			return $values[ $key ];
		} else {
			return $default_value;
		}
	}

	public function tab_row( $name, $value ) {
		echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
	}

	public function get_range_last30() {
		$diff  = ( self::$settings->get_global_option( 'default_date' ) === 'yesterday' ) ? -86400 : 0;
		$end   = time() + $diff;
		$start = time() - 2592000 + $diff;
		return gmdate( 'Y-m-d', $start ) . ',' . gmdate( 'Y-m-d', $end );
	}
}
