<?php

namespace WP_Piwik\Widget;

class OptOut extends \WP_Piwik\Widget {

	public $class_name = __CLASS__;

	protected function configure( $prefix = '', $params = array() ) {
		$this->parameter = $params;
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
		$width    = ( isset( $this->parameter['width'] ) ? rawurlencode( $this->parameter['width'] ) : '' );
		$height   = ( isset( $this->parameter['height'] ) ? rawurlencode( $this->parameter['height'] ) : '' );
		$idsite   = ( isset( $this->parameter['idsite'] ) ? 'idsite=' . (int) $this->parameter['idsite'] . '&' : '' );
		$language = ( isset( $this->parameter['language'] ) ? rawurlencode( $this->parameter['language'] ) : 'en' );
		$this->out( '<iframe frameborder="no" width="' . esc_attr( $width ) . '" height="' . esc_attr( $height ) . '" src="' . esc_attr( $piwik_url . 'index.php?module=CoreAdminHome&action=optOut&' . $idsite . 'language=' . $language ) . '"></iframe>' );
	}
}
