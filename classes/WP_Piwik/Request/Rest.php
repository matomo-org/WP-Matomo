<?php

namespace WP_Piwik\Request;

/**
 * TODO: switch to wp_remote_request
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
		list( $params, $map ) = $this->build_bulk_params();
		$url                  = self::$settings->get_matomo_url();
		$use_curl             = (
			function_exists( 'curl_init' )
			&& ini_get( 'allow_url_fopen' )
			&& 'curl' === self::$settings->get_global_option( 'http_connection' )
		) || (
			function_exists( 'curl_init' )
			&& ! ini_get( 'allow_url_fopen' )
		);
		$results              = $use_curl ? $this->curl( $id, $url, $params ) : $this->fopen( $id, $url, $params );
		if ( is_array( $results ) ) {
			foreach ( $results as $num => $result ) {
				if ( isset( $map[ $num ] ) ) {
					self::$results[ $map[ $num ] ] = $result;
				}
			}
		}
	}

	protected function build_bulk_params() {
		$count  = 0;
		$map    = [];
		$params = [
			'module' => 'API',
			'method' => 'API.getBulkRequest',
			'format' => 'json',
		];

		$filter_limit = self::$settings->get_global_option( 'filter_limit' );
		if (
			$filter_limit > 0
			&& is_numeric( $filter_limit )
		) {
			$params['filter_limit'] = $filter_limit;
		}
		$params['urls'] = [];
		foreach ( self::$requests as $request_id => $config ) {
			if ( ! isset( self::$results[ $request_id ] ) ) {
				$params['urls'][ $count ] = $this->build_url( $config );
				$map[ $count ]            = $request_id;
				++$count;
			}
		}
		return [ $params, $map ];
	}

	protected function build_param_string( $params, $mask_token = false ) {
		$params['token_auth'] = $mask_token ? '...' : self::$settings->get_global_option( 'piwik_token' );
		return http_build_query( $params, '', '&' );
	}

	protected function should_use_post() {
		return 'get' !== self::$settings->get_global_option( 'http_method' );
	}

	private function curl( $id, $url, $params ) {
		if ( $this->should_use_post() ) {
			$c = curl_init( $url );
			curl_setopt( $c, CURLOPT_POST, 1 );
			curl_setopt( $c, CURLOPT_POSTFIELDS, $this->build_param_string( $params ) );
		} else {
			$c = curl_init( $url . '?' . $this->build_param_string( $params ) );
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
		$response         = curl_exec( $c );
		self::$last_error = curl_error( $c );
		$body             = is_string( $response ) ? $response : '';
		if ( $GLOBALS ['wp-piwik_debug'] ) {
			$header_size        = curl_getinfo( $c, CURLINFO_HEADER_SIZE );
			$header             = substr( $body, 0, $header_size );
			$body               = substr( $body, $header_size );
			self::$debug[ $id ] = [ $header, ( $this->should_use_post() ? 'POST ' : 'GET ' ) . $url . ' ' . $this->build_param_string( $params, true ) ];
		}
		if ( '' === self::$last_error ) {
			// curl only reports transport failures, so an unusable body still needs checking
			self::$last_error = $this->check_response( false === $response ? false : $body, 'HTTP ' . curl_getinfo( $c, CURLINFO_HTTP_CODE ) );
		}
		$result = $this->unserialize( $body );
		if ( version_compare( PHP_VERSION, '8', '<' ) ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
			curl_close( $c );
		}
		return $result;
	}

	private function fopen( $id, $url, $params ) {
		$context_definition        = [
			'http' => [
				'timeout' => self::$settings->get_global_option( 'connection_timeout' ),
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			],
		];
		$context_definition['ssl'] = [];
		if ( self::$settings->get_global_option( 'disable_ssl_verify' ) ) {
			$context_definition['ssl'] = [
				'allow_self_signed' => true,
				'verify_peer'       => false,
			];
		}
		if ( self::$settings->get_global_option( 'disable_ssl_verify_host' ) ) {
			$context_definition['ssl']['verify_peer_name'] = false;
		}
		if ( $this->should_use_post() ) {
			$full_url                              = $url;
			$context_definition['http']['method']  = 'POST';
			$context_definition['http']['content'] = $this->build_param_string( $params );
		} else {
			$full_url = $url . '?' . $this->build_param_string( $params );
		}
		$context = stream_context_create( $context_definition );

		$http_response_header = [];
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$response         = @file_get_contents( $full_url, false, $context );
		self::$last_error = $this->check_response( $response, isset( $http_response_header[0] ) ? $http_response_header[0] : '' );
		$result           = $this->unserialize( $response );
		if ( $GLOBALS ['wp-piwik_debug'] ) {
			self::$debug[ $id ] = [ get_headers( $full_url, 1 ), ( $this->should_use_post() ? 'POST ' : 'GET ' ) . $url . ' ' . $this->build_param_string( $params, true ) ];
		}
		return $result;
	}

	/**
	 * Turn a failed or unusable response into an error message.
	 *
	 * @param string|false $response  response body, or false if the transfer failed.
	 * @param string       $status    status line of the response, if known.
	 * @return string error message, empty if the response looks usable.
	 */
	protected function check_response( $response, $status = '' ) {
		$suffix = '' !== $status ? ' (' . $status . ')' : '';
		if ( false === $response ) {
			$error = error_get_last();
			return ! empty( $error['message'] ) ? $error['message'] : 'Request to Matomo failed' . $suffix;
		}
		if ( null === json_decode( $response, true ) ) {
			return 'Matomo did not return a valid JSON response' . $suffix;
		}
		return '';
	}
}
