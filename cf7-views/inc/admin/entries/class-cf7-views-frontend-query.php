<?php
/**
 * CF7 Views Frontend Query Integration
 *
 * Integrates form entries with CF7 Views for frontend display
 *
 * @package CF7_Views
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_Views_Frontend_Query {

	/**
	 * Database handler instance
	 */
	private $db;

	public function __construct() {
		$this->db = new CF7_Views_Entries_DB();

		// Hook into CF7 Views query system
		add_filter( 'cf7_views_query_data', array( $this, 'add_entries_data' ), 10, 3 );
		add_filter( 'cf7_views_available_fields', array( $this, 'add_entry_fields' ), 10, 2 );
		add_action( 'wp_ajax_cf7_views_search_entries', array( $this, 'ajax_search_entries' ) );
		add_action( 'wp_ajax_nopriv_cf7_views_search_entries', array( $this, 'ajax_search_entries' ) );
	}

	/**
	 * Add entries data to CF7 Views query
	 *
	 * @param array $data Current view data
	 * @param int   $view_id View ID
	 * @param array $view_settings View settings
	 * @return array Modified data with entries
	 */
	public function add_entries_data( $data, $view_id, $view_settings ) {
		// Check if this view is configured to show entries
		if ( ! isset( $view_settings['data_source'] ) || $view_settings['data_source'] !== 'entries' ) {
			return $data;
		}

		$form_id = isset( $view_settings['form_id'] ) ? intval( $view_settings['form_id'] ) : 0;

		if ( ! $form_id ) {
			return $data;
		}

		// Get query parameters
		$search    = isset( $_GET['cf7_search'] ) ? sanitize_text_field( $_GET['cf7_search'] ) : '';
		$date_from = isset( $_GET['cf7_date_from'] ) ? sanitize_text_field( $_GET['cf7_date_from'] ) : '';
		$date_to   = isset( $_GET['cf7_date_to'] ) ? sanitize_text_field( $_GET['cf7_date_to'] ) : '';
		$orderby   = isset( $_GET['cf7_orderby'] ) ? sanitize_text_field( $_GET['cf7_orderby'] ) : 'created_at';
		$order     = isset( $_GET['cf7_order'] ) ? sanitize_text_field( $_GET['cf7_order'] ) : 'desc';
		$page      = isset( $_GET['cf7_page'] ) ? intval( $_GET['cf7_page'] ) : 1;

		// Items per page from view settings or default
		$per_page = isset( $view_settings['per_page'] ) ? intval( $view_settings['per_page'] ) : 10;
		$offset   = ( $page - 1 ) * $per_page;

		$args = array(
			'form_id'   => $form_id,
			'search'    => $search,
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'orderby'   => $orderby,
			'order'     => $order,
			'limit'     => $per_page,
			'offset'    => $offset,
		);

		$entries       = $this->db->get_entries( $args );
		$total_entries = $this->db->get_entries_count( $args );

		// Convert entries to CF7 Views format
		$formatted_entries = array();
		foreach ( $entries as $entry ) {
			$formatted_entry = array(
				'id'              => $entry->id,
				'form_id'         => $entry->form_id,
				'form_title'      => $entry->form_title,
				'created_at'      => $entry->created_at,
				'user_ip'         => $entry->user_ip,
				'entry_date'      => mysql2date( 'F j, Y', $entry->created_at ),
				'entry_time'      => mysql2date( 'g:i A', $entry->created_at ),
				'entry_timestamp' => strtotime( $entry->created_at ),
			);

			// Add form fields as individual data points
			if ( is_array( $entry->entry_data ) ) {
				foreach ( $entry->entry_data as $field_name => $field_value ) {
					$formatted_entry[ $field_name ] = $field_value;
				}
			}

			$formatted_entries[] = $formatted_entry;
		}

		return array(
			'entries'      => $formatted_entries,
			'total'        => $total_entries,
			'per_page'     => $per_page,
			'current_page' => $page,
			'total_pages'  => ceil( $total_entries / $per_page ),
			'form_id'      => $form_id,
		);
	}

	/**
	 * Add entry fields to available fields list
	 *
	 * @param array $fields Current available fields
	 * @param int   $form_id Form ID
	 * @return array Modified fields list
	 */
	public function add_entry_fields( $fields, $form_id ) {
		if ( ! $form_id ) {
			return $fields;
		}

		// Get form fields from a sample entry or form definition
		$sample_entry = $this->db->get_entries(
			array(
				'form_id' => $form_id,
				'limit'   => 1,
			)
		);

		if ( ! empty( $sample_entry ) && is_array( $sample_entry[0]->entry_data ) ) {
			foreach ( $sample_entry[0]->entry_data as $field_name => $field_value ) {
				$fields[ $field_name ] = array(
					'label'  => ucfirst( str_replace( array( '_', '-' ), ' ', $field_name ) ),
					'type'   => $this->guess_field_type( $field_value ),
					'source' => 'entry_data',
				);
			}
		}

		// Add system fields
		$system_fields = array(
			'entry_id'   => array(
				'label'  => __( 'Entry ID', 'cf7-views' ),
				'type'   => 'number',
				'source' => 'entry_meta',
			),
			'entry_date' => array(
				'label'  => __( 'Submission Date', 'cf7-views' ),
				'type'   => 'date',
				'source' => 'entry_meta',
			),
			'entry_time' => array(
				'label'  => __( 'Submission Time', 'cf7-views' ),
				'type'   => 'time',
				'source' => 'entry_meta',
			),
			'user_ip'    => array(
				'label'  => __( 'IP Address', 'cf7-views' ),
				'type'   => 'text',
				'source' => 'entry_meta',
			),
		);

		return array_merge( $fields, $system_fields );
	}

	/**
	 * Guess field type based on value
	 *
	 * @param mixed $value Field value
	 * @return string Field type
	 */
	private function guess_field_type( $value ) {
		if ( is_array( $value ) ) {
			return 'array';
		}

		if ( filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return 'email';
		}

		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return 'url';
		}

		if ( is_numeric( $value ) ) {
			return 'number';
		}

		if ( strlen( $value ) > 100 ) {
			return 'textarea';
		}

		return 'text';
	}

	/**
	 * AJAX handler for searching entries
	 */
	public function ajax_search_entries() {
		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'cf7_views_nonce' ) ) {
			wp_die( 'Security check failed' );
		}

		$form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;
		$search  = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		$filters = isset( $_POST['filters'] ) ? array_map( 'sanitize_text_field', $_POST['filters'] ) : array();

		if ( ! $form_id ) {
			wp_send_json_error( 'Invalid form ID' );
		}

		$args = array(
			'form_id' => $form_id,
			'search'  => $search,
			'limit'   => 20,
		);

		// Add filter conditions
		if ( ! empty( $filters['date_from'] ) ) {
			$args['date_from'] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$args['date_to'] = $filters['date_to'];
		}

		$entries = $this->db->get_entries( $args );
		$total   = $this->db->get_entries_count( $args );

		// Format entries for JSON response
		$formatted_entries = array();
		foreach ( $entries as $entry ) {
			$formatted_entry = array(
				'id'         => $entry->id,
				'created_at' => mysql2date( 'F j, Y g:i A', $entry->created_at ),
				'preview'    => $this->generate_entry_preview( $entry->entry_data ),
			);

			$formatted_entries[] = $formatted_entry;
		}

		wp_send_json_success(
			array(
				'entries' => $formatted_entries,
				'total'   => $total,
			)
		);
	}

	/**
	 * Generate entry preview text
	 *
	 * @param array $entry_data Entry data
	 * @return string Preview text
	 */
	private function generate_entry_preview( $entry_data ) {
		if ( ! is_array( $entry_data ) ) {
			return '';
		}

		$preview_parts = array();
		$count         = 0;

		foreach ( $entry_data as $key => $value ) {
			if ( $count >= 3 ) {
				break;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}

			$value = wp_trim_words( strip_tags( $value ), 5 );

			if ( ! empty( $value ) ) {
				$preview_parts[] = $key . ': ' . $value;
				$count++;
			}
		}

		return implode( ' | ', $preview_parts );
	}

	/**
	 * Get entry statistics for a form
	 *
	 * @param int $form_id Form ID
	 * @return array Statistics
	 */
	public function get_entry_stats( $form_id ) {
		if ( ! $form_id ) {
			return array();
		}

		$total_entries = $this->db->get_entries_count( array( 'form_id' => $form_id ) );

		// Get entries from last 30 days
		$recent_entries = $this->db->get_entries_count(
			array(
				'form_id'   => $form_id,
				'date_from' => date( 'Y-m-d', strtotime( '-30 days' ) ),
			)
		);

		// Get most recent entry
		$latest_entries = $this->db->get_entries(
			array(
				'form_id' => $form_id,
				'limit'   => 1,
				'orderby' => 'created_at',
				'order'   => 'desc',
			)
		);

		$last_submission = ! empty( $latest_entries ) ? $latest_entries[0]->created_at : null;

		return array(
			'total_entries'   => $total_entries,
			'recent_entries'  => $recent_entries,
			'last_submission' => $last_submission,
		);
	}

	/**
	 * Generate search form HTML
	 *
	 * @param int   $form_id Form ID
	 * @param array $args Display arguments
	 * @return string Search form HTML
	 */
	public function generate_search_form( $form_id, $args = array() ) {
		$defaults = array(
			'show_search'        => true,
			'show_date_filter'   => true,
			'show_sorting'       => true,
			'search_placeholder' => __( 'Search entries...', 'cf7-views' ),
			'submit_text'        => __( 'Search', 'cf7-views' ),
		);

		$args = wp_parse_args( $args, $defaults );

		$current_search    = isset( $_GET['cf7_search'] ) ? esc_attr( $_GET['cf7_search'] ) : '';
		$current_date_from = isset( $_GET['cf7_date_from'] ) ? esc_attr( $_GET['cf7_date_from'] ) : '';
		$current_date_to   = isset( $_GET['cf7_date_to'] ) ? esc_attr( $_GET['cf7_date_to'] ) : '';
		$current_orderby   = isset( $_GET['cf7_orderby'] ) ? esc_attr( $_GET['cf7_orderby'] ) : 'created_at';
		$current_order     = isset( $_GET['cf7_order'] ) ? esc_attr( $_GET['cf7_order'] ) : 'desc';

		ob_start();
		?>
		<div class="cf7-views-search-form">
			<form method="get" action="">
				<?php
				// Preserve other query parameters
				foreach ( $_GET as $key => $value ) {
					if ( ! in_array( $key, array( 'cf7_search', 'cf7_date_from', 'cf7_date_to', 'cf7_orderby', 'cf7_order', 'cf7_page' ) ) ) {
						echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
					}
				}
				?>

				<div class="cf7-views-search-fields">
					<?php if ( $args['show_search'] ) : ?>
						<div class="cf7-views-search-field">
							<input type="text" name="cf7_search" value="<?php echo $current_search; ?>"
								   placeholder="<?php echo esc_attr( $args['search_placeholder'] ); ?>">
						</div>
					<?php endif; ?>

					<?php if ( $args['show_date_filter'] ) : ?>
						<div class="cf7-views-date-fields">
							<input type="date" name="cf7_date_from" value="<?php echo $current_date_from; ?>"
								   placeholder="<?php _e( 'From Date', 'cf7-views' ); ?>">
							<input type="date" name="cf7_date_to" value="<?php echo $current_date_to; ?>"
								   placeholder="<?php _e( 'To Date', 'cf7-views' ); ?>">
						</div>
					<?php endif; ?>

					<?php if ( $args['show_sorting'] ) : ?>
						<div class="cf7-views-sorting-fields">
							<select name="cf7_orderby">
								<option value="created_at" <?php selected( $current_orderby, 'created_at' ); ?>><?php _e( 'Date', 'cf7-views' ); ?></option>
								<option value="id" <?php selected( $current_orderby, 'id' ); ?>><?php _e( 'Entry ID', 'cf7-views' ); ?></option>
							</select>
							<select name="cf7_order">
								<option value="desc" <?php selected( $current_order, 'desc' ); ?>><?php _e( 'Newest First', 'cf7-views' ); ?></option>
								<option value="asc" <?php selected( $current_order, 'asc' ); ?>><?php _e( 'Oldest First', 'cf7-views' ); ?></option>
							</select>
						</div>
					<?php endif; ?>

					<div class="cf7-views-search-submit">
						<input type="submit" value="<?php echo esc_attr( $args['submit_text'] ); ?>" class="button">
						<?php if ( $current_search || $current_date_from || $current_date_to ) : ?>
							<a href="<?php echo remove_query_arg( array( 'cf7_search', 'cf7_date_from', 'cf7_date_to', 'cf7_page' ) ); ?>"
							   class="button"><?php _e( 'Clear', 'cf7-views' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>

		<style>
		.cf7-views-search-form {
			margin-bottom: 20px;
			padding: 15px;
			background: #f9f9f9;
			border-radius: 5px;
		}
		.cf7-views-search-fields {
			display: flex;
			gap: 10px;
			align-items: center;
			flex-wrap: wrap;
		}
		.cf7-views-search-field input,
		.cf7-views-date-fields input,
		.cf7-views-sorting-fields select {
			padding: 8px;
			border: 1px solid #ddd;
			border-radius: 3px;
		}
		.cf7-views-date-fields {
			display: flex;
			gap: 5px;
		}
		.cf7-views-sorting-fields {
			display: flex;
			gap: 5px;
		}
		</style>
		<?php

		return ob_get_clean();
	}
}
