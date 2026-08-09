<?php

namespace WP_Piwik\Widget;

class OptOut extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$this->parameter = array(
			'width'    => isset( $params['width'] ) ? $params['width'] : '100%',
			'height'   => isset( $params['height'] ) ? $params['height'] : '200px',
			'idsite'   => isset( $params['idsite'] ) ? $params['idsite'] : '',
			'language' => isset( $params['language'] ) ? $params['language'] : 'en',
		);
	}

	public function show() {
		$protocol = ( isset( $_SERVER ['HTTPS'] ) && 'off' !== $_SERVER ['HTTPS'] ) ? 'https' : 'http';
		switch ( self::$settings->get_global_option( 'piwik_mode' ) ) {
			case 'php':
				$piwik_url = $protocol . ':' . self::$settings->get_global_option( 'proxy_url' );
				break;
			case 'cloud':
				$piwik_url = 'https://' . self::$settings->get_global_option( 'piwik_user' ) . '.innocraft.cloud/';
				break;
			case 'cloud-matomo':
				$piwik_url = 'https://' . self::$settings->get_global_option( 'matomo_user' ) . '.matomo.cloud/';
				break;
			default:
				$piwik_url = self::$settings->get_global_option( 'piwik_url' );
				break;
		}
		// width and height end up in HTML attributes, where esc_attr() is the
		// right encoding; idsite and language end up in the iframe URL
		$width    = $this->parameter['width'];
		$height   = $this->parameter['height'];
		$idsite   = ( '' !== $this->parameter['idsite'] ? 'idsite=' . (int) $this->parameter['idsite'] . '&' : '' );
		$language = rawurlencode( $this->parameter['language'] );
		$this->out( '<iframe frameborder="no" width="' . esc_attr( $width ) . '" height="' . esc_attr( $height ) . '" src="' . esc_attr( $piwik_url . 'index.php?module=CoreAdminHome&action=optOut&' . $idsite . 'language=' . $language ) . '"></iframe>' );
	}
}
