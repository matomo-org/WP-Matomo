<?php

namespace WP_Piwik\Widget;

use WP_Piwik\Widget;

class Visitors extends Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$time_settings   = $this->get_time_settings();
		$this->parameter = array(
			'idSite' => self::$wp_piwik->get_piwik_site_id( $this->blog_id ),
			'period' => isset( $params['period'] ) ? $params['period'] : $time_settings['period'],
			'date'   => 'last' . ( 'day' === $time_settings['period'] ? '30' : '12' ),
			'limit'  => null,
		);
		$this->title     = $prefix . __( 'Visitors', 'wp-piwik' ) . ' (' . $this->range_name() . ')';
		$this->method    = array( 'VisitsSummary.getVisits', 'VisitsSummary.getUniqueVisitors', 'VisitsSummary.getBounceCount', 'VisitsSummary.getActions' );
		$this->context   = 'normal';

		$version = self::$wp_piwik->get_plugin_version();
		wp_enqueue_script( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'js/wp-piwik.js', array(), $version, true );
		wp_enqueue_script( 'wp-piwik-chartjs', self::$wp_piwik->get_plugin_url() . 'js/chartjs/chart.min.js', array(), $version, false );
		wp_enqueue_style( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'css/wp-piwik.css', array(), $version );
	}

	public function request_data() {
		$response = array();
		$success  = true;
		foreach ( $this->method as $method ) {
			$response[ $method ] = self::$wp_piwik->request( $this->api_id[ $method ] );
			if ( ! empty( $response[ $method ]['result'] ) && 'error' === $response[ $method ]['result'] ) {
				$success = false;
			}
		}
		return array(
			'response' => $response,
			'success'  => $success,
		);
	}

	public function show() {
		$result   = $this->request_data();
		$response = $result['response'];
		if ( ! $result['success'] ) {
			$message = '';
			if ( is_array( $this->method ) ) {
				foreach ( $this->method as $m ) {
					if ( empty( $response[ $m ]['message'] ) ) {
						continue;
					}

					$message .= ' ' . $m . ' - ' . $response[ $m ]['message'];
				}
			} else {
				$message = $response[ $this->method ]['message'];
			}

			echo '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $message );
		} else {
			$data = array();
			if ( is_array( $response ) && is_array( $response['VisitsSummary.getVisits'] ) ) {
				foreach ( $response['VisitsSummary.getVisits'] as $key => $value ) {
					if ( 'week' === $this->parameter['period'] ) {
						preg_match( '/[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/', $key, $date_list );
						$js_key   = $date_list[0];
						$text_key = $this->date_format( $js_key, 'week' );
					} elseif ( 'month' === $this->parameter['period'] ) {
						$js_key   = $key . '-01';
						$text_key = $key;
					} else {
						$js_key   = $key;
						$text_key = $key;
					}
					$data[]        = array(
						$text_key,
						$value,
						$response['VisitsSummary.getUniqueVisitors'][ $key ] ? $response['VisitsSummary.getUniqueVisitors'][ $key ] : '-',
						$response['VisitsSummary.getBounceCount'][ $key ] ? $response['VisitsSummary.getBounceCount'][ $key ] : '-',
						$response['VisitsSummary.getActions'][ $key ] ? $response['VisitsSummary.getActions'][ $key ] : '-',
					);
					$java_script[] = 'javascript:wp_piwik_datelink('
						. wp_json_encode( rawurlencode( 'wp-piwik_stats' ) ) . ','
						. wp_json_encode( str_replace( '-', '', $js_key ) ) . ','
						. wp_json_encode( isset( $_GET['wpmu_show_stats'] ) ? (int) $_GET['wpmu_show_stats'] : '' )
						. ');';
				}
			}
			$this->table(
				array( __( 'Date', 'wp-piwik' ), __( 'Visits', 'wp-piwik' ), __( 'Unique', 'wp-piwik' ), __( 'Bounced', 'wp-piwik' ), __( 'Page Views', 'wp-piwik' ) ),
				array_reverse( $data ),
				array(),
				'clickable',
				array_reverse( isset( $java_script ) ? $java_script : array() )
			);
		}
	}
}
