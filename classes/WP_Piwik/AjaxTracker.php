<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */

namespace WP_Piwik;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // if accessed directly
}

if ( ! class_exists( '\WP_Piwik\PiwikTracker' ) ) {
	include_once __DIR__ . '/../../libs/matomo-php-tracker/MatomoTracker.php';
}

class AjaxTracker extends \WP_Piwik\MatomoTracker {

	private $hasCookie = false;
	private $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->logger = $logger;

		$idsite = $settings->getOption( 'site_id' );
		if ( ! $idsite ) {
			return;
		}

		$apiEndpoint = rtrim( $settings->getMatomoUrl(), '/' ) . '/matomo.php';

		parent::__construct( (int) $idsite, $apiEndpoint );

		$this->ip = false;

		if ( ! $settings->getGlobalOption( 'disable_cookies' ) ) {
			$cookie_domain = $this->getTrackingCookieDomain($settings);
			$this->enableCookies( $cookie_domain );
		} else {
			$this->disableCookieSupport();
		}

		if ( $this->loadVisitorIdCookie() ) {
			if ( ! empty( $this->cookieVisitorId ) ) {
				$this->hasCookie = true;
				$this->setVisitorIdSafe( $this->cookieVisitorId );
			}
		}
	}

	public function setVisitorIdSafe($visitor_id ) {
		try {
			$this->setVisitorId( $visitor_id );
		} catch ( \Exception $ex ) {
			// do not fatal if the visitor ID is invalid for some reason
			if ( ! $this->isInvalidVisitorIdError( $ex ) ) {
				throw $ex;
			}
		}
	}

	protected function setCookie( $cookieName, $cookieValue, $cookieTTL ) {
		if ( ! $this->hasCookie ) {
			// we only set / overwrite cookies if it is a visitor that has eg no JS enabled or ad blocker enabled etc.
			// this way we will track all cart updates and orders into the same visitor on following requests.
			// If we recognized the visitor before via cookie we want in our case to make sure to not overwrite
			// any cookie
			parent::setCookie( $cookieName, $cookieValue, $cookieTTL );
		}
	}

	protected function sendRequest( string $url, string $method = 'GET', $data = null, bool $force = false ): string {
		if ( ! $this->idSite ) {
			$this->logger->log('ecommerce tracking could not find idSite, cannot send request');
			return ''; // not installed or synced yet
		}

		if ( $this->isPrerender() ) {
			// do not track if for some reason we are prerendering
			return '';
		}

		$args = [
			'method'   => $method,
			'headers'  => [
				'User-Agent' => $this->userAgent,
			],
			'blocking' => false,
		];
		if ( ! empty( $data ) ) {
			$args['body'] = $data;
		}

		$url = $url . '&bots=1';

		try {
			$response = $this->wpRemoteRequest( $url, $args );
		} catch ( \Exception $ex ) {
			$this->logger->log( 'ajax_tracker: ' . $ex->getMessage() );
			return '';
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'ajax_tracker: ' . new \Exception( $response->get_error_message() ) );
			return '';
		}

		return $response['body'];
	}

	private function isInvalidVisitorIdError( \Exception $ex ) {
		return strpos( $ex->getMessage(), 'setVisitorId() expects' ) === 0;
	}

	/**
	 * See https://developer.chrome.com/docs/web-platform/prerender-pages
	 * @return bool
	 */
	private function isPrerender() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$purpose = strtolower( isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ) : '' );
		return strpos( $purpose, 'prefetch' ) !== false
			|| strpos( $purpose, 'prerender' ) !== false;
	}

	/**
	 * for tests to override
	 * @param string $url
	 * @param array $args
	 * @return array|\WP_Error
	 */
	protected function wpRemoteRequest($url, $args ) {
		return wp_remote_request( $url, $args );
	}

	/**
	 * In Connect Matomo we want to rely entirely on JavaScript tracker
	 * for creating cookies.
	 *
	 * @return void
	 */
	protected function setFirstPartyCookies() {
		// disabled
	}

	public static function getCurrentUrl(): string {
		return parent::getCurrentUrl();
	}

	public function getTrackingCookieDomain( Settings $settings ) {
		if (
			$settings->getGlobalOption( 'track_across' )
			|| $settings->getGlobalOption( 'track_crossdomain_linking' )
		) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! empty( $host ) ) {
				return '*.' . $host;
			}
		}

		return '';
	}
}
