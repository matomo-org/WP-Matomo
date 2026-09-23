<?php

namespace WP_Piwik;

/**
 * The hosts a site of a network may load its tracker from.
 */
class TrackerHosts {

	const OPTION     = 'wp-piwik-allowed_tracker_hosts';
	const FORM_FIELD = 'allowed_tracker_hosts';

	const MAX_ENTRIES      = 50;
	const MAX_ENTRY_LENGTH = 253; // the longest a host name can be

	/**
	 * A host name, optionally preceded by a '*.' wildcard standing for any subdomain of
	 * it. A bare '*' is not an entry: a list that allows everything is what leaving the
	 * setting empty is for.
	 */
	const ENTRY_PATTERN = '/^(\*\.)?[a-z0-9]([a-z0-9\-._]*[a-z0-9])?$/';

	const HOST_PATTERN = '/^[a-z0-9]([a-z0-9\-._]*[a-z0-9])?$/';

	/**
	 * @var array<int, string>
	 */
	private $cloud_hosts = array( '*.matomo.cloud', '*.innocraft.cloud' );

	/**
	 * Whether the current user is required to pick a tracker host from the allow list.
	 *
	 * @return boolean
	 */
	public function is_enforced() {
		return $this->applies() && ! current_user_can( 'manage_network' );
	}

	/**
	 * Whether the current user may change the allow list itself.
	 *
	 * @return boolean
	 */
	public function can_edit_allow_list() {
		return $this->applies() && current_user_can( 'manage_network' );
	}

	/**
	 * Whether the network allowlist applies to the current install type.
	 *
	 * Only a network where the plugin is activated site by site does: there, the
	 * administrator of a single site configures their own tracking. A network wide
	 * activation keeps one configuration for the whole network, which only a network
	 * administrator can reach. And outside a network there is nobody above the
	 * administrator to name the hosts they may use.
	 *
	 * @return boolean
	 */
	private function applies() {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return ! is_plugin_active_for_network( 'wp-piwik/wp-piwik.php' );
	}

	/**
	 * Whether the current user may point the site at the given URL.
	 *
	 * @param string $url tracker URL, CDN URL or bare host
	 * @return boolean
	 */
	public function is_allowed_for_current_user( $url ) {
		if ( ! $this->is_enforced() ) {
			return true;
		}
		return $this->allows( $this->host_from_url( $url ) );
	}

	/**
	 * Whether the allow list names the given host
	 *
	 * @param string     $host host name
	 * @param array|null $allow_list entries to match against, null to read the network's
	 *                               own. Pass one when checking many hosts in a row, so
	 *                               the list is only built once.
	 * @return boolean
	 */
	public function allows( $host, $allow_list = null ) {
		$host = $this->normalize_host( $host );
		if ( '' === $host ) {
			return false;
		}

		if ( null === $allow_list ) {
			$allow_list = $this->get_allow_list();
		}

		foreach ( $allow_list as $entry ) {
			if ( $entry === $host ) {
				return true;
			}
			if ( 0 === strpos( $entry, '*.' ) ) {
				// '*.example.org' allows anything under '.example.org'
				$suffix = substr( $entry, 1 );
				if ( substr( $host, - strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the hosts a site of this network may load its tracker from
	 *
	 * @return array<int, string> allow list entries, never empty
	 */
	public function get_allow_list() {
		$entries = $this->get_stored_allow_list();
		if ( empty( $entries ) ) {
			$entries = $this->get_default_allow_list();
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$filtered = apply_filters( 'wp-piwik_allowed_tracker_hosts', $entries );
		if ( $filtered === $entries ) {
			return $entries;
		}

		$parsed = $this->parse( $filtered );
		if ( empty( $parsed ) ) {
			// filter returned nothing usable, so we revert to the default (otherwise the network
			// would not be able to load the JS tracker from anywhere)
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: name of a WordPress filter */
					esc_html__( 'The %s filter has to answer with a list of host names, each optionally preceded by a "*." wildcard. Nothing it answered with is a valid host, so the existing stored/default list is used instead.', 'wp-piwik' ),
					'<code>wp-piwik_allowed_tracker_hosts</code>'
				),
				'1.1.13'
			);
			return $entries;
		}

		return $parsed;
	}

	/**
	 * @return array<int, string> allow list entries, empty when none were ever stored
	 */
	public function get_stored_allow_list() {
		if ( ! is_multisite() ) {
			return array();
		}

		$entries = get_site_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		// only allow string entries
		$entries = array_filter( $entries, 'is_string' );

		return array_values( $entries );
	}

	public function get_default_allow_list() {
		$entries = $this->cloud_hosts;

		$host = $this->host_from_url( $this->get_network_matomo_url() );
		if ( '' !== $host ) {
			$entries[] = $host;
		}

		return $entries;
	}

	/**
	 * @param mixed $value new allow list, one host per line or comma separated
	 * @return boolean whether the current user was allowed to store the list
	 */
	public function update_allow_list( $value ) {
		if ( ! $this->can_edit_allow_list() ) {
			return false;
		}

		update_site_option( self::OPTION, $this->parse( $value ) );
		return true;
	}

	/**
	 * Drop from an allow list everything that is not a host it can name
	 *
	 * @param mixed $value allow list, one host per line or comma separated
	 * @return array<int, string> allow list entries
	 */
	public function parse( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}
		if ( ! is_string( $value ) ) {
			return array();
		}

		$entries = array();
		foreach ( preg_split( '/[\s,;]+/', $value ) as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if (
				'' === $entry
				|| strlen( $entry ) > self::MAX_ENTRY_LENGTH
				|| ! preg_match( self::ENTRY_PATTERN, $entry )
			) {
				continue;
			}

			$entries[] = $entry;
			if ( count( $entries ) >= self::MAX_ENTRIES ) {
				break;
			}
		}

		return array_values( array_unique( $entries ) );
	}

	/**
	 * Get the host a tracker URL names
	 *
	 * @param string $url tracker URL, CDN URL or bare host. A CDN URL is stored without
	 *                    a protocol while a Matomo URL may be stored with one, so a value
	 *                    that carries no protocol is read as a host rather than as a path.
	 * @return string host name, empty when the value names none
	 */
	public function host_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '~^([a-z][a-z0-9+.-]*:)?//~i', $url ) ) {
			$url = '//' . $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? $this->normalize_host( $host ) : '';
	}

	/**
	 * Get the Matomo the network itself is connected to
	 *
	 * @return string Matomo URL, empty when the network names none
	 */
	private function get_network_matomo_url() {
		if ( ! is_multisite() ) {
			return '';
		}

		// a network activated plugin keeps the Matomo URL in one network wide option
		$url = (string) get_site_option( 'wp-piwik_global-piwik_url', '' );
		if ( '' !== $url ) {
			return $url;
		}

		// activated site by site there is no network wide one, and the main site's is the
		// closest thing to a Matomo the network chose
		return (string) get_blog_option( get_main_site_id(), 'wp-piwik_global-piwik_url', '' );
	}

	/**
	 * @param mixed $host host name
	 * @return string lower case host name, empty when the value is not one
	 */
	private function normalize_host( $host ) {
		if ( ! is_string( $host ) ) {
			return '';
		}

		$host = strtolower( trim( $host ) );
		if ( '' === $host
			|| strlen( $host ) > self::MAX_ENTRY_LENGTH
			|| ! preg_match( self::HOST_PATTERN, $host ) ) {
			return '';
		}

		return $host;
	}
}
