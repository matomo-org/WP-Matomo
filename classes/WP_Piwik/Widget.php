<?php

namespace WP_Piwik;

use WP_Piwik;

/**
 * Abstract widget class
 *
 * @author Andr&eacute; Br&auml;kling
 * @package WP_Piwik
 */
abstract class Widget {


	/**
	 *
	 * @var WP_Piwik
	 */
	protected static $wp_piwik;

	/**
	 * @var Settings
	 */
	protected static $settings;

	protected $is_shortcode = false;
	protected $method       = '';
	protected $title        = '';
	protected $context      = 'side';
	protected $priority     = 'core';
	protected $parameter    = array();
	protected $api_id       = array();
	protected $page_id      = 'dashboard';
	protected $blog_id      = null;
	protected $name         = 'Value';
	protected $limit        = 10;
	protected $content      = '';
	protected $output       = '';

	/**
	 * Widget constructor
	 *
	 * @param WP_Piwik $wp_piwik
	 *            current WP-Piwik object
	 * @param Settings $settings
	 *            current WP-Piwik settings
	 * @param string   $page_id
	 *            WordPress page ID (default: dashboard)
	 * @param string   $context
	 *            WordPress meta box context (defualt: side)
	 * @param string   $priority
	 *            WordPress meta box priority (default: default)
	 * @param array    $params
	 *            widget parameters (default: empty array)
	 * @param boolean  $is_shortcode
	 *            is the widget shown inline? (default: false)
	 */
	public function __construct( $wp_piwik, $settings, $page_id = 'dashboard', $context = 'side', $priority = 'default', $params = array(), $is_shortcode = false ) {
		self::$wp_piwik = $wp_piwik;
		self::$settings = $settings;
		$this->page_id  = $page_id;
		$this->context  = $context;
		$this->priority = $priority;
		if ( self::$settings->check_network_activation() && function_exists( 'is_super_admin' ) && is_super_admin() && isset( $_GET ['wpmu_show_stats'] ) ) {
			switch_to_blog( (int) $_GET ['wpmu_show_stats'] );
			$this->blog_id = get_current_blog_id();
			restore_current_blog();
		}
		$this->is_shortcode = $is_shortcode;
		$prefix             = ( 'dashboard' === $this->page_id ? self::$settings->get_global_option( 'plugin_display_name' ) . ' - ' : '' );
		$this->configure( $prefix, $params );
		if ( is_array( $this->method ) ) {
			foreach ( $this->method as $method ) {
				$this->api_id [ $method ] = Request::register( $method, $this->parameter );
				self::$wp_piwik->log( 'Register request: ' . $this->api_id [ $method ] );
			}
		} else {
			$this->api_id [ $this->method ] = Request::register( $this->method, $this->parameter );
			self::$wp_piwik->log( 'Register request: ' . $this->api_id [ $this->method ] );
		}
		if ( $this->is_shortcode ) {
			return;
		}
		add_meta_box(
			$this->get_name(),
			$this->title,
			array(
				$this,
				'show',
			),
			$page_id,
			$this->context,
			$this->priority
		);
	}

	/**
	 * Conifguration dummy method
	 *
	 * @param string $prefix
	 *            metabox title prefix (default: empty)
	 * @param array  $params
	 *            widget parameters (default: empty array)
	 */
	protected function configure( $prefix = '', $params = array() ) {
	}

	/**
	 * Default show widget method, handles default Piwik output
	 */
	public function show() {
		$response = self::$wp_piwik->request( $this->api_id [ $this->method ] );
		if ( ! empty( $response ['result'] ) && 'error' === $response['result'] ) {
			$this->out( '<strong>' . esc_html__( 'Piwik error', 'wp-piwik' ) . ':</strong> ' . esc_html( $response['message'] ) );
		} else {
			if ( isset( $response [0] ['nb_uniq_visitors'] ) ) {
				$unique = 'nb_uniq_visitors';
			} else {
				$unique = 'sum_daily_nb_uniq_visitors';
			}
			$table_head             = array(
				'label' => $this->name,
			);
			$table_head [ $unique ] = __( 'Unique', 'wp-piwik' );
			if ( isset( $response [0] ['nb_visits'] ) ) {
				$table_head ['nb_visits'] = __( 'Visits', 'wp-piwik' );
			}
			if ( isset( $response [0] ['nb_hits'] ) ) {
				$table_head ['nb_hits'] = __( 'Hits', 'wp-piwik' );
			}
			if ( isset( $response [0] ['nb_actions'] ) ) {
				$table_head ['nb_actions'] = __( 'Actions', 'wp-piwik' );
			}
			$table_body = array();
			$count      = 0;
			if ( is_array( $response ) ) {
				foreach ( $response as $row_key => $row ) {
					++$count;
					$table_body[ $row_key ] = array();
					foreach ( $table_head as $key => $value ) {
						$table_body[ $row_key ] [] = isset( $row[ $key ] ) ? $row[ $key ] : '-';
					}
					if ( 10 === $count ) {
						break;
					}
				}
			}
			$this->table( $table_head, $table_body, null );
		}
	}

	/**
	 * Display or store shortcode output
	 */
	protected function out( $output ) {
		if ( $this->is_shortcode ) {
			$this->output .= $output;
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
	}

	/**
	 * Return shortcode output
	 */
	public function get() {
		return $this->output;
	}

	/**
	 * Display a HTML table
	 *
	 * @param array|null   $thead
	 *            table header content (array of cells)
	 * @param array        $tbody
	 *            table body content (array of rows)
	 * @param array|null   $tfoot
	 *            table footer content (array of cells)
	 * @param string|false $css_class
	 *            CSS class name to apply on table sections
	 * @param array        $java_script
	 *            array of javascript code to apply on body rows
	 * @param array        $css_classes
	 *            array mapping keys in $tbody to css classes to apply on table rows.
	 */
	protected function table( $thead, $tbody = array(), $tfoot = array(), $css_class = false, $java_script = array(), $css_classes = array() ) {
		$this->out( '<div class="table"><table class="widefat wp-piwik-table">' );
		if ( $this->is_shortcode && $this->title ) {
			$colspan = ! empty( $tbody ) ? count( $tbody[0] ) : 2;
			$this->out( '<tr><th colspan="' . $colspan . '">' . esc_html( $this->title ) . '</th></tr>' );
		}
		if ( ! empty( $thead ) ) {
			$this->tab_head( $thead, $css_class );
		}
		if ( ! empty( $tbody ) ) {
			$this->tab_body( $tbody, $css_class, $java_script, $css_classes );
		} else {
			$this->out( '<tr><td colspan="10">' . esc_html__( 'No data available.', 'wp-piwik' ) . '</td></tr>' );
		}
		if ( ! empty( $tfoot ) ) {
			$this->tab_foot( $tfoot, $css_class );
		}
		$this->out( '</table></div>' );
	}

	/**
	 * Display a HTML table header
	 *
	 * @param array        $thead
	 *            array of cells.
	 * @param string|false $css_class
	 *            CSS class to apply
	 */
	private function tab_head( $thead, $css_class = false ) {
		$this->out( '<thead' . ( $css_class ? ' class="' . esc_attr( $css_class ) . '"' : '' ) . '><tr>' );
		$count = 0;
		foreach ( $thead as $value ) {
			$this->out( '<th' . ( $count++ ? ' class="right"' : '' ) . '>' . esc_html( $value ) . '</th>' );
		}
		$this->out( '</tr></thead>' );
	}

	/**
	 * Display a HTML table body
	 *
	 * @param array  $tbody
	 *            array of rows, each row containing an array of cells
	 * @param string $css_class
	 *            CSS class to apply
	 * @param array  $java_script
	 *            array of javascript code to apply (one item per row)
	 */
	private function tab_body( $tbody, $css_class = '', $java_script = array(), $css_classes = array() ) {
		$this->out( '<tbody' . ( $css_class ? ' class="' . esc_attr( $css_class ) . '"' : '' ) . '>' );
		foreach ( $tbody as $key => $trow ) {
			$this->tab_row( $trow, isset( $java_script [ $key ] ) ? $java_script [ $key ] : '', isset( $css_classes [ $key ] ) ? $css_classes [ $key ] : '' );
		}
		$this->out( '</tbody>' );
	}

	/**
	 * Display a HTML table footer
	 *
	 * @param array        $tfoot
	 *            array of cells
	 * @param string|false $css_class
	 *            CSS class to apply
	 */
	private function tab_foot( $tfoot, $css_class = false ) {
		$this->out( '<tfoot' . ( $css_class ? ' class="' . esc_attr( $css_class ) . '"' : '' ) . '><tr>' );
		$count = 0;
		foreach ( $tfoot as $value ) {
			// $value is allowed to contain html
			$this->out( '<td' . ( $count++ ? ' class="right"' : '' ) . '>' . $value . '</td>' );
		}
		$this->out( '</tr></tfoot>' );
	}

	/**
	 * Display a HTML table row
	 *
	 * @param array  $trow
	 *            array of cells
	 * @param string $java_script
	 *            javascript code to apply
	 */
	private function tab_row( $trow, $java_script = '', $css_class = '' ) {
		$this->out( '<tr' . ( ! empty( $java_script ) ? ' onclick="' . esc_attr( esc_js( $java_script ) ) . '"' : '' ) . ( ! empty( $css_class ) ? ' class="' . esc_attr( $css_class ) . '"' : '' ) . '>' );
		$count = 0;
		foreach ( $trow as $tcell ) {
			$this->out( '<td' . ( $count++ ? ' class="right"' : '' ) . '>' . esc_html( $tcell ) . '</td>' );
		}
		$this->out( '</tr>' );
	}

	/**
	 * Get the current request's Piwik time settings
	 *
	 * @return array time settings: period => Piwik period, date => requested date, description => time description to show in widget title
	 */
	protected function get_time_settings() {
		switch ( self::$settings->get_global_option( 'default_date' ) ) {
			case 'today':
				$period      = 'day';
				$date        = 'today';
				$description = __( 'today', 'wp-piwik' );
				break;
			case 'current_month':
				$period      = 'month';
				$date        = 'today';
				$description = __( 'current month', 'wp-piwik' );
				break;
			case 'last_month':
				$period      = 'month';
				$date        = gmdate( 'Y-m-d', strtotime( 'last day of previous month' ) );
				$description = __( 'last month', 'wp-piwik' );
				break;
			case 'current_week':
				$period      = 'week';
				$date        = 'today';
				$description = __( 'current week', 'wp-piwik' );
				break;
			case 'last_week':
				$period      = 'week';
				$date        = gmdate( 'Y-m-d', strtotime( '-1 week' ) );
				$description = __( 'last week', 'wp-piwik' );
				break;
			case 'yesterday':
			default:
				$period      = 'day';
				$date        = 'yesterday';
				$description = __( 'yesterday', 'wp-piwik' );
				break;
		}

		if ( isset( $_GET['date'] ) ) {
			$date = intval( wp_unslash( $_GET['date'] ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$description = $this->date_format( wp_unslash( $_GET['date'] ), $period );
		}

		return array(
			'period'      => $period,
			'date'        => $date,
			'description' => $description,
		);
	}

	/**
	 * Format a date to show in widget
	 *
	 * @param string $date
	 *            date string
	 * @param string $period
	 *            Piwik period
	 * @return string formatted date
	 */
	protected function date_format( $date, $period = 'day' ) {
		$prefix = '';
		switch ( $period ) {
			case 'week':
				$prefix = __( 'week', 'wp-piwik' ) . ' ';
				$format = 'W/Y';
				break;
			case 'short_week':
				$format = 'W';
				break;
			case 'month':
				$format = 'F Y';
				$date   = gmdate( 'Y-m-d', strtotime( $date ) );
				break;
			default:
				$format = get_option( 'date_format' );
		}
		return $prefix . date_i18n( $format, strtotime( $date ) );
	}

	/**
	 * Format time to show in widget
	 *
	 * @param int $time
	 *            time in seconds
	 * @return string formatted time
	 */
	protected function time_format( $time ) {
		return floor( $time / 3600 ) . 'h ' . floor( ( $time % 3600 ) / 60 ) . 'm ' . floor( ( $time % 3600 ) % 60 ) . 's';
	}

	/**
	 * Convert Piwik range into meaningful text
	 *
	 * @return string range description
	 */
	public function range_name() {
		switch ( $this->parameter ['date'] ) {
			case 'last90':
				return __( 'last 90 days', 'wp-piwik' );
			case 'last60':
				return __( 'last 60 days', 'wp-piwik' );
			case 'last30':
				return __( 'last 30 days', 'wp-piwik' );
			case 'last12':
				switch ( $this->parameter['period'] ) {
					case 'day':
						return __( 'last 12 days', 'wp-piwik' );
					case 'week':
						return __( 'last 12 weeks', 'wp-piwik' );
					case 'month':
						return __( 'last 12 months', 'wp-piwik' );
					case 'year':
						return __( 'last 12 years', 'wp-piwik' );
					default:
						return __( 'last 12', 'wp-piwik' ) . $this->parameter['period'];
				}
			default:
				return $this->parameter ['date'];
		}
	}

	/**
	 * Get the widget name
	 *
	 * @return string widget name
	 */
	public function get_name() {
		return str_replace( '\\', '-', get_called_class() );
	}

	/**
	 * Display a pie chart
	 *
	 * @param array $data chart data array(array(0 => name, 1 => value))
	 */
	public function pie_chart( $data ) {
		$labels = array();
		$values = array();
		foreach ( $data as $key => $data_set ) {
			$labels[] = $data_set[0];
			$values[] = $data_set[1];
			if ( 'Others' === $key ) {
				break;
			}
		}
		?>
		<div>
			<canvas id="<?php echo esc_attr( 'wp-piwik_stats_' . $this->get_name() . '_graph' ); ?>"></canvas>
		</div>
		<script>
			new Chart(
				document.getElementById(<?php echo wp_json_encode( 'wp-piwik_stats_' . $this->get_name() . '_graph' ); ?>),
				{
					type: 'pie',
					data: {
						labels: <?php echo wp_json_encode( $labels ); ?>,
						datasets: [
							{
								label: '',
								data: <?php echo wp_json_encode( $values ); ?>,
								backgroundColor: [
									'#4dc9f6',
									'#f67019',
									'#f53794',
									'#537bc4',
									'#acc236',
									'#166a8f',
									'#00a950',
									'#58595b',
									'#8549ba'
								]
							}
						]
					},
					options: {
						radius:"90%"
					}
				}
			);
		</script>
		<?php
	}

	/**
	 * Return an array value by key, return '-' if not set
	 *
	 * @param array  $values
	 *            array to get a value from
	 * @param string $key
	 *            key of the value to get from array
	 * @return string|float found value or '-' as a placeholder
	 */
	protected function value( $values, $key ) {
		return isset( $values[ $key ] ) ? $values[ $key ] : '-';
	}
}
