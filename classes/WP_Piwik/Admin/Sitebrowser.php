<?php

namespace WP_Piwik\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class Sitebrowser extends \WP_List_Table {

	private $data = array();

	/**
	 * @var \WP_Piwik
	 */
	private $wp_piwik;

	public function __construct( $wp_piwik ) {
		$this->wp_piwik = $wp_piwik;
		if ( isset( $_REQUEST['s'] ) ) {
			$cnt = $this->prepare_items( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) );
		} else {
			$cnt = $this->prepare_items();
		}
		$this->show_search_form();
		parent::__construct(
			array(
				'singular' => __( 'site', 'wp-piwik' ),
				'plural'   => __( 'sites', 'wp-piwik' ),
				'ajax'     => false,
			)
		);
		if ( $cnt > 0 ) {
			$this->display();
		} else {
			echo '<p>' . esc_html__( 'No site configured yet.', 'wp-piwik' ) . '</p>';
		}
	}

	public function get_columns() {
		$columns = array(
			'id'      => __( 'Blog ID', 'wp-piwik' ),
			'name'    => __( 'Title', 'wp-piwik' ),
			'siteurl' => __( 'URL', 'wp-piwik' ),
			'piwikid' => __( 'Site ID (Piwik)', 'wp-piwik' ),
		);
		return $columns;
	}

	public function prepare_items( $search = '' ) {
		global $blog_id;
		global $wpdb;
		global $pagenow;

		$current_page = $this->get_pagenum();
		$per_page     = 10;

		if ( is_plugin_active_for_network( 'wp-piwik/wp-piwik.php' ) ) {
			$search      = '%' . $wpdb->esc_like( $search ) . '%';
			$total_items = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %s WHERE CONCAT(domain, path) LIKE %s AND spam = 0 AND deleted = 0', $wpdb->blogs, $search ) );
			$blogs       = \WP_Piwik\Settings::get_blog_list( $per_page, $current_page, $search );
			foreach ( $blogs as $blog ) {
				$blog_details  = get_blog_details( $blog['blog_id'], true );
				$this->data [] = array(
					'name'    => $blog_details->blogname,
					'id'      => $blog_details->blog_id,
					'siteurl' => $blog_details->siteurl,
					'piwikid' => $this->wp_piwik->get_piwik_site_id( $blog_details->blog_id ),
				);
			}
		} else {
			$blog_details  = get_bloginfo();
			$this->data [] = array(
				'name'    => get_bloginfo( 'name' ),
				'id'      => '-',
				'siteurl' => get_bloginfo( 'url' ),
				'piwikid' => $this->wp_piwik->get_piwik_site_id(),
			);
			$total_items   = 1;
		}
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = array();
		$this->_column_headers = array(
			$columns,
			$hidden,
			$sortable,
		);
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
		foreach ( $this->data as $key => $dataset ) {
			if ( empty( $dataset['piwikid'] ) || 'n/a' === $dataset['piwikid'] ) {
				$this->data [ $key ] ['piwikid'] = __( 'Site not created yet.', 'wp-piwik' );
			}
			if ( $this->wp_piwik->is_network_mode() ) {
				$this->data [ $key ] ['name'] = '<a href="index.php?page=wp-piwik_stats&wpmu_show_stats=' . esc_attr( rawurlencode( $dataset['id'] ) ) . '">' . esc_html( $dataset['name'] ) . '</a>';
			}
		}
		$this->items = $this->data;
		return count( $this->items );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
			case 'name':
			case 'siteurl':
			case 'piwikid':
				return $item [ $column_name ];
			default:
				return print_r( $item, true );
		}
	}

	private function show_search_form() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
		?>
		<form method="post">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
			<?php $this->search_box( 'Search domain and path', 'wpPiwikSiteSearch' ); ?>
		</form>
		<?php
	}
}
