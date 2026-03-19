<?php

namespace WP_Piwik\Widget;

use WP_Piwik\Widget;

class Chart extends Visitors {

	public $class_name = __CLASS__;

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
			$values     = array();
			$labels     = array();
			$bounced    = array();
			$unique     = array();
			$count      = 0;
			$unique_sum = 0;
			if ( is_array( $response['VisitsSummary.getVisits'] ) ) {
				foreach ( $response['VisitsSummary.getVisits'] as $date => $value ) {
					++$count;
					$values  [] = $value;
					$unique  [] = $response['VisitsSummary.getUniqueVisitors'][ $date ];
					$bounced [] = $response['VisitsSummary.getBounceCount'][ $date ];
					if ( 'week' === $this->parameter['period'] ) {
						preg_match( '/[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/', $date, $date_list );
						$text_key = $this->date_format( $date_list[0], 'short_week' );
					} else {
						$text_key = substr( $date, -2 );
					}
					$labels     [] = array( $text_key );
					$unique_sum   += $response['VisitsSummary.getActions'][ $date ];
				}
			} else {
				$values  = array( 0 );
				$labels  = array( array( 0, '-' ) );
				$unique  = array( 0 );
				$bounced = array( 0 );
			}
			$average = round( $unique_sum / 30, 0 );
			?>
			<div>
				<canvas id="wp-piwik_stats_vistors_graph" style="height:220px;"></canvas>
			</div>
			<script>
				new Chart(
					document.getElementById('wp-piwik_stats_vistors_graph'),
					{
						type: 'line',
						data: {
							labels: <?php echo wp_json_encode( $labels ); ?>,
							datasets: [
								{
									label: 'Visitors',
									backgroundColor: '#0277bd',
									borderColor: '#0277bd',
									data: <?php echo wp_json_encode( $values ); ?>,
									borderWidth: 1
								},
								{
									label: 'Unique',
									backgroundColor: '#ff8f00',
									borderColor: '#ff8f00',
									data: <?php echo wp_json_encode( $unique ); ?>,
									borderWidth: 1
								},
								{
									label: 'Bounced',
									backgroundColor: '#ad1457',
									borderColor: '#ad1457',
									data: <?php echo wp_json_encode( $bounced ); ?>,
									borderWidth: 1
								},
							]
						},
						options: {}
					}
				);
			</script>
			<?php
		}
	}
}
