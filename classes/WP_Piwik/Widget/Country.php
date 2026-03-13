<?php

	namespace WP_Piwik\Widget;

	use WP_Piwik\Widget;

class Country extends Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$time_settings   = $this->get_time_settings();
		$this->parameter = array(
			'idSite' => self::$wp_piwik->get_piwik_site_id( $this->blog_id ),
			'period' => $time_settings['period'],
			'date'   => $time_settings['date'],
		);
		$this->title     = $prefix . __( 'Countries', 'wp-piwik' ) . ' (' . $time_settings['description'] . ')';
		$this->method    = 'UserCountry.getCountry ';
		$this->context   = 'normal';

		$version = self::$wp_piwik->get_plugin_version();
		wp_enqueue_script( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'js/wp-piwik.js', array(), $version, true );
		wp_enqueue_script( 'wp-piwik-chartjs', self::$wp_piwik->get_plugin_url() . 'js/chartjs/chart.min.js', array(), $version, false );
		wp_enqueue_style( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'css/wp-piwik.css', array(), $version );
	}

	public function show() {
		$response   = self::$wp_piwik->request( $this->api_id[ $this->method ] );
		$table_body = array();
		if ( ! empty( $response['result'] ) && 'error' === $response['result'] ) {
			echo '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $response['message'] );
		} else {
			$table_head = array( __( 'Country', 'wp-piwik' ), __( 'Unique', 'wp-piwik' ), __( 'Percent', 'wp-piwik' ) );
			if ( isset( $response[0]['nb_uniq_visitors'] ) ) {
				$unique = 'nb_uniq_visitors';
			} else {
				$unique = 'sum_daily_nb_uniq_visitors';
			}
			$count     = 0;
			$sum       = 0;
			$js        = array();
			$css_class = array();
			if ( is_array( $response ) ) {
				foreach ( $response as $row ) {
					++$count;
					$sum += isset( $row[ $unique ] ) ? $row[ $unique ] : 0;
					if ( $count < $this->limit ) {
						$table_body[ $row['label'] ] = array( $row['label'], $row[ $unique ], 0 );
					} elseif ( ! isset( $table_body['Others'] ) ) {
						$table_body['Others']        = array( $row['label'], $row[ $unique ], 0 );
						$css_class['Others']         = 'wp-piwik-hideDetails';
						$js['Others']                = '$j' . "( '.wp-piwik-hideDetails' ).toggle( 'hidden' );";
						$table_body[ $row['label'] ] = array( $row['label'], $row[ $unique ], 0 );
						$css_class[ $row['label'] ]  = 'wp-piwik-hideDetails hidden';
						$js[ $row['label'] ]         = '$j' . "( '.wp-piwik-hideDetails' ).toggle( 'hidden' );";
					} else {
						$table_body['Others'][1]    += $row[ $unique ];
						$table_body[ $row['label'] ] = array( $row['label'], $row[ $unique ], 0 );
						$css_class[ $row['label'] ]  = 'wp-piwik-hideDetails hidden';
						$js[ $row['label'] ]         = '$j' . "( '.wp-piwik-hideDetails' ).toggle( 'hidden' );";
					}
				}
			}
			if ( $count > $this->limit ) {
				$table_body['Others'][0] = __( 'Others', 'wp-piwik' );
			} elseif ( $count === $this->limit ) {
				$css_class['Others'] = '';
				$js['Others']        = '';
			}

			foreach ( $table_body as $key => $row ) {
				$table_body[ $key ][2] = number_format( $row[1] / $sum * 100, 2 ) . '%';
			}

			if ( ! empty( $table_body ) ) {
				$this->pie_chart( $table_body );
			}

			$this->table( $table_head, $table_body, null, false, $js, $css_class );
		}
	}
}
