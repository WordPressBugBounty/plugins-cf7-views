<?php
/**
 * CF7 Views Entries Admin Page
 *
 * Handles the admin interface for managing Contact Form 7 entries
 *
 * @package CF7_Views
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_Views_Entries_Admin {

	/**
	 * Database handler instance
	 */
	private $db;

	/**
	 * List table instance
	 */
	private $list_table;

	public function __construct() {

		$this->db = new CF7_Views_Entries_DB();

		// Ensure table exists
		$this->db->maybe_create_table();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		// Reorder submenu after all menus are added so "Entries" sits directly after "All CF7 Views"
		// Use a very late priority to ensure other plugins/themes have registered their submenus
		add_action( 'admin_menu', array( $this, 'reorder_entries_submenu' ), 999 );
		add_action( 'admin_init', array( $this, 'handle_single_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Reorder the Entries submenu to appear immediately after "All CF7 Views" submenu
	 */
	public function reorder_entries_submenu() {
		global $submenu;

		$parent = 'edit.php?post_type=cf7-views';
		$slug   = 'cf7-views-entries';

		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		// Find the menu item and remove it
		$found = null;
		foreach ( $submenu[ $parent ] as $index => $item ) {
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				$found = $submenu[ $parent ][ $index ];
				unset( $submenu[ $parent ][ $index ] );
				break;
			}
		}

		// If found, insert it immediately after the "All CF7 Views" submenu item if we can find that index.
		if ( $found ) {
			$items = array_values( $submenu[ $parent ] );

			// Find index of the "All CF7 Views" submenu (link back to edit.php?post_type=cf7-views)
			$insert_at = 1; // default
			foreach ( $items as $i => $it ) {
				if ( isset( $it[2] ) && $it[2] === 'edit.php?post_type=cf7-views' ) {
					$insert_at = $i + 1;
					break;
				}
			}

			array_splice( $items, $insert_at, 0, array( $found ) );
			$submenu[ $parent ] = $items;
		}
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=cf7-views',
			__( 'Form Entries (Beta)', 'cf7-views' ),
			__( 'Entries (Beta)', 'cf7-views' ),
			'read',
			'cf7-views-entries',
			array( $this, 'display_admin_page' )
		);
	}

	/**
	 * Handle single entry actions (view, delete)
	 */
	public function handle_single_actions() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'cf7-views-entries' ) {
			return;
		}

		// Check user capabilities - use edit_posts for delete actions
		$action       = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
		$required_cap = ( $action === 'delete' ) ? 'edit_posts' : 'read';

		if ( ! current_user_can( $required_cap ) ) {
			return;
		}

		$entry_id = isset( $_GET['entry_id'] ) ? intval( $_GET['entry_id'] ) : 0;

		if ( ! $action || ! $entry_id ) {
			return;
		}

		switch ( $action ) {
			case 'delete':
				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_entry_' . $entry_id ) ) {
					wp_die( __( 'Security check failed', 'cf7-views' ) );
				}

				if ( $this->db->delete_entry( $entry_id ) ) {
					add_action(
						'admin_notices',
						function() {
							echo '<div class="notice notice-success is-dismissible">';
							echo '<p>' . __( 'Entry deleted successfully.', 'cf7-views' ) . '</p>';
							echo '</div>';
						}
					);
				} else {
					add_action(
						'admin_notices',
						function() {
							echo '<div class="notice notice-error is-dismissible">';
							echo '<p>' . __( 'Failed to delete entry.', 'cf7-views' ) . '</p>';
							echo '</div>';
						}
					);
				}

				// Redirect to avoid resubmission
				wp_redirect( admin_url( 'edit.php?post_type=cf7-views&page=cf7-views-entries' ) );
				exit;
				break;

			case 'export':
				// Export single entry as CSV
				$this->export_single_entry_csv( $entry_id );
				break;
		}
	}

	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( $hook !== 'cf7-views_page_cf7-views-entries' ) {
			return;
		}

		$tailwind_css = CF7_VIEWS_DIR_URL . '/assets/css/cf7-views-tailwind.css';
		$tailwind_url = CF7_VIEWS_URL . '/assets/css/cf7-views-tailwind.css';

		if ( file_exists( $tailwind_css ) ) {
			wp_enqueue_style( 'cf7-views-entries-tailwind', $tailwind_url, array(), filemtime( $tailwind_css ) );
		}

		// Enqueue Tailwind helper JS to add utility classes to WP table markup.
		wp_enqueue_script( 'cf7-views-entries-tailwind-js', CF7_VIEWS_URL . '/assets/js/entries-admin-tailwind.js', array(), '1.0.0', true );

		wp_enqueue_script( 'cf7-views-entries-admin', CF7_VIEWS_URL . '/assets/js/admin.js', array( 'jquery' ), '1.0.0', true );
	}

	/**
	 * Display admin page
	 */
	public function display_admin_page() {
		// Check user capabilities
		if ( ! current_user_can( 'read' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'cf7-views' ) );
		}

		$action   = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
		$entry_id = isset( $_GET['entry_id'] ) ? intval( $_GET['entry_id'] ) : 0;

		if ( $action === 'view' && $entry_id ) {
			$this->display_single_entry( $entry_id );
			return;
		}

		$this->display_entries_list();
	}

	/**
	 * Display entries list
	 */
	private function display_entries_list() {
		// Check if table exists and create if needed
		global $wpdb;
		$table_name   = $wpdb->prefix . 'cf7_views_entries';
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;

		if ( ! $table_exists ) {
			// Try to create the table
			$created = $this->db->maybe_create_table();
			if ( ! $created ) {
				?>
				<div class="wrap">
					<h1 class="wp-heading-inline"><?php _e( 'Contact Form Entries', 'cf7-views' ); ?></h1>
					<div class="notice notice-error">
						<p>
							<strong><?php _e( 'Database Error:', 'cf7-views' ); ?></strong>
							<?php _e( 'The entries table could not be created. Please check your database permissions.', 'cf7-views' ); ?>
						</p>
						<p>
							<a href="<?php echo add_query_arg( 'force_create_table', '1' ); ?>" class="button">
								<?php _e( 'Try Creating Table Again', 'cf7-views' ); ?>
							</a>
						</p>
					</div>
				</div>
				<?php
				return;
			}
		}

		// Handle manual table creation
		if ( isset( $_GET['force_create_table'] ) ) {
			$this->db->force_create_table();
			wp_redirect( admin_url( 'edit.php?post_type=cf7-views&page=cf7-views-entries' ) );
			exit;
		}

		if ( ! $this->list_table ) {
			$this->list_table = new CF7_Views_Entries_List_Table();
		}

		try {
			$this->list_table->prepare_items();
		} catch ( Exception $e ) {
			?>
			<div class="wrap">
				<h1 class="wp-heading-inline"><?php _e( 'Contact Form Entries', 'cf7-views' ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php _e( 'Database Error:', 'cf7-views' ); ?></strong>
						<?php echo esc_html( $e->getMessage() ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		?>
		<div class="wrap cf7-views-admin">
			<h1 class="wp-heading-inline text-2xl font-semibold text-gray-900"><?php _e( 'Contact Form Entries', 'cf7-views' ); ?> <span class="cf7-views-beta-badge">Beta</span></h1>

			<div class="cf7-views-beta-note">
				<p>
					<?php _e( 'Entries is a beta feature. It stores Contact Form 7 submissions in a custom table to let you view, filter, and export submissions directly from this plugin. Please note that this feature is experimental: behaviour, storage schema, and UI may change in future releases.', 'cf7-views' ); ?>
				</p>
				<p>
					<?php _e( 'If you encounter issues or have feedback, please report it via the plugin support channel or open an issue on the project repository.', 'cf7-views' ); ?>
				</p>
			</div>
			<?php
			// Debug info for troubleshooting
			if ( isset( $_GET['debug'] ) && current_user_can( 'edit_posts' ) ) {
				$table_info = $this->db->get_table_info();
				?>
				<div class="notice notice-info">
					<h3><?php _e( 'Debug Information', 'cf7-views' ); ?></h3>
					<p><strong>Table Name:</strong> <?php echo esc_html( $table_info['table_name'] ); ?></p>
					<p><strong>Table Exists:</strong> <?php echo $table_info['exists'] ? 'Yes' : 'No'; ?></p>
					<p><strong>Current Version:</strong> <?php echo esc_html( $table_info['version'] ); ?></p>
					<p><strong>Expected Version:</strong> <?php echo esc_html( $table_info['expected_version'] ); ?></p>
					<?php if ( $table_info['last_error'] ) : ?>
						<p><strong>Last Error:</strong> <?php echo esc_html( $table_info['last_error'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php
			}

			// Display stats
			$total_entries      = $this->db->get_entries_count();
			$forms_with_entries = $this->db->get_forms_with_entries();
			?>

			<div class="cf7-views-stats">
				<p class="font-medium">
					<strong><?php _e( 'Statistics:', 'cf7-views' ); ?></strong>
					<?php printf( __( 'Total Entries: %d', 'cf7-views' ), $total_entries ); ?> |
					<?php printf( __( 'Forms with Entries: %d', 'cf7-views' ), count( $forms_with_entries ) ); ?>
					<?php // Debug Info link intentionally hidden. Use ?debug=1 to view debug panel if needed and you have 'edit_posts' capability. ?>
				</p>
			</div>

			<form method="get" class="bg-white p-6 rounded shadow-sm mb-6">
				<input type="hidden" name="post_type" value="cf7-views">
				<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>">
				<?php
				$this->list_table->search_box( __( 'Search entries', 'cf7-views' ), 'entry' );
				// Capture the table HTML so we can inject a Tailwind-ready class on the table element
				ob_start();
				$this->list_table->display();
				$table_html = ob_get_clean();
				// Add our Tailwind table helper class to the table tag
				$table_html = preg_replace( '/<table(\s+)/i', '<table class="cf7-views-table" $1', $table_html, 1 );
				echo $table_html;
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Display single entry
	 *
	 * @param int $entry_id Entry ID
	 */
	private function display_single_entry( $entry_id ) {
		$entry = $this->db->get_entry( $entry_id );

		if ( ! $entry ) {
			wp_die( __( 'Entry not found.', 'cf7-views' ) );
		}

		?>
		<div class="wrap cf7-views-admin">
			<h1 class="wp-heading-inline text-2xl font-semibold text-gray-900 mb-6">
				<?php printf( __( 'Entry #%d', 'cf7-views' ), $entry->id ); ?> <span class="cf7-views-beta-badge">Beta</span>
				<a href="<?php echo admin_url( 'edit.php?post_type=cf7-views&page=cf7-views-entries' ); ?>" class="cf7-views-button-secondary ml-4">
					<span class="dashicons dashicons-arrow-left-alt2" style="vertical-align: middle; margin-right: 4px;"></span>
					<?php _e( 'Back to Entries', 'cf7-views' ); ?>
				</a>
			</h1>

			<div class="cf7-views-entry-details">
				<div class="grid grid-cols-1 lg:grid-cols-2 gap-12 mb-10">
					<div class="cf7-views-entry-meta bg-gray-50 p-8 rounded-lg border">
						<h3 class="text-lg font-semibold mb-8 text-gray-900 flex items-center">
							<span class="dashicons dashicons-info" style="margin-right: 8px; font-size: 18px;"></span>
							<?php _e( 'Entry Information', 'cf7-views' ); ?>
						</h3>
						<table class="w-full">
							<tbody>
							<tr class="border-b border-gray-200">
								<td class="py-4 pr-6 font-medium text-gray-700 w-1/3"><?php _e( 'Entry ID:', 'cf7-views' ); ?></td>
								<td class="py-4 text-gray-900"><?php echo esc_html( $entry->id ); ?></td>
							</tr>
							<tr class="border-b border-gray-200">
								<td class="py-4 pr-6 font-medium text-gray-700"><?php _e( 'Form:', 'cf7-views' ); ?></td>
								<td class="py-4">
									<a href="<?php echo admin_url( 'admin.php?page=wpcf7&post=' . $entry->form_id . '&action=edit' ); ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
										<span class="dashicons dashicons-edit" style="vertical-align: middle; margin-right: 4px; font-size: 16px;"></span>
										<?php echo esc_html( $entry->form_title ); ?>
									</a>
								</td>
							</tr>
							<tr class="border-b border-gray-200">
								<td class="py-4 pr-6 font-medium text-gray-700"><?php _e( 'Submitted:', 'cf7-views' ); ?></td>
								<td class="py-4">
									<div class="text-gray-900"><?php echo mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at ); ?></div>
									<div class="text-sm text-gray-500"><?php printf( __( '%s ago', 'cf7-views' ), human_time_diff( strtotime( $entry->created_at ) ) ); ?></div>
								</td>
							</tr>
							<tr class="border-b border-gray-200">
								<td class="py-4 pr-6 font-medium text-gray-700"><?php _e( 'IP Address:', 'cf7-views' ); ?></td>
								<td class="py-4 text-gray-900"><?php echo esc_html( $entry->user_ip ); ?></td>
							</tr>
							<?php if ( ! empty( $entry->referer_url ) ) : ?>
							<tr class="border-b border-gray-200">
								<td class="py-4 pr-6 font-medium text-gray-700"><?php _e( 'Referrer:', 'cf7-views' ); ?></td>
								<td class="py-4">
									<a href="<?php echo esc_url( $entry->referer_url ); ?>" target="_blank" class="text-blue-600 hover:text-blue-800 text-sm">
										<span class="dashicons dashicons-external" style="vertical-align: middle; margin-right: 4px; font-size: 14px;"></span>
										<?php echo esc_html( wp_trim_words( $entry->referer_url, 5 ) ); ?>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if ( ! empty( $entry->user_agent ) ) : ?>
							<tr>
								<td class="py-4 pr-6 font-medium text-gray-700"><?php _e( 'User Agent:', 'cf7-views' ); ?></td>
								<td class="py-4 text-sm text-gray-600"><?php echo esc_html( wp_trim_words( $entry->user_agent, 8 ) ); ?></td>
							</tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="cf7-views-entry-actions bg-gray-50 p-8 rounded-lg border">
						<h3 class="text-lg font-semibold mb-8 text-gray-900 flex items-center">
							<span class="dashicons dashicons-admin-tools" style="margin-right: 8px; font-size: 18px;"></span>
							<?php _e( 'Actions', 'cf7-views' ); ?>
						</h3>
						<div class="space-y-6">
							<div>
								<a href="<?php echo admin_url( 'edit.php?post_type=cf7-views&page=cf7-views-entries&action=delete&entry_id=' . $entry->id . '&_wpnonce=' . wp_create_nonce( 'delete_entry_' . $entry->id ) ); ?>"
								   class="cf7-views-button-danger"
								   onclick="return confirm('<?php _e( 'Are you sure you want to delete this entry?', 'cf7-views' ); ?>')">
									<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 6px;"></span>
									<?php _e( 'Delete Entry', 'cf7-views' ); ?>
								</a>
							</div>
							<div>
								<a href="<?php echo admin_url( 'edit.php?post_type=cf7-views&page=cf7-views-entries&action=export&entry_id=' . $entry->id ); ?>"
								   class="cf7-views-button-secondary">
									<span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 6px;"></span>
									<?php _e( 'Export as CSV', 'cf7-views' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>

				<div class="cf7-views-entry-data bg-white p-8 border rounded-lg shadow-sm">
					<h3 class="text-lg font-semibold mb-8 text-gray-900 flex items-center">
						<span class="dashicons dashicons-feedback" style="margin-right: 8px; font-size: 18px;"></span>
						<?php _e( 'Form Data', 'cf7-views' ); ?>
					</h3>

					<?php if ( is_array( $entry->entry_data ) && ! empty( $entry->entry_data ) ) : ?>
						<div class="overflow-x-auto">
							<table class="cf7-views-table min-w-full">
								<thead>
									<tr>
										<th class="w-1/4"><?php _e( 'Field Name', 'cf7-views' ); ?></th>
										<th><?php _e( 'Value', 'cf7-views' ); ?></th>
									</tr>
								</thead>
								<tbody class="divide-y divide-gray-200">
									<?php foreach ( $entry->entry_data as $field_name => $field_value ) : ?>
										<tr>
											<td class="font-medium text-gray-900"><?php echo esc_html( $field_name ); ?></td>
											<td class="text-gray-700">
												<?php
												if ( is_array( $field_value ) ) {
													// Handle file uploads and multi-select fields
													$formatted_values = array();
													foreach ( $field_value as $value ) {
														if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
															// This looks like a file URL
															$filename           = basename( parse_url( $value, PHP_URL_PATH ) );
															$formatted_values[] = sprintf(
																'<a href="%s" target="_blank" class="text-blue-600 hover:text-blue-800">%s</a>',
																esc_url( $value ),
																esc_html( $filename )
															);
														} else {
															$formatted_values[] = esc_html( $value );
														}
													}
													echo implode( '<br>', $formatted_values );
												} else {
													if ( filter_var( $field_value, FILTER_VALIDATE_URL ) ) {
														// This looks like a file URL
														$filename = basename( parse_url( $field_value, PHP_URL_PATH ) );
														echo sprintf(
															'<a href="%s" target="_blank" class="text-blue-600 hover:text-blue-800">%s</a>',
															esc_url( $field_value ),
															esc_html( $filename )
														);
													} else {
														echo nl2br( esc_html( $field_value ) );
													}
												}
												?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else : ?>
						<p class="text-gray-500"><?php _e( 'No form data available.', 'cf7-views' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Export single entry as CSV
	 *
	 * @param int $entry_id Entry ID to export
	 */
	private function export_single_entry_csv( $entry_id ) {
		$entry = $this->db->get_entry( $entry_id );

		if ( ! $entry ) {
			wp_die( __( 'Entry not found.', 'cf7-views' ) );
		}

		// Generate filename
		$filename = sprintf( 'cf7-entry-%d-%s.csv', $entry_id, date( 'Y-m-d-H-i-s' ) );

		// Set headers for download
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Open output stream
		$output = fopen( 'php://output', 'w' );

		// Prepare headers
		$headers = array( 'Field Name', 'Value' );
		fputcsv( $output, $headers );

		// Write basic entry information
		$basic_fields = array(
			'Entry ID'       => $entry->id,
			'Form'           => $entry->form_title,
			'Submitted Date' => $entry->created_at,
			'IP Address'     => $entry->user_ip,
		);

		foreach ( $basic_fields as $field_name => $value ) {
			fputcsv( $output, array( $field_name, $value ) );
		}

		// Write form data
		if ( is_array( $entry->entry_data ) && ! empty( $entry->entry_data ) ) {
			foreach ( $entry->entry_data as $field_name => $field_value ) {
				if ( is_array( $field_value ) ) {
					$value = implode( ', ', $field_value );
				} else {
					$value = (string) $field_value;
				}
				fputcsv( $output, array( $field_name, $value ) );
			}
		}

		fclose( $output );
		exit;
	}
}
