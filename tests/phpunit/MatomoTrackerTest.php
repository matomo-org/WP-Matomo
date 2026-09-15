<?php

namespace WP_Piwik\Tests;

if ( ! class_exists( '\WP_Piwik\MatomoTracker' ) ) {
	require_once dirname( __DIR__, 2 ) . '/libs/matomo-php-tracker/MatomoTracker.php';
}

/**
 * @phpcs:disable PHPCompatibility.FunctionDeclarations.NewReturnTypeDeclarations.boolFound
 */
class Testable_Matomo_Tracker extends \WP_Piwik\MatomoTracker {

	private $curl_support;

	public function __construct( $id_site, $curl_support ) {
		parent::__construct( $id_site );
		$this->curl_support = $curl_support;
	}

	public function send_request_public( $url ) {
		return $this->sendRequest( $url );
	}

	protected function hasCurlSupport(): bool {
		return $this->curl_support;
	}
}

/**
 * @phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
 */
class MatomoTrackerTest extends WP_Piwik_TestCase {

	public function test_sendRequest_should_return_an_empty_string_when_matomo_cannot_be_reached_without_curl() {
		$tracker = new Testable_Matomo_Tracker( 1, false );

		$this->assertSame( '', $tracker->send_request_public( $this->get_unreachable_url() ) );
	}

	public function test_sendRequest_should_leave_the_incoming_cookies_empty_when_matomo_cannot_be_reached_without_curl() {
		$tracker = new Testable_Matomo_Tracker( 1, false );

		$tracker->send_request_public( $this->get_unreachable_url() );

		$this->assertSame( [], $tracker->incomingTrackerCookies );
	}

	public function test_sendRequest_should_report_the_transport_error_when_matomo_cannot_be_reached_with_curl() {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			self::markTestSkipped( 'the curl extension is not available' );
		}

		$tracker = new Testable_Matomo_Tracker( 1, true );

		$this->expectException( \RuntimeException::class );

		$tracker->send_request_public( $this->get_unreachable_url() );
	}

	private function get_unreachable_url() {
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$this->assertNotFalse( $server, 'could not reserve a port: ' . $errstr );

		$address = stream_socket_get_name( $server, false );
		fclose( $server );

		return 'http://' . $address . '/matomo.php?idsite=1&rec=1';
	}
}
