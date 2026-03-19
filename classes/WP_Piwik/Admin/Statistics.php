<?php

namespace WP_Piwik\Admin;

class Statistics extends \WP_Piwik\Admin {

	/**
	 * @return void
	 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	 */
	public function show() {
		global $screen_layout_columns;
		if ( empty( $screen_layout_columns ) ) {
			$screen_layout_columns = 2;
		}
		if ( self::$settings->get_global_option( 'disable_timelimit' ) ) {
			set_time_limit( 0 );
		}
		echo '<div id="wp-piwik-stats-general" class="wrap">';
		echo '<h2>' . esc_html( 'WP-Piwik' === self::$settings->get_global_option( 'plugin_display_name' ) ? 'Piwik ' . esc_html__( 'Statistics', 'wp-piwik' ) : self::$settings->get_global_option( 'plugin_display_name' ) ) . '</h2>';
		if ( self::$settings->check_network_activation() && function_exists( 'is_super_admin' ) && is_super_admin() ) {
			if ( isset( $_GET['wpmu_show_stats'] ) ) {
				switch_to_blog( (int) $_GET['wpmu_show_stats'] );
			} elseif ( ! empty( $_GET['overview'] ) || ( function_exists( 'is_network_admin' ) && is_network_admin() ) ) {
				new \WP_Piwik\Admin\Sitebrowser( self::$wp_piwik );
				return;
			}
			echo '<p>' . esc_html__( 'Currently shown stats:', 'wp-piwik' ) . ' <a href="' . esc_attr( get_bloginfo( 'url' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>. <a href="?page=wp-piwik_stats&overview=1">Show site overview</a>.</p>';
		}
		echo '<form action="admin-post.php" method="post"><input type="hidden" name="action" value="save_wp-piwik_stats_general" /><div id="dashboard-widgets" class="metabox-holder columns-' . esc_attr( $screen_layout_columns ) . esc_attr( 2 <= $screen_layout_columns ? ' has-right-sidebar' : '' ) . '">';
		wp_nonce_field( 'wp-piwik_stats-general' );
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
		$columns = array( 'normal', 'side', 'column3' );
		for ( $i = 0; $i < 3; $i++ ) {
			// @phpstan-ignore-next-line
			echo '<div id="postbox-container-' . esc_attr( $i + 1 ) . '" class="postbox-container">';
			do_meta_boxes( self::$wp_piwik->stats_page_id, $columns[ $i ], null );
			echo '</div>';
		}
		echo '</div></form></div>';
		echo '<script>//<![CDATA[' . "\n";
		echo 'jQuery(document).ready(function($) {$(".if-js-closed").removeClass("if-js-closed").addClass("closed"); postboxes.add_postbox_toggles(' . wp_json_encode( self::$wp_piwik->stats_page_id ) . ');});' . "\n";
		echo '//]]></script>' . "\n";
		if ( self::$settings->check_network_activation() && function_exists( 'is_super_admin' ) && is_super_admin() ) {
			restore_current_blog();
		}
	}

	public function print_admin_scripts() {
		$version = self::$wp_piwik->get_plugin_version();
		wp_enqueue_script( 'wp-piwik', self::$wp_piwik->get_plugin_url() . 'js/wp-piwik.js', array(), $version, true );
		wp_enqueue_script( 'wp-piwik-chartjs', self::$wp_piwik->get_plugin_url() . 'js/chartjs/chart.min.js', array(), $version, false );
	}
}
