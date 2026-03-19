<?php

namespace WP_Piwik\Widget;

class Ecommerce extends \WP_Piwik\Widget {


	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$time_settings   = $this->get_time_settings();
		$this->title     = $prefix . __( 'E-Commerce', 'wp-piwik' );
		$this->method    = 'Goals.get';
		$this->parameter = array(
			'idSite' => self::$wp_piwik->get_piwik_site_id( $this->blog_id ),
			'period' => $time_settings['period'],
			'date'   => $time_settings['date'],
		);
	}

	public function show() {
		$response = self::$wp_piwik->request( $this->api_id[ $this->method ] );
		if ( ! empty( $response['result'] ) && 'error' === $response['result'] ) {
			echo '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $response['message'] );
		} else {
			$table_head     = null;
			$revenue        = is_numeric( $this->value( $response, 'revenue' ) ) ? number_format( (float) $this->value( $response, 'revenue' ), 2 ) : '';
			$revenue_new    = is_numeric( $this->value( $response, 'revenue_new_visit' ) ) ? number_format( (float) $this->value( $response, 'revenue_new_visit' ), 2 ) : '';
			$revenue_return = is_numeric( $this->value( $response, 'revenue_returning_visit' ) ) ? number_format( (float) $this->value( $response, 'revenue_returning_visit' ), 2 ) : '';
			$table_body     = array(
				array( __( 'Conversions', 'wp-piwik' ) . ':', $this->value( $response, 'nb_conversions' ) ),
				array( __( 'Visits converted', 'wp-piwik' ) . ':', $this->value( $response, 'nb_visits_converted' ) ),
				array( __( 'Revenue', 'wp-piwik' ) . ':', $revenue ),
				array( __( 'Conversion rate', 'wp-piwik' ) . ':', $this->value( $response, 'conversion_rate' ) ),
				array( __( 'Conversions (new visitor)', 'wp-piwik' ) . ':', $this->value( $response, 'nb_conversions_new_visit' ) ),
				array( __( 'Visits converted (new visitor)', 'wp-piwik' ) . ':', $this->value( $response, 'nb_visits_converted_new_visit' ) ),
				array( __( 'Revenue (new visitor)', 'wp-piwik' ) . ':', $revenue_new ),
				array( __( 'Conversion rate (new visitor)', 'wp-piwik' ) . ':', $this->value( $response, 'conversion_rate_new_visit' ) ),
				array( __( 'Conversions (returning visitor)', 'wp-piwik' ) . ':', $this->value( $response, 'nb_conversions_returning_visit' ) ),
				array( __( 'Visits converted (returning visitor)', 'wp-piwik' ) . ':', $this->value( $response, 'nb_visits_converted_returning_visit' ) ),
				array( __( 'Revenue (returning visitor)', 'wp-piwik' ) . ':', $revenue_return ),
				array( __( 'Conversion rate (returning visitor)', 'wp-piwik' ) . ':', $this->value( $response, 'conversion_rate_returning_visit' ) ),
			);
			$table_foot     = self::$settings->get_global_option( 'piwik_shortcut' )
				? array(
					esc_html__( 'Shortcut', 'wp-piwik' ) . ':',
					'<a href="' . esc_attr( self::$settings->get_global_option( 'piwik_url' ) ) . '">Piwik</a>',
				)
				: null;
			$this->table( $table_head, $table_body, $table_foot );
		}
	}
}
