<?php
/**
 * Base Scanner Service - Abstract base class for all scanner services
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Models\File_Model;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Utils\Path_Helper;

/**
 * Abstract Base Scanner Service class
 */
abstract class Base_Scanner_Service {

	/**
	 * System monitor instance
	 *
	 * @var System_Monitor_Service
	 */
	protected $system_monitor;

	/**
	 * Default scan options (to be overridden by child classes)
	 *
	 * @var array
	 */
	protected $default_options = array();

	/**
	 * Constructor
	 *
	 * @param System_Monitor_Service $system_monitor System monitor instance
	 */
	public function __construct( System_Monitor_Service $system_monitor ) {
		$this->system_monitor = $system_monitor;
	}

	/**
	 * Resume an existing media library scan
	 *
	 * @param int $scan_id The scan ID to resume
	 * @return Scan_Model|false
	 */
	public function resume_scan( $scan_id ) {
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return false;
		}

		// Reset system monitor for resumed scan
		$this->system_monitor->reset_monitoring();

		// Clear all existing file scan records for this scan
		$scan->file_scans()->query()->delete();

		// Update scan to running status and reset timestamps
		$scan->update(
			array(
				'status'      => 'running',
				'started_at'  => current_time( 'mysql' ),
				'finished_at' => null,
			)
		);

		return $scan;
	}

	/**
	 * Get scan results for this scanner type
	 *
	 * @param int $scan_id Optional scan ID
	 * @return array
	 */
	public function get_scan_results( $scan_id = null ) {
		// Get counts grouped by status
		if ( $scan_id ) {
			$results = File_Scan_Model::where( 'scan_id', '=', $scan_id );
		} else {
			$results = File_Scan_Model::query();
		}

		$results = $results->select_raw( 'status, COUNT(*) as count' )
			->group_by( 'status' )
			->get_raw();

		// Transform to associative array
		$formatted = array(
			'in_media'     => 0,
			'not_in_media' => 0,
			'in_use'       => 0,
			'unused'       => 0,
			'orphaned'     => 0,
			'total_files'  => 0,
		);

		foreach ( $results as $result ) {
			$formatted[ $result->status ] = (int) $result->count;
			$formatted['total_files']    += (int) $result->count;
		}

		return $formatted;
	}

	/**
	 * Finish the scan (common implementation)
	 *
	 * @param int $scan_id The scan ID
	 * @return array|false
	 */
	public function finish_scan( $scan_id ) {
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return false;
		}

		$scan->update(
			array(
				'finished_at' => current_time( 'mysql' ),
			)
		);

		$scan_results = $this->get_scan_results( $scan_id );

		return $scan_results;
	}

	/**
	 * Helper method to get post information
	 *
	 * @param int $post_id Post ID
	 * @return array Array with post_title, post_type, and display_title
	 */
	protected function get_post_info( $post_id ) {
		$post_title = get_the_title( $post_id );
		$post_type  = get_post_type( $post_id );

		// Fallback if title is empty
		$display_title = ! empty( $post_title ) ? $post_title : sprintf(
			/* translators: %s is the post type (e.g., post, page, attachment) */
			__( 'Untitled %s', 'media-sweep' ),
			$post_type
		);

		return array(
			'post_title'    => $post_title,
			'post_type'     => $post_type,
			'display_title' => $display_title,
		);
	}

	/**
	 * Helper method to create usage note
	 *
	 * @param string $context     Usage context
	 * @param int    $post_id     Post ID (optional)
	 * @param string $extra_info  Additional information
	 * @return string Formatted note
	 */
	protected function create_usage_note( $context, $post_id = 0, $extra_info = '' ) {
		switch ( $context ) {
			case 'featured':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used as featured image for %1$s: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used as featured image', 'media-sweep' );

			case 'content':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used in %1$s content: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in content', 'media-sweep' );

			case 'custom_field':
				if ( $post_id && $extra_info ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the custom field name, %2$s is the post type, %3$s is the post title */
						__( 'Used in custom field "%1$s" for %2$s: "%3$s"', 'media-sweep' ),
						$extra_info,
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in custom field', 'media-sweep' );

			case 'gallery_shortcode':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used in gallery shortcode in %1$s: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in gallery shortcode', 'media-sweep' );

			case 'gallery_block':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used in gallery block in %1$s: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in gallery block', 'media-sweep' );

			case 'theme_plugin':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Used by theme or plugin: %s', 'media-sweep' ),
					$extra_info
				);

			case 'cache':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Cache file: %s', 'media-sweep' ),
					$extra_info
				);

			case 'backup':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Backup file: %s', 'media-sweep' ),
					$extra_info
				);

			case 'database':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Found in database table: %s', 'media-sweep' ),
					$extra_info
				);

			default:
				return $extra_info ?: sprintf(
					/* translators: %s is the extra information */
					__( 'File usage detected: %s', 'media-sweep' ),
					$extra_info
				);
		}
	}

	/**
	 * Helper method to execute database query and collect post IDs with notes
	 *
	 * @param string $query     SQL query
	 * @param array  $params    Query parameters
	 * @param string $context   Usage context
	 * @param string $extra_col Optional extra column name for additional info
	 * @return array Array of notes
	 */
	protected function execute_query_and_collect_notes( $query, $params, $context, $extra_col = '' ) {
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		$notes   = array();

		foreach ( $results as $result ) {
			$extra_info = ! empty( $extra_col ) && isset( $result->{$extra_col} ) ? $result->{$extra_col} : '';
			$notes[]    = $this->create_usage_note( $context, $result->ID, $extra_info );
		}

		return $notes;
	}

	/**
	 * Get or create file record
	 *
	 * @param string   $file_path     The absolute file path
	 * @param int|null $attachment_id Optional attachment ID
	 * @param int|null $thumb_of      Optional parent attachment ID for thumbnails
	 * @return File_Model|null
	 */
	protected function get_or_create_file_record( $file_path, $attachment_id = null, $thumb_of = null ) {
		// Convert absolute path to relative path from uploads directory
		$relative_path = Path_Helper::ensure_relative_path( $file_path );
		$filepath_sha1 = sha1( $relative_path );

		// Try to find existing file record
		$file = File_Model::where( 'filepath_sha1', '=', $filepath_sha1 )->first();
		if ( ! $file ) {
			// Create new file record
			$file_info = pathinfo( $file_path );
			$file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;
			$file      = File_Model::create(
				array(
					'filepath'      => $relative_path,
					'filepath_sha1' => $filepath_sha1,
					'attachment_id' => $attachment_id,
					'file_type'     => isset( $file_info['extension'] ) ? $file_info['extension'] : null,
					'size_bytes'    => $file_size,
					'thumb_of'      => $thumb_of,
					'status'        => 'active',
				)
			);
		} else {
			// If file was found and is trashed or deleted, reactivate it
			if ( in_array( $file->status, array( 'trashed', 'deleted' ), true ) ) {
				$update_data = array(
					'status'   => 'active',
					'filepath' => $relative_path, // Update path in case it was moved
				);

				// Update attachment_id if provided
				if ( null !== $attachment_id ) {
					$update_data['attachment_id'] = $attachment_id;
				}

				// Update thumb_of if provided
				if ( null !== $thumb_of ) {
					$update_data['thumb_of'] = $thumb_of;
				}

				// Update file size if file exists
				if ( file_exists( $file_path ) ) {
					$update_data['size_bytes'] = filesize( $file_path );
				}

				$file->update( $update_data );
			}
		}

		return $file;
	}

	/**
	 * Check if processing should continue based on system resources
	 *
	 * @return array Array with 'should_continue' boolean and optional 'warning' message
	 */
	protected function check_system_resources() {
		$is_safe = $this->system_monitor->is_safe_to_continue();
		$warning = $this->system_monitor->get_resource_warning();

		if ( ! $is_safe ) {
			// Force cleanup if resources are high
			$cleanup_result = $this->system_monitor->cleanup_memory();

			if ( ! empty( $cleanup_result['freed'] ) && $cleanup_result['freed'] > 0 ) {
				$warning .= sprintf(
					/* translators: %s is the amount of memory freed */
					__( ' Memory cleanup freed %s.', 'media-sweep' ),
					$cleanup_result['formatted']['freed']
				);

				// Check again after cleanup
				$is_safe = $this->system_monitor->is_safe_to_continue();
			}
		}

		return array(
			'should_continue' => $is_safe,
			'warning'         => $warning,
			'resources'       => $this->system_monitor->check_system_resources(),
		);
	}

	/**
	 * Get file search patterns for URL and path matching
	 *
	 * @param string $file_path The file path
	 * @return array Array of search patterns
	 */
	protected function get_file_search_patterns( $file_path ) {
		$patterns   = array();
		$upload_dir = wp_upload_dir();

		// Get relative path
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );

		// Add relative path pattern
		$patterns[] = $relative_path;

		// Add URL pattern
		$file_url   = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		$patterns[] = $file_url;

		// Add filename only
		$filename   = basename( $file_path );
		$patterns[] = $filename;

		// Add URL without domain for cases where site URL changed
		$parsed_url = parse_url( $file_url );
		if ( isset( $parsed_url['path'] ) ) {
			$patterns[] = $parsed_url['path'];
		}

		// Remove duplicates
		return array_unique( array_filter( $patterns ) );
	}

	/**
	 * Check if file is used in content (common base implementation)
	 *
	 * @param array $search_patterns Search patterns for the file
	 * @return array Array of notes if used in content
	 */
	protected function check_content_usage_by_patterns( $search_patterns ) {
		global $wpdb;
		$found_post_ids = array();

		// First, collect all unique post IDs that match any pattern
		foreach ( $search_patterns as $pattern ) {
			// Search in post_content
			$query = "SELECT DISTINCT ID FROM {$wpdb->posts} 
					  WHERE post_type != 'attachment' 
					  AND (post_content LIKE %s OR post_excerpt LIKE %s)
					  AND post_status IN ('publish', 'private', 'draft')";

			$results = $wpdb->get_results( $wpdb->prepare( $query, '%' . $pattern . '%', '%' . $pattern . '%' ) );

			foreach ( $results as $result ) {
				$found_post_ids[] = $result->ID;
			}
		}

		// Remove duplicate post IDs
		$found_post_ids = array_unique( $found_post_ids );

		// Generate notes from unique post IDs
		$notes = array();
		foreach ( $found_post_ids as $post_id ) {
			$notes[] = $this->create_usage_note( 'content', $post_id );
		}

		return $notes;
	}

	/**
	 * Abstract methods that must be implemented by child classes
	 *
	 * @param array $options Scan options
	 * @return mixed
	 */
	abstract public function start_scan( $options = array() );

	/**
	 * Get system monitor instance
	 *
	 * @return System_Monitor_Service
	 */
	public function get_system_monitor() {
		return $this->system_monitor;
	}
}
