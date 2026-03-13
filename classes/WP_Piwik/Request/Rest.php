<?php

namespace WP_Piwik\Request;

/**
 * TODO: switch to wp_remote_get
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */
class Rest extends \WP_Piwik\Request {

	protected function request( $id ) {
		$count  = 0;
		$url    = self::$settings->get_matomo_url();
		$params = 'module=API&method=API.getBulkRequest&format=json';

		$filter_limit = self::$settings->get_global_option( 'filter_limit' );
		if (
			$filter_limit > 0
			&& is_numeric( self::$settings->get_global_option( 'filter_limit' ) )
		) {
			$params .= '&filter_limit=' . self::$settings->get_global_option( 'filter_limit' );
		}
		foreach ( self::$requests as $request_id => $config ) {
			if ( ! isset( self::$results[ $request_id ] ) ) {
				$params       .= '&urls[' . $count . ']=' . rawurlencode( $this->build_url( $config ) );
				$map[ $count ] = $request_id;
				++$count;
			}
		}
		$use_curl = (
			function_exists( 'curl_init' )
			&& ini_get( 'allow_url_fopen' )
			&& 'curl' === self::$settings->get_global_option( 'http_connection' )
		) || (
			function_exists( 'curl_init' )
			&& ! ini_get( 'allow_url_fopen' )
		);
		$results  = $use_curl ? $this->curl( $id, $url, $params ) : $this->fopen( $id, $url, $params );
		if ( is_array( $results ) ) {
			foreach ( $results as $num => $result ) {
				if ( isset( $map[ $num ] ) ) {
					self::$results[ $map[ $num ] ] = $result;
				}
			}
		}
	}

	private function curl( $id, $url, $params ) {
		if ( 'post' === self::$settings->get_global_option( 'http_method' ) ) {
			$c = curl_init( $url );
			curl_setopt( $c, CURLOPT_POST, 1 );
			curl_setopt( $c, CURLOPT_POSTFIELDS, $params . '&token_auth=' . self::$settings->get_global_option( 'piwik_token' ) );
		} else {
			$c = curl_init( $url . '?' . $params . '&token_auth=' . self::$settings->get_global_option( 'piwik_token' ) );
		}
		curl_setopt( $c, CURLOPT_SSL_VERIFYPEER, ! self::$settings->get_global_option( 'disable_ssl_verify' ) );
		curl_setopt( $c, CURLOPT_SSL_VERIFYHOST, ! self::$settings->get_global_option( 'disable_ssl_verify_host' ) ? 2 : 0 );
		curl_setopt( $c, CURLOPT_USERAGENT, 'php' === self::$settings->get_global_option( 'piwik_useragent' ) ? ini_get( 'user_agent' ) : self::$settings->get_global_option( 'piwik_useragent_string' ) );
		curl_setopt( $c, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $c, CURLOPT_HEADER, $GLOBALS ['wp-piwik_debug'] );
		curl_setopt( $c, CURLOPT_TIMEOUT, self::$settings->get_global_option( 'connection_timeout' ) );
		$http_proxy_class = new \WP_HTTP_Proxy();
		if ( $http_proxy_class->is_enabled() && $http_proxy_class->send_through_proxy( $url ) ) {
			curl_setopt( $c, CURLOPT_PROXY, $http_proxy_class->host() );
			curl_setopt( $c, CURLOPT_PROXYPORT, $http_proxy_class->port() );
			if ( $http_proxy_class->use_authentication() ) {
				curl_setopt( $c, CURLOPT_PROXYUSERPWD, $http_proxy_class->username() . ':' . $http_proxy_class->password() );
			}
		}
		$result           = curl_exec( $c );
		self::$last_error = curl_error( $c );
		if ( $GLOBALS ['wp-piwik_debug'] ) {
			$header_size        = curl_getinfo( $c, CURLINFO_HEADER_SIZE );
			$header             = substr( $result, 0, $header_size );
			$body               = substr( $result, $header_size );
			$result             = $this->unserialize( $body );
			self::$debug[ $id ] = array( $header, $url . '?' . $params . '&token_auth=...' );
		} else {
			$result = $this->unserialize( $result );
		}
		curl_close( $c );
		return $result;
	}

	private function fopen( $id, $url, $params ) {
		$context_definition        = array(
			'http' => array(
				'timeout' => self::$settings->get_global_option( 'connection_timeout' ),
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			),
		);
		$context_definition['ssl'] = array();
		if ( self::$settings->get_global_option( 'disable_ssl_verify' ) ) {
			$context_definition['ssl'] = array(
				'allow_self_signed' => true,
				'verify_peer'       => false,
			);
		}
		if ( self::$settings->get_global_option( 'disable_ssl_verify_host' ) ) {
			$context_definition['ssl']['verify_peer_name'] = false;
		}
		if ( self::$settings->get_global_option( 'http_method' ) === 'post' ) {
			$full_url                              = $url;
			$context_definition['http']['method']  = 'POST';
			$context_definition['http']['content'] = $params . '&token_auth=' . self::$settings->get_global_option( 'piwik_token' );
		} else {
			$full_url = $url . '?' . $params . '&token_auth=' . self::$settings->get_global_option( 'piwik_token' );
		}
		$context = stream_context_create( $context_definition );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = $this->unserialize( @file_get_contents( $full_url, false, $context ) );
		if ( $GLOBALS ['wp-piwik_debug'] ) {
			self::$debug[ $id ] = array( get_headers( $full_url, 1 ), $url . '?' . $params . '&token_auth=...' );
		}
		return $result;
	}
}
