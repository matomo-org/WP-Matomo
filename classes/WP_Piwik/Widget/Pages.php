<?php

namespace WP_Piwik\Widget;

class Pages extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$time_settings   = $this->get_time_settings();
		$this->parameter = array(
			'idSite' => self::$wp_piwik->get_piwik_site_id( $this->blog_id ),
			'period' => $time_settings['period'],
			'date'   => $time_settings['date'],
		);
		$this->title     = $prefix . __( 'Pages', 'wp-piwik' ) . ' (' . $time_settings['description'] . ')';
		$this->method    = 'Actions.getPageTitles';
		$this->name      = __( 'Page', 'wp-piwik' );
	}
}
