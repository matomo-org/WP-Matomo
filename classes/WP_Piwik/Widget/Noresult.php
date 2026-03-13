<?php

	namespace WP_Piwik\Widget;

class Noresult extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$time_settings   = $this->get_time_settings();
		$this->parameter = array(
			'idSite' => self::$wp_piwik->get_piwik_site_id( $this->blog_id ),
			'period' => $time_settings['period'],
			'date'   => $time_settings['date'],
		);
		$this->title     = $prefix . __( 'Site Search', 'wp-piwik' ) . ' (' . $time_settings['description'] . ')';
		$this->method    = 'Actions.getSiteSearchNoResultKeywords';
	}

	public function show() {
		$response = self::$wp_piwik->request( $this->api_id[ $this->method ] );
		if (
			! empty( $response['result'] )
			&& 'error' === $response['result']
		) {
			echo '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $response['message'] );
		} else {
			$table_head = array( __( 'Keyword', 'wp-piwik' ), __( 'Requests', 'wp-piwik' ), __( 'Bounced', 'wp-piwik' ) );
			$table_body = array();
			$count      = 0;
			if ( is_array( $response ) ) {
				foreach ( $response as $row ) {
					++$count;
					$table_body[] = array( $row['label'], $row['nb_visits'], $row['bounce_rate'] );
					if ( 10 === $count ) {
						break;
					}
				}
			}
			$this->table( $table_head, $table_body, null );
		}
	}
}
