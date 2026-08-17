<?php

namespace WP_Piwik;

/**
 * Renders the [wp-piwik] shortcode
 */
class Shortcode {

	const TAG = 'wp-piwik';

	const MAX_BLOCK_PASSES = 10;

	private $available = array(
		'opt-out'  => 'OptOut',
		'post'     => 'Post',
		'overview' => 'Overview',
	);

	/**
	 * Attributes a shortcode may use, and their defaults
	 *
	 * Only the attributes listed here reach the widgets. Anything else an author
	 * writes is dropped.
	 *
	 * The defaults are null on purpose: the widgets test their parameters with
	 * isset(), so each widget applies its own fallback.
	 *
	 * @var array
	 */
	private $supported = array(
		'module'   => 'overview',
		'title'    => null,
		'period'   => null,
		'date'     => null,
		'limit'    => null,
		'width'    => null,
		'height'   => null,
		'idsite'   => null,
		'language' => null,
		'range'    => null,
		'key'      => null,
		'url'      => null,
	);

	/**
	 * @var \WP_Piwik
	 */
	private $wp_piwik;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @param \WP_Piwik $wp_piwik
	 * @param Settings  $settings
	 */
	public function __construct( $wp_piwik, $settings ) {
		$this->wp_piwik = $wp_piwik;
		$this->settings = $settings;
	}

	/**
	 * Execute the shortcode
	 *
	 * @param array|string $attributes
	 *          attribute list as WordPress passes it, an empty string if the
	 *          shortcode was written without any attribute
	 * @return string|null shortcode output, null if no widget matched
	 */
	public function render( $attributes ) {
		$attributes = shortcode_atts(
			$this->supported,
			$attributes
		);
		$attributes = $this->sanitize_attributes( $attributes );

		// authorize before any API request is sent
		$denial = $this->check_authorization( $attributes );
		if ( null !== $denial ) {
			return $denial;
		}

		return $this->render_widget( $attributes );
	}

	/**
	 * Resolve the shortcodes of a reusable block against the block's own author
	 * instead of the embedding post's author. Must be done before the content-wide
	 * do_shortcode pass expands it in order to get the author of the block (this
	 * information is lost afterward).
	 *
	 * @param string $block_content rendered block markup
	 * @param array  $block         parsed block
	 * @return string block markup with this plugin's shortcodes resolved
	 */
	public function render_reusable_block( $block_content, $block ) {
		global $shortcode_tags, $post;

		// only run the gate if we need to (ie, the block has the shortcode in it)
		if ( empty( $block['blockName'] ) || 'core/block' !== $block['blockName'] || empty( $block['attrs']['ref'] ) ) {
			return $block_content;
		}
		if ( ! isset( $shortcode_tags[ self::TAG ] ) || false === strpos( $block_content, '[' . self::TAG ) ) {
			return $block_content;
		}
		$reusable_block = get_post( (int) $block['attrs']['ref'] );
		if ( ! $reusable_block instanceof \WP_Post ) {
			return $block_content;
		}

		$all_tags   = $shortcode_tags;
		$saved_post = $post;
		// the post is swapped because that is where the gate reads its subject from.
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			$shortcode_tags = array( self::TAG => $all_tags[ self::TAG ] );
			$post           = $reusable_block;

			// do_shortcode() peels one bracket level off [[wp-piwik]] rather than
			// expanding it, so a single pass would hand the tag on to the content wide
			// pass, which authorizes against the embedding post instead. keep expanding
			// while there is something here of ours left to expand.
			$passes = 0;
			do {
				$before        = $block_content;
				$block_content = do_shortcode( $block_content );
			} while (
				$before !== $block_content
				&& false !== strpos( $block_content, '[' . self::TAG )
				&& ++$passes < self::MAX_BLOCK_PASSES
			);
		} finally {
			$shortcode_tags = $all_tags;
			$post           = $saved_post;
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		return $this->disarm_leftover_tags( $block_content );
	}

	private function disarm_leftover_tags( $block_content ) {
		// &#091; and not &#91;, which do_shortcode() turns back into a bracket
		return preg_replace(
			'/\[(?=\[*' . preg_quote( self::TAG, '/' ) . '(?![\w-]))/',
			'&#091;',
			$block_content
		);
	}

	/**
	 * Let the widget matching the requested module produce the output
	 *
	 * @param array $attributes
	 *          attribute list, already reduced to the supported attributes
	 * @return string|null widget output, null if the module is unknown
	 */
	private function render_widget( $attributes ) {
		$this->wp_piwik->log( 'Check requested shortcode widget ' . $attributes['module'] );
		if ( isset( $attributes['module'] ) && isset( $this->available[ $attributes['module'] ] ) ) {
			$this->wp_piwik->log( 'Add shortcode widget ' . $this->available[ $attributes['module'] ] );
			$class  = '\\WP_Piwik\\Widget\\' . $this->available[ $attributes['module'] ];
			$widget = new $class( $this->wp_piwik, $this->settings, null, null, null, $attributes, true );
			$widget->show();
			return $widget->get();
		}
		return null;
	}

	/**
	 * Reject shortcode attribute values Matomo would not accept anyway.
	 *
	 * @param array $attributes attribute list, already reduced to the supported attributes
	 * @return array attribute list with unusable values removed
	 */
	private function sanitize_attributes( $attributes ) {
		if (
			null !== $attributes['period']
			&& ! in_array( $attributes['period'], array( 'day', 'week', 'month', 'year', 'range' ), true )
		) {
			$attributes['period'] = null;
		}
		if (
			null !== $attributes['date']
			&& ! preg_match( '/^(today|yesterday|(last|previous)\d{1,4}|\d{4}-\d{2}-\d{2}(,\d{4}-\d{2}-\d{2})?)$/', $attributes['date'] )
		) {
			$attributes['date'] = null;
		}
		if (
			null !== $attributes['language']
			&& ! preg_match( '/^[a-z]{2,3}(-[A-Za-z]+)?$/', $attributes['language'] )
		) {
			$attributes['language'] = null;
		}
		return $attributes;
	}

	/**
	 * Decide whether a shortcode may ask Matomo for data
	 *
	 * @param array $attributes
	 *          attribute list, already reduced to the supported attributes
	 * @return string|null output to return instead of the widget, or null if the
	 *          shortcode may run
	 */
	private function check_authorization( $attributes ) {
		// the opt-out iframe performs no API request and has to stay reachable by
		// anonymous visitors, so it is never gated.
		if ( 'opt-out' === $attributes['module'] ) {
			return null;
		}

		$post       = get_post();
		$post       = $post instanceof \WP_Post ? $post : null;
		$author_id  = null !== $post ? (int) $post->post_author : 0;
		$authorized = true;
		$reason     = 'filtered';

		if (
			$this->settings->get_global_option( 'shortcode_author_check' )
			&& null !== $post
			&& ! user_can( $author_id, 'wp-piwik_read_stats' )
		) {
			$authorized = false;
			$reason     = 'author';
		}
		// $post === null means the shortcode text comes from a theme template, a sidebar
		// widget or WP-CLI. editing any of those needs capabilities well above
		// wp-piwik_read_stats, so there is nobody left to authorize and the shortcode
		// is allowed through. sites that want this closed can use the filter below.

		// extra check for the "post" shortcode type
		if ( $authorized && 'post' === $attributes['module'] ) {
			if ( null === $post ) {
				$authorized = false;
				$reason     = 'no_post';
			} elseif (
				null !== $attributes['url']
				&& ! $this->is_url_allowed( $attributes['url'], $author_id )
			) {
				$authorized = false;
				$reason     = 'url';
			}
		}

		/**
		 * Filter whether a shortcode may request data from Matomo. The hook can return
		 * a WP_Error to deny with a reason.
		 *
		 * @param bool|\WP_Error $authorized whether the shortcode may run
		 * @param array          $attributes shortcode attributes
		 * @param \WP_Post|null  $post       post the shortcode is rendered in, if any
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores
		$authorized = apply_filters( 'wp-piwik_shortcode_authorized', $authorized, $attributes, $post );
		if ( $authorized instanceof \WP_Error ) {
			$reason     = $authorized;
			$authorized = false;
		}

		return $authorized ? null : $this->build_denial_output( $reason );
	}

	/**
	 * Check whether a post shortcode may report on a URL
	 *
	 * @param string $url
	 *          URL requested by the shortcode's url attribute
	 * @param int    $author_id
	 *          author of the post containing the shortcode
	 * @return bool true if the URL is a post on this site the author may read
	 */
	private function is_url_allowed( $url, $author_id ) {
		// url_to_postid() below looks for ?p= anywhere in the string it is given, ahead
		// of stripping the fragment itself, so a fragment left on here could name a
		// post other than the one the path names and authorize against that instead.
		$fragment_at = strpos( $url, '#' );
		if ( false !== $fragment_at ) {
			$url = substr( $url, 0, $fragment_at );
		}

		if ( ! $this->is_url_on_this_site( $url ) ) {
			return false;
		}
		// this also rules out /wp-admin/, archives and anything else that is not a
		// post, so the url attribute can only ever name another post on this site.
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return false;
		}
		return user_can( $author_id, 'read_post', $post_id );
	}

	/**
	 * Check whether a URL points at this site
	 *
	 * @param string $url
	 *          URL to check
	 * @return bool
	 */
	private function is_url_on_this_site( $url ) {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( empty( $target ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( ! empty( $target['host'] ) && strtolower( $target['host'] ) !== strtolower( $home['host'] ) ) {
			return false;
		}
		if ( ! $this->is_same_port( $target, $home ) ) {
			return false;
		}
		$home_path   = isset( $home['path'] ) ? $home['path'] : '/';
		$target_path = isset( $target['path'] ) ? $target['path'] : '/';
		return 0 === strpos( $target_path, $home_path );
	}

	private function is_same_port( $target, $home ) {
		$target_port = isset( $target['port'] ) ? (int) $target['port'] : null;
		$home_port   = isset( $home['port'] ) ? (int) $home['port'] : null;
		if ( $target_port === $home_port ) {
			return true;
		}
		$stated_port = null === $home_port ? $target_port : $home_port;
		return null === $target_port || null === $home_port
			? in_array( $stated_port, array( 80, 443 ), true )
			: false;
	}

	/**
	 * Build the output of a shortcode that was not allowed to run
	 *
	 * @param string|\WP_Error $reason
	 *          which check rejected the shortcode: author, no_post, url or filtered
	 * @return string output to render in place of the widget
	 */
	private function build_denial_output( $reason ) {
		switch ( $reason ) {
			case 'author':
				$message = __( 'the author of this post is not allowed to see the statistics.', 'wp-piwik' );
				break;
			case 'no_post':
				$message = __( 'the post module can only report on a post, and this shortcode was not rendered within one.', 'wp-piwik' );
				break;
			case 'url':
				$message = __( 'its url attribute does not name a post on this site the author is allowed to read.', 'wp-piwik' );
				break;
			default:
				if ( $reason instanceof \WP_Error ) {
					$message = $reason->get_error_message();
					$reason  = 'filtered';
				} else {
					$message = __( 'the wp-piwik_shortcode_authorized filter rejected it.', 'wp-piwik' );
				}
				break;
		}
		$this->wp_piwik->log( 'Shortcode blocked (' . $reason . '): ' . $message );
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<p class="wp-piwik-shortcode-denied"><em>'
			. esc_html(
				sprintf(
					/* translators: %1$s: plugin display name, %2$s: sentence fragment explaining which check rejected the shortcode */
					__( '%1$s: this shortcode was not rendered, because %2$s', 'wp-piwik' ),
					$this->settings->get_not_empty_global_option( 'plugin_display_name' ),
					$message
				)
			)
			. '</em></p>';
	}
}
