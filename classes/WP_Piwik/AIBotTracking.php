<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @package matomo
 */

namespace WP_Piwik;

use LiteSpeed\ESI;

/**
 * Performs server side tracking for AI bots.
 *
 * Normal server side tracking: when no caching plugins or CDNs are being
 * used, this class will send tracking requests in the wp_footer hook.
 *
 * When advanced-cache.php is used: when a caching plugin creates an
 * advanced-cache.php file that WordPress uses, tracking must be done
 * through the standalone script in misc/track_ai_bot.php. This script
 * must be manually added to a user's wp-config.php file, right after
 * ABSPATH is defined.
 *
 * When .htaccess is used: when a caching plugin modifies the .htaccess
 * to serve cached files directly, AI bot tracking is not possible.
 *
 * When a CDN is used: when a CDN is used to serve cached content, AI
 * bot tracking can be accomplished through the use of ESI (Edge Side Includes).
 * In this case, this class outputs an `<esi:include>` directive that
 * loads the misc/track_ai_bot.php script. This technique will only work
 * for CDNs that support ESI.
 *
 * The misc/track_ai_bot.php script uses this class without all of WordPress loaded.
 * It can also be loaded outside of WordPress. Because of this, it is important
 * that the script and this class use as few total dependencies as possible. Otherwise,
 * AI bot tracking reduce the performance of requests to cached content.
 */

class AIBotTracking {

	private static $aiBotTracked = false;

	private static $extensionsToTrack = [
		'', // no extension

		'htm',
		'html',
		'php',
	];

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var AjaxTracker
	 */
	private $tracker;

	/**
	 * @param Settings $settings
	 * @param Logger   $logger
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function registerHooks() {
		add_action( 'litespeed_init', [ $this, 'litespeedInit' ] );
		add_action( 'wp_footer', [ $this, 'doAiBotTracking'], 999999 );
	}

	public function litespeedInit() {
		if ( class_exists( ESI::class )
			&& $this->settings->isAiBotTrackingEnabledViaEsiIncludes()
		) {
			ESI::set_has_esi();
		}
	}

	/**
	 * @param string|null $url
	 * @return void
	 */
	public function doAiBotTracking( $url = null ) {
		// track AI bots only once per request
		if ( self::$aiBotTracked ) {
			return;
		}

		self::$aiBotTracked = true;

		// if using ESI to track, and not within the track_ai_bot.php script, output the appropriate ESI tag
		$is_using_esi_to_track = $this->settings->isAiBotTrackingEnabledViaEsiIncludes();
		if (
			$is_using_esi_to_track
			&& empty( $GLOBALS['MATOMO_IN_AI_ESI'] )
		) {
			$track_script_url = plugins_url( '/misc/track_ai_bot.php', WP_PIWIK_FILE ) . '?mtm_esi=1&mtm_url=' . rawurlencode( AjaxTracker::getCurrentUrl() );
			echo '<esi:include src="' . esc_attr( $track_script_url ) . '" cache-control="no-cache" />';
			return;
		}

		if ( ! $this->isDoingAiBotTrackingThisRequest() ) {
			return;
		}

		$responseCode     = http_response_code();
		$requestElapsedMs = null;

		// cannot track request time if executed via an esi:include
		if (
			empty( $GLOBALS['MATOMO_IN_AI_ESI'] )
			&& array_key_exists( 'REQUEST_TIME_FLOAT', $_SERVER )
		) {
			$requestElapsedMs = (int) ( ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		}

		if ( empty( $responseCode ) ) {
			$responseCode = 200;
		}

		// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
		$source = 'wordpress';

		if ( empty( $url ) ) {
			$url = AjaxTracker::getCurrentUrl();
		}

		$tracker = $this->getTracker();

		$tracker->setUrl( $url );

		// cannot count bytes echo'd so no response size tracked
		$tracker->doTrackPageViewIfAIBot( $responseCode, null, $requestElapsedMs, $source );
	}

	public function shouldTrackCurrentPage() {
		if ( is_admin() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$requestPath = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );

		if ( preg_match( '/matomo\.php$/', $requestPath ) ) {
			return false;
		}

		if ( $this->isRequestForFile( $requestPath ) ) {
			return false;
		}

		return true;
	}

	private function isRequestForFile( $requestPath ) {
		if (
			! empty( $_SERVER['DOCUMENT_ROOT'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			&& is_dir( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) . $requestPath )
		) {
			return false;
		}

		$extension = pathinfo( $requestPath, PATHINFO_EXTENSION );
		return ! in_array( $extension, self::$extensionsToTrack, true );
	}

	public function isJsExecutionDetected() {
		return ! empty( $_COOKIE['matomo_has_js'] )
			&& '1' === $_COOKIE['matomo_has_js'];
	}

	private function isDoingAiBotTrackingThisRequest() {
		if ( ! empty( $GLOBALS['MATOMO_IN_AI_ESI'] ) ) {
			return true;
		}

		if ( ! $this->shouldTrackCurrentPage() ) {
			return false;
		}

		if ( $this->isJsExecutionDetected() ) {
			return false;
		}

		$tracker = $this->getTracker();
		if ( ! AjaxTracker::isUserAgentAIBot( $tracker->userAgent ) ) {
			return false;
		}

		if (
			! $this->settings->isAiBotTrackingEnabled()
			|| ! $this->settings->isTrackingEnabled()
		) {
			return false;
		}

		return true;
	}

	private function getTracker() {
		if ( empty( $this->tracker ) ) {
			$this->tracker  = new AjaxTracker( $this->settings, $this->logger );
			$this->tracker->setRequestTimeout( 1 );
		}

		return $this->tracker;
	}
}
