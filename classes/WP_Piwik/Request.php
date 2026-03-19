<?php

namespace WP_Piwik;

abstract class Request {

	/**
	 * @var \WP_Piwik
	 */
	protected static $wp_piwik;

	/**
	 * @var Settings
	 */
	protected static $settings;
	protected static $debug;
	protected static $last_error   = '';
	protected static $requests     = array();
	protected static $results      = array();
	protected static $is_cacheable = array();
	protected static $piwik_version;

	public function __construct( $wp_piwik, $settings ) {
		self::$wp_piwik = $wp_piwik;
		self::$settings = $settings;
		self::register( 'API.getPiwikVersion', array() );
	}

	public function reset() {
		self::$debug         = null;
		self::$requests      = array();
		self::$results       = array();
		self::$is_cacheable  = array();
		self::$piwik_version = null;
	}

	public static function register( $method, $parameter ) {
		if ( 'API.getPiwikVersion' === $method ) {
			$id = 'global.getPiwikVersion';
		} else {
			$id = 'method=' . $method . self::parameter_to_string( $parameter );
		}
		if (
			in_array( $method, array( 'API.getPiwikVersion', 'SitesManager.getJavascriptTag', 'SitesManager.getSitesWithAtLeastViewAccess', 'SitesManager.getSitesIdFromSiteUrl', 'SitesManager.addSite', 'SitesManager.updateSite', 'SitesManager.getSitesWithAtLeastViewAccess' ), true ) ||
			! isset( $parameter['date'] ) ||
			! isset( $parameter['period'] ) ||
			'last' === substr( $parameter['date'], 0, 4 ) ||
			'today' === $parameter['date'] ||
			( 'day' === $parameter['period'] && gmdate( 'Ymd' ) === $parameter['date'] ) ||
			( 'month' === $parameter['period'] && gmdate( 'Ym' ) === $parameter['date'] ) ||
			( 'week' === $parameter['period'] && gmdate( 'Ymd', strtotime( 'last Monday' ) ) === $parameter['date'] )
		) {
			self::$is_cacheable[ $id ] = false;
		} else {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			self::$is_cacheable[ $id ] = $method . '-' . serialize( $parameter );
		}
		if ( ! isset( self::$requests[ $id ] ) ) {
			self::$requests[ $id ] = array(
				'method'    => $method,
				'parameter' => $parameter,
			);
		}
		return $id;
	}

	private static function parameter_to_string( $parameter ) {
		$return = '';
		if ( is_array( $parameter ) ) {
			foreach ( $parameter as $key => $value ) {
				$return .= '&' . $key . '=' . $value;
			}
		}
		return $return;
	}

	public function perform( $id ) {
		if ( self::$settings->get_global_option( 'cache' ) ) {
			$cached = get_transient( 'wp-piwik_c_' . md5( self::$is_cacheable[ $id ] ) );
			if ( ! empty( $cached ) && ! ( ! empty( $cached['result'] ) && 'error' === $cached['result'] ) ) {
				self::$wp_piwik->log( 'Deliver cached data: ' . $id );
				return $cached;
			}
		}
		self::$wp_piwik->log( 'Perform request: ' . $id );
		if ( ! isset( self::$requests[ $id ] ) ) {
			return array(
				'result'  => 'error',
				'message' => 'Request ' . $id . ' was not registered.',
			);
		} elseif ( ! isset( self::$results[ $id ] ) ) {
			$this->request( $id );
		}
		if ( isset( self::$results[ $id ] ) ) {
			if ( self::$settings->get_global_option( 'cache' ) && self::$is_cacheable[ $id ] ) {
				set_transient( 'wp-piwik_c_' . md5( self::$is_cacheable[ $id ] ), self::$results[ $id ], WEEK_IN_SECONDS );
			}
			return self::$results[ $id ];
		} else {
			return false;
		}
	}

	public function get_debug( $id ) {
		return isset( self::$debug[ $id ] ) ? self::$debug[ $id ] : false;
	}

	protected function build_url( $config, $url_decode = false ) {
		$url = 'method=' . ( $config['method'] ) . '&idSite=' . self::$settings->get_option( 'site_id' );
		foreach ( $config['parameter'] as $key => $value ) {
			$url .= '&' . $key . '=' . ( $url_decode ? rawurldecode( $value ) : $value );
		}
		return $url;
	}

	protected function unserialize( $str ) {
		self::$wp_piwik->log( 'Result string: ' . $str );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return ( json_decode( '', true ) === $str || false !== @json_decode( $str, true ) ) ? json_decode( $str, true ) : array();
	}

	public static function get_last_error() {
		return self::$last_error;
	}

	abstract protected function request( $id );
}
