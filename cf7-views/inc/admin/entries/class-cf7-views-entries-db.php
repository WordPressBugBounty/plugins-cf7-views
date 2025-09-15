<?php
/**
 * CF7 Views Entries Database Handler
 *
 * Handles the custom database table for storing Contact Form 7 entries
 *
 * @package CF7_Views
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_Views_Entries_DB {

	/**
	 * Table name
	 */
	private $table_name;

	/**
	 * WordPress database object
	 */
	private $wpdb;

	/**
	 * Table version for upgrades
	 */
	private $table_version = '1.0';

	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'cf7_views_entries';

		// Create table immediately if it doesn't exist
		$this->maybe_create_table();

		// Also hook for future plugin loads
		add_action( 'plugins_loaded', array( $this, 'maybe_create_table' ) );
	}

	/**
	 * Create the entries table if it doesn't exist
	 */
	public function maybe_create_table() {
		// Check if table exists first
		$table_exists = $this->wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" ) === $this->table_name;

		if ( ! $table_exists ) {
			$this->create_table();
			update_option( 'cf7_views_entries_db_version', $this->table_version );
			return true;
		}

		// Check version for upgrades
		$installed_version = get_option( 'cf7_views_entries_db_version' );

		if ( $installed_version !== $this->table_version ) {
			$this->create_table();
			update_option( 'cf7_views_entries_db_version', $this->table_version );
			return true;
		}

		return false;
	}

	/**
	 * Create the entries table
	 */
	private function create_table() {
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            form_title varchar(255) NOT NULL DEFAULT '',
            entry_data longtext NOT NULL,
            user_ip varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            referer_url text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY created_at (created_at),
            KEY status (status)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$result = dbDelta( $sql );

		// Log any errors for debugging
		if ( $this->wpdb->last_error ) {
			error_log( 'CF7 Views Table Creation Error: ' . $this->wpdb->last_error );
		}

		return $result;
	}

	/**
	 * Force create table (for debugging/manual creation)
	 */
	public function force_create_table() {
		return $this->create_table();
	}

	/**
	 * Insert a new entry
	 *
	 * @param array $data Entry data
	 * @return int|false Entry ID on success, false on failure
	 */
	public function insert_entry( $data ) {
		$defaults = array(
			'form_id'     => 0,
			'form_title'  => '',
			'entry_data'  => '',
			'user_ip'     => $this->get_user_ip(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'referer_url' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( $_SERVER['HTTP_REFERER'] ) : '',
			'created_at'  => current_time( 'mysql' ),
			'status'      => 'active',
		);

		$data = wp_parse_args( $data, $defaults );

		// Serialize entry data if it's an array
		if ( is_array( $data['entry_data'] ) ) {
			$data['entry_data'] = maybe_serialize( $data['entry_data'] );
		}

		$result = $this->wpdb->insert(
			$this->table_name,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $this->wpdb->insert_id : false;
	}

	/**
	 * Get entries with optional filtering
	 *
	 * @param array $args Query arguments
	 * @return array Array of entry objects
	 */
	public function get_entries( $args = array() ) {
		$defaults = array(
			'form_id'   => null,
			'status'    => 'active',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'limit'     => 20,
			'offset'    => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array( 'status = %s' );
		$where_values  = array( $args['status'] );

		// Filter by form ID
		if ( ! empty( $args['form_id'] ) ) {
			$where_clauses[] = 'form_id = %d';
			$where_values[]  = intval( $args['form_id'] );
		}

		// Search in form data
		if ( ! empty( $args['search'] ) ) {
			$where_clauses[] = '(form_title LIKE %s OR entry_data LIKE %s)';
			$search_term     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where_values[]  = $search_term;
			$where_values[]  = $search_term;
		}

		// Date range filtering
		if ( ! empty( $args['date_from'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where_clauses[] = 'created_at <= %s';
			$where_values[]  = $args['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Sanitize orderby and order
		$allowed_orderby = array( 'id', 'form_id', 'form_title', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$limit_sql = '';
		if ( $args['limit'] > 0 ) {
			$limit_sql = $this->wpdb->prepare( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}

		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} {$limit_sql}",
			$where_values
		);

		$results = $this->wpdb->get_results( $sql );

		// Unserialize entry data
		foreach ( $results as $entry ) {
			$entry->entry_data = maybe_unserialize( $entry->entry_data );
		}

		return $results;
	}

	/**
	 * Get total count of entries
	 *
	 * @param array $args Query arguments (same as get_entries)
	 * @return int Total count
	 */
	public function get_entries_count( $args = array() ) {
		$defaults = array(
			'form_id'   => null,
			'status'    => 'active',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array( 'status = %s' );
		$where_values  = array( $args['status'] );

		if ( ! empty( $args['form_id'] ) ) {
			$where_clauses[] = 'form_id = %d';
			$where_values[]  = intval( $args['form_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where_clauses[] = '(form_title LIKE %s OR entry_data LIKE %s)';
			$search_term     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where_values[]  = $search_term;
			$where_values[]  = $search_term;
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where_clauses[] = 'created_at <= %s';
			$where_values[]  = $args['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}",
			$where_values
		);

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Get a single entry by ID
	 *
	 * @param int $entry_id Entry ID
	 * @return object|null Entry object or null if not found
	 */
	public function get_entry( $entry_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE id = %d AND status = 'active'",
			$entry_id
		);

		$entry = $this->wpdb->get_row( $sql );

		if ( $entry ) {
			$entry->entry_data = maybe_unserialize( $entry->entry_data );
		}

		return $entry;
	}

	/**
	 * Delete an entry (soft delete by changing status)
	 *
	 * @param int $entry_id Entry ID
	 * @return bool Success
	 */
	public function delete_entry( $entry_id ) {
		return $this->wpdb->update(
			$this->table_name,
			array( 'status' => 'deleted' ),
			array( 'id' => $entry_id ),
			array( '%s' ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Permanently delete an entry
	 *
	 * @param int $entry_id Entry ID
	 * @return bool Success
	 */
	public function permanently_delete_entry( $entry_id ) {
		return $this->wpdb->delete(
			$this->table_name,
			array( 'id' => $entry_id ),
			array( '%d' )
		) !== false;
	}

	/**
	 * Get unique form IDs that have entries
	 *
	 * @return array Array of form IDs
	 */
	public function get_forms_with_entries() {
		$sql = "SELECT DISTINCT form_id, form_title FROM {$this->table_name} WHERE status = 'active' ORDER BY form_title";
		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Get user IP address
	 *
	 * @return string IP address
	 */
	private function get_user_ip() {
		$ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = $_SERVER[ $key ];
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
	}

	/**
	 * Get table name
	 *
	 * @return string Table name
	 */
	public function get_table_name() {
		return $this->table_name;
	}

	/**
	 * Check if table exists
	 *
	 * @return bool True if table exists
	 */
	public function table_exists() {
		return $this->wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" ) === $this->table_name;
	}

	/**
	 * Get table creation status and info
	 *
	 * @return array Status information
	 */
	public function get_table_info() {
		return array(
			'table_name'       => $this->table_name,
			'exists'           => $this->table_exists(),
			'version'          => get_option( 'cf7_views_entries_db_version', 'not set' ),
			'expected_version' => $this->table_version,
			'last_error'       => $this->wpdb->last_error,
		);
	}
}
