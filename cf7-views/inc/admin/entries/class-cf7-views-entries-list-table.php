<?php
/**
 * CF7 Views Entries List Table
 *
 * Displays Contact Form 7 entries in a WordPress admin table
 *
 * @package CF7_Views
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CF7_Views_Entries_List_Table extends WP_List_Table {

	/**
	 * Database handler instance
	 */
	private $db;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->db = new CF7_Views_Entries_DB();

		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get table columns
	 *
	 * @return array Columns
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'id'            => __( 'ID', 'cf7-views' ),
			'form_title'    => __( 'Form', 'cf7-views' ),
			'entry_preview' => __( 'Entry Data', 'cf7-views' ),
			'user_ip'       => __( 'IP Address', 'cf7-views' ),
			'created_at'    => __( 'Submitted', 'cf7-views' ),
		);
	}

	/**
	 * Get sortable columns
	 *
	 * @return array Sortable columns
	 */
	public function get_sortable_columns() {
		return array(
			'id'         => array( 'id', true ),
			'form_title' => array( 'form_title', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Get bulk actions
	 *
	 * @return array Bulk actions
	 */
	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'cf7-views' ),
			'export' => __( 'Export to CSV', 'cf7-views' ),
		);
	}

	/**
	 * Process bulk actions
	 */
	public function process_bulk_action() {
		$action = $this->current_action();

		if ( ! $action ) {
			return;
		}

		$entry_ids = isset( $_POST['entry'] ) ? array_map( 'intval', $_POST['entry'] ) : array();

		if ( empty( $entry_ids ) ) {
			return;
		}

		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bulk-entries' ) ) {
			wp_die( __( 'Security check failed', 'cf7-views' ) );
		}

		switch ( $action ) {
			case 'delete':
				$deleted_count = 0;
				foreach ( $entry_ids as $entry_id ) {
					if ( $this->db->delete_entry( $entry_id ) ) {
						$deleted_count++;
					}
				}

				if ( $deleted_count > 0 ) {
					add_action(
						'admin_notices',
						function() use ( $deleted_count ) {
							echo '<div class="notice notice-success is-dismissible">';
							echo '<p>' . sprintf( _n( '%d entry deleted.', '%d entries deleted.', $deleted_count, 'cf7-views' ), $deleted_count ) . '</p>';
							echo '</div>';
						}
					);
				}
				break;

			case 'export':
				$this->export_entries_to_csv( $entry_ids );
				break;
		}
	}

	/**
	 * Prepare items for display
	 */
	public function prepare_items() {
		$this->process_bulk_action();

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Get filter values
		$form_id   = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : null;
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

		// Get sorting
		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'desc';

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

		$this->items = $this->db->get_entries( $args );
		$total_items = $this->db->get_entries_count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Display checkbox column
	 *
	 * @param object $item Row item
	 * @return string Checkbox HTML
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="entry[]" value="%d" />', $item->id );
	}

	/**
	 * Display ID column
	 *
	 * @param object $item Row item
	 * @return string ID with row actions
	 */
	public function column_id( $item ) {
		$actions = array(
			'view'   => sprintf(
				'<a href="?post_type=cf7-views&page=%s&action=view&entry_id=%d"><span class="dashicons dashicons-visibility" style="font-size: 14px; vertical-align: middle; margin-right: 3px;"></span>%s</a>',
				$_REQUEST['page'],
				$item->id,
				__( 'View', 'cf7-views' )
			),
			'delete' => sprintf(
				'<a href="?post_type=cf7-views&page=%s&action=delete&entry_id=%d&_wpnonce=%s" onclick="return confirm(\'%s\')"><span class="dashicons dashicons-trash" style="font-size: 14px; vertical-align: middle; margin-right: 3px; color: #d63638;"></span>%s</a>',
				$_REQUEST['page'],
				$item->id,
				wp_create_nonce( 'delete_entry_' . $item->id ),
				__( 'Are you sure you want to delete this entry?', 'cf7-views' ),
				__( 'Delete', 'cf7-views' )
			),
		);

		return sprintf( '%d %s', $item->id, $this->row_actions( $actions ) );
	}

	/**
	 * Display form title column
	 *
	 * @param object $item Row item
	 * @return string Form title
	 */
	public function column_form_title( $item ) {
		$edit_url = admin_url( 'admin.php?page=wpcf7&post=' . $item->form_id . '&action=edit' );
		return sprintf( '<a href="%s" target="_blank"><span class="dashicons dashicons-edit" style="font-size: 14px; vertical-align: middle; margin-right: 4px;"></span>%s</a>', $edit_url, esc_html( $item->form_title ) );
	}

	/**
	 * Display entry preview column
	 *
	 * @param object $item Row item
	 * @return string Entry data preview
	 */
	public function column_entry_preview( $item ) {
		$entry_data    = is_array( $item->entry_data ) ? $item->entry_data : array();
		$preview_items = array();
		$count         = 0;

		foreach ( $entry_data as $key => $value ) {
			if ( $count >= 3 ) {
				break; // Show only first 3 fields
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value ) );
			}

			$value = wp_trim_words( strip_tags( $value ), 10 );

			if ( ! empty( $value ) ) {
				$preview_items[] = sprintf( '<strong>%s:</strong> %s', esc_html( $key ), esc_html( $value ) );
				$count++;
			}
		}

		$preview = implode( '<br>', $preview_items );

		if ( count( $entry_data ) > 3 ) {
			$preview .= '<br><em>' . sprintf( __( '... and %d more fields', 'cf7-views' ), count( $entry_data ) - 3 ) . '</em>';
		}

		return $preview;
	}

	/**
	 * Display user IP column
	 *
	 * @param object $item Row item
	 * @return string IP address
	 */
	public function column_user_ip( $item ) {
		return esc_html( $item->user_ip );
	}

	/**
	 * Display created at column
	 *
	 * @param object $item Row item
	 * @return string Formatted date
	 */
	public function column_created_at( $item ) {
		$date     = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->created_at );
		$time_ago = sprintf( __( '%s ago', 'cf7-views' ), human_time_diff( strtotime( $item->created_at ) ) );

		return sprintf( '<span title="%s">%s</span>', $time_ago, $date );
	}

	/**
	 * Default column display
	 *
	 * @param object $item Row item
	 * @param string $column_name Column name
	 * @return string Column content
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Display filters above the table
	 */
	public function extra_tablenav( $which ) {
		if ( $which !== 'top' ) {
			return;
		}

		$form_id   = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

		// Try to list all Contact Form 7 forms (so the dropdown shows every form even if it has no entries).
		$cf7_forms = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		if ( ! empty( $cf7_forms ) ) {
			$forms = array();
			foreach ( $cf7_forms as $f ) {
				$forms[] = (object) array(
					'form_id'    => $f->ID,
					'form_title' => $f->post_title,
				);
			}
		} else {
			// Fallback to forms that have entries (legacy behavior / when CF7 is inactive)
			$forms = $this->db->get_forms_with_entries();
		}
		?>
		<div class="alignleft actions">
			<input type="hidden" name="post_type" value="cf7-views">
			<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>">

			<select name="form_id" class="cf7-views-select">
				<option value=""><?php _e( 'All Forms', 'cf7-views' ); ?></option>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( $form->form_id ); ?>" <?php selected( $form_id, $form->form_id ); ?>>
						<?php echo esc_html( $form->form_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="<?php _e( 'From Date', 'cf7-views' ); ?>" class="cf7-views-input">
			<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="<?php _e( 'To Date', 'cf7-views' ); ?>" class="cf7-views-input">

			<input type="submit" name="filter_action" class="cf7-views-button" value="<?php _e( 'Filter', 'cf7-views' ); ?>">

			<?php if ( $form_id || $date_from || $date_to ) : ?>
				<a href="<?php echo admin_url( 'edit.php?post_type=cf7-views&page=' . $_REQUEST['page'] ); ?>" class="cf7-views-button-secondary">
					<span class="dashicons dashicons-dismiss" style="vertical-align: middle; margin-right: 4px; font-size: 14px;"></span>
					<?php _e( 'Clear Filters', 'cf7-views' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Export entries to CSV
	 *
	 * @param array $entry_ids Entry IDs to export
	 */
	private function export_entries_to_csv( $entry_ids ) {
		if ( empty( $entry_ids ) ) {
			return;
		}

		$entries = array();
		foreach ( $entry_ids as $entry_id ) {
			$entry = $this->db->get_entry( $entry_id );
			if ( $entry ) {
				$entries[] = $entry;
			}
		}

		if ( empty( $entries ) ) {
			return;
		}

		// Generate filename
		$filename = 'cf7-entries-' . date( 'Y-m-d-H-i-s' ) . '.csv';

		// Set headers for download
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Open output stream
		$output = fopen( 'php://output', 'w' );

		// Get all unique field names
		$all_fields  = array( 'ID', 'Form', 'Submitted Date', 'IP Address' );
		$field_names = array();

		foreach ( $entries as $entry ) {
			if ( is_array( $entry->entry_data ) ) {
				$field_names = array_merge( $field_names, array_keys( $entry->entry_data ) );
			}
		}

		$field_names = array_unique( $field_names );
		$headers     = array_merge( $all_fields, $field_names );

		// Write CSV headers
		fputcsv( $output, $headers );

		// Write data rows
		foreach ( $entries as $entry ) {
			$row = array(
				$entry->id,
				$entry->form_title,
				$entry->created_at,
				$entry->user_ip,
			);

			// Add field data
			foreach ( $field_names as $field_name ) {
				$value = '';
				if ( is_array( $entry->entry_data ) && isset( $entry->entry_data[ $field_name ] ) ) {
					$field_value = $entry->entry_data[ $field_name ];
					if ( is_array( $field_value ) ) {
						$value = implode( ', ', $field_value );
					} else {
						$value = (string) $field_value;
					}
				}
				$row[] = $value;
			}

			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}
}
