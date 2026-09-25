<?php

function cf7_views_get_form_fields( $slug ) {
	if ( empty( $slug ) ) {
		return '{}';
	}
	$form_fields_obj = new stdClass();
	$post            = cf7_views_get_post_by_slug( $slug );

	if ( empty( $post ) || ! is_object( $post ) ) {
		return '{}';
	}
	$form_id      = $post->ID;
	$contact_form = WPCF7_ContactForm::get_instance( $form_id );

	if ( $contact_form ) {
		$tags            = $contact_form->scan_form_tags();
		$form_fields_obj = new stdClass();
		foreach ( $tags as $tag ) {
			if ( $tag->type != 'submit' ) {
				$form_fields_obj->{$tag->name} = (object) array(
					'id'        => $tag->name,
					'label'     => $tag->name,
					'fieldType' => $tag->basetype,
				);
			}
		}
		return json_encode( $form_fields_obj );

	}

}


/**
 * Get submissions based on specific critera.
 *
 * @since 2.7
 * @param array $args
 * @return array $sub_ids
 */
function cf7_views_get_submissions_data( $args ) {

		// Sort
	if ( isset( $args['sort_order'] ) ) {
		$orderby = array();
		foreach ( $args['sort_order'] as $sortfield ) {
			if ( $sortfield['field'] === 'submission_id' || $sortfield['field'] === 'entryId' ) {
				$orderby['ID'] = $sortfield['value'];

			} elseif ( $sortfield['field'] === 'entryDate' ) {
				$orderby['date'] = $sortfield['value'];
			} else {
				$args['meta_query'][ '_field_' . $sortfield['field'] . '_clause' ] = array(
					'key' => '_field_' . $sortfield['field'],
				);
				$orderby[ '_field_' . $sortfield['field'] . '_clause' ]                  = $sortfield['value'];
			}
		}
		if ( ! empty( $orderby ) ) {
			$args['orderby'] = $orderby;
		}
	}
	if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
		$submissions = Flamingo_Inbound_Message::find( $args );
		return array(
			'submissions' => $submissions,
			'count'       => Flamingo_Inbound_Message::count( $args ),
		);
	} else {
		return array(
			'submissions' => '',
			'count'       => 0,
		);
	}

}

function cf7_views_get_post_by_slug( $slug ) {
	$posts = get_posts(
		array(
			'name'           => $slug,
			'posts_per_page' => 1,
			'post_type'      => 'wpcf7_contact_form',
			'post_status'    => 'publish',
		)
	);

	if ( ! $posts ) {
		return '';
	}

	return $posts[0];
}

/**
 * Encode a PHP string as a JavaScript string literal (including quotes) that
 * is safe to print inside an inline <script> block.
 */
function cf7_views_js_string( $value ) {
	$encoded = wp_json_encode( (string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	return false === $encoded ? '""' : $encoded;
}

/**
 * Mark view_settings as protected so it cannot be written through generic
 * custom-field paths (XML-RPC custom_fields, the Custom Fields UI, etc.).
 */
function cf7_views_protect_meta( $protected, $meta_key ) {
	return 'view_settings' === $meta_key ? true : $protected;
}
add_filter( 'is_protected_meta', 'cf7_views_protect_meta', 10, 2 );
