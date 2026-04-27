<?php
/**
 * Action Service - Handle file actions like delete and restore in batches
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Models\File_Model;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Services\System_Monitor_Service;
use Media_Sweep\Utils\Path_Helper;
use Media_Sweep\Utils\Trash_Helper;

/**
 * Action Service class - Stateless batch processing
 */
class Action_Service {

	/**
	 * System monitor instance
	 *
	 * @var System_Monitor_Service
	 */
	protected $system_monitor;

	/**
	 * Constructor
	 *
	 * @param System_Monitor_Service $system_monitor System monitor instance
	 */
	public function __construct( System_Monitor_Service $system_monitor ) {
		$this->system_monitor = $system_monitor;
	}

	/**
	 * Get file scan IDs for batch processing
	 *
	 * @param int        $scan_id Scan ID
	 * @param string     $status  Optional status filter
	 * @param array|null $ids     Optional specific IDs
	 * @return array Array of file scan IDs
	 */
	public function get_batch_files( $scan_id, $status = null, $ids = null ) {
		$query = File_Scan_Model::where( 'scan_id', '=', $scan_id );

		if ( $ids ) {
			$query->where_in( 'id', $ids );
		} elseif ( $status ) {
			$query->where( 'status', '=', $status );
		}

		return $query->pluck( 'id' );
	}

	/**
	 * Process a batch of files
	 *
	 * @param string $action         Action type (delete|restore)
	 * @param array  $file_scan_ids  Array of file scan IDs to process
	 * @param int    $start_index    Start index for this batch
	 * @param int    $batch_size     Number of files to process in this batch
	 * @return array
	 */
	public function process_batch( $action, $file_scan_ids, $start_index = 0, $batch_size = 10 ) {
		// Reset system monitor
		$this->system_monitor->reset_monitoring();

		$total_files = count( $file_scan_ids );

		$result = array(
			'success'       => true,
			'processed'     => 0,
			'success_count' => 0,
			'errors'        => 0,
			'skipped'       => 0,
			'should_pause'  => false,
			'pause_reason'  => '',
			'is_complete'   => false,
			'resume_index'  => $start_index,
			'total'         => $total_files,
			'error_details' => array(),
		);

		// Check if we should pause initially
		$should_pause = $this->system_monitor->should_pause();
		if ( $should_pause['pause'] ) {
			$result['should_pause'] = true;
			$result['pause_reason'] = $should_pause['reason'];
			return $result;
		}

		// Get batch of file scan IDs to process
		$batch_ids = array_slice( $file_scan_ids, $start_index, $batch_size );

		foreach ( $batch_ids as $index => $file_scan_id ) {
			$current_index = $start_index + $index;

			// Get file scan record
			$file_scan = File_Scan_Model::find( $file_scan_id );
			if ( ! $file_scan ) {
				++$result['errors'];
				++$result['processed'];
				$result['resume_index']    = $current_index + 1;
				$result['error_details'][] = sprintf(
					/* translators: %d is the file scan ID */
					__( 'File scan ID %d not found', 'media-sweep' ),
					$file_scan_id
				);
				continue;
			}

			// Load file relationship
			$file_scan->load( array( 'file' => array( 'columns' => array( '*' ) ) ) );
			$file = $file_scan->file;

			if ( ! $file ) {
				++$result['errors'];
				++$result['processed'];
				$result['resume_index']    = $current_index + 1;
				$result['error_details'][] = sprintf(
					/* translators: %d is the file scan ID */
					__( 'File record not found for scan ID %d', 'media-sweep' ),
					$file_scan_id
				);
				continue;
			}

			// Process the file based on action type
			$process_result = $this->process_single_file( $action, $file, $file_scan );

			if ( $process_result['success'] ) {
				++$result['success_count'];
			} elseif ( $process_result['skipped'] ) {
				++$result['skipped'];
			} else {
				++$result['errors'];
				$result['error_details'][] = $process_result['error'];
			}

			++$result['processed'];
			$result['resume_index'] = $current_index + 1;

			// Check if we should pause every 5 files
			if ( $result['processed'] % 5 === 0 ) {
				$should_pause = $this->system_monitor->should_pause();
				if ( $should_pause['pause'] ) {
					$result['should_pause'] = true;
					$result['pause_reason'] = $should_pause['reason'];
					break;
				}
			}
		}

		// Check if complete
		if ( $result['resume_index'] >= $total_files ) {
			$result['is_complete'] = true;
		}

		return $result;
	}

	/**
	 * Process a single file
	 *
	 * @param string          $action_type Action type
	 * @param File_Model      $file        File model
	 * @param File_Scan_Model $file_scan   File scan model
	 * @return array
	 */
	protected function process_single_file( $action_type, $file, $file_scan ) {
		$result = array(
			'success' => false,
			'skipped' => false,
			'error'   => '',
		);

		try {
			if ( 'delete' === $action_type ) {
				return $this->delete_file( $file, $file_scan );
			} elseif ( 'restore' === $action_type ) {
				return $this->restore_file( $file, $file_scan );
			}

			$result['error'] = sprintf(
				/* translators: %s is the action type */
				__( 'Unknown action type: %s', 'media-sweep' ),
				$action_type
			);
			return $result;

		} catch ( \Exception $e ) {
			$result['error'] = sprintf(
				/* translators: %1$s is the file path, %2$s is the error message */
				__( 'Error processing file %1$s: %2$s', 'media-sweep' ),
				$file->filepath,
				$e->getMessage()
			);
			return $result;
		}
	}

	/**
	 * Delete a file (move to trash)
	 *
	 * @param File_Model      $file      File model
	 * @param File_Scan_Model $file_scan File scan model
	 * @return array
	 */
	protected function delete_file( $file, $file_scan ) {
		$result = array(
			'success' => false,
			'skipped' => false,
			'error'   => '',
		);

		// Skip if file is already processed (trashed or deleted)
		if ( in_array( $file->status, array( 'trashed', 'deleted' ) ) ) {
			$result['skipped'] = true;
			return $result;
		}

		// Only allow deletion of certain file scan statuses
		$deletable_statuses = array( 'not_in_media', 'orphaned', 'unused' );
		if ( ! in_array( $file_scan->status, $deletable_statuses ) ) {
			$result['error'] = sprintf(
				/* translators: %s is the file status */
				__( 'Cannot delete file with status "%s". Only files with status "not_in_media", "orphaned", or "unused" can be deleted.', 'media-sweep' ),
				$file_scan->status
			);
			return $result;
		}

		$file_path = $file->get_absolute_path();

		// Check if file exists on filesystem
		if ( ! file_exists( $file_path ) ) {
			// File doesn't exist, just mark as deleted in database
			$file->update( array( 'status' => 'deleted' ) );
			$result['success'] = true;
			return $result;
		}

		// Skip if file is already in trash
		if ( Trash_Helper::is_trash_path( $file_path ) ) {
			$result['skipped'] = true;
			return $result;
		}

		// Move file to trash instead of permanent deletion
		$trash_result = Trash_Helper::move_to_trash( $file_path );

		if ( $trash_result['success'] ) {
			// Update file status to trashed and store new path
			$relative_trash_path = Trash_Helper::get_relative_trash_path( $trash_result['new_path'] );

			$file->update(
				array(
					'status'   => 'trashed',
					'filepath' => $relative_trash_path,
				)
			);

			// If this file has an attachment_id, delete the attachment from WordPress
			// This will also permanently delete all thumbnails
			if ( $file->attachment_id ) {
				// First, delete all thumbnail file records and their scan records from our database
				$this->delete_thumbnail_records_for_media( $file->attachment_id );

				// Then delete the WordPress attachment (this deletes thumbnails from filesystem)
				wp_delete_attachment( $file->attachment_id, true );
			}

			// Delete all file_scan records associated with this main file
			// This ensures the scan results don't show files that have been trashed
			File_Scan_Model::where( 'file_id', '=', $file->id )->delete();

			$result['success'] = true;
		} else {
			$result['error'] = $trash_result['error'];
		}

		return $result;
	}

	/**
	 * Restore a file (only for not_in_media files)
	 *
	 * @param File_Model      $file      File model
	 * @param File_Scan_Model $file_scan File scan model
	 * @return array
	 */
	protected function restore_file( $file, $file_scan ) {
		$result = array(
			'success' => false,
			'skipped' => false,
			'error'   => '',
		);

		// Only restore files that are not_in_media
		if ( 'not_in_media' !== $file_scan->status ) {
			$result['skipped'] = true;
			return $result;
		}

		// Skip if file is already active (restored)
		if ( $file->status === 'active' ) {
			$result['skipped'] = true;
			return $result;
		}

		// Skip if file is deleted (can't restore deleted files)
		if ( 'deleted' === $file->status ) {
			$result['error'] = sprintf(
				/* translators: %s is the file status */
				__( 'Cannot restore deleted file', 'media-sweep' ),
				$file->status
			);
			return $result;
		}

		$file_path = $file->get_absolute_path();

		// If file is in trash, restore it to filesystem first
		if ( 'trashed' === $file->status && Trash_Helper::is_trash_path( $file_path ) ) {
			$restore_result = Trash_Helper::restore_from_trash( $file_path );

			if ( ! $restore_result['success'] ) {
				$result['error'] = $restore_result['error'];
				return $result;
			}

			// Update file path to restored location
			$file_path     = $restore_result['restore_path'];
			$relative_path = Path_Helper::ensure_relative_path( $file_path );

			$file->update(
				array(
					'filepath' => $relative_path,
					'status'   => 'active',
				)
			);
		}

		// Check if file exists on filesystem
		if ( ! file_exists( $file_path ) ) {
			$result['error'] = sprintf(
				/* translators: %s is the file path */
				__( 'Cannot restore file - physical file missing: %s', 'media-sweep' ),
				$file->filepath
			);
			return $result;
		}

		// For not_in_media files, try to add them to the media library
		// This is the main purpose of "restore" - to add files back to media library
		$media_library_result = $this->add_file_to_media_library( $file_path );

		if ( $media_library_result['success'] ) {
			// Successfully added to media library
			// WordPress may have moved the file to a new location (e.g., uploads/2025/01/)
			$new_relative_path = Path_Helper::ensure_relative_path( $media_library_result['new_path'] );
			
			$file->update(
				array(
					'filepath'      => $new_relative_path,
					'attachment_id' => $media_library_result['attachment_id'],
					'status'        => 'active',
				)
			);

			// Update file scan status to in_media since it's now in the media library
			// Ensure notes is properly handled as an array
			$current_notes = $file_scan->notes;
			if ( ! is_array( $current_notes ) ) {
				// Handle cases where notes might be a string or null
				$current_notes = ! empty( $current_notes ) ? array( $current_notes ) : array();
			}
			// Add a note about the restoration
			$current_notes[] = __( 'File restored to media library', 'media-sweep' );

			$file_scan->update(
				array(
					'status' => 'in_media',
					'notes'  => $current_notes,
				)
			);

			$result['success'] = true;
		} else {
			// Check if it's a file type restriction issue
			if ( $media_library_result['reason'] === 'file_type_not_allowed' ) {
				// For non-media files (like .woff2), just restore to filesystem without adding to media library
				$file->update( array( 'status' => 'active' ) );
				$result['success'] = true;
			} else {
				// Actual failure
				$result['error'] = $media_library_result['error'];
			}
		}

		return $result;
	}

	/**
	 * Add file to media library with detailed error handling
	 *
	 * @param string $file_path File path
	 * @return array Result with success status, attachment_id, new_path, error, and reason
	 */
	protected function add_file_to_media_library( $file_path ) {
		$result = array(
			'success'       => false,
			'attachment_id' => null,
			'new_path'      => null,
			'error'         => '',
			'reason'        => '',
		);

		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Check if file is an allowed type
		$file_type = wp_check_filetype( basename( $file_path ) );
		if ( ! $file_type['type'] ) {
			$result['error'] = sprintf(
				/* translators: %s is the file type */
				__( 'File type not allowed by WordPress: %s', 'media-sweep' ),
				pathinfo( $file_path, PATHINFO_EXTENSION )
			);
			$result['reason'] = 'file_type_not_allowed';
			return $result;
		}

		// Check if file exists
		if ( ! file_exists( $file_path ) ) {
			$result['error'] = sprintf(
				/* translators: %s is the file path */
				__( 'File does not exist on filesystem: %s', 'media-sweep' ),
				$file_path
			);
			$result['reason'] = 'file_not_found';
			return $result;
		}

		// Prepare file array for wp_handle_sideload
		$file_array = array(
			'name'     => basename( $file_path ),
			'type'     => $file_type['type'],
			'tmp_name' => $file_path,
			'error'    => 0,
			'size'     => filesize( $file_path ),
		);

		// Handle the sideload
		$sideload = wp_handle_sideload( $file_array, array( 'test_form' => false ) );

		if ( isset( $sideload['error'] ) ) {
			$result['error']  = $sideload['error'];
			$result['reason'] = 'sideload_failed';
			return $result;
		}

		// Create attachment
		$attachment = array(
			'post_mime_type' => $sideload['type'],
			'post_title'     => sanitize_file_name( pathinfo( $sideload['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $sideload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			$result['error']  = $attachment_id->get_error_message();
			$result['reason'] = 'attachment_creation_failed';
			return $result;
		}

		// Generate attachment metadata
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $sideload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		$result['success']       = true;
		$result['attachment_id'] = $attachment_id;
		$result['new_path']      = $sideload['file']; // New path after WordPress moves the file
		return $result;
	}


	/**
	 * Get thumbnail count for a media attachment (for warning users)
	 *
	 * @param int $attachment_id Attachment ID
	 * @return int Number of thumbnails
	 */
	public function get_thumbnail_count_for_media( $attachment_id ) {
		return File_Model::where( 'thumb_of', '=', $attachment_id )->count();
	}

	/**
	 * Mark thumbnail file records as deleted when parent media is deleted
	 * Also deletes associated file_scan records
	 *
	 * @param int $attachment_id Parent attachment ID
	 * @return void
	 */
	protected function delete_thumbnail_records_for_media( $attachment_id ) {
		// Find all thumbnail files for this attachment
		$thumbnail_files = File_Model::where( 'thumb_of', '=', $attachment_id )->get();

		foreach ( $thumbnail_files as $thumb_file ) {
			// Delete all file_scan records associated with this thumbnail file
			File_Scan_Model::where( 'file_id', '=', $thumb_file->id )->delete();

			// Mark the file as deleted
			$thumb_file->update( array( 'status' => 'deleted' ) );
		}
	}
}
