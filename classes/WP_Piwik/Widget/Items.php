<?php

namespace WP_Piwik\Widget;

class Items extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$time_settings   = $this->get_time_settings();
		$this->title     = $prefix . __( 'E-Commerce Items', 'wp-piwik' );
		$this->method    = 'Goals.getItemsName';
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
			$table_head = array(
				__( 'Label', 'wp-piwik' ),
				__( 'Revenue', 'wp-piwik' ),
				__( 'Quantity', 'wp-piwik' ),
				__( 'Orders', 'wp-piwik' ),
				__( 'Avg. price', 'wp-piwik' ),
				__( 'Avg. quantity', 'wp-piwik' ),
				__( 'Conversion rate', 'wp-piwik' ),
			);
			$table_body = array();
			if ( is_array( $response ) ) {
				foreach ( $response as $data ) {
					array_push(
						$table_body,
						array(
							$data['label'],
							number_format( $data['revenue'], 2 ),
							$data['quantity'],
							$data['orders'],
							number_format( $data['avg_price'], 2 ),
							$data['avg_quantity'],
							$data['conversion_rate'],
						)
					);
				}
			}
			$table_foot = array();
			$this->table( $table_head, $table_body, $table_foot );
		}
	}
}
