<?php
/**
 * CF7 Views Entry Capture
 *
 * Captures Contact Form 7 submissions and stores them in the database
 *
 * @package CF7_Views
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_Views_Entry_Capture {

	/**
	 * Database handler instance
	 */
	private $db;

	/**
	 * Captured forms to prevent duplicates
	 */
	private $captured_forms = array();

	public function __construct() {
		$this->db = new CF7_Views_Entries_DB();

		// Hook into Contact Form 7 submission
		add_action( 'wpcf7_before_send_mail', array( $this, 'capture_entry' ), 10, 3 );

		// Also hook after mail is sent as backup
		add_action( 'wpcf7_mail_sent', array( $this, 'capture_entry_backup' ), 10, 1 );
	}

	/**
	 * Capture form entry before mail is sent
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object
	 * @param bool              $abort Whether to abort the mail sending
	 * @param WPCF7_Submission  $submission The submission object
	 */
	public function capture_entry( $contact_form, &$abort, $submission ) {
		// Skip if this is not a valid submission
		if ( ! $submission || ! $contact_form ) {
			return;
		}

		// Skip if this entry was already captured
		$form_hash = $contact_form->id() . '_' . $submission->get_posted_data_hash();
		if ( isset( $this->captured_forms[ $form_hash ] ) ) {
			return;
		}

		$this->save_entry( $contact_form, $submission );
		$this->mark_as_captured( $contact_form, $submission );
	}

	/**
	 * Backup capture method after mail is sent
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object
	 */
	public function capture_entry_backup( $contact_form ) {
		$submission = WPCF7_Submission::get_instance();

		if ( ! $submission || $this->is_captured( $contact_form, $submission ) ) {
			return;
		}

		$this->save_entry( $contact_form, $submission );
		$this->mark_as_captured( $contact_form, $submission );
	}

	/**
	 * Save the entry to database
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object
	 * @param WPCF7_Submission  $submission The submission object
	 */
	private function save_entry( $contact_form, $submission ) {
		$posted_data = $submission->get_posted_data();

		// Skip if no data
		if ( empty( $posted_data ) ) {
			return;
		}

		// Remove WordPress nonce and other system fields
		$system_fields = array( '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', '_wpnonce' );
		foreach ( $system_fields as $field ) {
			unset( $posted_data[ $field ] );
		}

		// Process file uploads
		$uploaded_files = $submission->uploaded_files();
		if ( ! empty( $uploaded_files ) ) {
			foreach ( $uploaded_files as $field_name => $files ) {
				if ( ! empty( $files ) ) {
					$file_urls = array();
					foreach ( $files as $file ) {
						// Move file to permanent location
						$file_url = $this->handle_file_upload( $file, $contact_form->id() );
						if ( $file_url ) {
							$file_urls[] = $file_url;
						}
					}
					$posted_data[ $field_name ] = $file_urls;
				}
			}
		}

		// Prepare entry data
		$entry_data = array(
			'form_id'    => $contact_form->id(),
			'form_title' => $contact_form->title(),
			'entry_data' => $posted_data,
		);

		// Save to database
		$entry_id = $this->db->insert_entry( $entry_data );

		if ( $entry_id ) {
			// Allow other plugins to hook into this
			do_action( 'cf7_views_entry_saved', $entry_id, $posted_data, $contact_form, $submission );
		}
	}

	/**
	 * Handle file upload and move to permanent location
	 *
	 * @param string $file_path Temporary file path
	 * @param int    $form_id Form ID
	 * @return string|false Permanent file URL or false on failure
	 */
	private function handle_file_upload( $file_path, $form_id ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Create upload directory
		$upload_dir    = wp_upload_dir();
		$cf7_views_dir = $upload_dir['basedir'] . '/cf7-views-entries';
		$cf7_views_url = $upload_dir['baseurl'] . '/cf7-views-entries';

		if ( ! file_exists( $cf7_views_dir ) ) {
			wp_mkdir_p( $cf7_views_dir );

			// Add .htaccess for security
			$htaccess_content = "Options -Indexes\n";
			file_put_contents( $cf7_views_dir . '/.htaccess', $htaccess_content );

			// Add index.php for security
			file_put_contents( $cf7_views_dir . '/index.php', '<?php // Silence is golden' );
		}

		// Create form-specific directory
		$form_dir = $cf7_views_dir . '/form-' . $form_id;
		$form_url = $cf7_views_url . '/form-' . $form_id;

		if ( ! file_exists( $form_dir ) ) {
			wp_mkdir_p( $form_dir );
		}

		// Generate unique filename
		$file_info       = pathinfo( $file_path );
		$filename        = sanitize_file_name( $file_info['basename'] );
		$unique_filename = wp_unique_filename( $form_dir, $filename );
		$new_file_path   = $form_dir . '/' . $unique_filename;

		// Move file
		if ( copy( $file_path, $new_file_path ) ) {
			// Set proper permissions
			chmod( $new_file_path, 0644 );
			return $form_url . '/' . $unique_filename;
		}

		return false;
	}

	/**
	 * Get form fields for a specific form
	 *
	 * @param int $form_id Form ID
	 * @return array Array of field names and labels
	 */
	public function get_form_fields( $form_id ) {
		$contact_form = wpcf7_contact_form( $form_id );

		if ( ! $contact_form ) {
			return array();
		}

		$form_fields  = array();
		$form_content = $contact_form->prop( 'form' );

		// Parse form content for field tags
		if ( preg_match_all( '/\[([a-zA-Z][0-9a-zA-Z:._-]*)\s+([^\]]*)\]/', $form_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag_type    = $match[1];
				$tag_content = $match[2];

				// Extract field name
				if ( preg_match( '/^([a-zA-Z][0-9a-zA-Z:._-]*)/', $tag_content, $name_match ) ) {
					$field_name = $name_match[1];

					// Skip system fields
					if ( in_array( $field_name, array( 'submit', 'reset' ) ) ) {
						continue;
					}

					// Extract label from form content (look for label tag or text before field)
					$label = $this->extract_field_label( $form_content, $match[0], $field_name );

					$form_fields[ $field_name ] = array(
						'name'  => $field_name,
						'type'  => $tag_type,
						'label' => $label,
					);
				}
			}
		}

		return $form_fields;
	}

	/**
	 * Extract field label from form content
	 *
	 * @param string $form_content Full form content
	 * @param string $field_tag The field tag
	 * @param string $field_name Field name
	 * @return string Field label
	 */
	private function extract_field_label( $form_content, $field_tag, $field_name ) {
		// Look for label tag
		$pattern = '/<label[^>]*for=["\']' . preg_quote( $field_name, '/' ) . '["\'][^>]*>(.*?)<\/label>/is';
		if ( preg_match( $pattern, $form_content, $matches ) ) {
			return strip_tags( trim( $matches[1] ) );
		}

		// Look for text before the field tag
		$field_pos = strpos( $form_content, $field_tag );
		if ( $field_pos !== false ) {
			$before_text = substr( $form_content, max( 0, $field_pos - 200 ), 200 );

			// Remove HTML tags and get the last meaningful text
			$before_text = strip_tags( $before_text );
			$lines       = explode( "\n", $before_text );
			$last_line   = trim( end( $lines ) );

			if ( ! empty( $last_line ) && strlen( $last_line ) < 100 ) {
				return $last_line;
			}
		}

		// Fallback to field name
		return ucfirst( str_replace( array( '_', '-' ), ' ', $field_name ) );
	}

	/**
	 * Clean up old entries (can be called by cron)
	 *
	 * @param int $days_old Delete entries older than this many days
	 * @return int Number of entries deleted
	 */
	public function cleanup_old_entries( $days_old = 365 ) {
		global $wpdb;

		$table_name     = $this->db->get_table_name();
		$date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < %s AND status = 'deleted'",
				$date_threshold
			)
		);

		return $deleted ?: 0;
	}

	/**
	 * Check if an entry was already captured
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object
	 * @param WPCF7_Submission  $submission The submission object
	 * @return bool True if already captured
	 */
	private function is_captured( $contact_form, $submission ) {
		$form_hash = $contact_form->id() . '_' . $submission->get_posted_data_hash();
		return isset( $this->captured_forms[ $form_hash ] );
	}

	/**
	 * Mark entry as captured
	 *
	 * @param WPCF7_ContactForm $contact_form The contact form object
	 * @param WPCF7_Submission  $submission The submission object
	 */
	private function mark_as_captured( $contact_form, $submission ) {
		$form_hash                          = $contact_form->id() . '_' . $submission->get_posted_data_hash();
		$this->captured_forms[ $form_hash ] = true;
	}
}
