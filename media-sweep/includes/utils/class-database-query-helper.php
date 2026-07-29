<?php
/**
 * Database Query Helper - Handles database operations for scanner services
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * Database Query Helper class
 */
class Database_Query_Helper {

	/**
	 * Perform deep scan for file usage in all database tables
	 *
	 * @param array $search_patterns Search patterns to look for
	 * @param bool  $exclude_posts_meta Whether to exclude posts and postmeta tables (default true for media scanner)
	 * @return array Array of notes if found in custom tables
	 */
	public static function deep_scan_for_file_usage( $search_patterns, $exclude_posts_meta = true ) {
		global $wpdb;

		$all_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );

		// Always exclude our own plugin tables.
		$excluded_tables = array();

		// Conditionally exclude posts and postmeta based on scanner type.
		if ( $exclude_posts_meta ) {
			$excluded_tables[] = $wpdb->posts;
			$excluded_tables[] = $wpdb->postmeta;
		}

		$tables_to_scan = array();
		foreach ( array_diff( $all_tables, $excluded_tables ) as $table ) {
			$clean_table = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $table, 1 );

			// Skip our own plugin tables (mswp_ prefix)
			if ( strpos( $clean_table, 'mswp_' ) !== 0 ) {
				$tables_to_scan[] = $table;
			}
		}

		$notes = array();

		foreach ( $tables_to_scan as $table ) {
			// Get table columns
			$columns = $wpdb->get_col( "DESCRIBE `{$table}`" );

			foreach ( $columns as $column ) {
				// Skip non-text columns
				$column_info = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'" );
				if ( ! self::is_text_column( $column_info->Type ) ) {
					continue;
				}

				foreach ( $search_patterns as $pattern ) {
					$query = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE %s";
					$count = $wpdb->get_var( $wpdb->prepare( $query, '%' . $pattern . '%' ) );

					if ( $count > 0 ) {
						$notes[] = self::create_database_usage_note( $table, $column, $count, $pattern );
					}
				}
			}
		}

		return array_unique( $notes );
	}

	/**
	 * Check if column type is suitable for text search
	 *
	 * @param string $column_type MySQL column type
	 * @return bool True if column can contain text
	 */
	protected static function is_text_column( $column_type ) {
		$text_types = array( 'text', 'mediumtext', 'longtext', 'varchar', 'char' );

		foreach ( $text_types as $type ) {
			if ( stripos( $column_type, $type ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create database usage note
	 *
	 * @param string $table_name  Table name
	 * @param string $column_name Column name
	 * @param int    $count       Number of occurrences
	 * @param string $file_path   File path or pattern
	 * @return string Formatted note
	 */
	public static function create_database_usage_note( $table_name, $column_name, $count, $file_path ) {
		global $wpdb;

		// Clean table name (remove prefix for readability)
		$clean_table = str_replace( $wpdb->prefix, '', $table_name );

		// Try to identify the table type/plugin
		$table_info = self::identify_table_source( $clean_table );

		if ( $table_info ) {
			return sprintf(
				/* translators: %1$d is the count, %2$s is the source (WordPress/plugin name), %3$s is the table name, %4$s is the column name, %5$s is the description */
				__( 'Found %1$d time(s) in %2$s table %3$s.%4$s (%5$s)', 'media-sweep' ),
				$count,
				$table_info['source'],
				$clean_table,
				$column_name,
				$table_info['description']
			);
		}

		return sprintf(
			/* translators: %1$d is the count, %2$s is the table name, %3$s is the column name */
			__( 'Found %1$d time(s) in database table %2$s.%3$s', 'media-sweep' ),
			$count,
			$clean_table,
			$column_name
		);
	}

	/**
	 * Try to identify the source/plugin of a database table
	 *
	 * @param string $table_name Clean table name (without prefix)
	 * @return array|null Table information or null if unknown
	 */
	protected static function identify_table_source( $table_name ) {
		$known_tables = array(
			// WooCommerce
			'wc_'            => array(
				'source'      => 'WooCommerce',
				'description' => 'E-commerce data',
			),
			'woocommerce_'   => array(
				'source'      => 'WooCommerce',
				'description' => 'E-commerce data',
			),

			// Yoast SEO
			'yoast_'         => array(
				'source'      => 'Yoast SEO',
				'description' => 'SEO data',
			),
			'wpseo_'         => array(
				'source'      => 'Yoast SEO',
				'description' => 'SEO data',
			),

			// Contact Form 7
			'cf7_'           => array(
				'source'      => 'Contact Form 7',
				'description' => 'Form data',
			),
			'contact_form_7' => array(
				'source'      => 'Contact Form 7',
				'description' => 'Form data',
			),

			// Gravity Forms
			'gf_'            => array(
				'source'      => 'Gravity Forms',
				'description' => 'Form data',
			),
			'rg_'            => array(
				'source'      => 'Gravity Forms',
				'description' => 'Form data',
			),

			// WPForms
			'wpforms_'       => array(
				'source'      => 'WPForms',
				'description' => 'Form data',
			),

			// Elementor
			'elementor_'     => array(
				'source'      => 'Elementor',
				'description' => 'Page builder data',
			),

			// Easy Digital Downloads
			'edd_'           => array(
				'source'      => 'Easy Digital Downloads',
				'description' => 'Digital store data',
			),

			// bbPress
			'bbp_'           => array(
				'source'      => 'bbPress',
				'description' => 'Forum data',
			),

			// BuddyPress
			'bp_'            => array(
				'source'      => 'BuddyPress',
				'description' => 'Community data',
			),

			// MailChimp
			'mc4wp_'         => array(
				'source'      => 'MailChimp for WordPress',
				'description' => 'Email marketing data',
			),

			// Events Calendar
			'tribe_'         => array(
				'source'      => 'The Events Calendar',
				'description' => 'Events data',
			),

			// TablePress
			'tablepress_'    => array(
				'source'      => 'TablePress',
				'description' => 'Table data',
			),

			// Custom Post Type UI
			'cptui_'         => array(
				'source'      => 'Custom Post Type UI',
				'description' => 'Custom post types',
			),

			// Advanced Custom Fields
			'acf_'           => array(
				'source'      => 'Advanced Custom Fields',
				'description' => 'Custom fields data',
			),

			// Wordfence
			'wfls_'          => array(
				'source'      => 'Wordfence',
				'description' => 'Security data',
			),
			'wf'             => array(
				'source'      => 'Wordfence',
				'description' => 'Security data',
			),

			// WPML
			'icl_'           => array(
				'source'      => 'WPML',
				'description' => 'Translation data',
			),

			// WP Rocket
			'wpr_'           => array(
				'source'      => 'WP Rocket',
				'description' => 'Cache data',
			),

			// LiteSpeed Cache
			'litespeed_'     => array(
				'source'      => 'LiteSpeed Cache',
				'description' => 'Cache data',
			),

			// W3 Total Cache
			'w3tc_'          => array(
				'source'      => 'W3 Total Cache',
				'description' => 'Cache data',
			),

			// UpdraftPlus
			'updraft_'       => array(
				'source'      => 'UpdraftPlus',
				'description' => 'Backup data',
			),

			// BackWPup
			'backwpup_'      => array(
				'source'      => 'BackWPup',
				'description' => 'Backup data',
			),
		);

		foreach ( $known_tables as $prefix => $info ) {
			if ( strpos( $table_name, $prefix ) === 0 ) {
				return $info;
			}
		}

		// Check for common patterns
		if ( strpos( $table_name, 'cache' ) !== false ) {
			return array(
				'source'      => 'Cache Plugin',
				'description' => 'Cache data',
			);
		}

		if ( strpos( $table_name, 'backup' ) !== false ) {
			return array(
				'source'      => 'Backup Plugin',
				'description' => 'Backup data',
			);
		}

		if ( strpos( $table_name, 'log' ) !== false ) {
			return array(
				'source'      => 'Logging Plugin',
				'description' => 'Log data',
			);
		}

		if ( strpos( $table_name, 'analytics' ) !== false ) {
			return array(
				'source'      => 'Analytics Plugin',
				'description' => 'Analytics data',
			);
		}

		return null;
	}

	/**
	 * Check if attachment is used in custom fields
	 *
	 * @param int   $attachment_id The attachment ID
	 * @param array $search_patterns Search patterns for the attachment
	 * @return array Array of notes if used in custom fields
	 */
	public static function check_custom_field_usage( $attachment_id, $search_patterns ) {
		global $wpdb;

		// WordPress core meta keys to exclude (these are not "usage" indicators)
		$excluded_meta_keys         = array(
			'_thumbnail_id',
			'_wp_attachment_metadata',
			'_wp_attached_file',
		);
		$excluded_keys_placeholders = implode( ',', array_fill( 0, count( $excluded_meta_keys ), '%s' ) );

		// Check if attachment ID is directly referenced in postmeta (excluding core meta keys)
		$query = "SELECT post_id, meta_key FROM {$wpdb->postmeta} 
				  WHERE meta_value = %s 
				  AND meta_key NOT IN ({$excluded_keys_placeholders})";

		$params  = array_merge( array( $attachment_id ), $excluded_meta_keys );
		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		$notes   = array();

		foreach ( $results as $result ) {
			$notes[] = sprintf(
				/* translators: %1$s is the custom field name, %2$s is the post title */
				__( 'Used in custom field "%1$s" for %2$s', 'media-sweep' ),
				$result->meta_key,
				html_entity_decode( get_the_title( $result->post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			);
		}

		// If we found direct ID references, return early
		if ( ! empty( $notes ) ) {
			return $notes;
		}

		// Check if any of the patterns are used in postmeta (excluding core meta keys)
		foreach ( $search_patterns as $pattern ) {
			$query = "SELECT post_id, meta_key FROM {$wpdb->postmeta} 
					  WHERE meta_value LIKE %s 
					  AND meta_key NOT IN ({$excluded_keys_placeholders})";

			$params  = array_merge( array( '%' . $pattern . '%' ), $excluded_meta_keys );
			$results = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

			foreach ( $results as $result ) {
				$notes[] = sprintf(
					__( 'Used in custom field "%1$s" for %2$s', 'media-sweep' ),
					$result->meta_key,
					html_entity_decode( get_the_title( $result->post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
				);
			}
		}

		return array_unique( $notes );
	}

	/**
	 * Check if attachment is used as featured image
	 *
	 * @param int $attachment_id The attachment ID
	 * @return array Array of notes if used as featured image
	 */
	public static function check_featured_image_usage( $attachment_id ) {
		global $wpdb;

		$query = "SELECT post_id FROM {$wpdb->postmeta} 
				  WHERE meta_key = '_thumbnail_id' 
				  AND meta_value = %s";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $attachment_id ) );
		$notes   = array();

		foreach ( $results as $result ) {
			$post_title    = html_entity_decode( get_the_title( $result->post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$post_type     = get_post_type( $result->post_id );
			$display_title = ! empty( $post_title ) ? $post_title : sprintf( __( 'Untitled %s', 'media-sweep' ), $post_type );

			$notes[] = sprintf(
				__( 'Used as featured image for %1$s: "%2$s"', 'media-sweep' ),
				$post_type,
				$display_title
			);
		}

		return $notes;
	}
}
