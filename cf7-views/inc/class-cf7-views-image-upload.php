<?php
function cf7_views_lite_create_attachment( $filename ) {
	// Check the type of file. We'll use this as the 'post_mime_type'.
	$filetype = wp_check_filetype( basename( $filename ), null );

	// Get the path and URL to the upload directory.
	$wp_upload_dir   = wp_upload_dir();
	$upload_dir_path = trailingslashit( $wp_upload_dir['path'] );
	$upload_dir_url  = trailingslashit( $wp_upload_dir['url'] );

	// Extract the original base name and extension.
	$original_basename = pathinfo( $filename, PATHINFO_FILENAME );
	$extension         = pathinfo( $filename, PATHINFO_EXTENSION );

	// Set up the initial target file name.
	$target_filename = $original_basename . '.' . $extension;
	$target_filepath = $upload_dir_path . $target_filename;

	// Loop to modify the filename if it already exists.
	$counter = 1;
	while ( file_exists( $target_filepath ) ) {
		$target_filename = $original_basename . '-' . $counter . '.' . $extension;
		$target_filepath = $upload_dir_path . $target_filename;
		$counter++;
	}

	// Copy the file to the determined target file path.
	copy( $filename, $target_filepath );

	// Build the attachment file URL.
	$attachFileLink = $upload_dir_url . $target_filename;

	// Allow filtering of the final file path (if needed).
	$target_filepath = apply_filters( 'cf7_views_create_attachment_file_name', $target_filepath );

	// Prepare an array of post data for the attachment.
	$attachment = array(
		'guid'           => $attachFileLink,
		'post_mime_type' => $filetype['type'],
		'post_title'     => preg_replace( '/\.[^.]+$/', '', $target_filename ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	// Allow modifications to the attachment data.
	$attachment = apply_filters( 'cf7_views_before_insert_attachment', $attachment );

	// Insert the attachment into WordPress.
	$attach_id = wp_insert_attachment( $attachment, $target_filepath );

	do_action( 'cf7_views_create_attachment_id_generated', $attach_id );

	// Return the attachment data.
	$attach_data = array(
		'id'   => $attach_id,
		'path' => $attachFileLink,
	);
	return $attach_data;
}

function cf7_views_lite_save_attachment( $result ) {
	$submission = WPCF7_Submission::get_instance();

	if ( $submission ) {
		$uploaded_files = $submission->uploaded_files();
		if ( $uploaded_files ) {

			foreach ( $uploaded_files as $fieldName => $filepath ) {
				$data = array();
				if ( is_array( $filepath ) ) {
					foreach ( $filepath as $key => $value ) {
						$data[] = cf7_views_lite_create_attachment( $value );
					}
				} else {
					$data = cf7_views_lite_create_attachment( $filepath );
				}
				//error_log( '_cf7_attachment_' . $fieldName );
				update_post_meta( $result['flamingo_inbound_id'], '_cf7_attachment_' . $fieldName, $data );

				// error_log( print_r( $data, true ) );
			}
		}
	}
}
// intercept contact form 7 before email send
add_action( 'wpcf7_after_flamingo', 'cf7_views_lite_save_attachment' );
