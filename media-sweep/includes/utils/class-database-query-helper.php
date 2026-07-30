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
	 * A match here can decide that a file is in use.
	 *
	 * @var string
	 */
	const EVIDENCE_USAGE = 'usage';

	/**
	 * A match here records that a file exists; it is shown but never decides status.
	 *
	 * @var string
	 */
	const EVIDENCE_BOOKKEEPING = 'bookkeeping';

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
	 * Note for a match that is reported but does not decide status.
	 *
	 * @param string $table_name  Table name without the site prefix.
	 * @param string $column_name Column name.
	 * @param int    $count       Number of occurrences.
	 * @return string Formatted note.
	 */
	public static function create_database_mention_note( $table_name, $column_name, $count ) {
		$table_info = self::identify_table_source( $table_name );

		if ( $table_info ) {
			return sprintf(
				/* translators: %1$d is the count, %2$s is the plugin name, %3$s is the table name, %4$s is the column name, %5$s is the data description */
				__( 'Also mentioned %1$d time(s) in %2$s table %3$s.%4$s (%5$s) - this records the file, it does not display it, so it is not counted as usage', 'media-sweep' ),
				$count,
				$table_info['source'],
				$table_name,
				$column_name,
				$table_info['description']
			);
		}

		return sprintf(
			/* translators: %1$d is the count, %2$s is the table name, %3$s is the column name */
			__( 'Also mentioned %1$d time(s) in database table %2$s.%3$s - not counted as usage', 'media-sweep' ),
			$count,
			$table_name,
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
		// Wordfence's tables have no separator after "wf" (wfhits, wffilemods), so a bare "wf" prefix would
		// also claim an unrelated table such as wfoo_content. Its real table names are matched instead.
		if ( preg_match( '/^wf(ls_|blocks|config|crawlers|filechanges|filemods|hits|hoover|issues|knownfilelist|livetraffic|locs|logins|notifications|pendingissues|reversecache|snipcache|status|trafficrates)/', $table_name ) ) {
			return array(
				'source'      => 'Wordfence',
				'category'    => 'security',
				'description' => 'Security data',
			);
		}

		// 'category' is the stable key that logic reads; 'description' is display text and may be reworded
		// or translated at any time, so nothing may branch on it.
		$known_tables = array(
			// WooCommerce
			'wc_'            => array(
				'source'      => 'WooCommerce',
				'category'    => 'ecommerce',
				'description' => 'E-commerce data',
			),
			'woocommerce_'   => array(
				'source'      => 'WooCommerce',
				'category'    => 'ecommerce',
				'description' => 'E-commerce data',
			),

			// Yoast SEO
			'yoast_'         => array(
				'source'      => 'Yoast SEO',
				'category'    => 'seo',
				'description' => 'SEO data',
			),
			'wpseo_'         => array(
				'source'      => 'Yoast SEO',
				'category'    => 'seo',
				'description' => 'SEO data',
			),

			// Contact Form 7
			'cf7_'           => array(
				'source'      => 'Contact Form 7',
				'category'    => 'forms',
				'description' => 'Form data',
			),
			'contact_form_7' => array(
				'source'      => 'Contact Form 7',
				'category'    => 'forms',
				'description' => 'Form data',
			),

			// Gravity Forms
			'gf_'            => array(
				'source'      => 'Gravity Forms',
				'category'    => 'forms',
				'description' => 'Form data',
			),
			'rg_'            => array(
				'source'      => 'Gravity Forms',
				'category'    => 'forms',
				'description' => 'Form data',
			),

			// WPForms
			'wpforms_'       => array(
				'source'      => 'WPForms',
				'category'    => 'forms',
				'description' => 'Form data',
			),

			// Elementor
			'elementor_'     => array(
				'source'      => 'Elementor',
				'category'    => 'page_builder',
				'description' => 'Page builder data',
			),

			// Easy Digital Downloads
			'edd_'           => array(
				'source'      => 'Easy Digital Downloads',
				'category'    => 'ecommerce',
				'description' => 'Digital store data',
			),

			// bbPress
			'bbp_'           => array(
				'source'      => 'bbPress',
				'category'    => 'forum',
				'description' => 'Forum data',
			),

			// BuddyPress
			'bp_'            => array(
				'source'      => 'BuddyPress',
				'category'    => 'community',
				'description' => 'Community data',
			),

			// MailChimp
			'mc4wp_'         => array(
				'source'      => 'MailChimp for WordPress',
				'category'    => 'email',
				'description' => 'Email marketing data',
			),

			// Events Calendar
			'tribe_'         => array(
				'source'      => 'The Events Calendar',
				'category'    => 'events',
				'description' => 'Events data',
			),

			// TablePress
			'tablepress_'    => array(
				'source'      => 'TablePress',
				'category'    => 'tables',
				'description' => 'Table data',
			),

			// Custom Post Type UI
			'cptui_'         => array(
				'source'      => 'Custom Post Type UI',
				'category'    => 'content_types',
				'description' => 'Custom post types',
			),

			// Advanced Custom Fields
			'acf_'           => array(
				'source'      => 'Advanced Custom Fields',
				'category'    => 'custom_fields',
				'description' => 'Custom fields data',
			),

			// WPML
			'icl_'           => array(
				'source'      => 'WPML',
				'category'    => 'translation',
				'description' => 'Translation data',
			),

			// WP Rocket
			'wpr_'           => array(
				'source'      => 'WP Rocket',
				'category'    => 'cache',
				'description' => 'Cache data',
			),

			// LiteSpeed Cache
			'litespeed_'     => array(
				'source'      => 'LiteSpeed Cache',
				'category'    => 'cache',
				'description' => 'Cache data',
			),

			// W3 Total Cache
			'w3tc_'          => array(
				'source'      => 'W3 Total Cache',
				'category'    => 'cache',
				'description' => 'Cache data',
			),

			// UpdraftPlus
			'updraft_'       => array(
				'source'      => 'UpdraftPlus',
				'category'    => 'backup',
				'description' => 'Backup data',
			),

			// BackWPup
			'backwpup_'      => array(
				'source'      => 'BackWPup',
				'category'    => 'backup',
				'description' => 'Backup data',
			),
		);

		foreach ( $known_tables as $prefix => $info ) {
			if ( self::table_matches_prefix( $table_name, $prefix ) ) {
				return $info;
			}
		}

		// Word-boundary, not substring: a plain strpos() for 'log' classified blog_posts and shop_catalog
		// as log data, which would have made real content look like bookkeeping.
		$fallbacks = array(
			'cache'     => array(
				'source'      => 'Cache Plugin',
				'category'    => 'cache',
				'description' => 'Cache data',
			),
			'backup'    => array(
				'source'      => 'Backup Plugin',
				'category'    => 'backup',
				'description' => 'Backup data',
			),
			'log'       => array(
				'source'      => 'Logging Plugin',
				'category'    => 'logs',
				'description' => 'Log data',
			),
			'analytics' => array(
				'source'      => 'Analytics Plugin',
				'category'    => 'analytics',
				'description' => 'Analytics data',
			),
		);

		foreach ( $fallbacks as $word => $info ) {
			if ( preg_match( '/(^|_)' . $word . 's?($|_)/', $table_name ) ) {
				return $info;
			}
		}

		return null;
	}

	/**
	 * Whether a table name starts with a known plugin prefix, at a separator boundary.
	 *
	 * Prefixes that already end in "_" match as-is; the rest must be followed by "_" or end the name, so a
	 * short prefix cannot claim an unrelated table that merely begins with the same letters.
	 *
	 * @param string $table_name Table name without the site prefix.
	 * @param string $prefix     Known plugin prefix.
	 * @return bool
	 */
	protected static function table_matches_prefix( $table_name, $prefix ) {
		if ( $table_name === $prefix ) {
			return true;
		}

		if ( substr( $prefix, -1 ) === '_' ) {
			return strpos( $table_name, $prefix ) === 0;
		}

		return 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '($|_)/', $table_name );
	}

	/**
	 * Evidence weight of a third-party table: may a match here decide that a file is in use?
	 *
	 * A cache, queue, log, backup manifest or security index records that a file exists; it never renders
	 * it to a visitor. Counting those as usage is what made an entire media library report as in use on any
	 * site running an image optimizer or a security plugin.
	 *
	 * Unknown tables default to bookkeeping. That is the safe direction here: over-reporting "in use" hides
	 * every genuinely unused file and makes the plugin look broken, while under-reporting is recoverable -
	 * the match is still shown in the file's notes, deletion goes to trash, and the admin can override.
	 *
	 * @param string $table_name  Table name without the site prefix.
	 * @param string $column_name Column the match was found in.
	 * @return string self::EVIDENCE_USAGE or self::EVIDENCE_BOOKKEEPING.
	 */
	public static function table_evidence( $table_name, $column_name = '' ) {
		$info = self::identify_table_source( $table_name );

		// Categories whose content is rendered to visitors, so a match really can be usage. Compared against
		// the stable 'category' key, never the display text: a reworded or translated label must not
		// silently change what gets deleted.
		$usage_categories = array(
			'ecommerce',
			'page_builder',
			'custom_fields',
			'forms',
			'forum',
			'community',
			'events',
			'tables',
			'content_types',
			'translation',
		);

		$evidence = ( $info && isset( $info['category'] ) && in_array( $info['category'], $usage_categories, true ) )
			? self::EVIDENCE_USAGE
			: self::EVIDENCE_BOOKKEEPING;

		/**
		 * Filter the evidence weight of a third-party table.
		 *
		 * Lets a site correct a misclassification without waiting for a plugin release - for example
		 * marking a bespoke content table as usage, or a noisy custom table as bookkeeping.
		 *
		 * @param string $evidence    'usage' or 'bookkeeping'.
		 * @param string $table_name  Table name without the site prefix.
		 * @param string $column_name Column the match was found in.
		 */
		return apply_filters( 'media_sweep_table_evidence', $evidence, $table_name, $column_name );
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
