<?php

namespace WP_Piwik\Admin;

/**
 * WordPress Admin settings page
 *
 * @package WP_Piwik\Admin
 * @author Andr&eacute; Br&auml;kling <webmaster@braekling.de>
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class Settings extends \WP_Piwik\Admin {

	/**
	 * Builds and displays the settings page
	 */
	public function show() {
		if ( ! empty( $_GET['sitebrowser'] ) ) {
			new \WP_Piwik\Admin\Sitebrowser( self::$wp_piwik );
			return;
		}
		if ( ! empty( $_GET['clear'] ) && check_admin_referer() ) {
			$this->clear( 2 === $_GET['clear'] );
			self::$wp_piwik->reset_request();
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			echo '<form method="post" action="?page=' . esc_attr( $page ) . '"><input type="submit" value="' . esc_attr__( 'Reload', 'wp-piwik' ) . '" /></form>';
			return;
		} elseif ( self::$wp_piwik->is_config_submitted() ) {
			$this->show_box( 'updated', 'yes', esc_html__( 'Changes saved.', 'wp-piwik' ) );
			self::$wp_piwik->reset_request();
			if ( 'php' === self::$settings->get_global_option( 'piwik_mode' ) ) {
				self::$wp_piwik->define_piwik_constants();
			}
			if ( self::$settings->get_global_option( 'auto_site_config' ) && self::$wp_piwik->is_configured() ) {
				$site_id = self::$wp_piwik->get_piwik_site_id( null, true );
				self::$wp_piwik->update_tracking_code( $site_id );
				self::$settings->set_option( 'site_id', $site_id );
			} else {
				self::$wp_piwik->update_tracking_code();
			}
		}
		global $wp_roles;
		?>
<div id="plugin-options-wrap" class="widefat">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->get_headline( 1, 'admin-generic', 'Settings', true );
		if ( ! empty( $_GET['testscript'] ) ) {
			$this->run_testscript();
		}
		?>
		<?php
		if ( self::$wp_piwik->is_configured() ) {
			$piwik_version = self::$wp_piwik->request( 'global.getPiwikVersion' );
			if ( is_array( $piwik_version ) && isset( $piwik_version['value'] ) ) {
				$piwik_version = $piwik_version['value'];
			}
			if ( ! empty( $piwik_version ) && ! is_array( $piwik_version ) ) {
				$this->show_donation();
			}
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		?>
	<form method="post" action="?page=<?php echo esc_attr( $page ); ?>">
		<input type="hidden" name="wp-piwik[revision]" value="<?php echo esc_attr( self::$settings->get_global_option( 'revision' ) ); ?>" />
		<?php wp_nonce_field( 'wp-piwik_settings' ); ?>
		<table class="wp-piwik-form">
			<tbody>
			<?php
			$submit_button = '<tr><td colspan="2"><p class="submit"><input name="Submit" type="submit" class="button-primary" value="' . esc_attr__( 'Save Changes', 'wp-piwik' ) . '" /></p></td></tr>';
			printf( '<tr><td colspan="2">%s</td></tr>', esc_html__( 'Thanks for using WP-Matomo!', 'wp-piwik' ) );
			if ( self::$wp_piwik->is_configured() ) {
				if ( ! empty( $piwik_version ) && ! is_array( $piwik_version ) ) {
					$this->show_text( sprintf( __( 'WP-Matomo %1$s is successfully connected to Matomo %2$s.', 'wp-piwik' ), self::$wp_piwik->get_plugin_version(), $piwik_version ) . ' ' . ( ! self::$wp_piwik->is_network_mode() ? sprintf( __( 'You are running WordPress %s.', 'wp-piwik' ), get_bloginfo( 'version' ) ) : sprintf( __( 'You are running a WordPress %s blog network (WPMU). WP-Matomo will handle your sites as different websites.', 'wp-piwik' ), get_bloginfo( 'version' ) ) ) );
				} else {
					$error_message = \WP_Piwik\Request::get_last_error();
					if ( empty( $error_message ) ) {
						$this->show_box( 'error', 'no', esc_html( sprintf( __( 'WP-Matomo %s was not able to connect to Matomo using your configuration. Check the &raquo;Connect to Matomo&laquo; section below.', 'wp-piwik' ), self::$wp_piwik->get_plugin_version() ) ) );
					} else {
						$this->show_box( 'error', 'no', esc_html( sprintf( __( 'WP-Matomo %1$s was not able to connect to Matomo using your configuration. During connection the following error occured: ', 'wp-piwik' ), self::$wp_piwik->get_plugin_version() ) ) . '<br /><code>' . esc_html( $error_message ) . '</code>' );
					}
				}
			} else {
				$this->show_box( 'error', 'no', esc_html( sprintf( __( 'WP-Matomo %s has to be connected to Matomo first. Check the &raquo;Connect to Matomo&laquo; section below.', 'wp-piwik' ), self::$wp_piwik->get_plugin_version() ) ) );
			}

			$tabs ['connect'] = array(
				'icon' => 'admin-plugins',
				'name' => __( 'Connect to Matomo', 'wp-piwik' ),
			);
			if ( self::$wp_piwik->is_configured() ) {
				$tabs ['statistics'] = array(
					'icon' => 'chart-pie',
					'name' => __( 'Show Statistics', 'wp-piwik' ),
				);
				$tabs ['tracking']   = array(
					'icon' => 'location-alt',
					'name' => __( 'Enable Tracking', 'wp-piwik' ),
				);
			}
			$tabs ['expert']  = array(
				'icon' => 'shield',
				'name' => __( 'Expert Settings', 'wp-piwik' ),
			);
			$tabs ['support'] = array(
				'icon' => 'lightbulb',
				'name' => __( 'Support', 'wp-piwik' ),
			);
			$tabs ['credits'] = array(
				'icon' => 'groups',
				'name' => __( 'Credits', 'wp-piwik' ),
			);

			echo '<tr><td colspan="2"><h2 class="nav-tab-wrapper">';
			foreach ( $tabs as $tab => $details ) {
				$class = 'connect' === $tab ? ' nav-tab-active' : '';
				echo '<a style="cursor:pointer;" id="tab-' . esc_attr( $tab ) . '" class="nav-tab' . esc_attr( $class ) . '" onclick="javascript:jQuery(\'table.wp-piwik_menu-tab\').addClass(\'hidden\');jQuery(' . esc_attr( wp_json_encode( '#' . $tab ) ) . ').removeClass(\'hidden\');jQuery(\'a.nav-tab\').removeClass(\'nav-tab-active\');jQuery(' . esc_attr( wp_json_encode( '#tab-' . $tab ) ) . ').addClass(\'nav-tab-active\');">';
				$this->show_headline( 0, $details ['icon'], $details ['name'] );
				echo '</a>';
			}
			echo '</h2></td></tr></tbody></table><table id="connect" class="wp-piwik_menu-tab"><tbody>';

			if ( ! self::$wp_piwik->is_configured() ) {
				$this->show_box( 'updated', 'info', esc_html__( 'Before you can complete the setup, make sure you have a Matomo instance running. If you don\'t have one, you can', 'wp-piwik' ) . ' <a href="https://matomo.org/start-free-analytics-trial/" target="_blank">' . esc_html__( 'create a free account', 'wp-piwik' ) . '</a> ' . esc_html__( 'or ', 'wp-piwik' ) . '<a href="https://wordpress.org/plugins/matomo/" target="_blank">' . esc_html__( 'install the "Matomo for WordPress" plugin', 'wp-piwik' ) . '</a> ' . esc_html__( 'instead.', 'wp-piwik' ) );
			}

			if ( ! function_exists( 'curl_init' ) && ! ini_get( 'allow_url_fopen' ) ) {
				$this->show_box( 'error', 'no', esc_html__( 'Neither cURL nor fopen are available. So WP-Matomo can not use the HTTP API and not connect to InnoCraft Cloud.', 'wp-piwik' ) . ' ' . sprintf( '<a href="%s">%s.</a>', 'https://wordpress.org/plugins/wp-piwik/faq/', esc_html__( 'More information', 'wp-piwik' ) ) );
			}

			$description = sprintf( '%s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s', esc_html__( 'You can choose between three connection methods:', 'wp-piwik' ), esc_html__( 'Self-hosted (HTTP API, default)', 'wp-piwik' ), esc_html__( 'This is the default option for a self-hosted Matomo and should work for most configurations. WP-Matomo will connect to Matomo using http(s).', 'wp-piwik' ), esc_html__( 'Self-hosted (PHP API)', 'wp-piwik' ), esc_html__( 'Choose this, if your self-hosted Matomo and WordPress are running on the same machine and you know the full server path to your Matomo instance.', 'wp-piwik' ), esc_html__( 'Cloud-hosted', 'wp-piwik' ), esc_html__( 'If you are using a cloud-hosted Matomo by InnoCraft, you can simply use this option. Be carefull to choose the option which fits to your cloud domain (matomo.cloud or innocraft.cloud).', 'wp-piwik' ) );
			$this->show_select(
				'piwik_mode',
				__( 'Matomo Mode', 'wp-piwik' ),
				array(
					'disabled'     => __( 'Disabled (WP-Matomo will not connect to Matomo)', 'wp-piwik' ),
					'http'         => __( 'Self-hosted (HTTP API, default)', 'wp-piwik' ),
					'php'          => __( 'Self-hosted (PHP API)', 'wp-piwik' ),
					'cloud-matomo' => __( 'Cloud-hosted (Innocraft Cloud, *.matomo.cloud)', 'wp-piwik' ),
					'cloud'        => __( 'Cloud-hosted (InnoCraft Cloud, *.innocraft.cloud)', 'wp-piwik' ),
				),
				$description,
				'jQuery(\'tr.wp-piwik-mode-option\').addClass(\'hidden\'); jQuery(\'.wp-piwik-mode-option-\' + jQuery(\'#piwik_mode\').val()).removeClass(\'hidden\');',
				false,
				'',
				self::$wp_piwik->is_configured()
			);

			$this->show_input( 'piwik_url', __( 'Matomo URL', 'wp-piwik' ), __( 'Enter your Matomo URL. This is the same URL you use to access your Matomo instance, e.g. http://www.example.com/matomo/.', 'wp-piwik' ), 'http' !== self::$settings->get_global_option( 'piwik_mode' ), 'wp-piwik-mode-option', 'http', self::$wp_piwik->is_configured(), true );
			$this->show_input( 'piwik_path', __( 'Matomo path', 'wp-piwik' ), __( 'Enter the file path to your Matomo instance, e.g. /var/www/matomo/.', 'wp-piwik' ), 'php' !== self::$settings->get_global_option( 'piwik_mode' ), 'wp-piwik-mode-option', 'php', self::$wp_piwik->is_configured(), true );
			$this->show_input( 'piwik_user', __( 'Innocraft subdomain', 'wp-piwik' ), __( 'Enter your InnoCraft Cloud subdomain. It is also part of your URL: https://SUBDOMAIN.innocraft.cloud.', 'wp-piwik' ), 'cloud' !== self::$settings->get_global_option( 'piwik_mode' ), 'wp-piwik-mode-option', 'cloud', self::$wp_piwik->is_configured() );
			$this->show_input( 'matomo_user', __( 'Matomo subdomain', 'wp-piwik' ), __( 'Enter your Matomo Cloud subdomain. It is also part of your URL: https://SUBDOMAIN.matomo.cloud.', 'wp-piwik' ), 'cloud-matomo' !== self::$settings->get_global_option( 'piwik_mode' ), 'wp-piwik-mode-option', 'cloud-matomo', self::$wp_piwik->is_configured() );
			$this->show_input( 'piwik_token', __( 'Auth token', 'wp-piwik' ), __( 'Enter your Matomo auth token here. It is an alphanumerical code like 0a1b2c34d56e78901fa2bc3d45678efa.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sWP-Matomo FAQ%2$s.', 'wp-piwik' ), '<a href="https://wordpress.org/plugins/wp-piwik/faq/" target="_BLANK">', '</a>' ), false, '', '', self::$wp_piwik->is_configured(), true, 'password' );

			// Site configuration
			$piwik_site_id = self::$wp_piwik->is_configured() ? self::$wp_piwik->get_piwik_site_id() : false;
			if ( ! self::$wp_piwik->is_network_mode() ) {
				$this->show_checkbox(
					'auto_site_config',
					__( 'Auto config', 'wp-piwik' ),
					__( 'Check this to automatically choose your blog from your Matomo sites by URL. If your blog is not added to Matomo yet, WP-Matomo will add a new site.', 'wp-piwik' ),
					false,
					'',
					false,
					'jQuery(\'tr.wp-piwik-auto-option\').toggle(\'hidden\');' . ( $piwik_site_id ? 'jQuery(\'#site_id\').val(' . $piwik_site_id . ');' : '' )
				);
				if ( self::$wp_piwik->is_configured() ) {
					$piwik_site_list = self::$wp_piwik->get_piwik_site_details();
					if ( isset( $piwik_site_list['result'] ) && 'error' === $piwik_site_list['result'] ) {
						$this->show_box(
							'error',
							'no',
							esc_html( sprintf( __( 'WP-Matomo %1$s was not able to get sites with at least view access: ', 'wp-piwik' ), self::$wp_piwik->get_plugin_version() ) ) . '<br /><code>' . esc_html( $piwik_site_list['message'] ) . '</code>'
						);
					} else {
						if ( is_array( $piwik_site_list ) ) {
							foreach ( $piwik_site_list as $details ) {
								$piwik_site_details[ $details['idsite'] ] = $details;
							}
						}
						unset( $piwik_site_list );
						if ( 'n/a' !== $piwik_site_id && isset( $piwik_site_details ) ) {
							$piwik_site_description = $piwik_site_details [ $piwik_site_id ] ['name'] . ' (' . $piwik_site_details [ $piwik_site_id ] ['main_url'] . ')';
						} else {
							$piwik_site_description = 'n/a';
						}
						echo '<tr class="wp-piwik-auto-option' . esc_attr( ! self::$settings->get_global_option( 'auto_site_config' ) ? ' hidden' : '' ) . '"><th scope="row">' . esc_html__( 'Determined site', 'wp-piwik' ) . ':</th><td>' . esc_html( $piwik_site_description ) . '</td></tr>';
						if ( isset( $piwik_site_details ) ) {
							foreach ( $piwik_site_details as $key => $site_data ) {
								$site_list[ $site_data['idsite'] ] = $site_data['name'] . ' (' . $site_data ['main_url'] . ')';
							}
						}
						if ( isset( $site_list ) ) {
							$this->show_select(
								'site_id',
								__( 'Select site', 'wp-piwik' ),
								$site_list,
								__( 'Choose the Matomo site corresponding to this blog.', 'wp-piwik' ),
								'',
								(int) self::$settings->get_global_option( 'auto_site_config' ) === 1,
								'wp-piwik-auto-option',
								true,
								false
							);
						}
					}
				}
			} else {
				echo '<tr class="hidden"><td colspan="2"><input type="hidden" name="wp-piwik[auto_site_config]" value="1" /></td></tr>';
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $submit_button;

			echo '</tbody></table><table id="statistics" class="wp-piwik_menu-tab hidden"><tbody>';
			// Stats configuration
			$this->show_select(
				'default_date',
				__( 'Matomo default date', 'wp-piwik' ),
				array(
					'today'         => __( 'Today', 'wp-piwik' ),
					'yesterday'     => __( 'Yesterday', 'wp-piwik' ),
					'current_month' => __( 'Current month', 'wp-piwik' ),
					'last_month'    => __( 'Last month', 'wp-piwik' ),
					'current_week'  => __( 'Current week', 'wp-piwik' ),
					'last_week'     => __( 'Last week', 'wp-piwik' ),
				),
				__( 'Default date shown on statistics page.', 'wp-piwik' )
			);

			$this->show_checkbox( 'stats_seo', __( 'Show SEO data', 'wp-piwik' ), __( 'Display SEO ranking data on statistics page.', 'wp-piwik' ) . ' (' . __( 'Slow!', 'wp-piwik' ) . ')' );
			$this->show_checkbox( 'stats_ecommerce', __( 'Show e-commerce data', 'wp-piwik' ), __( 'Display e-commerce data on statistics page.', 'wp-piwik' ) );

			$this->show_select(
				'dashboard_widget',
				__( 'Dashboard overview', 'wp-piwik' ),
				array(
					'disabled'  => __( 'Disabled', 'wp-piwik' ),
					'yesterday' => __( 'Yesterday', 'wp-piwik' ),
					'today'     => __( 'Today', 'wp-piwik' ),
					'last30'    => __( 'Last 30 days', 'wp-piwik' ),
					'last60'    => __( 'Last 60 days', 'wp-piwik' ),
					'last90'    => __( 'Last 90 days', 'wp-piwik' ),
				),
				__( 'Enable WP-Matomo dashboard widget &quot;Overview&quot;.', 'wp-piwik' )
			);

			$this->show_checkbox( 'dashboard_chart', __( 'Dashboard graph', 'wp-piwik' ), __( 'Enable WP-Matomo dashboard widget &quot;Graph&quot;.', 'wp-piwik' ) );

			$this->show_checkbox( 'dashboard_seo', __( 'Dashboard SEO', 'wp-piwik' ), __( 'Enable WP-Matomo dashboard widget &quot;SEO&quot;.', 'wp-piwik' ) . ' (' . __( 'Slow!', 'wp-piwik' ) . ')' );

			$this->show_checkbox( 'dashboard_ecommerce', __( 'Dashboard e-commerce', 'wp-piwik' ), __( 'Enable WP-Matomo dashboard widget &quot;E-commerce&quot;.', 'wp-piwik' ) );

			$this->show_checkbox( 'toolbar', __( 'Show graph on WordPress Toolbar', 'wp-piwik' ), __( 'Display a last 30 days visitor graph on WordPress\' toolbar.', 'wp-piwik' ) );

			echo '<tr><th scope="row"><label for="capability_read_stats">' . esc_html__( 'Display stats to', 'wp-piwik' ) . '</label>:</th><td>';
			$filter = self::$settings->get_global_option( 'capability_read_stats' );
			foreach ( $wp_roles->role_names as $key => $name ) {
				echo '<input type="checkbox" ' . esc_attr( isset( $filter [ $key ] ) && $filter [ $key ] ? 'checked="checked" ' : '' ) . 'value="1" onchange="jQuery(' . esc_attr( wp_json_encode( '#capability_read_stats-' . $key . '-input' ) ) . ').val(this.checked?1:0);" />';
				echo '<input id="capability_read_stats-' . esc_attr( $key ) . '-input" type="hidden" name="wp-piwik[capability_read_stats][' . esc_attr( $key ) . ']" value="' . intval( isset( $filter [ $key ] ) && $filter [ $key ] ) . '" />';
				echo esc_html( $name ) . ' &nbsp; ';
			}
			echo '<span class="dashicons dashicons-editor-help" onclick="jQuery(\'#capability_read_stats-desc\').toggleClass(\'hidden\');"></span> <p class="description hidden" id="capability_read_stats-desc">' . esc_html__( 'Choose user roles allowed to see the statistics page.', 'wp-piwik' ) . '</p></td></tr>';

			$this->show_select(
				'perpost_stats',
				__( 'Show per post stats', 'wp-piwik' ),
				array(
					'disabled'  => __( 'Disabled', 'wp-piwik' ),
					'yesterday' => __( 'Yesterday', 'wp-piwik' ),
					'today'     => __( 'Today', 'wp-piwik' ),
					'last30'    => __( 'Last 30 days', 'wp-piwik' ),
					'last60'    => __( 'Last 60 days', 'wp-piwik' ),
					'last90'    => __( 'Last 90 days', 'wp-piwik' ),
				),
				__( 'Show stats about single posts at the post edit admin page.', 'wp-piwik' )
			);

			$this->show_checkbox( 'piwik_shortcut', __( 'Matomo shortcut', 'wp-piwik' ), __( 'Display a shortcut to Matomo itself.', 'wp-piwik' ) );

			$this->show_input( 'plugin_display_name', __( 'WP-Matomo display name', 'wp-piwik' ), __( 'Plugin name shown in WordPress.', 'wp-piwik' ) );

			$this->show_checkbox( 'shortcodes', __( 'Enable shortcodes', 'wp-piwik' ), __( 'Enable shortcodes in post or page content.', 'wp-piwik' ) );

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $submit_button;

			echo '</tbody></table><table id="tracking" class="wp-piwik_menu-tab hidden"><tbody>';

			// Tracking Configuration
			$is_not_tracking               = 'disabled' === self::$settings->get_global_option( 'track_mode' );
			$is_not_generated_tracking     = $is_not_tracking || 'manually' === self::$settings->get_global_option( 'track_mode' );
			$full_generated_tracking_group = 'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-proxy';

			$description = sprintf(
				'%s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s<br /><strong>%s:</strong> %s',
				esc_html__( 'You can choose between four tracking code modes:', 'wp-piwik' ),
				esc_html__( 'Disabled', 'wp-piwik' ),
				esc_html__( 'WP-Matomo will not add the tracking code. Use this, if you want to add the tracking code to your template files or you use another plugin to add the tracking code.', 'wp-piwik' ),
				esc_html__( 'Default tracking', 'wp-piwik' ),
				esc_html__( 'WP-Matomo will use Matomo\'s standard tracking code.', 'wp-piwik' ),
				esc_html__( 'Use js/index.php', 'wp-piwik' ),
				esc_html__( 'You can choose this tracking code, to deliver a minified proxy code and to avoid using the files called piwik.js or piwik.php.', 'wp-piwik' )
					. ' '
					. sprintf( esc_html__( 'See %1$sreadme file%2$s.', 'wp-piwik' ), '<a href="http://demo.piwik.org/js/README" target="_BLANK">', '</a>' ),
				esc_html__( 'Use proxy script', 'wp-piwik' ),
				esc_html__( 'Use this tracking code to not reveal the Matomo server URL.', 'wp-piwik' )
					. ' '
					. sprintf( esc_html__( 'See %1$sMatomo FAQ%2$s.', 'wp-piwik' ), '<a href="http://piwik.org/faq/how-to/#faq_132" target="_BLANK">', '</a>' ),
				esc_html__( 'Enter manually', 'wp-piwik' ),
				esc_html__( 'Enter your own tracking code manually. You can choose one of the prior options, pre-configure your tracking code and switch to manually editing at last.', 'wp-piwik' )
					. ( self::$wp_piwik->is_network_mode() ? ' ' . esc_html__( 'Use the placeholder {ID} to add the Matomo site ID.', 'wp-piwik' ) : '' )
			);
			$this->show_select(
				'track_mode',
				__( 'Add tracking code', 'wp-piwik' ),
				array(
					'disabled' => __( 'Disabled', 'wp-piwik' ),
					'default'  => __( 'Default tracking', 'wp-piwik' ),
					'js'       => __( 'Use js/index.php', 'wp-piwik' ),
					'proxy'    => __( 'Use proxy script', 'wp-piwik' ),
					'manually' => __( 'Enter manually', 'wp-piwik' ),
				),
				$description,
				'jQuery(\'tr.wp-piwik-track-option\').addClass(\'hidden\'); jQuery(\'tr.wp-piwik-track-option-\' + jQuery(\'#track_mode\').val()).removeClass(\'hidden\'); jQuery(\'#tracking_code, #noscript_code\').prop(\'readonly\', jQuery(\'#track_mode\').val() != \'manually\');'
			);

			$this->show_textarea(
				'tracking_code',
				__( 'Tracking code', 'wp-piwik' ),
				15,
				esc_html__( 'This is a preview of your current tracking code. If you choose to enter your tracking code manually, you can change it here.', 'wp-piwik' ),
				$is_not_tracking,
				'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-proxy wp-piwik-track-option-manually',
				true,
				'',
				'manually' !== self::$settings->get_global_option( 'track_mode' ),
				false
			);

			$this->show_select(
				'track_codeposition',
				__( 'JavaScript code position', 'wp-piwik' ),
				array(
					'footer' => __( 'Footer', 'wp-piwik' ),
					'header' => __( 'Header', 'wp-piwik' ),
				),
				__( 'Choose whether the JavaScript code is added to the footer or the header.', 'wp-piwik' ),
				'',
				$is_not_tracking,
				'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-proxy wp-piwik-track-option-manually'
			);

			$this->show_textarea(
				'noscript_code',
				__( 'Noscript code', 'wp-piwik' ),
				2,
				esc_html__( 'This is a preview of your &lt;noscript&gt; code which is part of your tracking code.', 'wp-piwik' ),
				'proxy' === self::$settings->get_global_option( 'track_mode' ),
				'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-manually',
				true,
				'',
				'manually' !== self::$settings->get_global_option( 'track_mode' ),
				false
			);

			$this->show_checkbox( 'track_noscript', __( 'Add &lt;noscript&gt;', 'wp-piwik' ), __( 'Adds the &lt;noscript&gt; code to your footer.', 'wp-piwik' ) . ' ' . __( 'Disabled in proxy mode.', 'wp-piwik' ), 'proxy' === self::$settings->get_global_option( 'track_mode' ), 'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-manually' );

			$this->show_checkbox( 'track_nojavascript', __( 'Add rec parameter to noscript code', 'wp-piwik' ), __( 'Enable tracking for visitors without JavaScript (not recommended).', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo FAQ%2$s.', 'wp-piwik' ), '<a href="http://piwik.org/faq/how-to/#faq_176" target="_BLANK">', '</a>' ) . ' ' . __( 'Disabled in proxy mode.', 'wp-piwik' ), 'proxy' === self::$settings->get_global_option( 'track_mode' ), 'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-manually' );

			$this->show_select(
				'track_content',
				__( 'Enable content tracking', 'wp-piwik' ),
				array(
					'disabled' => __( 'Disabled', 'wp-piwik' ),
					'all'      => __( 'Track all content blocks', 'wp-piwik' ),
					'visible'  => __( 'Track only visible content blocks', 'wp-piwik' ),
				),
				esc_html__( 'Content tracking allows you to track interaction with the content of a web page or application.', 'wp-piwik' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/guides/content-tracking" target="_BLANK">', '</a>' ),
				'',
				$is_not_tracking,
				$full_generated_tracking_group . ' wp-piwik-track-option-manually'
			);

			$this->show_checkbox( 'track_search', __( 'Track search', 'wp-piwik' ), __( 'Use Matomo\'s advanced Site Search Analytics feature.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="http://piwik.org/docs/site-search/#track-site-search-using-the-tracking-api-advanced-users-only" target="_BLANK">', '</a>' ), $is_not_tracking, $full_generated_tracking_group . ' wp-piwik-track-option-manually' );

			$this->show_checkbox( 'track_404', __( 'Track 404', 'wp-piwik' ), __( 'WP-Matomo can automatically add a 404-category to track 404-page-visits.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo FAQ%2$s.', 'wp-piwik' ), '<a href="http://piwik.org/faq/how-to/faq_60/" target="_BLANK">', '</a>' ), $is_not_tracking, $full_generated_tracking_group . ' wp-piwik-track-option-manually' );

			echo '<tr class="' . esc_attr( $full_generated_tracking_group ) . ' wp-piwik-track-option-manually' . ( $is_not_tracking ? ' hidden' : '' ) . '">';
			echo '<th scope="row"><label for="add_post_annotations">' . esc_html__( 'Add annotation on new post of type', 'wp-piwik' ) . '</label>:</th><td>';
			$filter = self::$settings->get_global_option( 'add_post_annotations' );
			foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
				echo '<input type="checkbox" ' . ( ! empty( $filter [ $post_type->name ] ) ? 'checked="checked" ' : '' ) . 'value="1" name="wp-piwik[add_post_annotations][' . esc_attr( $post_type->name ) . ']" /> ' . esc_html( $post_type->label ) . ' &nbsp; ';
			}
			echo '<span class="dashicons dashicons-editor-help" onclick="jQuery(\'#add_post_annotations-desc\').toggleClass(\'hidden\');"></span> <p class="description hidden" id="add_post_annotations-desc">' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="http://piwik.org/docs/annotations/" target="_BLANK">', '</a>' ) . '</p></td></tr>';

			$this->show_checkbox( 'add_customvars_box', __( 'Show custom variables box', 'wp-piwik' ), __( ' Show a &quot;custom variables&quot; edit box on post edit page.', 'wp-piwik' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="http://piwik.org/docs/custom-variables/" target="_BLANK">', '</a>' ), $is_not_generated_tracking, $full_generated_tracking_group . ' wp-piwik-track-option-manually' );

			$this->show_input( 'add_download_extensions', __( 'Add new file types for download tracking', 'wp-piwik' ), __( 'Add file extensions for download tracking, divided by a vertical bar (&#124;).', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/guides/tracking-javascript-guide#file-extensions-for-tracking-downloads" target="_BLANK">', '</a>' ), $is_not_generated_tracking, $full_generated_tracking_group );

			$this->show_select(
				'require_consent',
				__( 'Tracking or cookie consent', 'wp-piwik' ),
				array(
					'disabled'      => __( 'Disabled', 'wp-piwik' ),
					'consent'       => __( 'Require consent', 'wp-piwik' ),
					'cookieconsent' => __( 'Require cookie consent', 'wp-piwik' ),
				),
				esc_html__( 'Enable support for consent managers.', 'wp-piwik' ) . ' ' . sprintf( esc_html__( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="https://developer.matomo.org/guides/tracking-consent" target="_BLANK">', '</a>' ),
				'',
				$is_not_generated_tracking,
				$full_generated_tracking_group
			);

			$this->show_checkbox( 'disable_cookies', __( 'Disable cookies', 'wp-piwik' ), __( 'Disable all tracking cookies for a visitor.', 'wp-piwik' ), $is_not_generated_tracking, $full_generated_tracking_group );

			$this->show_checkbox( 'limit_cookies', __( 'Limit cookie lifetime', 'wp-piwik' ), __( 'You can limit the cookie lifetime to avoid tracking your users over a longer period as necessary.', 'wp-piwik' ), $is_not_generated_tracking, $full_generated_tracking_group, true, 'jQuery(\'tr.wp-piwik-cookielifetime-option\').toggleClass(\'wp-piwik-hidden\');' );

			$this->show_input( 'limit_cookies_visitor', __( 'Visitor timeout (seconds)', 'wp-piwik' ), false, $is_not_generated_tracking || ! self::$settings->get_global_option( 'limit_cookies' ), $full_generated_tracking_group . ' wp-piwik-cookielifetime-option' . ( self::$settings->get_global_option( 'limit_cookies' ) ? '' : ' wp-piwik-hidden' ) );

			$this->show_input( 'limit_cookies_session', __( 'Session timeout (seconds)', 'wp-piwik' ), false, $is_not_generated_tracking || ! self::$settings->get_global_option( 'limit_cookies' ), $full_generated_tracking_group . ' wp-piwik-cookielifetime-option' . ( self::$settings->get_global_option( 'limit_cookies' ) ? '' : ' wp-piwik-hidden' ) );

			$this->show_input( 'limit_cookies_referral', __( 'Referral timeout (seconds)', 'wp-piwik' ), false, $is_not_generated_tracking || ! self::$settings->get_global_option( 'limit_cookies' ), $full_generated_tracking_group . ' wp-piwik-cookielifetime-option' . ( self::$settings->get_global_option( 'limit_cookies' ) ? '' : ' wp-piwik-hidden' ) );

			$this->show_checkbox( 'track_admin', __( 'Track admin pages', 'wp-piwik' ), __( 'Enable to track users on admin pages (remember to configure the tracking filter appropriately).', 'wp-piwik' ), $is_not_tracking, $full_generated_tracking_group . ' wp-piwik-track-option-manually' );

			echo '<tr class="' . esc_attr( $full_generated_tracking_group ) . ' wp-piwik-track-option-manually' . ( $is_not_tracking ? ' hidden' : '' ) . '">';
			echo '<th scope="row"><label for="capability_stealth">' . esc_html__( 'Tracking filter', 'wp-piwik' ) . '</label>:</th><td>';
			$filter = self::$settings->get_global_option( 'capability_stealth' );
			foreach ( $wp_roles->role_names as $key => $name ) {
				echo '<input type="checkbox" ' . ( ! empty( $filter [ $key ] ) ? 'checked="checked" ' : '' ) . 'value="1" name="wp-piwik[capability_stealth][' . esc_attr( $key ) . ']" /> ' . esc_html( $name ) . ' &nbsp; ';
			}
			echo '<span class="dashicons dashicons-editor-help" onclick="jQuery(\'#capability_stealth-desc\').toggleClass(\'hidden\');"></span> <p class="description hidden" id="capability_stealth-desc">' . sprintf( esc_html__( 'Choose users by user role you do %1$snot%2$s want to track.', 'wp-piwik' ), '<strong>', '</strong>' ) . '</p></td></tr>';

			$this->show_checkbox( 'track_across', __( 'Track subdomains in the same website', 'wp-piwik' ), __( 'Adds *.-prefix to cookie domain.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/guides/tracking-javascript-guide#tracking-subdomains-in-the-same-website" target="_BLANK">', '</a>' ), $is_not_generated_tracking, $full_generated_tracking_group );

			$this->show_checkbox( 'track_across_alias', __( 'Do not count subdomains as outlink', 'wp-piwik' ), __( 'Adds *.-prefix to tracked domain.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/guides/tracking-javascript-guide#outlink-tracking-exclusions" target="_BLANK">', '</a>' ), $is_not_generated_tracking, $full_generated_tracking_group );

			$this->show_checkbox( 'track_crossdomain_linking', __( 'Enable cross domain linking', 'wp-piwik' ), __( 'When enabled, it will make sure to use the same visitor ID for the same visitor across several domains. This works only when this feature is enabled because the visitor ID is stored in a cookie and cannot be read on the other domain by default. When this feature is enabled, it will append a URL parameter "pk_vid" that contains the visitor ID when a user clicks on a URL that belongs to one of your domains. For this feature to work, you also have to configure which domains should be treated as local in your Matomo website settings. This feature requires Matomo 3.0.2.', 'wp-piwik' ), 'proxy' === self::$settings->get_global_option( 'track_mode' ), 'wp-piwik-track-option wp-piwik-track-option-default wp-piwik-track-option-js wp-piwik-track-option-manually' );

			$this->show_checkbox( 'track_feed', __( 'Track RSS feeds', 'wp-piwik' ), __( 'Enable to track posts in feeds via tracking pixel.', 'wp-piwik' ), $is_not_tracking, $full_generated_tracking_group . ' wp-piwik-track-option-manually' );

			$this->show_checkbox(
				'track_feed_addcampaign',
				__( 'Track RSS feed links as campaign', 'wp-piwik' ),
				esc_html__( 'This will add Matomo campaign parameters to the RSS feed links.', 'wp-piwik' )
					. ' '
					. sprintf(
						__( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ),
						'<a href="http://piwik.org/docs/tracking-campaigns/" target="_BLANK">',
						'</a>'
					),
				$is_not_tracking,
				$full_generated_tracking_group . ' wp-piwik-track-option-manually',
				true,
				'jQuery(\'tr.wp-piwik-feed_campaign-option\').toggle(\'hidden\');'
			);

			$this->show_input( 'track_feed_campaign', __( 'RSS feed campaign', 'wp-piwik' ), __( 'Keyword: post name.', 'wp-piwik' ), $is_not_generated_tracking || ! self::$settings->get_global_option( 'track_feed_addcampaign' ), $full_generated_tracking_group . ' wp-piwik-feed_campaign-option' );

			$this->show_input( 'track_heartbeat', __( 'Enable heartbeat timer', 'wp-piwik' ), __( 'Enable a heartbeat timer to get more accurate visit lengths by sending periodical HTTP ping requests as long as the site is opened. Enter the time between the pings in seconds (Matomo default: 15) to enable or 0 to disable this feature. <strong>Note:</strong> This will cause a lot of additional HTTP requests on your site.', 'wp-piwik' ), $is_not_generated_tracking, $full_generated_tracking_group );

			$this->show_select(
				'track_user_id',
				__( 'User ID Tracking', 'wp-piwik' ),
				array(
					'disabled'    => __( 'Disabled', 'wp-piwik' ),
					'uid'         => __( 'WP User ID', 'wp-piwik' ),
					'email'       => __( 'Email Address', 'wp-piwik' ),
					'username'    => __( 'Username', 'wp-piwik' ),
					'displayname' => __( 'Display Name (Not Recommended!)', 'wp-piwik' ),
				),
				__( 'When a user is logged in to WordPress, track their &quot;User ID&quot;. You can select which field from the User\'s profile is tracked as the &quot;User ID&quot;. When enabled, Tracking based on Email Address is recommended.', 'wp-piwik' ),
				'',
				$is_not_tracking,
				$full_generated_tracking_group
			);

			?>
		<tr class="<?php echo $is_not_tracking ? 'hidden' : ''; ?> <?php echo esc_attr( $full_generated_tracking_group ); ?> wp-piwik-track-option-manually">
			<td><h4><?php esc_html_e( 'AI Bot Tracking', 'wp-piwik' ); ?></h4></td>
		</tr>
		<?php

		$this->show_checkbox(
			\WP_Piwik\Settings::TRACK_AI_BOTS,
			esc_html__( 'Track AI Bots', 'wp-piwik' ),
			esc_html__( 'If enabled, AI bots will trigger page views even if they do not execute JavaScript. These page views can be seen in the special AI Assistants report.', 'wp-piwik' ),
			$is_not_tracking,
			$full_generated_tracking_group . ' wp-piwik-track-option-manually',
			true,
			"window.jQuery('.wp-matomo-track-ai-warning').toggle();"
		);

		$matomo_is_track_ai_enabled               = self::$settings->is_ai_bot_tracking_enabled();
		$matomo_is_advanced_cache_used            = self::is_advanced_cache_used();
		$matomo_is_track_script_used_in_wp_config = self::is_track_script_used_in_wp_config();
		$matomo_is_htaccess_serving_cache_files   = self::is_htaccess_serving_cache_files();

		if ( $matomo_is_htaccess_serving_cache_files ) {
			?>
			<tr>
				<td colspan="2">
					<div class="wp-matomo-inline-notice wp-matomo-warning wp-matomo-track-ai-warning" style="<?php echo $matomo_is_track_ai_enabled ? '' : 'display:none;'; ?>">
						<p>
							<strong><?php esc_html_e( 'Warning', 'wp-piwik' ); ?>:</strong>
							<?php esc_html_e( 'Your caching plugin is using an .htaccess file to serve cached pages directly through your webserver, bypassing PHP. AI bots cannot be tracked for pages served this way. Please consult your caching plugin documentation if you wish to disable this behavior.', 'wp-piwik' ); ?>
						</p>
					</div>
				</td>
			</tr>
			<?php
		} elseif ( $matomo_is_advanced_cache_used && false === $matomo_is_track_script_used_in_wp_config ) {
			?>
			<tr>
				<td colspan="2">
					<div class="wp-matomo-inline-notice wp-matomo-warning wp-matomo-track-ai-warning" style="<?php echo $matomo_is_track_ai_enabled ? '' : 'display:none;'; ?>">
						<p>
							<strong><?php esc_html_e( 'Warning', 'wp-piwik' ); ?>:</strong>
							<?php esc_html_e( 'We noticed WordPress\' advanced cache feature is active. This feature will serve your blog pages without ever loading your WordPress plugins. To track AI bots while the advanced cache is active you will need to add the following snippet to your wp-config.php file:', 'wp-piwik' ); ?>
						</p>
						<p>
							<textarea style="width:100%" rows="3" readonly="readonly">if ( is_file( ABSPATH . 'wp-content/plugins/wp-piwik/misc/track_ai_bot.php' ) ) {
	require_once ABSPATH . 'wp-content/plugins/wp-piwik/misc/track_ai_bot.php';
}</textarea>
						</p>
						<p><?php printf( esc_html__( 'Make sure to add it immediately before the line that reads %1$srequire_once ABSPATH . \'wp-settings.php\';%2$s.', 'wp-piwik' ), '<code>', '</code>' ); ?></p>
					</div>
				</td>
			</tr>
			<?php
		}

		$this->show_checkbox(
			\WP_Piwik\Settings::TRACK_AI_BOTS_USING_ESI,
			esc_html__( 'Track AI Bots using Edge Side Includes', 'wp-piwik' ),
			esc_html__( 'If you are using a CDN to serve your blog, you will not be able to track AI bots in the traditional method. If your CDN supports ESI (Edge Side Includes), however, you can enable this option to use this feature for tracking AI bots.', 'wp-piwik' ),
			$is_not_tracking,
			$full_generated_tracking_group . ' wp-piwik-track-option-manually'
		);

		$matomo_is_track_via_esi_enabled    = self::$settings->is_track_via_esi_enabled();
		$matomo_is_using_litespeed          = $this->is_using_litespeed_web_server();
		$matomo_is_using_litespeed_cache    = $this->is_using_litespeed_cache_plugin();
		$matomo_is_esi_enabled_in_litespeed = $this->is_litespeed_esi_enabled_in_webserver();

		if ( $matomo_is_using_litespeed && $matomo_is_using_litespeed_cache ) {
			if ( ! $matomo_is_track_via_esi_enabled ) {
				?>
			<tr>
				<td colspan="2">
					<div class="wp-matomo-inline-notice wp-matomo-warning wp-matomo-track-ai-warning" style="<?php echo $matomo_is_track_ai_enabled ? '' : 'display:none;'; ?>">
						<p>
							<strong><?php esc_html_e( 'Warning', 'wp-piwik' ); ?>:</strong>
							<?php esc_html_e( 'We noticed you are using a LiteSpeed webserver with the LiteSpeed Cache plugin. Tracking AI bots with LiteSpeed can only be accomplished via ESI. Please enable the feature both here and in your LiteSpeed webserver.', 'wp-piwik' ); ?>
						</p>
					</div>
				</td>
			</tr>
				<?php
			} elseif ( ! $matomo_is_esi_enabled_in_litespeed ) {
				?>
			<tr>
				<td colspan="2">
					<div class="wp-matomo-inline-notice wp-matomo-warning wp-matomo-track-ai-warning" style="<?php echo $matomo_is_track_ai_enabled ? '' : 'display:none;'; ?>">
						<p>
							<strong><?php esc_html_e( 'Warning', 'wp-piwik' ); ?>:</strong>
							<?php
								printf(
									esc_html__( 'ESI is not currently enabled in your LiteSpeed webserver. To track AI bots with LiteSpeed it is required to enable this feature. %1$sSee LiteSpeed docs for more info.%2$s', 'wp-piwik' ),
									'<a href="https://docs.litespeedtech.com/lscache/lscwp/cache/#esi-tab" target="_blank" rel="noreferrer noopener">',
									'</a>'
								);
							?>
						</p>
					</div>
				</td>
			</tr>
				<?php
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $submit_button;
		echo '</tbody></table><table id="expert" class="wp-piwik_menu-tab hidden"><tbody>';

		$this->show_text( __( 'Usually, you do not need to change these settings. If you want to do so, you should know what you do or you got an expert\'s advice.', 'wp-piwik' ) );

		$this->show_checkbox( 'cache', __( 'Enable cache', 'wp-piwik' ), __( 'Cache API calls, which not contain today\'s values, for a week.', 'wp-piwik' ) );

		if ( function_exists( 'curl_init' ) && ini_get( 'allow_url_fopen' ) ) {
			$this->show_select(
				'http_connection',
				__( 'HTTP connection via', 'wp-piwik' ),
				array(
					'curl'  => __( 'cURL', 'wp-piwik' ),
					'fopen' => __( 'fopen', 'wp-piwik' ),
				),
				__( 'Choose whether WP-Matomo should use cURL or fopen to connect to Matomo in HTTP or Cloud mode.', 'wp-piwik' )
			);
		}

		$this->show_select(
			'http_method',
			__( 'HTTP method', 'wp-piwik' ),
			array(
				'post' => __( 'POST', 'wp-piwik' ),
				'get'  => __( 'GET', 'wp-piwik' ),
			),
			__( 'Choose whether WP-Matomo should use POST or GET in HTTP or Cloud mode.', 'wp-piwik' )
		);

		$this->show_checkbox( 'disable_timelimit', __( 'Disable time limit', 'wp-piwik' ), __( 'Use set_time_limit(0) if stats page causes a time out.', 'wp-piwik' ) );

		$this->show_input( 'filter_limit', __( 'Filter limit', 'wp-piwik' ), __( 'Use filter_limit if you need to get more than 100 results per page.', 'wp-piwik' ) );

		$this->show_input( 'connection_timeout', __( 'Connection timeout', 'wp-piwik' ), 'Define a connection timeout for all HTTP requests done by WP-Matomo in seconds.' );

		$this->show_checkbox( 'disable_ssl_verify', __( 'Disable SSL peer verification', 'wp-piwik' ), '(' . __( 'not recommended', 'wp-piwik' ) . ')' );
		$this->show_checkbox( 'disable_ssl_verify_host', __( 'Disable SSL host verification', 'wp-piwik' ), '(' . __( 'not recommended', 'wp-piwik' ) . ')' );

		$this->show_select(
			'piwik_useragent',
			__( 'User agent', 'wp-piwik' ),
			array(
				'php' => __( 'Use the PHP default user agent', 'wp-piwik' ) . ( ini_get( 'user_agent' ) ? '(' . ini_get( 'user_agent' ) . ')' : ' (' . __( 'empty', 'wp-piwik' ) . ')' ),
				'own' => __( 'Define a specific user agent', 'wp-piwik' ),
			),
			'WP-Matomo can send the default user agent defined by your PHP settings or use a specific user agent below. The user agent is send by WP-Matomo if HTTP requests are performed.',
			'jQuery(\'tr.wp-piwik-useragent-option\').toggleClass(\'hidden\');'
		);
		$this->show_input( 'piwik_useragent_string', __( 'Specific user agent', 'wp-piwik' ), 'Define a user agent description which is send by WP-Matomo if HTTP requests are performed.', 'own' !== self::$settings->get_global_option( 'piwik_useragent' ), 'wp-piwik-useragent-option' );

		$this->show_checkbox(
			'dnsprefetch',
			__( 'Enable DNS prefetch', 'wp-piwik' ),
			esc_html__( 'Add a DNS prefetch tag.', 'wp-piwik' )
				. ' '
				. sprintf(
					esc_html__( 'See %1$sMatomo Blog%2$s.', 'wp-piwik' ),
					'<a target="_BLANK" href="https://piwik.org/blog/2017/04/important-performance-optimizations-load-piwik-javascript-tracker-faster/">',
					'</a>'
				)
		);

		$this->show_checkbox(
			'track_datacfasync',
			__( 'Add data-cfasync=false', 'wp-piwik' ),
			esc_html__( 'Adds data-cfasync=false to the script tag, e.g., to ask Rocket Loader to ignore the script.', 'wp-piwik' )
				. ' '
				. sprintf(
					esc_html__( 'See %1$sCloudFlare Knowledge Base%2$s.', 'wp-piwik' ),
					'<a href="https://support.cloudflare.com/hc/en-us/articles/200169436-How-can-I-have-Rocket-Loader-ignore-my-script-s-in-Automatic-Mode-" target="_BLANK">',
					'</a>'
				)
		);

		$this->show_input( 'track_cdnurl', __( 'CDN URL', 'wp-piwik' ) . ' http://', 'Enter URL if you want to load the tracking code via CDN.' );

		$this->show_input( 'track_cdnurlssl', __( 'CDN URL (SSL)', 'wp-piwik' ) . ' https://', 'Enter URL if you want to load the tracking code via a separate SSL CDN.' );

		$this->show_select(
			'force_protocol',
			__( 'Force Matomo to use a specific protocol', 'wp-piwik' ),
			array(
				'disabled' => __( 'Disabled (default)', 'wp-piwik' ),
				'http'     => __( 'http', 'wp-piwik' ),
				'https'    => __( 'https (SSL)', 'wp-piwik' ),
			),
			__( 'Choose if you want to explicitly force Matomo to use HTTP or HTTPS. Does not work with a CDN URL.', 'wp-piwik' )
		);

		$this->show_checkbox( 'remove_type_attribute', __( 'Remove type attribute', 'wp-piwik' ), __( 'Removes the type attribute from Matomo\'s tracking code script tag.', 'wp-piwik' ) );

		$this->show_select(
			'update_notice',
			__( 'Update notice', 'wp-piwik' ),
			array(
				'enabled'  => __( 'Show always if WP-Matomo is updated', 'wp-piwik' ),
				'script'   => __( 'Show only if WP-Matomo is updated and settings were changed', 'wp-piwik' ),
				'disabled' => __( 'Disabled', 'wp-piwik' ),
			),
			esc_html__( 'Choose if you want to get an update notice if WP-Matomo is updated.', 'wp-piwik' )
		);

		$this->show_input( 'set_download_extensions', __( 'Define all file types for download tracking', 'wp-piwik' ), __( 'Replace Matomo\'s default file extensions for download tracking, divided by a vertical bar (&#124;). Leave blank to keep Matomo\'s default settings.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo documentation%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/guides/tracking-javascript-guide#file-extensions-for-tracking-downloads" target="_BLANK">', '</a>' ) );

		$this->show_input( 'set_download_classes', __( 'Set classes to be treated as downloads', 'wp-piwik' ), __( 'Set classes to be treated as downloads (in addition to piwik_download), divided by a vertical bar (&#124;). Leave blank to keep Matomo\'s default settings.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo JavaScript Tracking Client reference%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/api-reference/tracking-javascript" target="_BLANK">', '</a>' ) );

		$this->show_input( 'set_link_classes', __( 'Set classes to be treated as outlinks', 'wp-piwik' ), __( 'Set classes to be treated as outlinks (in addition to piwik_link), divided by a vertical bar (&#124;). Leave blank to keep Matomo\'s default settings.', 'wp-piwik' ) . ' ' . sprintf( __( 'See %1$sMatomo JavaScript Tracking Client reference%2$s.', 'wp-piwik' ), '<a href="https://developer.piwik.org/api-reference/tracking-javascript" target="_BLANK">', '</a>' ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $submit_button;
		?>
			</tbody>
		</table>
		<table id="support" class="wp-piwik_menu-tab hidden">
			<tbody>
				<tr><td colspan="2">
				<?php $this->show_support(); ?>
				</td></tr>
			</tbody>
		</table>
		<table id="credits" class="wp-piwik_menu-tab hidden">
			<tbody>
				<tr><td colspan="2">
				<?php $this->show_credits(); ?>
				</td></tr>
			</tbody>
		</table>
		<input type="hidden" name="wp-piwik[proxy_url]"
			value="<?php echo esc_attr( self::$settings->get_global_option( 'proxy_url' ) ); ?>" />
	</form>
</div>
		<?php
	}

	/**
	 * Show a checkbox option
	 *
	 * @param string  $id option id
	 * @param string  $name descriptive option name
	 * @param string  $description option description
	 * @param boolean $is_hidden set to true to initially hide the option (default: false)
	 * @param string  $group_name define a class name to access a group of option rows by javascript (default: empty)
	 * @param boolean $hide_description $hideDescription set to false to show description initially (default: true)
	 * @param string  $on_change javascript for onchange event (default: empty)
	 */
	private function show_checkbox( $id, $name, $description, $is_hidden = false, $group_name = '', $hide_description = true, $on_change = '' ) {
		$this->show_input_wrapper(
			$id,
			$name,
			$description,
			$is_hidden,
			$group_name,
			$hide_description,
			function () use ( $id, $on_change ) {
				?>
			<input type="checkbox" value="1" <?php echo ( self::$settings->get_global_option( $id ) ? ' checked="checked"' : '' ); ?> onchange="jQuery(<?php echo esc_attr( wp_json_encode( '#' . $id ) ); ?>).val(this.checked?1:0);
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $on_change;
				?>
				" />
			<input id="<?php echo esc_attr( $id ); ?>" type="hidden" name="wp-piwik[<?php echo esc_attr( $id ); ?>]" value="<?php echo intval( self::$settings->get_global_option( $id ) ); ?>" />
				<?php
			}
		);
	}

	/**
	 * Display the input with the extra elements around it
	 *
	 * @param string       $id option id
	 * @param string       $name descriptive option name
	 * @param string       $description option description
	 * @param boolean      $is_hidden set to true to initially hide the option (default: false)
	 * @param string       $group_name define a class name to access a group of option rows by javascript (default: empty)
	 * @param boolean      $hide_description $hideDescription set to false to show description initially (default: true)
	 * @param callable     $input function to inject the input into the wrapper
	 * @param string|false $row_name define a class name to access the specific option row by javascript (default: empty)
	 *
	 * @return void
	 */
	private function show_input_wrapper( $id, $name, $description, $is_hidden, $group_name, $hide_description, $input, $row_name = false ) {
		?>
		<tr class="<?php echo esc_attr( $group_name ); ?> <?php echo esc_attr( $group_name ); ?>-<?php echo esc_attr( $row_name ); ?> <?php echo $is_hidden ? 'hidden' : ''; ?>">
			<td colspan="2" class="wp-piwik-input-row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?>:</label>
				<?php $input(); ?>
				<?php if ( ! empty( $description ) ) : ?>
					<span class="dashicons dashicons-editor-help" onclick="jQuery(<?php echo esc_attr( wp_json_encode( '#' . $id . '-desc' ) ); ?>).toggleClass('hidden');"></span>
					<p class="description <?php echo $hide_description ? 'hidden' : ''; ?>" id="<?php echo esc_attr( $id ); ?>-desc">
						<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo $description;
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Show a textarea option
	 *
	 * @param string     $id option id
	 * @param string     $name descriptive option name
	 * @param int|string $rows number of rows to show
	 * @param string     $description option description
	 * @param boolean    $is_hidden set to true to initially hide the option (default: false)
	 * @param string     $group_name define a class name to access a group of option rows by javascript (default: empty)
	 * @param boolean    $hide_description $hideDescription set to false to show description initially (default: true)
	 * @param string     $on_change javascript for onchange event (default: empty)
	 * @param boolean    $is_readonly set textarea to read only (default: false)
	 * @param boolean    $is_global set to false if the textarea shows a site-specific option (default: true)
	 */
	private function show_textarea( $id, $name, $rows, $description, $is_hidden, $group_name, $hide_description = true, $on_change = '', $is_readonly = false, $is_global = true ) {
		$this->show_input_wrapper(
			$id,
			$name,
			$description,
			$is_hidden,
			$group_name,
			$hide_description,
			function () use ( $id, $on_change, $rows, $is_readonly, $is_global ) {
				?>
				<textarea cols="80" rows="<?php echo esc_attr( $rows ); ?>" id="<?php echo esc_attr( $id ); ?>" name="wp-piwik[<?php echo esc_attr( $id ); ?>]" onchange="<?php echo esc_attr( $on_change ); ?>" <?php echo ( $is_readonly ? ' readonly="readonly"' : '' ); ?>>
					<?php echo esc_html( $is_global ? self::$settings->get_global_option( $id ) : self::$settings->get_option( $id ) ); ?>
				</textarea>
				<?php
			}
		);
	}

	/**
	 * Show a simple text
	 *
	 * @param string $text Text to show
	 */
	private function show_text( $text ) {
		printf( '<tr><td colspan="2"><p>%s</p></td></tr>', esc_html( $text ) );
	}

	/**
	 * Show an input option
	 *
	 * @param string       $id option id
	 * @param string       $name descriptive option name
	 * @param string|false $description option description
	 * @param boolean      $is_hidden set to true to initially hide the option (default: false)
	 * @param string       $group_name define a class name to access a group of option rows by javascript (default: empty)
	 * @param string|false $row_name define a class name to access the specific option row by javascript (default: empty)
	 * @param boolean      $hide_description $hideDescription set to false to show description initially (default: true)
	 * @param boolean      $wide Create a wide box (default: false)
	 */
	private function show_input( $id, $name, $description, $is_hidden = false, $group_name = '', $row_name = false, $hide_description = true, $wide = false, $type = false ) {
		$this->show_input_wrapper(
			$id,
			$name,
			$description,
			$is_hidden,
			$group_name,
			$hide_description,
			function () use ( $id, $type ) {
				?>
			<input type="<?php echo esc_attr( $type ? $type : 'text' ); ?>" name="wp-piwik[<?php echo esc_attr( $id ); ?>]" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( self::$settings->get_global_option( $id ) ); ?>" >
				<?php
			},
			$row_name
		);
	}

	/**
	 * Show a select box option
	 *
	 * @param string  $id option id
	 * @param string  $name descriptive option name
	 * @param array   $options list of options to show array[](option id => descriptive name)
	 * @param string  $description option description
	 * @param string  $on_change javascript for onchange event (default: empty)
	 * @param boolean $is_hidden set to true to initially hide the option (default: false)
	 * @param string  $group_name define a class name to access a group of option rows by javascript (default: empty)
	 * @param boolean $hide_description $hideDescription set to false to show description initially (default: true)
	 * @param boolean $is_global set to false if the textarea shows a site-specific option (default: true)
	 */
	private function show_select( $id, $name, $options = array(), $description = '', $on_change = '', $is_hidden = false, $group_name = '', $hide_description = true, $is_global = true ) {
		$default = $is_global ? self::$settings->get_global_option( $id ) : self::$settings->get_option( $id );

		$this->show_input_wrapper(
			$id,
			$name,
			$description,
			$is_hidden,
			$group_name,
			$hide_description,
			function () use ( $id, $on_change, $options, $default ) {
				?>
			<select name="wp-piwik[<?php echo esc_attr( $id ); ?>]" id="<?php echo esc_attr( $id ); ?>" onchange="<?php echo esc_attr( $on_change ); ?>">
				<?php foreach ( $options as $key => $value ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php echo ( $key === $default ? ' selected="selected"' : '' ); ?> ><?php echo esc_html( $value ); ?></option>
				<?php endforeach; ?>
			</select>
				<?php
			}
		);
	}

	/**
	 * Show an info box
	 *
	 * @param string $type box style (e.g., updated, error)
	 * @param string $icon box icon, see https://developer.wordpress.org/resource/dashicons/
	 * @param string $content box message (HTML allowed)
	 */
	private function show_box( $type, $icon, $content ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<tr><td colspan="2"><div class="%s"><p><span class="dashicons dashicons-%s"></span> %s</p></div></td></tr>', esc_attr( $type ), esc_attr( $icon ), $content );
	}

	/**
	 * Show headline
	 *
	 * @param int         $order headline order (h?-tag), set to 0 to avoid headline-tagging
	 * @param string      $icon headline icon, see https://developer.wordpress.org/resource/dashicons/
	 * @param string      $headline headline text
	 * @param string|bool $add_plugin_name set to true to add the plugin name to the headline (default: false)
	 */
	private function show_headline( $order, $icon, $headline, $add_plugin_name = false ) {
		$this->get_headline( $order, $icon, $headline, $add_plugin_name );
	}

	/**
	 * Get headline HTML
	 *
	 * @param int    $order headline order (h?-tag), set to 0 to avoid headline-tagging
	 * @param string $icon headline icon, see https://developer.wordpress.org/resource/dashicons/
	 * @param string $headline headline text
	 * @param bool   $add_plugin_name set to true to add the plugin name to the headline (default: false)
	 */
	private function get_headline( $order, $icon, $headline, $add_plugin_name = false ) {
		echo ( $order > 0 ? '<h' . intval( $order ) . '>' : '' )
			. sprintf(
				'<span class="dashicons dashicons-%s"></span> %s%s',
				esc_attr( $icon ),
				esc_html( $add_plugin_name ? self::$settings->get_not_empty_global_option( 'plugin_display_name' ) . ' ' : '' ),
				esc_html( $headline )
			)
			. ( $order > 0 ? '</h' . intval( $order ) . '>' : '' );
	}

	/**
	 * Show donation info
	 */
	private function show_donation() {
		?>
<div class="wp-piwik-donate">
	<p>
		<strong><?php esc_html_e( 'Donate', 'wp-piwik' ); ?></strong>
	</p>
	<p>
		<?php esc_html_e( 'If you like WP-Matomo, you can support its development by a donation:', 'wp-piwik' ); ?>
	</p>
	<div>
		Paypal
		<form action="https://www.paypal.com/cgi-bin/webscr" method="post">
			<input type="hidden" name="cmd" value="_s-xclick" />
			<input type="hidden" name="hosted_button_id" value="6046779" />
			<input type="image" src="https://www.paypal.com/en_GB/i/btn/btn_donateCC_LG.gif" name="submit" alt="PayPal - The safer, easier way to pay online." />
			<img alt="" border="0" src="https://www.paypal.com/de_DE/i/scr/pixel.gif" width="1" height="1" />
		</form>
	</div>
	<div>
		<a href="bitcoin:32FMBngRne9wQ7XPFP2CfR25tjp3oa4roN">Bitcoin<br />
		<img style="border:none;" src="<?php echo esc_attr( self::$wp_piwik->get_plugin_url() ); ?>bitcoin.png" width="100" height="100" alt="Bitcoin Address" title="32FMBngRne9wQ7XPFP2CfR25tjp3oa4roN" /></a>
	</div>
	<div>
		<a href="http://www.amazon.de/gp/registry/wishlist/111VUJT4HP1RA?reveal=unpurchased&amp;filter=all&amp;sort=priority&amp;layout=standard&amp;x=12&amp;y=14"><?php esc_html_e( 'My Amazon.de wishlist', 'wp-piwik' ); ?></a>
	</div>
</div>
		<?php
	}

	/**
	 * Register admin scripts
	 *
	 * @see \WP_Piwik\Admin::print_admin_scripts()
	 */
	public function print_admin_scripts() {
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Extend admin header
	 *
	 * @see \WP_Piwik\Admin::extend_admin_header()
	 */
	public function extend_admin_header() {
	}

	/**
	 * Show credits
	 */
	public function show_credits() {
		?>
		<p><strong><?php esc_html_e( 'Thank you very much, everyone who donates to the WP-Matomo project, including the Matomo team!', 'wp-piwik' ); ?></strong></p>
		<p>
			<?php
				printf(
					esc_html__( 'Graphs powered by %1$sChart.js%2$s (MIT License).', 'wp-piwik' ),
					'<a href="https://www.chartjs.org" target="_BLANK">',
					'</a>'
				);
			?>
		</p>
		<p><?php esc_html_e( 'Thank you very much', 'wp-piwik' ); ?>, <?php esc_html_e( 'Transifex and WordPress translation community for your translation work.', 'wp-piwik' ); ?>!</p>
		<p><?php esc_html_e( 'Thank you very much, all users who send me mails containing criticism, commendation, feature requests and bug reports! You help me to make WP-Matomo much better.', 'wp-piwik' ); ?></p>
		<p>
			<?php
				printf(
					esc_html__( 'Thank %1$syou%2$s for using my plugin. It is the best commendation if my piece of code is really used!', 'wp-piwik' ),
					'<strong>',
					'</strong>'
				);
			?>
		</p>
		<?php
	}

	/**
	 * Show support information
	 */
	public function show_support() {
		?>
		<h2><?php esc_html_e( 'How can we help?', 'wp-piwik' ); ?></h2>

		<form method="get" action="https://matomo.org" target="_blank" rel="noreferrer noopener">
			<input type="text" name="s" style="width:300px;"><input type="submit" class="button-secondary" value="<?php esc_attr_e( 'Search on', 'wp-piwik' ); ?> matomo.org">
		</form>
		<ul class="wp-piwik-help-list">
			<li><a target="_blank" rel="noreferrer noopener"
					href="https://matomo.org/docs/"><?php esc_html_e( 'User guides', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Learn how to configure Matomo and how to effectively analyse your data', 'wp-piwik' ); ?></li>
			<li><a target="_blank" rel="noreferrer noopener"
					href="https://matomo.org/faq/wordpress/"><?php esc_html_e( 'Matomo for WordPress FAQs', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Get answers to frequently asked questions', 'wp-piwik' ); ?></li>
			<li><a target="_blank" rel="noreferrer noopener"
					href="https://matomo.org/faq/"><?php esc_html_e( 'General FAQs', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Get answers to frequently asked questions', 'wp-piwik' ); ?></li>
			<li><a target="_blank" rel="noreferrer noopener"
					href="https://forum.matomo.org/"><?php esc_html_e( 'Forums', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Get help directly from the community of Matomo users', 'wp-piwik' ); ?></li>
			<li><a target="_blank" rel="noreferrer noopener"
					href="https://glossary.matomo.org"><?php esc_html_e( 'Glossary', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Learn about commonly used terms to make the most of Matomo Analytics', 'wp-piwik' ); ?></li>
			<li><a target="_blank" rel="noreferrer noopener"
					href="https://matomo.org/support-plans/"><?php esc_html_e( 'Support Plans', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Let our experienced team assist you online on how to best utilise Matomo', 'wp-piwik' ); ?></li>
			<li><a href="https://local.wordpressplugin.matomo.org/wp-admin/admin.php?page=matomo-systemreport&#038;tab=troubleshooting"><?php esc_html_e( 'Troubleshooting', 'wp-piwik' ); ?></a>
				- <?php esc_html_e( 'Click here if you are having Trouble with Matomo', 'wp-piwik' ); ?></li>
		</ul>

		<ul>
			<li><?php esc_html_e( 'Contact Matomo support here:', 'wp-piwik' ); ?> <a href="https://matomo.org/contact/" target="_BLANK">https://matomo.org/contact/</a></li>
			<li><?php esc_html_e( 'Find support for this plugin here:', 'wp-piwik' ); ?> <a href="https://wordpress.org/support/plugin/wp-piwik" target="_BLANK"><?php esc_html_e( 'WP-Matomo support forum', 'wp-piwik' ); ?></a></li>
			<li><?php esc_html_e( 'Please don\'t forget to vote the compatibility at the', 'wp-piwik' ); ?> <a href="http://wordpress.org/extend/plugins/wp-piwik/" target="_BLANK">WordPress.org Plugin Directory</a>.</li>
		</ul>
		<h3><?php esc_html_e( 'Debugging', 'wp-piwik' ); ?></h3>
		<p>
			<?php
				sprintf(
					esc_html__( 'Either allow_url_fopen has to be enabled %1$sor%2$s cURL has to be available:', 'wp-piwik' ),
					'<em>',
					'</em>'
				);
			?>
		</p>
		<ol>
			<li>
			<?php
				esc_html_e( 'cURL is', 'wp-piwik' );
				echo ' <strong>' . ( function_exists( 'curl_init' ) ? '' : esc_html__( 'not', 'wp-piwik' ) ) . ' ';
				esc_html_e( 'available', 'wp-piwik' );
			?>
			</strong>.</li>
			<li>
			<?php
				esc_html_e( 'allow_url_fopen is', 'wp-piwik' );
				echo ' <strong>' . ( ini_get( 'allow_url_fopen' ) ? '' : esc_html__( 'not', 'wp-piwik' ) ) . ' ';
				esc_html_e( 'enabled', 'wp-piwik' );
			?>
			</strong>.</li>
			<li><strong><?php echo ( ( ( function_exists( 'curl_init' ) && ini_get( 'allow_url_fopen' ) && 'curl' === self::$settings->get_global_option( 'http_connection' ) ) || ( function_exists( 'curl_init' ) && ! ini_get( 'allow_url_fopen' ) ) ) ? esc_html__( 'cURL', 'wp-piwik' ) : esc_html__( 'fopen', 'wp-piwik' ) ) . ' (' . ( 'post' === self::$settings->get_global_option( 'http_method' ) ? esc_html__( 'POST', 'wp-piwik' ) : esc_html__( 'GET', 'wp-piwik' ) ) . ')</strong> ' . esc_html__( 'is used.', 'wp-piwik' ); ?></li>
			<?php
			if ( 'php' === self::$settings->get_global_option( 'piwik_mode' ) ) {
				?>
				<li>
				<?php
				esc_html_e( 'Determined Matomo base URL is', 'wp-piwik' );
				echo ' <strong>' . esc_html( self::$settings->get_global_option( 'proxy_url' ) ) . '</strong>';
				?>
			</li><?php } ?>
		</ol>
		<p><?php esc_html_e( 'Tools', 'wp-piwik' ); ?>:</p>
		<ol>
			<?php
				$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			?>
			<li><a href="<?php echo esc_attr( admin_url( ( self::$settings->check_network_activation() ? 'network/settings' : 'options-general' ) . '.php?page=' . esc_attr( rawurlencode( $page ) ) . '&testscript=1' ) ); ?>"><?php esc_html_e( 'Run testscript', 'wp-piwik' ); ?></a></li>
			<li><a href="<?php echo esc_attr( admin_url( ( self::$settings->check_network_activation() ? 'network/settings' : 'options-general' ) . '.php?page=' . esc_attr( rawurlencode( $page ) ) . '&sitebrowser=1' ) ); ?>"><?php esc_html_e( 'Sitebrowser', 'wp-piwik' ); ?></a></li>
			<li><a href="<?php echo esc_attr( wp_nonce_url( admin_url( ( self::$settings->check_network_activation() ? 'network/settings' : 'options-general' ) . '.php?page=' . esc_attr( rawurlencode( $page ) ) . '&clear=1' ) ) ); ?>"><?php esc_html_e( 'Clear cache', 'wp-piwik' ); ?></a></li>
			<li><a onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Are you sure you want to clear all settings?', 'wp-piwik' ) ) ); ?>)"
					href="<?php echo esc_attr( wp_nonce_url( admin_url( ( self::$settings->check_network_activation() ? 'network/settings' : 'options-general' ) . '.php?page=' . rawurlencode( $page ) . '&clear=2' ) ) ); ?>"><?php esc_html_e( 'Reset WP-Matomo', 'wp-piwik' ); ?></a></li>
		</ol>
		<h3><?php esc_html_e( 'Latest support threads on WordPress.org', 'wp-piwik' ); ?></h3>
		<?php
		$support_threads = $this->read_rss_feed( 'http://wordpress.org/support/rss/plugin/wp-piwik' );
		if ( ! empty( $support_threads ) ) {
			echo '<ol>';
			foreach ( $support_threads as $support_thread ) {
				echo '<li><a href="' . esc_attr( $support_thread['url'] ) . '">' . esc_html( $support_thread['title'] ) . '</a></li>';
			}
			echo '</ol>';
		}
	}

	/**
	 * Read RSS feed
	 *
	 * @param string $feed
	 *          feed URL
	 * @param int    $cnt
	 *          item limit
	 * @return array feed items array[](title, url)
	 *
	 */
	private function read_rss_feed( $feed, $cnt = 5 ) {
		$result = array();
		if ( function_exists( 'simplexml_load_file' ) && ! empty( $feed ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$xml = @simplexml_load_file( $feed );
			if ( ! $xml || ! isset( $xml->channel [0]->item ) ) {
				return array(
					array(
						'title' => 'Can\'t read RSS feed.',
						'url'   => $xml,
					),
				);
			}
			foreach ( $xml->channel [0]->item as $item ) {
				if ( 0 === $cnt-- ) {
					break;
				}
				$result [] = array(
					'title' => $item->title [0],
					'url'   => $item->link [0],
				);
			}
		}
		return $result;
	}

	/**
	 * Clear cache and reset settings
	 *
	 * @param boolean $clear_settings set to true to reset settings (default: false)
	 */
	private function clear( $clear_settings = false ) {
		if ( $clear_settings ) {
			self::$settings->reset_settings();
			$this->show_box( 'updated', 'yes', esc_html__( 'Settings cleared (except connection settings).', 'wp-piwik' ) );
		}
		global $wpdb;
		if ( self::$settings->check_network_activation() ) {
			$ary_blogs = \WP_Piwik\Settings::get_blog_list();
			if ( is_array( $ary_blogs ) ) {
				foreach ( $ary_blogs as $ary_blog ) {
					switch_to_blog( $ary_blog['blog_id'] );
					$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_wp-piwik_%'" );
					$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_wp-piwik_%'" );
					restore_current_blog();
				}
			}
		} else {
			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_wp-piwik_%'" );
			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_wp-piwik_%'" );
		}
		$this->show_box( 'updated', 'yes', esc_html__( 'Cache cleared.', 'wp-piwik' ) );
	}

	/**
	 * Execute test script and display results
	 */
	private function run_testscript() {
		?>
		<div class="wp-piwik-debug">
		<h2>Testscript Result</h2>
		<?php
		if ( self::$wp_piwik->is_configured() ) {
			if ( ! empty( $_GET['testscript_id'] ) ) {
				switch_to_blog( intval( wp_unslash( $_GET['testscript_id'] ) ) );
			}
			?>
		<textarea cols="80" rows="10">
			<?php
			echo '`WP-Matomo ' . esc_html( self::$wp_piwik->get_plugin_version() ) . "\nMode: " . esc_html( self::$settings->get_global_option( 'piwik_mode' ) ) . "\n\n";
			?>
		Test 1/3: global.getPiwikVersion
			<?php
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$GLOBALS ['wp-piwik_debug'] = true;
			$id                         = \WP_Piwik\Request::register( 'API.getPiwikVersion', array() );
			echo "\n\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$wp_piwik->request( $id ) );
			echo "\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$wp_piwik->request( $id, true ) );
			echo "\n";
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$GLOBALS ['wp-piwik_debug'] = false;
			?>
		Test 2/3: SitesManager.getSitesWithAtLeastViewAccess
			<?php
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$GLOBALS ['wp-piwik_debug'] = true;
			$id                         = \WP_Piwik\Request::register( 'SitesManager.getSitesWithAtLeastViewAccess', array() );
			echo "\n\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$wp_piwik->request( $id ) );
			echo "\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$wp_piwik->request( $id, true ) );
			echo "\n";
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$GLOBALS ['wp-piwik_debug'] = false;
			?>
		Test 3/3: SitesManager.getSitesIdFromSiteUrl
			<?php
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$GLOBALS ['wp-piwik_debug'] = true;
			$id                         = \WP_Piwik\Request::register(
				'SitesManager.getSitesIdFromSiteUrl',
				array(
					'url' => get_bloginfo( 'url' ),
				)
			);
			echo "\n\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$wp_piwik->request( $id ) );
			echo "\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$wp_piwik->request( $id, true ) );
			echo "\n";
			echo "\n\n";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
			var_dump( self::$settings->get_debug_data() );
			echo '`';
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			$GLOBALS ['wp-piwik_debug'] = false;
			?>
		</textarea>
			<?php
			if ( ! empty( $_GET['testscript_id'] ) ) {
				restore_current_blog();
			}
		} else {
			?>
			<p>Please configure WP-Matomo first.</p>
			<?php
		}
		?>
		</div>
		<?php
	}


	/**
	 * Returns true if WordPress is configured to use the advanced-cache.php
	 * file, and if such a file exists.
	 *
	 * @return bool
	 */
	public static function is_advanced_cache_used() {
		return defined( 'WP_CACHE' )
			&& WP_CACHE
			&& is_file( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	/**
	 * To track AI bots when the advanced-cache.php file is in use, a
	 * special code snippet must be added to a user's wp-config.php.
	 *
	 * This function checks if the required snippet has been added to
	 * this WordPress' wp-config.php file.
	 *
	 * @param string $abspath_override only used for tests.
	 * @return bool|null true if the snippet is detected, false if it is not,
	 *                   and null if the wp-config.php file cannot be read for
	 *                   some reason
	 */
	public static function is_track_script_used_in_wp_config( $abspath_override = null ) {
		$abspath_override = ! empty( $abspath_override ) ? $abspath_override : ABSPATH;

		$wp_config_path = $abspath_override . '/wp-config.php';

		if ( ! is_readable( $wp_config_path ) ) {
			return null;
		}

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$wp_config_contents = @file_get_contents( $wp_config_path );

		// some systems may disable reading of files outside of wp-content
		if ( ! is_string( $wp_config_contents ) ) {
			return null;
		}

		$is_track_ai_bot_script_used = preg_match( '/require_once.*?track_ai_bot\.php/', $wp_config_contents ) === 1;

		return $is_track_ai_bot_script_used;
	}

	public static function is_htaccess_serving_cache_files() {
		if ( ! is_file( ABSPATH . '/.htaccess' ) ) {
			return false;
		}

		if ( ! function_exists( 'apache_get_modules' ) ) {
			return false; // not using apache
		}

		$htaccess_contents     = file_get_contents( ABSPATH . '/.htaccess' );
		$is_rewrite_rule_found = preg_match( '%RewriteRule.*?/wp-content/cache/%', $htaccess_contents ) === 1;

		return $is_rewrite_rule_found;
	}


	public function is_using_litespeed_web_server() {
		return php_sapi_name() === 'litespeed';
	}

	public function is_using_litespeed_cache_plugin() {
		return is_plugin_active( 'litespeed-cache/litespeed-cache.php' );
	}

	private function is_litespeed_esi_enabled_in_webserver() {
		// see https://docs.litespeedtech.com/lscache/lscwp/api/#get-esi-enable-status
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return (bool) apply_filters( 'litespeed_esi_status', false );
	}
}
