<?php
/**
 * Trash Service - Manages trash directory operations
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Utils\Trash_Helper;
use Media_Sweep\Utils\Filesystem_Helper;
use Media_Sweep\Utils\Path_Helper;
use Media_Sweep\Utils\File_Type_Helper;
use Media_Sweep\Models\File_Model;

/**
 * Trash Service class
 */
class Trash_Service {

	/**
	 * Get trash files with pagination
	 *
	 * @param int   $page     Page number (1-based)
	 * @param int   $per_page Items per page
	 * @param array $filters Optional filters
	 * @return array
	 */
	public function get_trash_files( $page = 1, $per_page = 50, $filters = array() ) {
		$trash_dir = Trash_Helper::get_trash_directory();

		if ( ! file_exists( $trash_dir ) ) {
			return array(
				'files'       => array(),
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => 0,
				'has_more'    => false,
			);
		}

		// Get all trash files
		$all_files = $this->scan_trash_directory( $trash_dir, $filters );

		// Apply pagination
		$total  = count( $all_files );
		$offset = ( $page - 1 ) * $per_page;
		$files  = array_slice( $all_files, $offset, $per_page );

		return array(
			'files'       => $files,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
			'has_more'    => ( $page * $per_page ) < $total,
		);
	}

	/**
	 * Scan trash directory for files
	 *
	 * @param string $trash_dir Trash directory path
	 * @param array  $filters   Optional filters
	 * @return array Array of file information
	 */
	protected function scan_trash_directory( $trash_dir, $filters = array() ) {
		$files = array();

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $trash_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$filename = $file->getFilename();

				// Skip protection files
				if ( Trash_Helper::is_protection_file( $filename ) ) {
					continue;
				}

				$file_path = $file->getPathname();
				$file_info = $this->get_trash_file_info( $file_path, $trash_dir );

				// Apply filters
				if ( $this->should_include_file( $file_info, $filters ) ) {
					$files[] = $file_info;
				}
			}
		} catch ( \Exception $e ) {
			// Handle silently, return empty array
		}

		// Sort by modification time (newest first)
		usort(
			$files,
			function ( $a, $b ) {
				return $b['modified_time'] - $a['modified_time'];
			}
		);

		return $files;
	}

	/**
	 * Get detailed information about a trash file
	 *
	 * @param string $file_path Absolute path to trash file
	 * @param string $trash_dir Trash directory base path
	 * @return array File information
	 */
	protected function get_trash_file_info( $file_path, $trash_dir ) {
		$filename      = basename( $file_path );
		$file_size     = filesize( $file_path );
		$modified_time = filemtime( $file_path );

		// Normalize both sides first: the directory iterator and wp_upload_dir() can report different separators, and a
		// mismatch left this path absolute, which broke every by-ID trash action and printed a server path in the UI.
		$relative_path = str_replace(
			trailingslashit( wp_normalize_path( $trash_dir ) ),
			'',
			wp_normalize_path( $file_path )
		);

		// Determine original path
		$original_path = $relative_path;

		// Get file type information
		$file_extension = pathinfo( $filename, PATHINFO_EXTENSION );
		$file_type      = File_Type_Helper::get_file_type_category( $file_path );

		// Get directory info
		$directory = dirname( $relative_path );
		if ( $directory === '.' ) {
			$directory = '/';
		}

		return array(
			'id'             => md5( $relative_path ), // Unique identifier
			'filename'       => $filename,
			'relative_path'  => $relative_path,
			'absolute_path'  => $file_path,
			'original_path'  => $original_path, // Original relative path (not absolute)
			'directory'      => $directory,
			'size'           => $file_size,
			'size_formatted' => size_format( $file_size ),
			'extension'      => $file_extension,
			'file_type'      => $file_type,
			'modified_time'  => $modified_time,
			'modified_date'  => wp_date( 'Y-m-d H:i:s', $modified_time ),
			'age_days'       => floor( ( time() - $modified_time ) / DAY_IN_SECONDS ),
		);
	}

	/**
	 * Check if file should be included based on filters
	 *
	 * @param array $file_info File information
	 * @param array $filters   Filters to apply
	 * @return bool True if file should be included
	 */
	protected function should_include_file( $file_info, $filters ) {
		// File type filter
		if ( ! empty( $filters['file_type'] ) && $file_info['file_type'] !== $filters['file_type'] ) {
			return false;
		}

		// Directory filter
		if ( ! empty( $filters['directory'] ) && $file_info['directory'] !== $filters['directory'] ) {
			return false;
		}

		// Age filter (in days)
		if ( ! empty( $filters['min_age_days'] ) && $file_info['age_days'] < $filters['min_age_days'] ) {
			return false;
		}

		// Search filter
		if ( ! empty( $filters['search'] ) ) {
			$search   = strtolower( $filters['search'] );
			$filename = strtolower( $file_info['filename'] );
			if ( strpos( $filename, $search ) === false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Permanently delete a trash file
	 *
	 * @param string $relative_path Relative path in trash
	 * @return array Result with success status
	 */
	public function delete_trash_file( $relative_path ) {
		$result = array(
			'success' => false,
			'error'   => '',
		);

		$trash_dir = Trash_Helper::get_trash_directory();
		$file_path = trailingslashit( $trash_dir ) . $relative_path;

		// Validate file exists and is in trash
		if ( ! file_exists( $file_path ) ) {
			$result['error'] = __( 'Trash file does not exist', 'media-sweep' );
			return $result;
		}

		if ( ! Trash_Helper::is_trash_path( $file_path ) ) {
			$result['error'] = __( 'File is not in trash directory', 'media-sweep' );
			return $result;
		}

		// Delete the file permanently
		if ( Filesystem_Helper::delete( $file_path ) ) {
			$result['success'] = true;

			// The record must stop claiming the file is recoverable from trash.
			$file_model = $this->find_file_record_for_path( $relative_path );
			if ( $file_model ) {
				$file_model->update( array( 'status' => 'deleted' ) );
			}
		} else {
			$result['error'] = sprintf(
				/* translators: %s is the filename that failed to delete */
				__( 'Failed to delete file: %s', 'media-sweep' ),
				basename( $file_path )
			);
		}

		return $result;
	}

	/**
	 * Restore a trash file to its original location
	 *
	 * @param string $relative_path Relative path in trash
	 * @param string $restore_type  Restore type: 'files' or 'media'
	 * @return array Result with success status
	 */
	public function restore_trash_file( $relative_path, $restore_type = 'files' ) {
		// First, restore the file to filesystem
		$restore_result = Trash_Helper::restore_from_trash( $relative_path );

		if ( ! $restore_result['success'] ) {
			return $restore_result;
		}

		$result = array(
			'success'      => true,
			'restore_path' => $restore_result['restore_path'],
			'restore_type' => $restore_type,
		);

		// If restore type is 'media', also add to media library
		if ( 'media' === $restore_type ) {
			$media_result = $this->add_file_to_media_library( $restore_result['restore_path'] );

			if ( $media_result['success'] ) {
				$result['attachment_id'] = $media_result['attachment_id'];
				$result['new_path']      = $media_result['new_path'];
				$result['message']       = __( 'File restored to media library successfully.', 'media-sweep' );
				
				// Update file status in our database with new path and attachment ID
				$this->update_file_status_after_restore( $relative_path, $media_result['new_path'], $media_result['attachment_id'] );
			} else {
				// File restored to filesystem but failed to add to media library
				$result['attachment_id'] = null;
				$result['message']       = sprintf(
					/* translators: %s is the error message from media library restoration */
					__( 'File restored to filesystem but failed to add to media library: %s', 'media-sweep' ),
					$media_result['error']
				);
				
				// Update file status in our database (filesystem only)
				$this->update_file_status_after_restore( $relative_path );
			}
		} else {
			$result['message'] = __( 'File restored to filesystem successfully.', 'media-sweep' );
			
			// Update file status in our database if file exists in our records
			$this->update_file_status_after_restore( $relative_path );
		}

		return $result;
	}

	/**
	 * Add file to media library
	 *
	 * @param string $file_path Absolute path to file
	 * @return array Result with success status, attachment ID, and new path
	 */
	protected function add_file_to_media_library( $file_path ) {
		$result = array(
			'success'       => false,
			'attachment_id' => null,
			'new_path'      => null,
			'error'         => '',
		);

		if ( ! file_exists( $file_path ) ) {
			$result['error'] = __( 'File does not exist', 'media-sweep' );
			return $result;
		}

		// Check if file type is supported by WordPress media library
		$file_type = wp_check_filetype( basename( $file_path ) );
		if ( ! $file_type['type'] ) {
			$result['error'] = __( 'File type not supported by media library', 'media-sweep' );
			return $result;
		}

		// Prepare file for sideloading
		$file_array = array(
			'name'     => basename( $file_path ),
			'tmp_name' => $file_path,
		);

		// Include required WordPress files
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Sideload the file
		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			$result['error'] = $attachment_id->get_error_message();
			return $result;
		}

		// Get the new file path after WordPress moves it
		$new_file_path = get_attached_file( $attachment_id );

		$result['success']       = true;
		$result['attachment_id'] = $attachment_id;
		$result['new_path']      = $new_file_path;
		return $result;
	}

	/**
	 * Update file status after restore
	 *
	 * @param string   $relative_path  Relative path in trash
	 * @param string   $new_path       New absolute path (optional, for media library restores)
	 * @param int|null $attachment_id  Attachment ID (optional, for media library restores)
	 * @return void
	 */
	protected function update_file_status_after_restore( $relative_path, $new_path = null, $attachment_id = null ) {
		$original_relative_path = $relative_path;
		$relative_path          = Path_Helper::ensure_relative_path( $original_relative_path );
		$relative_path          = str_replace( Trash_Helper::TRASH_FOLDER . '/', '', $relative_path );

		$file_model = $this->find_file_record_for_path( $relative_path );
		if ( $file_model ) {
			$update_data = array(
				'status' => 'active',
			);

			// If new path is provided (media library restore), update the file path
			if ( null !== $new_path ) {
				$update_data['filepath'] = Path_Helper::ensure_relative_path( $new_path );
			} else {
				$update_data['filepath'] = $relative_path;
			}

			// If attachment ID is provided, update it
			if ( null !== $attachment_id ) {
				$update_data['attachment_id'] = $attachment_id;
			}

			$file_model->update( $update_data );
		}
	}

	/**
	 * Find the file record for an uploads-relative path.
	 *
	 * Records may have been stored with either an uploads-relative or an absolute path, and filepath_sha1 was
	 * hashed from whichever form was stored, so every candidate form has to be tried before giving up.
	 *
	 * @param string $relative_path Uploads-relative path (no trash folder prefix).
	 * @return File_Model|null
	 */
	protected function find_file_record_for_path( $relative_path ) {
		// The record we trashed still points at the trash copy, so match that first: it identifies the row exactly.
		$file_model = File_Model::where( 'filepath', '=', Trash_Helper::TRASH_FOLDER . '/' . $relative_path )->first();
		if ( $file_model ) {
			return $file_model;
		}

		foreach ( array_unique( array( $relative_path, Path_Helper::ensure_absolute_path( $relative_path ) ) ) as $candidate ) {
			$file_model = File_Model::where( 'filepath_sha1', '=', sha1( $candidate ) )->first();
			if ( $file_model ) {
				return $file_model;
			}
		}

		return null;
	}

	/**
	 * Get trash file by ID
	 *
	 * @param string $file_id File ID (MD5 hash of relative path)
	 * @return array|null File information or null if not found
	 */
	public function get_trash_file_by_id( $file_id ) {
		$trash_dir = Trash_Helper::get_trash_directory();

		if ( ! file_exists( $trash_dir ) ) {
			return null;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $trash_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$filename = $file->getFilename();

				// Skip protection files
				if ( Trash_Helper::is_protection_file( $filename ) ) {
					continue;
				}

				$file_path     = $file->getPathname();
				$relative_path = str_replace( trailingslashit( wp_normalize_path( $trash_dir ) ), '', wp_normalize_path( $file_path ) );

				// Check if this is the file we're looking for
				if ( md5( $relative_path ) === $file_id ) {
					return $this->get_trash_file_info( $file_path, $trash_dir );
				}
			}
		} catch ( \Exception $e ) {
			// Handle silently, return null
		}

		return null;
	}

	/**
	 * Get trash statistics
	 *
	 * @return array Trash statistics
	 */
	public function get_trash_stats() {
		return Trash_Helper::get_trash_stats();
	}

	/**
	 * Empty entire trash (delete all files)
	 *
	 * @return array Result with success status and count
	 */
	public function empty_trash() {
		$result = array(
			'success'       => true,
			'deleted_count' => 0,
			'errors'        => array(),
		);

		$trash_dir = Trash_Helper::get_trash_directory();
		if ( ! file_exists( $trash_dir ) ) {
			return $result;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $trash_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $file ) {
				$filename = $file->getFilename();

				// Skip protection files
				if ( Trash_Helper::is_protection_file( $filename ) ) {
					continue;
				}

				if ( $file->isFile() ) {
					if ( Filesystem_Helper::delete( $file->getPathname() ) ) {
						++$result['deleted_count'];
					} else {
						$result['errors'][] = sprintf(
							__( 'Failed to delete file: %s', 'media-sweep' ),
							$filename
						);
					}
				}
			}
		} catch ( \Exception $e ) {
			$result['success']  = false;
			$result['errors'][] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Auto-delete old files from trash based on age
	 *
	 * @param int $days_old Minimum age in days for files to be deleted
	 * @return array Result with counts
	 */
	public function auto_delete_old_files( $days_old ) {
		$result = array(
			'deleted_count' => 0,
			'error_count'   => 0,
			'errors'        => array(),
			'total_size'    => 0,
		);

		if ( $days_old <= 0 ) {
			return $result;
		}

		$trash_dir = Trash_Helper::get_trash_directory();
		if ( ! file_exists( $trash_dir ) ) {
			return $result;
		}

		$cutoff_time = time() - ( $days_old * DAY_IN_SECONDS );

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $trash_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$filename = $file->getFilename();

				// Skip protection files
				if ( Trash_Helper::is_protection_file( $filename ) ) {
					continue;
				}

				// Check if file is older than the cutoff
				$modified_time = $file->getMTime();
				if ( $modified_time < $cutoff_time ) {
					$file_size = $file->getSize();
					$file_path = $file->getPathname();

					if ( Filesystem_Helper::delete( $file_path ) ) {
						++$result['deleted_count'];
						$result['total_size'] += $file_size;
					} else {
						++$result['error_count'];
						$result['errors'][] = sprintf(
							/* translators: %s is the filename that failed to delete */
							__( 'Failed to delete file: %s', 'media-sweep' ),
							$filename
						);
					}
				}
			}
		} catch ( \Exception $e ) {
			++$result['error_count'];
			$result['errors'][] = $e->getMessage();
		}

		return $result;
	}
}
