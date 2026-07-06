<?php

namespace WP_Piwik\Tests;

abstract class WP_Piwik_TestCase extends \WP_UnitTestCase {

	protected $captured_http_requests = [];

	protected $mock_http_response;

	private $server_backup;

	private $cookie_backup;

	public function set_up() {
		parent::set_up();

		$this->delete_plugin_options();

		$this->server_backup = $_SERVER;
		$this->cookie_backup = $_COOKIE;

		$_SERVER['HTTP_HOST']       = 'example.org';
		$_SERVER['REQUEST_URI']     = '/test-page/';
		$_SERVER['SCRIPT_NAME']     = '/index.php';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64)';
		unset( $_SERVER['HTTPS'], $_SERVER['QUERY_STRING'], $_SERVER['HTTP_SEC_PURPOSE'] );
		$_COOKIE = [];

		$this->captured_http_requests = [];
		$this->mock_http_response     = [
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'headers'  => [],
			'cookies'  => [],
			'filename' => null,
		];

		// intercept all outgoing HTTP requests; the filter is removed
		// automatically when WP_UnitTestCase restores the hooks
		add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );
	}

	public function tear_down() {
		$_SERVER = $this->server_backup;
		$_COOKIE = $this->cookie_backup;
		unset( $GLOBALS['WP_PIWIK_IN_ESI'] );
		parent::tear_down();
	}

	private function delete_plugin_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup, rolled back with the test transaction
		$option_names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'wp-piwik%' OR option_name LIKE '\_transient%wp-piwik%'"
		);
		foreach ( $option_names as $option_name ) {
			if ( 0 === strpos( $option_name, '_transient_timeout_' ) ) {
				continue; // removed by delete_transient() below
			}
			if ( 0 === strpos( $option_name, '_transient_' ) ) {
				delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $option_name );
			}
		}
	}

	public function intercept_http_request( $preempt, $args, $url ) {
		$this->captured_http_requests[] = [
			'url'  => $url,
			'args' => $args,
		];
		return $this->mock_http_response;
	}

	protected function get_request_user_agent( $request ) {
		if ( isset( $request['args']['headers']['User-Agent'] ) ) {
			return $request['args']['headers']['User-Agent'];
		}
		if ( isset( $request['args']['user-agent'] ) ) {
			return $request['args']['user-agent'];
		}
		return null;
	}

	protected function create_settings( $global_options = [], $options = [] ) {
		foreach ( $global_options as $key => $value ) {
			update_option( 'wp-piwik_global-' . $key, $value );
		}
		foreach ( $options as $key => $value ) {
			update_option( 'wp-piwik-' . $key, $value );
		}
		return new \WP_Piwik\Settings( new \WP_Piwik_Test_Fake_Plugin() );
	}
}
