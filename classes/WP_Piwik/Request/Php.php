<?php

namespace WP_Piwik\Request;

class Php extends \WP_Piwik\Request {

	/**
	 * @var mixed
	 */
	private static $piwik_environment = false;

	protected function request( $id ) {
		$count = 0;
		$url   = self::$settings->get_global_option( 'piwik_url' );
		foreach ( self::$requests as $request_id => $config ) {
			if ( ! isset( self::$results[ $request_id ] ) ) {
				if ( '' !== self::$settings->get_global_option( 'filter_limit' ) && is_numeric( self::$settings->get_global_option( 'filter_limit' ) ) ) {
					$config['parameter']['filter_limit'] = self::$settings->get_global_option( 'filter_limit' );
				}
				$params                          = 'module=API&format=json&' . $this->build_url( $config, true );
				$map[ $count ]                   = $request_id;
				$result                          = $this->call( $id, $url, $params );
				self::$results[ $map[ $count ] ] = $result;
				++$count;
			}
		}
	}

	private function call( $id, $url, $params ) {
		if ( ! defined( 'PIWIK_INCLUDE_PATH' ) ) {
			return false;
		}
		if ( PIWIK_INCLUDE_PATH === false ) {
			return array(
				'result'  => 'error',
				'message' => __( 'Could not resolve', 'wp-piwik' ) . ' &quot;' . htmlentities( self::$settings->get_global_option( 'piwik_path' ) ) . '&quot;: ' . __( 'realpath() returns false', 'wp-piwik' ) . '.',
			);
		}
		if ( file_exists( PIWIK_INCLUDE_PATH . '/index.php' ) ) {
			require_once PIWIK_INCLUDE_PATH . '/index.php';
		}
		if ( file_exists( PIWIK_INCLUDE_PATH . '/core/API/Request.php' ) ) {
			require_once PIWIK_INCLUDE_PATH . '/core/API/Request.php';
		}
		if ( class_exists( '\Piwik\Application\Environment' ) && ! self::$piwik_environment ) {
			// Piwik 2.14.* compatibility fix
			self::$piwik_environment = new \Piwik\Application\Environment( null );
			self::$piwik_environment->init();
		}
		if ( class_exists( 'Piwik\FrontController' ) ) {
			\Piwik\FrontController::getInstance()->init();
		} else {
			return array(
				'result'  => 'error',
				'message' => __( 'Class Piwik\FrontController does not exists.', 'wp-piwik' ),
			);
		}
		if ( class_exists( 'Piwik\API\Request' ) ) {
			$request = new \Piwik\API\Request( $params . '&token_auth=' . self::$settings->get_global_option( 'piwik_token' ) );
		} else {
			return array(
				'result'  => 'error',
				'message' => __( 'Class Piwik\API\Request does not exists.', 'wp-piwik' ),
			);
		}

		$result = $request->process();

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html', true );
		}
		$result = $this->unserialize( $result );
		if ( $GLOBALS ['wp-piwik_debug'] ) {
			self::$debug[ $id ] = array( $params . '&token_auth=...' );
		}
		return $result;
	}

	public function reset() {
		if (
			class_exists( '\Piwik\Application\Environment' )
			&& self::$piwik_environment instanceof \Piwik\Application\Environment
		) {
			self::$piwik_environment->destroy();
		}
		if ( class_exists( 'Piwik\FrontController' ) ) {
			\Piwik\FrontController::unsetInstance();
		}
		parent::reset();
	}
}
