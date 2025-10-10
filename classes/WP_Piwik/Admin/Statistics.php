<?php
	
	namespace WP_Piwik\Admin;

	class Statistics extends \WP_Piwik\Admin {

		public function show() {
			global $screen_layout_columns;
			if (empty($screen_layout_columns)) $screen_layout_columns = 2;
			if (self::$settings->getGlobalOption('disable_timelimit')) set_time_limit(0);
			echo '<div id="wp-piwik-stats-general" class="wrap">';
			echo '<h2>'.(self::$settings->getGlobalOption('plugin_display_name') == 'WP-Piwik'?'Piwik '.__('Statistics', 'wp-piwik'):self::$settings->getGlobalOption('plugin_display_name')).'</h2>';
			if (self::$settings->checkNetworkActivation() && function_exists('is_super_admin') && is_super_admin()) {

                if (isset($_GET['wpmu_show_stats'])) {
					switch_to_blog((int) $_GET['wpmu_show_stats']);
				} elseif ((isset($_GET['overview']) && $_GET['overview']) || (function_exists('is_network_admin') && is_network_admin())) {
					new \WP_Piwik\Admin\Sitebrowser(self::$wpPiwik);
					return;
				}
				echo '<p>'.__('Currently shown stats:').' <a href="'.get_bloginfo('url').'">'.get_bloginfo('name').'</a>.'.' <a href="?page=wp-piwik_stats&overview=1">Show site overview</a>.</p>';
			}
			echo '<form action="admin-post.php" method="post"><input type="hidden" name="action" value="save_wp-piwik_stats_general" /><div id="dashboard-widgets" class="metabox-holder columns-'.$screen_layout_columns.(2 <= $screen_layout_columns?' has-right-sidebar':'').'">';
			wp_nonce_field('wp-piwik_stats-general');
			wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', false);
			wp_nonce_field('meta-box-order', 'meta-box-order-nonce', false);
			$columns = array('normal', 'side', 'column3');
			for ($i = 0; $i < 3; $i++) {
				echo '<div id="postbox-container-'.($i+1).'" class="postbox-container">';
				do_meta_boxes(self::$wpPiwik->statsPageId, $columns[$i], null);
				echo '</div>';
			}
			echo '</div></form></div>';
			echo '<script>//<![CDATA['."\n";
			echo 'jQuery(document).ready(function($) {$(".if-js-closed").removeClass("if-js-closed").addClass("closed"); postboxes.add_postbox_toggles("'.self::$wpPiwik->statsPageId.'");});'."\n";
			echo '//]]></script>'."\n";
			if (self::$settings->checkNetworkActivation() && function_exists('is_super_admin') && is_super_admin()) {
				restore_current_blog();
			}
		}

		public function printAdminScripts() {
			wp_enqueue_script('wp-piwik', self::$wpPiwik->getPluginURL().'js/wp-piwik.js', array(), self::$wpPiwik->getPluginVersion(), true);
            wp_enqueue_script ( 'wp-piwik-chartjs', self::$wpPiwik->getPluginURL () . 'js/chartjs/chart.min.js', "3.4.1" );
		}

	}