<?php

namespace WP_Piwik\Widget;

class Seo extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$this->parameter = array(
			'url' => get_bloginfo( 'url' ),
		);
		$this->title     = $prefix . __( 'SEO', 'wp-piwik' );
		$this->method    = 'SEO.getRank';
	}

	public function show() {
		$response = self::$wp_piwik->request( $this->api_id[ $this->method ] );
		if ( ! empty( $response['result'] ) && 'error' === $response['result'] ) {
			echo '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $response['message'] );
		} else {
			echo '<div class="table"><table class="widefat"><tbody>';
			if ( is_array( $response ) ) {
				foreach ( $response as $val ) {
					echo '<tr><td>' . ( isset( $val['logo_link'] ) && ! empty( $val['logo_link'] ) ? '<a href="' . esc_attr( $val['logo_link'] ) . '" title="' . esc_attr( $val['logo_tooltip'] ) . '">' . esc_html( $val['label'] ) . '</a>' : esc_html( $val['label'] ) ) . '</td><td>' . esc_html( $val['rank'] ) . '</td></tr>';
				}
			} else {
				echo '<tr><td>SEO module currently not available.</td></tr>';
			}
			echo '</tbody></table></div>';
		}
	}
}
