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

	const HOST_PATTERN = '/^[a-z0-9]([a-z0-9\-._]*[a-z0-9])?$/';

	/**
	 * @var array<int, string>
	 */
	private $cloud_hosts = array( '*.matomo.cloud', '*.innocraft.cloud' );

	/**
	 * @var array<int, string> values the last allowlist save attempt could not hold
	 */
	private $rejected_entries = array();

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

		/**
		 * Filter the hosts a site of this network may load its tracker from.
		 *
		 * @since 1.1.13
		 *
		 * @param array<int, string> $entries host names, each optionally preceded by a
		 *                                    '*.' wildcard
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$filtered = apply_filters( 'wp-piwik_allowed_tracker_hosts', $entries );
		if ( $filtered === $entries ) {
			return $entries;
		}

		// the entry count is bounded to keep a list somebody types from growing the option
		// without end. a list that comes from code was written by somebody who knows how
		// many hosts their network runs, so no limit is enforced.
		$parsed = $this->parse( $filtered, null );
		if ( empty( $parsed['entries'] ) ) {
			// filter returned nothing usable, so we revert to the default (otherwise the network
			// would not be able to load the JS tracker from anywhere)
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: name of a WordPress filter */
					__( 'The %s filter has to answer with a list of host names, each optionally preceded by a "*." wildcard. Nothing it answered with is a valid host, so the existing stored/default list is used instead.', 'wp-piwik' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					'<code>wp-piwik_allowed_tracker_hosts</code>'
				),
				'1.1.13'
			);
			return $entries;
		}

		return $parsed['entries'];
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

		// another plugin or a wp-cli call can write anything into a network option, and an
		// entry that is not a host name would be compared against one
		$parsed = $this->parse( $entries );

		return $parsed['entries'];
	}

	/**
	 * Get the hosts a site of this network may load the Matomo JS tracker from when the network
	 * does not specify its own allowlist.
	 *
	 * The two Matomo clouds, which Matomo and InnoCraft run, and nothing else.
	 *
	 * Note: the current site URL is left out, because the site administrator would then be able
	 * to point the tracker to a file in the uploads directory, effectively adding arbitrary JavaScript
	 * to the frontend.
	 *
	 * @return array<int, string> allow list entries
	 */
	public function get_default_allow_list() {
		return $this->cloud_hosts;
	}

	/**
	 * @param mixed $value new allow list, one host per line or comma separated
	 * @return boolean whether the current user was allowed to store the list
	 */
	public function update_allow_list( $value ) {
		$this->rejected_entries = array();

		if ( ! $this->can_edit_allow_list() ) {
			return false;
		}

		$parsed                 = $this->parse( $value );
		$this->rejected_entries = $parsed['rejected'];
		update_site_option( self::OPTION, $parsed['entries'] );
		return true;
	}

	/**
	 * Get the entries the last stored allow list could not hold
	 *
	 * A list that ends up holding nothing is a list that does not apply, so an entry this
	 * cannot read is the difference between a network that restricted its sites and one
	 * that believes it did.
	 *
	 * @return array<int, string> entries as they were written, empty when the list held
	 *                            every one of them
	 */
	public function get_rejected_entries() {
		return $this->rejected_entries;
	}

	/**
	 * Read an allow list into the hosts it names and the entries it cannot name one with.
	 *
	 * @param mixed    $value allow list, one host per line or comma separated
	 * @param int|null $max_entries most entries to keep, null to keep every one
	 * @return array{entries: array<int, string>, rejected: array<int, string>} the hosts the
	 *         list names, and the entries that were rejected
	 */
	public function parse( $value, $max_entries = self::MAX_ENTRIES ) {
		$entries  = array();
		$rejected = array();

		foreach ( self::split_into_entries( $value ) as $written ) {
			$entry = $this->normalize_entry( $written );

			if ( '' !== $entry && in_array( $entry, $entries, true ) ) {
				continue; // entry is a duplicate, ignore it
			}

			$is_past_entry_limit = null !== $max_entries && count( $entries ) >= $max_entries;
			if ( '' === $entry || $is_past_entry_limit ) {
				$rejected[] = $written;
				continue;
			}

			$entries[] = $entry;
		}

		return array(
			'entries'  => $entries,
			'rejected' => array_values( array_unique( $rejected ) ),
		);
	}

	private static function split_into_entries( $value ) {
		if ( is_array( $value ) ) {
			// a POST can carry anything, and only a string is an entry somebody wrote
			$value = implode( "\n", array_filter( $value, 'is_string' ) );
		}

		if ( ! is_string( $value ) ) {
			return array();
		}

		$entries = preg_split( '/[\s,;]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		$entries = array_filter( $entries );
		$entries = array_values( $entries );
		return $entries;
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

	private function normalize_entry( $entry ) {
		if ( ! is_string( $entry ) ) {
			return '';
		}

		$entry = strtolower( trim( $entry ) );

		// the wildcard belongs to the entry rather than to the host name, so it is taken
		// off before the host is read and put back afterwards
		$wildcard = '';
		if ( 0 === strpos( $entry, '*.' ) ) {
			$wildcard = '*.';
			$entry    = substr( $entry, 2 );
		}

		$host = $this->host_from_url( $entry );
		if (
			'' === $host
			|| strlen( $wildcard ) + strlen( $host ) > self::MAX_ENTRY_LENGTH
		) {
			return '';
		}

		return $wildcard . $host;
	}

	/**
	 * @param mixed $host host name
	 * @return string lower case ASCII host name, empty when the value is not one
	 */
	private function normalize_host( $host ) {
		if ( ! is_string( $host ) ) {
			return '';
		}

		$host = $this->to_ascii_host( strtolower( trim( $host ) ) );
		if ( '' === $host
			|| strlen( $host ) > self::MAX_ENTRY_LENGTH
			|| ! preg_match( self::HOST_PATTERN, $host )
		) {
			return '';
		}

		return $host;
	}

	/**
	 * Write a host name the way a browser resolves it
	 *
	 * @param string $host lower case host name
	 * @return string the same host in ASCII, empty when it has no ASCII form
	 */
	private function to_ascii_host( $host ) {
		if ( ! preg_match( '/[^\x00-\x7f]/', $host ) ) {
			return $host; // already ASCII, nothing to convert
		}

		// the same conversion the tracking code writes the host out with
		$ascii = \WP_Piwik\TrackingCode\Generator::to_ascii_host( $host );
		return null === $ascii ? '' : $ascii;
	}
}
