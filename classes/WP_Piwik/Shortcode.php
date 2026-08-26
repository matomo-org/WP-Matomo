<?php

namespace WP_Piwik;

/**
 * Renders the [wp-piwik] shortcode
 */
class Shortcode {

	const TAG = 'wp-piwik';

	/**
	 * Post meta key the users who wrote a shortcode into somebody else's post are kept
	 * under.
	 *
	 * The name is protected, so the custom fields box cannot write it, and the meta
	 * is deliberately left unregistered, so the REST API cannot write it either.
	 */
	const AUTHORS_META_KEY = '_wp-piwik_shortcode_authors';

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
	 * The reusable blocks currently being rendered, outermost first.
	 *
	 * Used to get the authors to authorize against should the [wp-piwik] shortcode
	 * be used in a re-usable block. A pattern can embed another pattern and hand it
	 * text through pattern overrides, so every pattern on the way down could have
	 * written a shortcode the innermost one renders.
	 *
	 * An entry is null where the ref named no post. Retained in the array so the entry
	 * a caller closes is always the one it opened.
	 *
	 * Must be static because WP_Piwik::shortcode() builds a fresh instance for every
	 * shortcode it expands.
	 *
	 * @var array<\WP_Post|null>
	 */
	private static $open_reusable_blocks = array();

	/**
	 * Stack of routes of REST requests being dispatched, outermost first.
	 *
	 * Used to detect if we are in the block-rendering REST request.
	 *
	 * @var string[]
	 */
	private static $open_rest_routes = array();

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
	 * Resolve the shortcodes of a reusable block against its own author as well as
	 * every author on the way to it. Must be done before the content-wide
	 * do_shortcode pass expands it, because which pattern the text came from is lost
	 * afterward.
	 *
	 * @param string $block_content rendered block markup
	 * @param array  $block         parsed block
	 * @return string block markup with this plugin's shortcodes resolved
	 */
	public function render_reusable_block( $block_content, $block ) {
		if ( ! self::is_reusable_block( $block ) ) {
			return $block_content;
		}

		return $this->resolve_shortcodes_of_reusable_block( $block_content, $block );
	}

	/**
	 * Record every pattern while it renders, by wrapping the render callback of the
	 * core/block block. Meant to be used with the register_block_type_args filter.
	 *
	 * The render callback is the one thing every path core takes to a pattern goes
	 * through, so we use this approach instead of using block hooks.
	 *
	 * @param array  $args block type arguments
	 * @param string $name block type name, including its namespace
	 * @return array the arguments, with the render callback of core/block wrapped
	 */
	public static function wrap_reusable_block_renderer( $args, $name ) {
		if (
			'core/block' !== $name
			|| ! isset( $args['render_callback'] )
			|| ! is_callable( $args['render_callback'] )
		) {
			return $args;
		}

		$render_pattern = $args['render_callback'];

		$args['render_callback'] = function ( $attributes, $content, $block ) use ( $render_pattern ) {
			// core renders nothing at all for a block without a ref, so there is nobody to
			// record for one
			$ref    = isset( $attributes['ref'] ) ? $attributes['ref'] : 0;
			$opened = ! empty( $ref );
			if ( $opened ) {
				self::open_reusable_block( get_post( (int) $ref ) );
			}

			try {
				return call_user_func( $render_pattern, $attributes, $content, $block );
			} finally {
				if ( $opened ) {
					self::close_reusable_block();
				}
			}
		};

		return $args;
	}

	/**
	 * Record that somebody who is not the author of a post has written this plugin's
	 * shortcode into it. Meant to be used with the wp_after_insert_post hook.
	 *
	 * The gate authorizes against post_author, which names who created a post rather
	 * than who wrote what is in it now. Anybody with edit_others_posts can write a
	 * shortcode into a post, or into a pattern, that somebody else is the author of,
	 * and wp_block maps that capability the same way an ordinary post does. Everyone
	 * who edits is collected here, so that the gate can authorize against them as well.
	 *
	 * @param int           $post_id     post that was written
	 * @param \WP_Post      $post        post as it was written
	 * @param bool          $update      whether the post already existed
	 * @param \WP_Post|null $post_before post as it was before, null if it did not
	 */
	public static function record_shortcode_author( $post_id, $post, $update, $post_before ) {
		// a revision, and an autosave with it, is a copy of a post that is recorded in
		// its own right
		if ( 'revision' === $post->post_type ) {
			return;
		}

		$writer = get_current_user_id();
		if ( 0 === $writer || (int) $post->post_author === $writer ) {
			// nobody is signed in, so this is WP-CLI, cron or a plugin writing on nobody's
			// behalf and there is nobody to record. or the author wrote it, whom the gate
			// reads anyway.
			return;
		}

		// a save that did not modify post content does not need to be processed
		if ( $update && $post_before instanceof \WP_Post && $post_before->post_content === $post->post_content ) {
			return;
		}

		// posts without the shortcode do not need to be processed
		if ( false === strpos( $post->post_content, '[' . self::TAG ) ) {
			return;
		}

		$writers = self::get_recorded_shortcode_authors( $post_id );
		if ( in_array( $writer, $writers, true ) ) {
			return;
		}

		$writers[] = $writer;
		update_post_meta( $post_id, self::AUTHORS_META_KEY, $writers );
	}

	/**
	 * Forget everybody record_shortcode_author() has recorded for a single post.
	 *
	 * @param int $post_id post to forget
	 * @return bool whether there was anything recorded for it
	 */
	public static function forget_recorded_shortcode_authors( $post_id ) {
		return delete_post_meta( $post_id, self::AUTHORS_META_KEY );
	}

	/**
	 * Note which route a REST request is asking for. Meant to be used with the
	 * rest_request_before_callbacks hook.
	 *
	 * @param mixed            $response response to send instead of calling the handler, if any
	 * @param array            $handler  route handler matched for the request
	 * @param \WP_REST_Request $request  request being dispatched
	 * @return mixed the response, unchanged
	 */
	public static function record_open_rest_route( $response, $handler, $request ) {
		self::$open_rest_routes[] = (string) $request->get_route();
		return $response;
	}

	/**
	 * Drop the route noted by record_open_rest_route() for a REST request whose handler
	 * has returned. Meant to be used with the rest_request_after_callbacks hook.
	 *
	 * @param mixed $response response the handler produced
	 * @return mixed the response, unchanged
	 */
	public static function close_rest_route( $response ) {
		array_pop( self::$open_rest_routes );
		return $response;
	}

	/**
	 * Record a reusable block that is about to render.
	 *
	 * @param \WP_Post|mixed $reusable_block post the block embeds, anything else if the ref named none
	 */
	private static function open_reusable_block( $reusable_block ) {
		self::$open_reusable_blocks[] = $reusable_block instanceof \WP_Post ? $reusable_block : null;
	}

	/**
	 * Drop the entry a matching open_reusable_block() call made.
	 */
	private static function close_reusable_block() {
		array_pop( self::$open_reusable_blocks );
	}

	/**
	 * @param \WP_Post $reusable_block post a block embeds
	 * @return bool whether the innermost block still open is that same post
	 */
	private static function is_innermost_open_reusable_block( $reusable_block ) {
		$open = count( self::$open_reusable_blocks );
		if ( 0 === $open ) {
			return false;
		}

		$innermost = self::$open_reusable_blocks[ $open - 1 ];
		return $innermost instanceof \WP_Post && $innermost->ID === $reusable_block->ID;
	}

	/**
	 * @param array $block parsed block
	 * @return bool whether the block embeds a reusable block
	 */
	private static function is_reusable_block( $block ) {
		return ! empty( $block['blockName'] )
			&& 'core/block' === $block['blockName']
			&& ! empty( $block['attrs']['ref'] );
	}

	/**
	 * @param string $block_content rendered block markup
	 * @param array  $block         parsed block
	 * @return string block markup with this plugin's shortcodes resolved
	 */
	private function resolve_shortcodes_of_reusable_block( $block_content, $block ) {
		global $shortcode_tags;

		// only run the gate if we need to (ie, the block has the shortcode in it)
		if ( ! isset( $shortcode_tags[ self::TAG ] ) || false === strpos( $block_content, '[' . self::TAG ) ) {
			return $block_content;
		}
		$reusable_block = get_post( (int) $block['attrs']['ref'] );
		if ( ! $reusable_block instanceof \WP_Post ) {
			return $block_content;
		}

		// ensure the block being rendered is included in the authorization check,
		// even if it was never opened or was closed before we get here.
		$opened_here = ! self::is_innermost_open_reusable_block( $reusable_block );
		if ( $opened_here ) {
			self::open_reusable_block( $reusable_block );
		}

		$all_tags = $shortcode_tags;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		try {
			$shortcode_tags = array( self::TAG => $all_tags[ self::TAG ] );

			// exactly one pass, the way the content wide pass would expand this, so that
			// [[wp-piwik]] is peeled down to text rather than expanded.
			//
			// disarm_leftover_tags() below is what keeps whatever is left of ours from
			// reaching the content wide pass, which would authorize it against the
			// embedding post alone.
			$block_content = do_shortcode( $block_content );
		} finally {
			$shortcode_tags = $all_tags;
			if ( $opened_here ) {
				self::close_reusable_block();
			}
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		return $this->disarm_leftover_tags( $block_content );
	}

	private function disarm_leftover_tags( $block_content ) {
		$disarmed = preg_replace(
			'/\[(' . preg_quote( self::TAG, '/' ) . '(?![\w-]))/',
			'&#091;$1',
			$block_content
		);
		if ( null === $disarmed ) {
			error_log( 'wp-piwik: could not disarm the shortcodes of a reusable block (preg_last_error ' . preg_last_error() . '), dropped its content' );
			return '';
		}

		return $disarmed;
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
		$author_ids = $this->get_authors_to_authorize( $post );
		$authorized = true;
		$reason     = 'filtered';

		if ( $this->settings->get_global_option( 'shortcode_author_check' ) ) {
			foreach ( $author_ids as $author_id ) {
				if ( ! user_can( $author_id, 'wp-piwik_read_stats' ) ) {
					$authorized = false;
					$reason     = 'author';
					break;
				}
			}
		}
		// an empty author list means the shortcode text comes from a theme template, a
		// sidebar widget or WP-CLI. editing any of those needs capabilities well above
		// wp-piwik_read_stats, so there is nobody left to authorize and the shortcode
		// is allowed through. sites that want this closed can use the filter below.

		// extra check for the "post" shortcode type
		if ( $authorized && 'post' === $attributes['module'] ) {
			if ( null === $post ) {
				$authorized = false;
				$reason     = 'no_post';
			} elseif (
				null !== $attributes['url']
				&& ! $this->is_url_allowed( $attributes['url'], $author_ids )
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
	 * Collect everyone who has to authorize a shortcode before it can be used.
	 *
	 * Shortcode text rendered inside a reusable block could have been written by any
	 * post in the embedding chain rather than by the block itself, since a pattern
	 * override lets an embedding post supply it. Each of these authors is collected
	 * here and the caller requires all of them to be allowed.
	 *
	 * @param \WP_Post|null $post post the shortcode is rendered in, if any
	 * @return int[] author IDs, without duplicates
	 */
	private function get_authors_to_authorize( $post ) {
		$posts = array();
		foreach ( self::$open_reusable_blocks as $open_block ) {
			// a ref that named no post has nobody to authorize
			if ( null !== $open_block ) {
				$posts[] = $open_block;
			}
		}
		if ( null !== $post ) {
			array_unshift( $posts, $post );
		}

		$author_ids = array_map( 'intval', wp_list_pluck( $posts, 'post_author' ) );
		foreach ( $posts as $chain_post ) {
			// post_author only names who created a post, so anybody else who has written
			// the shortcode into one of these has to be allowed as well
			$author_ids = array_merge( $author_ids, self::get_recorded_shortcode_authors( $chain_post->ID ) );
		}
		if ( self::is_block_renderer_request() ) {
			// this is a REST request to render a block, we need to authorize the caller
			// in this case too
			$author_ids[] = get_current_user_id();
		}
		$author_ids = array_unique( $author_ids );
		$author_ids = array_values( $author_ids );

		return $author_ids;
	}

	/**
	 * @param int $post_id post to read
	 * @return int[] users other than its author who have written the shortcode into it
	 */
	private static function get_recorded_shortcode_authors( $post_id ) {
		$writers = get_post_meta( $post_id, self::AUTHORS_META_KEY, true );
		return is_array( $writers ) ? array_map( 'intval', $writers ) : array();
	}

	/**
	 * Checks whether the REST block renderer is what is rendering right now.
	 *
	 * /wp/v2/block-renderer/<name> renders a block from attributes the request
	 * carries and asks for nothing beyond edit_posts. A requester can therefore
	 * point it at somebody else's pattern and, through a pattern override, supply
	 * the text it renders, which makes them an author of it like an embedding post
	 * would be.
	 *
	 * Every route being dispatched is looked at, not just the innermost one: no matter
	 * what else made REST requests while the block rendered, the block attributes still
	 * came from the request, so the caller must be authorized.
	 *
	 * @return bool
	 */
	private static function is_block_renderer_request() {
		$in_rest_request = function_exists( 'wp_is_rest_endpoint' )
			? wp_is_rest_endpoint()
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $in_rest_request ) {
			return false;
		}

		foreach ( self::$open_rest_routes as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/block-renderer/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a post shortcode may report on a URL
	 *
	 * @param string $url
	 *          URL requested by the shortcode's url attribute
	 * @param int[]  $author_ids
	 *          everybody who has to authorize the shortcode
	 * @return bool true if the URL is a post on this site every author may read
	 */
	private function is_url_allowed( $url, $author_ids ) {
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
		foreach ( $author_ids as $author_id ) {
			if ( ! user_can( $author_id, 'read_post', $post_id ) ) {
				return false;
			}
		}
		return true;
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
