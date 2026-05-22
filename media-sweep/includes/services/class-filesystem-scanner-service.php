<?php
/**
 * Filesystem Scanner Service - Smart recursive scanning with pause/resume
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Interfaces\Filesystem_Scanner;
use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Utils\Plugin_Pattern_Helper;
use Media_Sweep\Utils\Database_Query_Helper;
use Media_Sweep\Utils\File_Type_Helper;
use Media_Sweep\Utils\Trash_Helper;

/**
 * Filesystem Scanner Service class
 */
class Filesystem_Scanner_Service extends Base_Scanner_Service implements Filesystem_Scanner {

	/**
	 * Default scan options
	 *
	 * @var array
	 */
	protected $default_options = array(
		'check_content_usage' => true,  // Check if file is used in content
		'include_regex'       => '',    // Regex pattern to include files
		'exclude_regex'       => '',    // Regex pattern to exclude files
		'exclude_cache_dirs'  => true,  // Exclude common cache directories
		'exclude_backup_dirs' => true,  // Exclude common backup directories
		'check_theme_plugin'  => true,  // Check if file is used by theme or plugin
		'deep_scan'           => true,  // Scan all database tables for file usage
	);

	/**
	 * Memory threshold for pausing (75%)
	 *
	 * @var float
	 */
	protected $memory_threshold = 0.75;

	/**
	 * Time threshold for pausing (75%)
	 *
	 * @var float
	 */
	protected $time_threshold = 0.75;

	/**
	 * Start a new filesystem scan
	 *
	 * @param array $options Scan options
	 * @return Scan_Model|false
	 */
	public function start_scan( $options = array() ) {
		// Merge with default options
		$options = wp_parse_args( $options, $this->default_options );

		// Reset system monitor for this scan
		$this->system_monitor->reset_monitoring();
		$this->system_monitor->set_memory_threshold( $this->memory_threshold );
		$this->system_monitor->set_time_threshold( $this->time_threshold );

		// Create scan record
		$scan = Scan_Model::create(
			array(
				'mode'       => 'file_system',
				'status'     => 'running',
				'started_at' => current_time( 'mysql' ),
				'options'    => $options,
			)
		);

		return $scan;
	}

	/**
	 * Get main folder structure - Step 1: Get initial folder structure
	 *
	 * @param int   $scan_id Scan ID
	 * @param array $options Scan options for filtering
	 * @return array Main folder structure
	 */
	public function get_main_folder_structure( $scan_id, $options = array() ) {
		$options    = wp_parse_args( $options, $this->default_options );
		$upload_dir = wp_upload_dir();
		$base_path  = $upload_dir['basedir'];

		$result = array(
			'folders'      => array(),
			'files'        => array(),
			'should_pause' => false,
			'pause_reason' => '',
		);

		// Check if we should pause
		$should_pause = $this->system_monitor->should_pause();
		if ( $should_pause['pause'] ) {
			$result['should_pause'] = true;
			$result['pause_reason'] = $should_pause['reason'];
			return $result;
		}

		try {
			$iterator = new \DirectoryIterator( $base_path );

			foreach ( $iterator as $file_info ) {
				if ( $file_info->isDot() ) {
					continue;
				}

				$item_name = $file_info->getFilename();
				$item_path = $item_name; // relative to uploads

				if ( $file_info->isDir() ) {
					// Skip trash folder
					if ( $item_name === Trash_Helper::TRASH_FOLDER ) {
						continue;
					}

					$result['folders'][] = array(
						'name' => $item_name,
						'path' => $item_path,
					);
				} else {
					// Apply regex filtering during discovery phase
					if ( ! File_Type_Helper::should_exclude_item( $item_path, $item_name, $options ) ) {
						$result['files'][] = array(
							'name' => $item_name,
							'path' => $item_path,
							'size' => $file_info->getSize(),
							'type' => File_Type_Helper::get_file_type_category( $file_info->getPathname() ),
						);
					}
				}

				// Check if we should pause periodically
				$should_pause = $this->system_monitor->should_pause();
				if ( $should_pause['pause'] ) {
					$result['should_pause'] = true;
					$result['pause_reason'] = $should_pause['reason'];
					break;
				}
			}
		} catch ( \Exception $e ) {
			$result['error'] = sprintf(
				/* translators: %s is the error message */
				__( 'Error reading main directory: %s', 'media-sweep' ),
				$e->getMessage()
			);
		}

		return $result;
	}

	/**
	 * Get folder content recursively - Step 2: Process each folder completely
	 *
	 * @param int    $scan_id     Scan ID
	 * @param string $folder_path Folder path relative to uploads directory
	 * @param string $resume_from Optional subfolder to resume from
	 * @param array  $options     Scan options for filtering
	 * @return array Folder content with all subfolders processed recursively
	 */
	public function get_folder_content_recursive( $scan_id, $folder_path, $resume_from = '', $options = array() ) {
		$options    = wp_parse_args( $options, $this->default_options );
		$upload_dir = wp_upload_dir();
		$base_path  = $upload_dir['basedir'];
		$full_path  = $base_path . '/' . ltrim( $folder_path, '/' );

		$result = array(
			'files'           => array(),
			'processed_paths' => array(),
			'should_pause'    => false,
			'pause_reason'    => '',
			'resume_folder'   => '',
		);

		// Check if we should pause initially
		$should_pause = $this->system_monitor->should_pause();
		if ( $should_pause['pause'] ) {
			$result['should_pause']  = true;
			$result['pause_reason']  = $should_pause['reason'];
			$result['resume_folder'] = $resume_from ?: $folder_path;
			return $result;
		}

		try {
			$this->scan_directory_recursive( $full_path, $folder_path, $result, $resume_from, $options );
		} catch ( \Exception $e ) {
			$result['error'] = sprintf(
				/* translators: %1$s is the folder path, %2$s is the error message */
				__( 'Error scanning folder %1$s: %2$s', 'media-sweep' ),
				$folder_path,
				$e->getMessage()
			);
		}

		return $result;
	}

	/**
	 * Recursively scan directory and all subdirectories
	 *
	 * @param string $full_path   Absolute path to directory
	 * @param string $folder_path Relative path from uploads
	 * @param array  &$result     Result array to populate
	 * @param string $resume_from Optional subfolder to resume from
	 * @param array  $options     Scan options for filtering
	 */
	protected function scan_directory_recursive( $full_path, $folder_path, &$result, $resume_from = '', $options = array() ) {
		if ( ! is_dir( $full_path ) ) {
			return;
		}

		// If we're resuming and haven't reached the resume point yet, skip
		if ( $resume_from && $folder_path !== $resume_from && strpos( $resume_from, $folder_path ) !== 0 ) {
			return;
		}

		try {
			$iterator       = new \DirectoryIterator( $full_path );
			$subdirectories = array();

			// First pass: collect files and subdirectories
			foreach ( $iterator as $file_info ) {
				if ( $file_info->isDot() ) {
					continue;
				}

				$item_name = $file_info->getFilename();
				$item_path = $folder_path ? $folder_path . '/' . $item_name : $item_name;

				if ( $file_info->isDir() ) {
					// Skip trash folder and any subdirectories within it
					if ( $item_name === Trash_Helper::TRASH_FOLDER ||
						strpos( $item_path, Trash_Helper::TRASH_FOLDER ) === 0 ) {
						continue;
					}

					$subdirectories[] = array(
						'name'      => $item_name,
						'path'      => $item_path,
						'full_path' => $file_info->getPathname(),
					);
				} else {
					// Apply regex filtering during discovery phase
					if ( ! File_Type_Helper::should_exclude_item( $item_path, $item_name, $options ) ) {
						$result['files'][] = array(
							'name' => $item_name,
							'path' => $item_path,
							'size' => $file_info->getSize(),
							'type' => File_Type_Helper::get_file_type_category( $file_info->getPathname() ),
						);
					}
				}

				// Check resources every 50 items
				if ( count( $result['files'] ) % 50 === 0 && $this->should_pause() ) {
					$pause_info              = $this->get_pause_info();
					$result['should_pause']  = true;
					$result['pause_reason']  = $pause_info['reason'];
					$result['resume_folder'] = $folder_path;
					$result['resources']     = $pause_info['resources'];
					return;
				}
			}

			// Mark this directory as processed
			$result['processed_paths'][] = $folder_path;

			// Second pass: recursively scan subdirectories
			foreach ( $subdirectories as $subdir ) {
				// Skip if we're resuming and haven't reached this subfolder yet
				if ( $resume_from && $subdir['path'] !== $resume_from && strpos( $resume_from, $subdir['path'] ) !== 0 ) {
					continue;
				}

				$this->scan_directory_recursive( $subdir['full_path'], $subdir['path'], $result, $resume_from, $options );

				// Check if we should pause after processing each subdirectory
				if ( $result['should_pause'] ) {
					return;
				}
			}
		} catch ( \Exception $e ) {
			// Log error but continue
		}
	}

	/**
	 * Process file batch with pause/resume capability
	 *
	 * @param int   $scan_id    The scan ID
	 * @param array $file_paths Array of relative file paths
	 * @param array $options    Scan options
	 * @return array Processing results with pause information
	 */
	public function process_file_batch( $scan_id, $file_paths, $options = array() ) {
		$options    = wp_parse_args( $options, $this->default_options );
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		$result = array(
			'success'      => true,
			'processed'    => 0,
			'errors'       => 0,
			'skipped'      => 0,
			'should_pause' => false,
			'pause_reason' => '',
			'total_files'  => count( $file_paths ),
		);

		// Check if we should pause initially
		$should_pause = $this->system_monitor->should_pause();
		if ( $should_pause['pause'] ) {
			$result['should_pause'] = true;
			$result['pause_reason'] = $should_pause['reason'];
			return $result;
		}

		foreach ( $file_paths as $index => $relative_path ) {

			$full_path = $base_dir . '/' . ltrim( $relative_path, '/' );

			// Check if file exists
			if ( ! file_exists( $full_path ) ) {
				++$result['errors'];
				++$result['processed'];
				continue;
			}

			// Check if item should be excluded or is in trash
			if ( File_Type_Helper::should_exclude_item( $relative_path, basename( $full_path ), $options ) ||
				Trash_Helper::is_trash_path( $full_path ) ) {
				++$result['skipped'];
				++$result['processed'];
				continue;
			}

			// Get media relationships (attachment_id and thumb_of)
			$media_relationships = $this->get_media_relationships( $full_path );

			// Get or create file record with media relationships
			$file = $this->get_or_create_file_record(
				$full_path,
				$media_relationships['attachment_id'],
				$media_relationships['thumb_of']
			);
			if ( ! $file ) {
				++$result['errors'];
				++$result['processed'];
				continue;
			}

			// Check if this file has already been processed in this scan
			$existing_scan = File_Scan_Model::where( 'scan_id', '=', $scan_id )
				->where( 'file_id', '=', $file->id )
				->first();

			if ( ! $existing_scan ) {
				// Check file status
				$status_result = $this->check_file_status( $full_path, $options, $media_relationships );

				// Create file scan record. try/catch keeps a single bad row
				// (e.g. a wpdb edge case) from aborting the whole batch.
				try {
					File_Scan_Model::create(
						array(
							'scan_id'    => $scan_id,
							'file_id'    => $file->id,
							'status'     => $status_result['status'],
							'notes'      => $this->cap_notes( $status_result['notes'] ),
							'created_at' => current_time( 'mysql' ),
						)
					);
				} catch ( \Exception $e ) {
					++$result['errors'];
				}
			} else {
				// File already processed, count as skipped
				++$result['skipped'];
			}

			++$result['processed'];

			// Check if we should pause every 10 files
			if ( $result['processed'] % 10 === 0 ) {
				$should_pause = $this->system_monitor->should_pause();
				if ( $should_pause['pause'] ) {
					$result['should_pause'] = true;
					$result['pause_reason'] = $should_pause['reason'];
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Check if processing should pause based on resource thresholds
	 *
	 * @return bool True if should pause
	 */
	protected function should_pause() {
		return ! $this->system_monitor->is_safe_to_continue();
	}

	/**
	 * Get pause information
	 *
	 * @return array Pause information with reason and resources
	 */
	protected function get_pause_info() {
		$resources = $this->system_monitor->check_system_resources();
		$warning   = $this->system_monitor->get_resource_warning();

		return array(
			'reason'    => $warning ?: __( 'Resource threshold reached', 'media-sweep' ),
			'resources' => $resources,
		);
	}

	/**
	 * Check file status in filesystem scan
	 *
	 * @param string $file_path The full file path
	 * @param array  $options   Scan options
	 * @param array  $media_relationships Media relationships (attachment_id, thumb_of)
	 * @return array Array with 'status' and 'notes'
	 */
	protected function check_file_status( $file_path, $options, $media_relationships = array() ) {
		$notes = array();

		// Check if it's in media library
		$attachment_id = $this->get_attachment_id_from_path( $file_path );
		if ( $attachment_id ) {
			$notes[] = sprintf(
				/* translators: %d is the attachment ID */
				__( 'Found in media library (ID: %d)', 'media-sweep' ),
				$attachment_id
			);

			// Check if it's used as featured image
			$featured_notes = Database_Query_Helper::check_featured_image_usage( $attachment_id );
			$notes          = array_merge( $notes, $featured_notes );

			// Check content usage
			$content_notes      = array();
			$custom_field_notes = array();
			if ( ! empty( $options['check_content_usage'] ) ) {
				$search_patterns = $this->get_file_search_patterns( $file_path );
				$content_notes   = $this->check_content_usage_by_patterns( $search_patterns );
				$notes           = array_merge( $notes, $content_notes );

				// Also check custom fields for media library files
				$custom_field_notes = Database_Query_Helper::check_custom_field_usage( $attachment_id, $search_patterns );
				$notes              = array_merge( $notes, $custom_field_notes );
			}

			// For filesystem scan: files in media library are always "in_media" regardless of usage
			return array(
				'status' => 'in_media',
				'notes'  => $notes,
			);
		}

		// Check if it's a thumbnail of a media attachment
		if ( ! empty( $media_relationships['thumb_of'] ) ) {
			$parent_attachment_id = $media_relationships['thumb_of'];
			$notes[]              = sprintf(
				/* translators: %d is the parent attachment ID */
				__( 'Thumbnail of media attachment (ID: %d)', 'media-sweep' ),
				$parent_attachment_id
			);

			// Verify the parent attachment still exists
			if ( get_post( $parent_attachment_id ) ) {
				return array(
					'status' => 'in_use',
					'notes'  => $notes,
				);
			} else {
				$notes[] = sprintf(
					/* translators: %d is the parent attachment ID */
					__( 'Parent media attachment no longer exists (ID: %d)', 'media-sweep' ),
					$parent_attachment_id
				);
				return array(
					'status' => 'orphaned',
					'notes'  => $notes,
				);
			}
		}

		// Check if it's used in content
		if ( ! empty( $options['check_content_usage'] ) ) {
			$search_patterns = $this->get_file_search_patterns( $file_path );
			$content_notes   = $this->check_content_usage_by_patterns( $search_patterns );
			if ( ! empty( $content_notes ) ) {
				$notes = array_merge( $notes, $content_notes );
				return array(
					'status' => 'not_in_media',
					'notes'  => $notes,
				);
			}
		}

		// Check if it's used by themes or plugins (prioritize this over cache detection)
		$theme_plugin_notes = Plugin_Pattern_Helper::check_theme_plugin_usage( $file_path );
		if ( ! empty( $theme_plugin_notes ) ) {
			$notes = array_merge( $notes, $theme_plugin_notes );
			return array(
				'status' => 'not_in_media',
				'notes'  => $notes,
			);
		}

		// Deep scan - check all database tables if enabled
		if ( ! empty( $options['deep_scan'] ) ) {
			$search_patterns = $this->get_file_search_patterns( $file_path );
			$deep_scan_notes = Database_Query_Helper::deep_scan_for_file_usage( $search_patterns );
			if ( ! empty( $deep_scan_notes ) ) {
				$notes = array_merge( $notes, $deep_scan_notes );
				return array(
					'status' => 'not_in_media',
					'notes'  => $notes,
				);
			}
		}

		// Check if it's a generic cache or backup file (only after plugin/theme check)
		$file_type_notes = File_Type_Helper::check_generic_file_type( $file_path );
		if ( ! empty( $file_type_notes ) ) {
			$notes = array_merge( $notes, $file_type_notes );
		}

		// If no usage found, it's orphaned
		$notes[] = __( 'No usage found - appears to be orphaned', 'media-sweep' );
		return array(
			'status' => 'orphaned',
			'notes'  => $notes,
		);
	}

	/**
	 * Get media relationships for a file (attachment_id and thumb_of)
	 *
	 * @param string $file_path The full file path
	 * @return array Array with 'attachment_id' and 'thumb_of' values
	 */
	protected function get_media_relationships( $file_path ) {
		$attachment_id = null;
		$thumb_of      = null;

		// First check if this file is a main attachment
		$main_attachment_id = $this->get_attachment_id_from_path( $file_path );
		if ( $main_attachment_id ) {
			$attachment_id = $main_attachment_id;
			return array(
				'attachment_id' => $attachment_id,
				'thumb_of'      => null,
			);
		}

		// If not a main attachment, check if it's a thumbnail
		$thumb_of = $this->find_parent_attachment_for_thumbnail( $file_path );
		if ( $thumb_of ) {
			return array(
				'attachment_id' => null,
				'thumb_of'      => $thumb_of,
			);
		}

		return array(
			'attachment_id' => null,
			'thumb_of'      => null,
		);
	}

	/**
	 * Find parent attachment ID for a thumbnail file
	 *
	 * @param string $file_path The thumbnail file path
	 * @return int|null Parent attachment ID or null if not a thumbnail
	 */
	protected function find_parent_attachment_for_thumbnail( $file_path ) {
		global $wpdb;

		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );
		$filename      = basename( $file_path );
		$dirname       = dirname( $relative_path );

		// Check if filename matches thumbnail pattern (filename-{width}x{height}.ext)
		if ( ! preg_match( '/^(.+)-(\d+)x(\d+)\.(\w+)$/', $filename, $matches ) ) {
			return null;
		}

		$base_filename = $matches[1];
		$width         = $matches[2];
		$height        = $matches[3];
		$extension     = $matches[4];

		// Look for the parent attachment with the base filename
		$parent_filename = $base_filename . '.' . $extension;
		$parent_path     = $dirname ? $dirname . '/' . $parent_filename : $parent_filename;

		// Find attachment by parent file path
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} 
				 WHERE meta_key = '_wp_attached_file' 
				 AND meta_value = %s",
				$parent_path
			)
		);

		if ( $attachment_id ) {
			// Verify this is actually a thumbnail by checking metadata
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( isset( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_name => $size_data ) {
					if ( isset( $size_data['file'] ) && $size_data['file'] === $filename ) {
						return (int) $attachment_id;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Get attachment ID from file path
	 *
	 * @param string $file_path The file path
	 * @return int|false Attachment ID or false if not found
	 */
	protected function get_attachment_id_from_path( $file_path ) {
		global $wpdb;

		// Get relative path
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );

		// First, check if file is the current attached file (most accurate)
		$attachment = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} 
				 WHERE meta_key = '_wp_attached_file' 
				 AND meta_value = %s",
				$relative_path
			)
		);

		if ( ! empty( $attachment ) ) {
			return (int) $attachment[0];
		}

		// Only use GUID as fallback, but exclude attachments that have a different _wp_attached_file
		// This prevents matching original files when WordPress has created scaled versions
		$file_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );

		$attachment = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment' 
				 AND p.guid = %s
				 AND (pm.meta_value IS NULL OR pm.meta_value = %s)",
				$file_url,
				$relative_path
			)
		);

		if ( ! empty( $attachment ) ) {
			return (int) $attachment[0];
		}

		return false;
	}

	/**
	 * Legacy method for interface compatibility
	 *
	 * @param int   $scan_id Scan ID
	 * @param array $options Scan options
	 * @return array
	 */
	public function get_main_folder_content( $scan_id, $options = array() ) {
		return $this->get_main_folder_structure( $scan_id, $options );
	}

	/**
	 * Legacy method for interface compatibility
	 *
	 * @param int    $scan_id Scan ID
	 * @param string $folder_path Path to scan
	 * @param array  $options Scan options
	 * @return array
	 */
	public function get_folder_content( $scan_id, $folder_path, $options = array() ) {
		return $this->get_folder_content_recursive( $scan_id, $folder_path, '', $options );
	}

	/**
	 * Get the scanner type identifier
	 *
	 * @return string
	 */
	public function get_scanner_type() {
		return 'file_system';
	}
}
