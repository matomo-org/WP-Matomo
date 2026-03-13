<?php

namespace WP_Piwik;

class Shortcode {

	private $available = array(
		'opt-out'  => 'OptOut',
		'post'     => 'Post',
		'overview' => 'Overview',
	);

	private $content;

	/**
	 * @param array     $attributes
	 * @param \WP_Piwik $wp_piwik
	 * @param Settings  $settings
	 */
	public function __construct( $attributes, $wp_piwik, $settings ) {
		$wp_piwik->log( 'Check requested shortcode widget ' . $attributes['module'] );
		if ( isset( $attributes['module'] ) && isset( $this->available[ $attributes['module'] ] ) ) {
			$wp_piwik->log( 'Add shortcode widget ' . $this->available[ $attributes['module'] ] );
			$class  = '\\WP_Piwik\\Widget\\' . $this->available[ $attributes['module'] ];
			$widget = new $class( $wp_piwik, $settings, null, null, null, $attributes, true );
			$widget->show();
			$this->content = $widget->get();
		}
	}

	public function get() {
		return $this->content;
	}
}
