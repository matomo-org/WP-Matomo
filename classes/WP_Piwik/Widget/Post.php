<?php

namespace WP_Piwik\Widget;

class Post extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		global $post;
		$time_settings   = $this->get_time_settings();
		$this->parameter = array(
			'idSite'      => self::$wp_piwik->get_piwik_site_id( $this->blog_id ),
			'period'      => isset( $params['period'] ) ? $params['period'] : $time_settings['period'],
			'date'        => isset( $params['date'] ) ? $params['date'] : $time_settings['date'],
			'key'         => isset( $params['key'] ) ? $params['key'] : null,
			'pageUrl'     => isset( $params['url'] ) ? $params['url'] : rawurlencode( get_permalink( $post->ID ) ),
			'description' => $time_settings['description'],
		);
		$this->title     = $prefix . esc_html__( 'Overview', 'wp-piwik' ) . ' (' . $this->parameter['date'] . ')';
		$this->method    = 'Actions.getPageUrl';
	}

	public function show() {
		$response = self::$wp_piwik->request( $this->api_id[ $this->method ] );
		if ( ! empty( $response['result'] ) && 'error' === $response['result'] ) {
			echo '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $response['message'] );
		} else {
			if ( in_array( $this->parameter['date'], array( 'last30', 'last60', 'last90' ), true ) ) {
				$result = array();
				if ( is_array( $response ) ) {
					foreach ( $response as $data ) {
						if ( isset( $data[0] ) ) {
							foreach ( $data[0] as $key => $value ) {
								if ( isset( $result[ $key ] ) && is_numeric( $value ) ) {
									$result[ $key ] += $value;
								} elseif ( is_numeric( $value ) ) {
									$result[ $key ] = $value;
								} else {
									$result[ $key ] = 0;
								}
							}
						}
					}
					if ( isset( $result['nb_visits'] ) && $result['nb_visits'] > 0 ) {
						$result['nb_actions_per_visit'] = round( ( isset( $result['nb_actions'] ) ? $result['nb_actions'] : 0 ) / $result['nb_visits'], 1 );
						$result['bounce_rate']          = round( ( isset( $result['bounce_count'] ) ? $result['bounce_count'] : 0 ) / $result['nb_visits'] * 100, 1 ) . '%';
						$result['avg_time_on_site']     = round( ( isset( $result['sum_visit_length'] ) ? $result['sum_visit_length'] : 0 ) / $result['nb_visits'], 0 );
					} else {
						$result['nb_actions_per_visit'] = 0;
						$result['bounce_rate']          = 0;
						$result['avg_time_on_site']     = 0;
					}
				}
				$response = $result;
			} else {
				if ( isset( $response[0] ) ) {
					$response = $response[0];
				}
				if ( $this->parameter['key'] ) {
					$this->out( isset( $response[ $this->parameter['key'] ] ) ? esc_html( $response[ $this->parameter['key'] ] ) : '<em>not defined</em>' );
					return;
				}
			}
			$time       = isset( $response['sum_visit_length'] ) ? $this->time_format( $response['sum_visit_length'] ) : '-';
			$avg_time   = isset( $response['avg_time_on_site'] ) ? $this->time_format( $response['avg_time_on_site'] ) : '-';
			$table_head = null;
			$table_body = array( array( __( 'Visitors', 'wp-piwik' ) . ':', $this->value( $response, 'nb_visits' ) ) );
			if ( '-' !== $this->value( $response, 'nb_uniq_visitors' ) ) {
				array_push( $table_body, array( __( 'Unique visitors', 'wp-piwik' ) . ':', $this->value( $response, 'nb_uniq_visitors' ) ) );
			} elseif ( '-' !== $this->value( $response, 'sum_daily_nb_uniq_visitors' ) ) {
				array_push( $table_body, __( 'Unique visitors', 'wp-piwik' ) . ':', $this->value( $response, 'sum_daily_nb_uniq_visitors' ) );
			}
			array_push(
				$table_body,
				array( __( 'Page views', 'wp-piwik' ) . ':', $this->value( $response, 'nb_hits' ) . ' (&#216; ' . $this->value( $response, 'entry_nb_actions' ) . ')' ),
				array( __( 'Total time spent', 'wp-piwik' ) . ':', $time ),
				array( __( 'Bounce count', 'wp-piwik' ) . ':', $this->value( $response, 'entry_bounce_count' ) . ' (' . $this->value( $response, 'bounce_rate' ) . ')' ),
				array( __( 'Time/visit', 'wp-piwik' ) . ':', $avg_time ),
				array( __( 'Min. generation time', 'wp-piwik' ) . ':', $this->value( $response, 'min_time_generation' ) ),
				array( __( 'Max. generation time', 'wp-piwik' ) . ':', $this->value( $response, 'max_time_generation' ) )
			);
			if ( ! in_array( $this->parameter['date'], array( 'last30', 'last60', 'last90' ), true ) ) {
				array_push( $table_body, array( __( 'Time/visit', 'wp-piwik' ) . ':', $avg_time ), array( __( 'Max. page views in one visit', 'wp-piwik' ) . ':', $this->value( $response, 'max_actions' ) ) );
			}
			$table_foot = self::$settings->get_global_option( 'piwik_shortcut' )
				? array(
					esc_html__( 'Shortcut', 'wp-piwik' ) . ':',
					'<a href="' . esc_attr( self::$settings->get_global_option( 'piwik_url' ) ) . '">Piwik</a>',
				)
				: null;
			$this->table( $table_head, $table_body, $table_foot );
		}
	}
}
