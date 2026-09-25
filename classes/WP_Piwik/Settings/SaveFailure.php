<?php

namespace WP_Piwik\Settings;

class SaveFailure {

	/**
	 * The value names a tracker host the network does not allow the current user to use.
	 */
	const HOST_NOT_ALLOWED = 'host_not_allowed';

	/**
	 * The connection to Matomo was turned off while naming a Matomo URL the network does not
	 * allow, so the URL was removed.
	 */
	const HOST_REMOVED = 'host_removed';

	/**
	 * The value names a host outside ASCII that could not be converted to its ASCII form.
	 */
	const HOST_NOT_CONVERTIBLE = 'host_not_convertible';

	/**
	 * The value names a host outside ASCII, which this server cannot convert to its ASCII
	 * form because the PHP intl extension is missing.
	 */
	const HOST_NEEDS_INTL = 'host_needs_intl';

	/**
	 * The value is not a single host name label, as a cloud subdomain has to be.
	 */
	const INVALID_SUBDOMAIN = 'invalid_subdomain';

	/**
	 * @var string
	 */
	private $reason;

	/**
	 * @var string
	 */
	private $setting;

	/**
	 * @var string
	 */
	private $host;

	public function __construct( $reason, $setting, $host = '' ) {
		$this->reason  = $reason;
		$this->setting = $setting;
		$this->host    = $host;
	}

	public function get_reason() {
		return $this->reason;
	}

	public function get_setting() {
		return $this->setting;
	}

	public function get_host() {
		return $this->host;
	}

	public function get_message() {
		$labels = [
			'piwik_url'       => __( 'Matomo URL', 'wp-piwik' ),
			'track_cdnurl'    => __( 'CDN URL', 'wp-piwik' ),
			'track_cdnurlssl' => __( 'CDN URL (SSL)', 'wp-piwik' ),
			'piwik_user'      => __( 'Innocraft subdomain', 'wp-piwik' ),
			'matomo_user'     => __( 'Matomo subdomain', 'wp-piwik' ),
		];
		if ( ! isset( $labels[ $this->setting ] ) ) {
			return '';
		}

		switch ( $this->reason ) {
			case self::HOST_NOT_CONVERTIBLE:
				return sprintf(
					/* translators: %s: name of a setting of this page */
					esc_html__( '"%s" names a host outside ASCII that could not be converted to its ASCII form, so it was left as it was. Please check the host for a typo, such as two dots in a row.', 'wp-piwik' ),
					esc_html( $labels[ $this->setting ] )
				);
			case self::HOST_NEEDS_INTL:
				return sprintf(
					/* translators: 1: name of a setting of this page, 2: an example host name outside ASCII, 3: the same host name in its ASCII form */
					esc_html__( '"%1$s" names a host outside ASCII, which this server cannot write the way a browser does because the PHP intl extension is missing, so it was left as it was. Please enter the host in its ASCII form instead, e.g. %3$s for %2$s.', 'wp-piwik' ),
					esc_html( $labels[ $this->setting ] ),
					'<code>bücher.example</code>',
					'<code>xn--bcher-kva.example</code>'
				);
			case self::INVALID_SUBDOMAIN:
				return sprintf(
					/* translators: 1: name of a setting of this page, 2: an example subdomain, 3: the URL that subdomain belongs to */
					esc_html__( '"%1$s" has to be the single name your Matomo is reachable under, e.g. %2$s of %3$s, so it was left as it was.', 'wp-piwik' ),
					esc_html( $labels[ $this->setting ] ),
					'<code>acme</code>',
					'<code>https://acme.' . ( 'piwik_user' === $this->setting ? 'innocraft' : 'matomo' ) . '.cloud/</code>'
				);
			default:
				return '';
		}
	}

	public static function get_not_allowed_hosts_message( array $hosts, array $allow_list ) {
		return sprintf(
			/* translators: 1: comma separated list of host names the save named, 2: comma separated list of host names the network allows */
			esc_html__( 'This site is not allowed to load its tracker from %1$s, so that part of the configuration was left as it was. A network administrator decides which servers a site may use, and this network allows: %2$s.', 'wp-piwik' ),
			self::format_list( $hosts ),
			self::format_list( $allow_list )
		);
	}

	public static function get_removed_hosts_message( array $hosts, array $allow_list ) {
		return sprintf(
			/* translators: 1: comma separated list of host names the Matomo URL named, 2: comma separated list of host names the network allows */
			esc_html__( 'Tracking was disabled, and the Matomo URL naming %1$s was removed, because this site is not allowed to load its tracker from it. A network administrator decides which servers a site may use, and this network allows: %2$s.', 'wp-piwik' ),
			self::format_list( $hosts ),
			self::format_list( $allow_list )
		);
	}

	public static function get_rejected_allow_list_entries_message( array $entries, array $stored_allow_list, array $allow_list ) {
		$message = sprintf(
			/* translators: 1: comma separated list of entries as they were written, 2: the most entries the list holds */
			esc_html__( '"Allowed tracker hosts" only holds host names, at most %2$d of them, so %1$s could not be saved. Every other entry was stored.', 'wp-piwik' ),
			self::format_list( $entries ),
			\WP_Piwik\TrackerHosts::MAX_ENTRIES
		);

		if ( empty( $stored_allow_list ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma separated list of host names */
				esc_html__( 'Nothing is stored now, so the sites of this network may use the default hosts again: %s.', 'wp-piwik' ),
				self::format_list( $allow_list )
			);
		}

		return $message;
	}

	private static function format_list( array $values ) {
		return '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $values ) ) . '</code>';
	}
}
