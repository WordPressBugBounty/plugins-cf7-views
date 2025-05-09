<?php
class CF7_Views_Shortcode {
	public $view_id;
	public $submissions_count;
	public $table_heading_added;
	private $seq_no         = 1;
	public $repeater_fields = array();
	public $form_fields     = array();
	function __construct() {

		add_shortcode( 'cf7-views', array( $this, 'shortcode' ), 10 );

	}

	public function shortcode( $atts ) {
		$this->seq_no = 1;
		$atts         = shortcode_atts(
			array(
				'id' => '',
			),
			$atts
		);

		if ( empty( $atts['id'] ) ) {
			return;
		}
		$view_id                   = $atts['id'];
		$this->view_id             = $view_id;
		$this->table_heading_added = false;
		$view_settings_json        = get_post_meta( $view_id, 'view_settings', true );
		if ( empty( $view_settings_json ) ) {
			return;
		}

		$view_settings = json_decode( $view_settings_json );
		$view_type     = $view_settings->viewType;
		$method_name   = 'get_view';
		$view          = $this->$method_name( $view_settings );
		return $view;

	}

	function get_view( $view_settings ) {
		global $wpdb;
		$view_type        = $view_settings->viewType;
		$before_loop_rows = $view_settings->sections->beforeloop->rows;
		$loop_rows        = $view_settings->sections->loop->rows;
		$after_loop_rows  = $view_settings->sections->afterloop->rows;

		$this->submissions_count = 0;
			/*  Set repeater fields */
		$this->repeater_fields = $this->set_repeater_fields( $view_settings->formId );
		$this->form_fields     = $this->get_form_fields( $view_settings->formId );
		$per_page              = $view_settings->viewSettings->multipleentries->perPage;
		// $sort_order            = isset( $view_settings->viewSettings->sort->direction ) ? $view_settings->viewSettings->sort->direction : 'ASC';
		$sort_order = $view_settings->viewSettings->sort;
		$args       = array(
			'channel'        => $view_settings->formId,
			'posts_per_page' => $per_page,
			'order'          => $sort_order,
		);

		if ( ! empty( $_GET['pagenum'] ) ) {
			$page_no        = sanitize_text_field( $_GET['pagenum'] );
			$offset         = $per_page * ( $page_no - 1 );
			$args['offset'] = $offset;
			$this->seq_no   = $offset + 1;
		}

			// OrderBy Params
		if ( ! empty( $sort_order ) ) {
			foreach ( $sort_order as $sortrrow ) {
				if ( isset( $sortrrow->field ) ) {
					$args['sort_order'][] = array(
						'field' => $sortrrow->field,
						'value' => $sortrrow->value,
					);
				}
			}
		}

		$submissions_data        = cf7_views_get_submissions_data( $args );
		$submissions             = $submissions_data['submissions'];
		$this->submissions_count = $submissions_data['count'];
		if ( empty( $submissions ) ) {
			return '<div class="views-no-records-cnt">' . __( 'No records found.', 'cf7-views' ) . '</div>';
		}

		$view_content = '';
		if ( ! empty( $before_loop_rows ) ) {
			$view_content .= $this->get_sections_content( 'beforeloop', $view_settings, $submissions );
		}

		if ( ! empty( $loop_rows ) ) {
			if ( $view_type == 'table' ) {
				$view_content .= $this->get_table_content( 'loop', $view_settings, $submissions );
			} else {
				$view_content .= $this->get_sections_content( 'loop', $view_settings, $submissions );
			}
		}

		if ( ! empty( $after_loop_rows ) ) {
			$view_content .= $this->get_sections_content( 'afterloop', $view_settings, $submissions );
		}
		return $view_content;

	}


	function get_sections_content( $section_type, $view_settings, $submissions ) {
		$content      = '';
		$section_rows = $view_settings->sections->{$section_type}->rows;
		if ( $section_type == 'loop' ) {
			foreach ( $submissions as $sub ) {
				foreach ( $section_rows as $row_id ) {
					// $content .= $this->get_table_content( $row_id, $view_settings, $sub );
					$content .= $this->get_grid_row_html( $row_id, $view_settings, $sub );
					$this->seq_no++;
				}
			}
		} else {
			foreach ( $section_rows as $row_id ) {
				$content .= $this->get_grid_row_html( $row_id, $view_settings );
			}
		}
		return $content;
	}



	function get_table_content( $section_type, $view_settings, $submissions ) {
		$content      = '';
		$section_rows = $view_settings->sections->{$section_type}->rows;
		$content      = ' <div class="cf7-views-cont cf7-views-' . $this->view_id . '-cont"> <table class="cf7-views-table cf7-view-' . $this->view_id . '-table pure-table pure-table-bordered">';
		$content     .= '<thead>';
		foreach ( $submissions as $sub ) {
			$content .= '<tr>';
			foreach ( $section_rows as $row_id ) {
				$content .= $this->get_table_row_html( $row_id, $view_settings, $sub );
				$this->seq_no++;
			}
			$content .= '</tr>';
		}
		$content .= '</tbody></table></div>';

		return $content;
	}

	function get_table_row_html( $row_id, $view_settings, $sub = false ) {
		$row_content = '';
		$row_columns = $view_settings->rows->{$row_id}->cols;
		foreach ( $row_columns as $column_id ) {
			$row_content .= $this->get_table_column_html( $column_id, $view_settings, $sub );
		}
		// $row_content .= '</table>'; // row ends
		return $row_content;
	}

	function get_table_column_html( $column_id, $view_settings, $sub ) {
		$column_size   = $view_settings->columns->{$column_id}->size;
		$column_fields = $view_settings->columns->{$column_id}->fields;

		$column_content = '';

		if ( ! ( $this->table_heading_added ) ) {

			foreach ( $column_fields as $field_id ) {
				$column_content .= $this->get_table_headers( $field_id, $view_settings, $sub );
			}
			$this->table_heading_added = true;
			$column_content           .= '</tr></thead><tbody><tr>';
		}
		foreach ( $column_fields as $field_id ) {

			$column_content .= $this->get_field_html( $field_id, $view_settings, $sub );
		}

		return $column_content;
	}



	function get_grid_row_html( $row_id, $view_settings, $sub = false ) {
		$row_columns = $view_settings->rows->{$row_id}->cols;

		$row_content = '<div class="pure-g">';
		foreach ( $row_columns as $column_id ) {
			$row_content .= $this->get_grid_column_html( $column_id, $view_settings, $sub );
		}
		$row_content .= '</div>'; // row ends
		return $row_content;
	}

	function get_grid_column_html( $column_id, $view_settings, $sub ) {
		$column_size   = $view_settings->columns->{$column_id}->size;
		$column_fields = $view_settings->columns->{$column_id}->fields;

		$column_content = '<div class="pure-u-1 pure-u-md-' . $column_size . '">';

		foreach ( $column_fields as $field_id ) {

			$column_content .= $this->get_field_html( $field_id, $view_settings, $sub );

		}
		$column_content .= '</div>'; // column ends
		return $column_content;
	}

	function get_field_html( $field_id, $view_settings, $sub ) {
		$field         = $view_settings->fields->{$field_id};
		$form_field_id = $field->formFieldId;
		$fieldSettings = $field->fieldSettings;
		$label         = $fieldSettings->useCustomLabel ? $fieldSettings->label : $field->label;
		$class         = $fieldSettings->customClass;
		$view_type     = $view_settings->viewType;
		$field_html    = '';
		if ( $view_type == 'table' ) {
			$width       = ! empty( $field->fieldSettings->columnWidth ) ? $field->fieldSettings->columnWidth : 'auto';
			$field_html .= '<td  style="width:' . $width . '">';
		}

		$field_html .= '<div  class="cf7-view-field-cont  field-' . $form_field_id . ' ' . $class . '">';

		// check if it's a form field
		if ( ! empty( $sub ) && is_object( $sub ) && ( $form_field_id !== 'entryId' && $form_field_id !== 'sequenceNumber' ) ) {
			// if view type is table then don't send label
			if ( ! empty( $label && $view_type != 'table' ) ) {
				$field_html .= '<div class="field-label">' . $label . '</div>';
			}
			$form_field_type = isset( $this->form_fields[ $form_field_id ] ) ? $this->form_fields[ $form_field_id ]['type'] : $form_field_id;
			$field_value     = $this->get_field_value( $form_field_id, $sub );
			$field_value     = $this->get_repeaters_value( $field_value, $form_field_id, $sub );
			if ( is_array( $field_value ) ) {
				$field_value = implode( ',', $field_value );
			}

			if ( $form_field_type == 'file' ) {
				$value = get_post_meta( $sub->id(), '_cf7_attachment_' . $form_field_id, true );

				if ( ! empty( $value ) ) {
					$img_html = '';
					foreach ( $value as $file ) {
						if ( isset( $fieldSettings->displayFileType ) && $fieldSettings->displayFileType == 'Image' ) {
							$width    = ! empty( $fieldSettings->imageWidth ) ? $fieldSettings->imageWidth : '100%';
							$img_html = '<img style="width:' . $width . '" class="cf7-view-img" src="' . wp_strip_all_tags( $file['path'] ) . '">';

							if ( isset( $fieldSettings->onClickAction ) && $fieldSettings->onClickAction == 'newTab' ) {
								$img_html = sprintf(
									'<a href="%s" rel="noopener" target="_blank">%s</a>',
									esc_url( $file['path'] ),
									$img_html
								);
							}
						} else {
							$img_html = sprintf(
								'<a href="%s" rel="noopener" target="_blank">%s</a>',
								esc_url( $file['path'] ),
								basename( $file['path'] )
							);
						}
					}
					$field_value = $img_html;
				}
			}

			$field_value = apply_filters( 'cf7views-field-value', $field_value, $field, $view_settings, $sub );
			$field_html .= $field_value;
		} else {

			switch ( $form_field_id ) {
				case 'pagination':
					$field_html .= $this->get_pagination_links( $view_settings, $sub );
					break;
				case 'paginationInfo':
					$field_html .= $this->get_pagination_info( $view_settings, $sub );
					break;
				case 'entryId':
					$field_html .= '<div class="cf7-view-field-value cf7-view-field-type-entryId-value">';
					$field_html .= $sub->id();
					$field_html .= '</div>';
					break;
				case 'sequenceNumber':
					$field_html .= '<div class="cf7-view-field-value cf7-view-field-type-sequenceNumber-value">';
					$field_html .= $this->seq_no;
					$field_html .= '</div>';
					break;
			}
		}

		$field_html .= '</div>';
		if ( $view_type == 'table' ) {
			$field_html .= '</td>';
		}

		return $field_html;
	}

	function get_table_headers( $field_id, $view_settings, $sub ) {
		$field         = $view_settings->fields->{$field_id};
		$fieldSettings = $field->fieldSettings;
		$label         = $fieldSettings->useCustomLabel ? $fieldSettings->label : $field->label;
		$width         = ! empty( $field->fieldSettings->columnWidth ) ? $field->fieldSettings->columnWidth : 'auto';
		$header        = '<th>';
		$header       .= '<div style="width:' . $width . '" class="cf7-views-table-header ">';
		$header       .= $label;
		$header       .= '</div>';
		$header       .= '</th>';
		return $header;
	}


	function get_pagination_links( $view_settings, $sub ) {
		global $wp;
		$entries_count = $this->submissions_count;
		$per_page      = $view_settings->viewSettings->multipleentries->perPage;
		$pages         = new CF7_View_Paginator( $per_page, 'pagenum' );
		$pages->set_total( $entries_count ); // or a number of records
		$current_url = site_url( remove_query_arg( array( 'pagenum', 'view_id' ) ) );
		$current_url = add_query_arg( 'view_id', $this->view_id, $current_url );

		return $pages->page_links( $current_url . '&' );
	}

	function get_pagination_info( $view_settings, $sub ) {
		$page_no           = empty( $_GET['pagenum'] ) ? 1 : sanitize_text_field( $_GET['pagenum'] );
		$submissions_count = $this->submissions_count;
		$per_page          = $view_settings->viewSettings->multipleentries->perPage;
		$from              = ( $page_no - 1 ) * $per_page;
		if ( $submissions_count > $per_page ) {
			$of = $per_page * $page_no;
		} else {
			$of = $submissions_count;
		}

		return sprintf(
			__( 'Displaying %1$s - %2$s of %3$s', 'cf7-views' ),
			$from,
			$of,
			$submissions_count
		);

	}

	function get_field_value( $key, $sub ) {
		$value  = '';
		$fields = $sub->fields;
		if ( array_key_exists( $key, $fields ) ) {
			$value = $fields[ $key ];
		}

		return $value;
	}


	public function set_repeater_fields( $formId ) {

		$form           = $this->get_form_raw_content( $formId );
		$form_parts     = preg_split( '/(\[\/?repeater(?:\]|\s.*?\]))/', $form, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE );
		$repeater_stack = array();
		$repeaters      = array();
		foreach ( $form_parts as $form_part ) {
			if ( substr( $form_part, 0, 10 ) == '[repeater ' ) {
				$tag_parts = str_getcsv( rtrim( $form_part, ']' ), ' ' );

				array_shift( $tag_parts );

				// at this point the '[repeater' and ']' values are removed from the array.
				// $tag_parts will look something like this: ['repeater-1', 'min:1', 'max:5', 'add', 'Add a new item', 'remove', 'Remove item"]

				$tag_id = $tag_parts[0];

				array_push( $repeaters, $tag_id );
				array_push( $repeater_stack, $tag_id );
			}
		}
		$repeater_fields = array();
		$i               = 0;
		$pattern         = get_shortcode_regex( array( 'repeater' ) );
		if ( preg_match_all( '/' . $pattern . '/s', $form, $repeaters_groups ) ) {
			 $repeaters_groups_array = array_key_exists( 5, $repeaters_groups ) ? $repeaters_groups[5] : array();
			$manager                 = WPCF7_FormTagsManager::get_instance();
			foreach ( $repeaters_groups_array as $repeaters_group ) {
				$tags = $manager->scan( $repeaters_group );
				if ( is_array( $tags ) ) {

					$repeater_fields[ $repeater_stack[ $i ] ] = wp_list_pluck( $tags, 'raw_name' );
				}
				$i++;
			}
		}
		return $repeater_fields;

	}

	public function get_form_raw_content( $slug ) {

		$post = cf7_views_get_post_by_slug( $slug );

		if ( empty( $post ) || ! is_object( $post ) ) {
			return '{}';

		}

			$post = get_post( $post->ID );
		if ( ! empty( $post ) ) {
			return $post->post_content;
		}
		return '';
	}


	public function get_repeaters_value( $field_value, $form_field_id, $sub ) {
		$fields = $sub->fields;
		if ( ! empty( $this->repeater_fields ) ) {
			foreach ( $this->repeater_fields as $repeater_id => $repeater_fields ) {
				if ( in_array( $form_field_id, $repeater_fields ) ) {
					// It's a repeater field
					$count = $fields[ $repeater_id . '_count' ];
					for ( $i = 1; $i <= $count; $i++ ) {
						 $field_value .= isset( $fields[ $form_field_id . '__' . $i ] ) ? $fields[ $form_field_id . '__' . $i ] . "\r\n" : '';
					}
				}
			}
			return nl2br( $field_value );
		}

		return $field_value;
	}

	function get_form_fields( $slug ) {
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
			$form_fields_obj = array();
			// echo '<pre>'; print_r($tags); die;
			foreach ( $tags as $tag ) {
				if ( $tag->type != 'submit' ) {
					$form_fields_obj[ $tag->name ] = array(
						'id'        => $tag->name,
						'label'     => $tag->name,
						'fieldType' => $tag->basetype,
						'type'      => $tag->basetype,
						'values'    => $tag->values,
					);
				}
			}

			return $form_fields_obj;
		}
		return $form_fields_obj;
	}

	function is_image_link( $url ) {
		// Get the file extension from the URL
		$extension = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );

		// Array of common image file extensions
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'bmp' );

		// Check if the extension is in the list of image extensions
		if ( in_array( $extension, $image_extensions ) ) {
			return true; // It's an image link
		} else {
			return false; // It's not an image link
		}
	}



}
new CF7_Views_Shortcode();
