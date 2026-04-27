<?php
/**
 * Media Scanner Service - Refactored with system monitoring and reduced complexity
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Interfaces\Media_Scanner;
use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Utils\Database_Query_Helper;

/**
 * Media Scanner Service class
 */
class Media_Scanner_Service extends Base_Scanner_Service implements Media_Scanner {

	/**
	 * Default scan options
	 *
	 * @var array
	 */
	protected $default_options = array(
		'deep_scan'           => false, // Scan all database tables vs just standard WordPress tables
		'include_thumbnails'  => true,  // Include thumbnail files in scan
		'check_custom_fields' => true,  // Check custom fields for media usage
		'check_shortcodes'    => true,  // Check for gallery shortcodes
		'check_blocks'        => true,  // Check for gallery blocks
	);

	/**
	 * Start a new media library scan
	 *
	 * @param array $options Scan options
	 * @return Scan_Model|false
	 */
	public function start_scan( $options = array() ) {
		// Merge with default options
		$options = wp_parse_args( $options, $this->default_options );

		// Reset system monitor for this scan
		$this->system_monitor->reset_monitoring();

		// Create scan record
		$scan = Scan_Model::create(
			array(
				'mode'       => 'media_library',
				'status'     => 'running',
				'options'    => $options,
				'started_at' => current_time( 'mysql' ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			)
		);

		return $scan;
	}

	/**
	 * Get all media attachments for frontend processing
	 *
	 * @param int   $page     Page number (1-based)
	 * @param int   $per_page Items per page
	 * @param array $options  Scan options
	 * @return array
	 */
	public function get_media_attachments( $page = 1, $per_page = 100, $options = array() ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		// Get attachment IDs only
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' 
				 AND post_status = 'inherit'
				 ORDER BY ID ASC 
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// Convert to integers
		$attachment_ids = array_map( 'intval', $attachment_ids );

		// Get total count for pagination info
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// Check if we should pause
		$should_pause = $this->system_monitor->should_pause();

		return array(
			'attachments'  => $attachment_ids,
			'total'        => (int) $total,
			'page'         => $page,
			'per_page'     => $per_page,
			'total_pages'  => ceil( $total / $per_page ),
			'has_more'     => ( $page * $per_page ) < $total,
			'should_pause' => $should_pause['pause'],
			'pause_reason' => $should_pause['reason'],
		);
	}

	/**
	 * Process a batch of media attachments with simplified monitoring
	 *
	 * @param int   $scan_id     The scan ID
	 * @param array $attachments Array of attachment IDs
	 * @param array $options     Scan options
	 * @return array Processing results with essential information only
	 */
	public function process_media_batch( $scan_id, $attachments, $options = array() ) {
		$options = wp_parse_args( $options, $this->default_options );
		$results = array(
			'success'      => true,
			'processed'    => 0,
			'errors'       => 0,
			'should_pause' => false,
			'pause_reason' => '',
			'resume_info'  => null,
		);

		// Track processed file IDs to prevent duplicates within this batch
		$processed_file_ids = array();
		foreach ( $attachments as $index => $attachment_id ) {
			// Check if we should pause every 5 attachments
			if ( $index % 5 === 0 ) {
				$should_pause = $this->system_monitor->should_pause();
				if ( $should_pause['pause'] ) {
					$results['should_pause'] = true;
					$results['pause_reason'] = $should_pause['reason'];
					$results['resume_info']  = array(
						'attachment_index' => $index,
						'remaining_count'  => count( $attachments ) - $index,
					);
					break;
				}
			}

			// Get usage result
			$usage_result = $this->check_media_usage( $attachment_id, $options );
			$status       = $usage_result['status'];
			$notes        = $usage_result['notes'];

			// Get the main file path
			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path ) {
				++$results['errors'];
				continue;
			}

			// Get or create file record
			$file = $this->get_or_create_file_record( $file_path, $attachment_id );
			if ( ! $file ) {
				++$results['errors'];
				continue;
			}

			// Check if this file has already been processed in this scan (database check)
			$existing_scan = File_Scan_Model::where( 'scan_id', '=', $scan_id )
				->where( 'file_id', '=', $file->id )
				->first();

			if ( ! $existing_scan && ! in_array( $file->id, $processed_file_ids ) ) {
				// Create file scan record
				File_Scan_Model::create(
					array(
						'scan_id'    => $scan_id,
						'file_id'    => $file->id,
						'status'     => $status,
						'notes'      => $notes,
						'created_at' => current_time( 'mysql' ),
					)
				);

				// Track this file as processed
				$processed_file_ids[] = $file->id;
			}

			// Process thumbnails if enabled
			if ( ! empty( $options['include_thumbnails'] ) ) {
				$this->process_attachment_thumbnails( $scan_id, $attachment_id, $status, $notes, $processed_file_ids );
			}

			++$results['processed'];
		}

		return $results;
	}

	/**
	 * Check media usage for a specific attachment
	 *
	 * @param int   $attachment_id The attachment ID
	 * @param array $options       Scan options
	 * @return array Array with 'status' and 'notes'
	 */
	public function check_media_usage( $attachment_id, $options = array() ) {
		$notes = array();

		// Check if attachment still exists
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			$notes[] = sprintf(
				/* translators: %d is the attachment ID */
				__( 'Attachment post no longer exists in database (ID: %d)', 'media-sweep' ),
				$attachment_id
			);
			return array(
				'status' => 'orphaned',
				'notes'  => $notes,
			);
		}

		// Check if file exists
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$notes[] = sprintf(
				/* translators: %s is the file path */
				__( 'Physical file is missing from server: %s', 'media-sweep' ),
				$file_path
			);
			return array(
				'status' => 'orphaned',
				'notes'  => $notes,
			);
		}

		// Collect all usage notes first
		$is_used = false;

		// Check if it's a featured image
		$featured_notes = Database_Query_Helper::check_featured_image_usage( $attachment_id );
		if ( ! empty( $featured_notes ) ) {
			$notes   = array_merge( $notes, $featured_notes );
			$is_used = true;
		}

		// Check if used in posts/pages content
		$content_notes = $this->is_used_in_content( $attachment_id, $options );
		if ( ! empty( $content_notes ) ) {
			$notes   = array_merge( $notes, $content_notes );
			$is_used = true;
		}

		// Check if used in custom fields
		if ( ! empty( $options['check_custom_fields'] ) ) {
			$attachment_url     = wp_get_attachment_url( $attachment_id );
			$search_patterns    = $this->get_attachment_search_patterns( $attachment_id, $attachment_url );
			$custom_field_notes = Database_Query_Helper::check_custom_field_usage( $attachment_id, $search_patterns );
			if ( ! empty( $custom_field_notes ) ) {
				$notes   = array_merge( $notes, $custom_field_notes );
				$is_used = true;
			}
		}

		// Check if used in gallery shortcodes
		if ( ! empty( $options['check_shortcodes'] ) ) {
			$shortcode_notes = $this->is_used_in_gallery_shortcode( $attachment_id );
			if ( ! empty( $shortcode_notes ) ) {
				$notes   = array_merge( $notes, $shortcode_notes );
				$is_used = true;
			}
		}

		// Check if used in gallery blocks
		if ( ! empty( $options['check_blocks'] ) ) {
			$block_notes = $this->is_used_in_gallery_block( $attachment_id );
			if ( ! empty( $block_notes ) ) {
				$notes   = array_merge( $notes, $block_notes );
				$is_used = true;
			}
		}

		// Deep scan - check additional tables if enabled
		if ( ! empty( $options['deep_scan'] ) ) {
			$attachment_url  = wp_get_attachment_url( $attachment_id );
			$search_patterns = $this->get_attachment_search_patterns( $attachment_id, $attachment_url );
			$deep_scan_notes = Database_Query_Helper::deep_scan_for_file_usage( $search_patterns, true );
			if ( ! empty( $deep_scan_notes ) ) {
				$notes   = array_merge( $notes, $deep_scan_notes );
				$is_used = true;
			}
		}

		// For media scan: return in_use or unused based on collected usage
		if ( $is_used ) {
			return array(
				'status' => 'in_use',
				'notes'  => $notes,
			);
		}

		$notes[] = __( 'No usage found in database', 'media-sweep' );
		return array(
			'status' => 'unused',
			'notes'  => $notes,
		);
	}

	/**
	 * Check if attachment is used in content
	 *
	 * @param int   $attachment_id The attachment ID
	 * @param array $options       Scan options
	 * @return array Array of notes if used in content, empty array otherwise
	 */
	protected function is_used_in_content( $attachment_id, $options = array() ) {
		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( ! $attachment_url ) {
			return array();
		}

		// Get all search patterns for this attachment
		$search_patterns = $this->get_attachment_search_patterns( $attachment_id, $attachment_url );

		// Use base class method for content checking
		return $this->check_content_usage_by_patterns( $search_patterns );
	}

	/**
	 * Get all search patterns for an attachment
	 *
	 * @param int    $attachment_id The attachment ID
	 * @param string $attachment_url The attachment URL
	 * @return array Array of search patterns
	 */
	protected function get_attachment_search_patterns( $attachment_id, $attachment_url ) {
		$patterns = array();

		// Add main URL and ID
		$patterns[] = $attachment_url;

		$file_path = get_attached_file( $attachment_id );
		if ( $file_path ) {
			$patterns = array_merge( $patterns, $this->get_file_search_patterns( $file_path ) );
		}

		// Add thumbnail patterns
		$thumbnail_patterns = $this->get_thumbnail_search_patterns( $attachment_id );
		$patterns           = array_merge( $patterns, $thumbnail_patterns );

		// Remove duplicates and empty patterns
		$patterns = array_unique( array_filter( $patterns ) );

		return $patterns;
	}

	/**
	 * Get search patterns for thumbnail sizes
	 *
	 * @param int $attachment_id The attachment ID
	 * @return array Array of thumbnail search patterns
	 */
	protected function get_thumbnail_search_patterns( $attachment_id ) {
		$patterns = array();

		// Get all registered image sizes
		$image_sizes   = get_intermediate_image_sizes();
		$image_sizes[] = 'full'; // Include full size

		foreach ( $image_sizes as $size ) {
			$thumb_url = wp_get_attachment_image_url( $attachment_id, $size );
			if ( $thumb_url && $thumb_url !== wp_get_attachment_url( $attachment_id ) ) {
				$patterns[] = $thumb_url;
				// Also add the filename from the URL
				$patterns[] = basename( $thumb_url );
			}
		}

		return $patterns;
	}

	/**
	 * Check if attachment is used in gallery shortcodes
	 *
	 * @param int $attachment_id The attachment ID
	 * @return array Array of notes if used in gallery shortcodes, empty array otherwise
	 */
	protected function is_used_in_gallery_shortcode( $attachment_id ) {
		global $wpdb;

		// Search for gallery shortcodes that might contain this attachment (excluding attachment post type)
		$posts_with_galleries = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts} 
			 WHERE post_type != 'attachment' 
			 AND post_content LIKE '%[gallery%' 
			 AND post_status IN ('publish', 'private', 'draft')"
		);

		$notes = array();
		foreach ( $posts_with_galleries as $post ) {
			if ( $this->post_contains_attachment_in_shortcodes( $post->post_content, $attachment_id ) ) {
				$notes[] = $this->create_usage_note( 'gallery_shortcode', $post->ID );
			}
		}

		return $notes;
	}

	/**
	 * Check if post content contains attachment in shortcodes
	 *
	 * @param string $content       Post content
	 * @param int    $attachment_id Attachment ID
	 * @return bool
	 */
	protected function post_contains_attachment_in_shortcodes( $content, $attachment_id ) {
		// Find all shortcodes in content
		if ( ! preg_match_all( '/\[([^\]]+)\]/', $content, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $shortcode_content ) {
			// Parse shortcode attributes
			$attributes = $this->parse_shortcode_attributes( $shortcode_content );

			// Check common attributes that might contain attachment IDs
			foreach ( array( 'ids', 'include', 'id' ) as $attr ) {
				if ( isset( $attributes[ $attr ] ) && $this->attribute_contains_attachment_id( $attributes[ $attr ], $attachment_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Parse shortcode attributes
	 *
	 * @param string $attributes_string Shortcode attributes string
	 * @return array
	 */
	protected function parse_shortcode_attributes( $attributes_string ) {
		$attrs = array();

		if ( preg_match_all( '/(\w+)=["\']([^"\']*)["\']/', $attributes_string, $matches ) ) {
			for ( $i = 0; $i < count( $matches[1] ); $i++ ) {
				$attrs[ $matches[1][ $i ] ] = $matches[2][ $i ];
			}
		}

		return $attrs;
	}

	/**
	 * Check if attribute contains attachment ID
	 *
	 * @param string $attr_value    Attribute value
	 * @param int    $attachment_id Attachment ID
	 * @return bool
	 */
	protected function attribute_contains_attachment_id( $attr_value, $attachment_id ) {
		// Split by comma and check each ID
		$ids = array_map( 'trim', explode( ',', $attr_value ) );
		return in_array( (string) $attachment_id, $ids );
	}

	/**
	 * Check if attachment is used in gallery blocks
	 *
	 * @param int $attachment_id The attachment ID
	 * @return array Array of notes if used in gallery blocks, empty array otherwise
	 */
	protected function is_used_in_gallery_block( $attachment_id ) {
		global $wpdb;

		// Search for gallery blocks that contain this attachment ID
		$query = "SELECT ID FROM {$wpdb->posts} 
				  WHERE post_type != 'attachment' 
				  AND post_content LIKE %s 
				  AND post_status IN ('publish', 'private', 'draft')";

		$notes = $this->execute_query_and_collect_notes(
			$query,
			array( '%"id":' . $attachment_id . '%' ),
			'gallery_block'
		);

		return $notes;
	}

	/**
	 * Process attachment thumbnails
	 *
	 * @param int    $scan_id           The scan ID
	 * @param int    $attachment_id     The attachment ID
	 * @param string $parent_status     Parent file status
	 * @param array  $parent_notes      Parent file notes
	 * @param array  $processed_file_ids Reference to processed file IDs array
	 */
	protected function process_attachment_thumbnails( $scan_id, $attachment_id, $parent_status, $parent_notes = array(), &$processed_file_ids = array() ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata['sizes'] ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$base_path  = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );

		foreach ( $metadata['sizes'] as $size => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}

			$thumb_path = $base_path . $size_data['file'];

			if ( ! file_exists( $thumb_path ) ) {
				continue;
			}

			// Create file record for thumbnail
			$thumb_file = $this->get_or_create_file_record( $thumb_path, null, $attachment_id );

			if ( $thumb_file && ! in_array( $thumb_file->id, $processed_file_ids ) ) {
				$thumb_notes = array_merge(
					array(
						sprintf(
																/* translators: %1$s is the thumbnail size (e.g., medium, large), %2$d is the attachment ID */
							__( 'Thumbnail (%1$s) of attachment ID %2$d', 'media-sweep' ),
							$size,
							$attachment_id
						),
					),
					$parent_notes
				);

				File_Scan_Model::create(
					array(
						'scan_id'    => $scan_id,
						'file_id'    => $thumb_file->id,
						'status'     => $parent_status,
						'notes'      => $thumb_notes,
						'created_at' => current_time( 'mysql' ),
					)
				);

				// Track this thumbnail file as processed
				$processed_file_ids[] = $thumb_file->id;
			}
		}
	}

	/**
	 * Get the scanner type identifier
	 *
	 * @return string
	 */
	public function get_scanner_type() {
		return 'media_library';
	}
}
