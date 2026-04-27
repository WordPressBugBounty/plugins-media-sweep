<?php
/**
 * Trash Helper - Manages the mswp-trash folder system
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

use Media_Sweep\Utils\Path_Helper;

/**
 * Trash Helper class
 */
class Trash_Helper {

	/**
	 * Trash folder name
	 *
	 * @var string
	 */
	const TRASH_FOLDER = 'mswp-trash';

	/**
	 * Get the trash directory path
	 *
	 * @return string Absolute path to trash directory
	 */
	public static function get_trash_directory() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::TRASH_FOLDER;
	}

	/**
	 * Ensure trash directory exists with proper protection
	 *
	 * @return bool True if directory exists or was created successfully
	 */
	public static function ensure_trash_directory_exists() {
		$trash_dir = self::get_trash_directory();

		if ( ! file_exists( $trash_dir ) ) {
			if ( ! wp_mkdir_p( $trash_dir ) ) {
				return false;
			}

			// Add protection files only to the root trash directory
			self::create_trash_protection( $trash_dir );
		}

		return true;
	}

	/**
	 * Create protection files for trash directory (once only)
	 *
	 * @param string $directory Directory to protect
	 */
	protected static function create_trash_protection( $directory ) {
		// Create .htaccess file
		$htaccess_file = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content  = "# Media Sweep Trash Protection\n";
			$htaccess_content .= "# Deny direct access to all files in trash\n";
			$htaccess_content .= "Order deny,allow\n";
			$htaccess_content .= "Deny from all\n";
			$htaccess_content .= "\n";
			$htaccess_content .= "# Allow only authorized access\n";
			$htaccess_content .= "<Files \"index.php\">\n";
			$htaccess_content .= "    Allow from all\n";
			$htaccess_content .= "</Files>\n";

			file_put_contents( $htaccess_file, $htaccess_content );
		}

		// Create index.php file to prevent directory browsing
		$index_file = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			$index_content  = "<?php\n";
			$index_content .= "// Media Sweep Trash Directory\n";
			$index_content .= "// Silence is golden.\n";
			$index_content .= "exit;\n";

			file_put_contents( $index_file, $index_content );
		}
	}

	/**
	 * Move a file to trash preserving directory structure
	 *
	 * @param string $file_path Absolute path to the file
	 * @return array Result with success status and new path
	 */
	public static function move_to_trash( $file_path ) {
		$result = array(
			'success'  => false,
			'new_path' => '',
			'error'    => '',
		);

		// Validate file exists
		if ( ! file_exists( $file_path ) ) {
			$result['error'] = __( 'File does not exist', 'media-sweep' );
			return $result;
		}

		// Ensure main trash directory exists
		if ( ! self::ensure_trash_directory_exists() ) {
			$result['error'] = __( 'Failed to create trash directory', 'media-sweep' );
			return $result;
		}

		// Get the relative path from uploads directory to preserve structure
		$upload_dir   = wp_upload_dir();
		$uploads_base = trailingslashit( $upload_dir['basedir'] );

		// Normalize paths for cross-platform compatibility
		$normalized_uploads_base = wp_normalize_path( $uploads_base );
		$normalized_file_path    = wp_normalize_path( $file_path );

		// Convert absolute path to relative path from uploads
		$relative_path = str_replace( $normalized_uploads_base, '', $normalized_file_path );

		// Create target path in trash with same directory structure
		$trash_base  = self::get_trash_directory();
		$target_path = trailingslashit( $trash_base ) . $relative_path;

		// Ensure target directory exists
		$target_dir = dirname( $target_path );
		if ( ! file_exists( $target_dir ) ) {
			if ( ! wp_mkdir_p( $target_dir ) ) {
				// Get a clean relative path for the error message
				$normalized_trash_base = wp_normalize_path( $trash_base );
				$normalized_target_dir = wp_normalize_path( $target_dir );
				$relative_target_dir   = str_replace( trailingslashit( $normalized_trash_base ), '', $normalized_target_dir );

				$result['error'] = sprintf(
					/* translators: %s is the directory path that failed to be created */
					__( 'Failed to create trash subdirectory: %s', 'media-sweep' ),
					$relative_target_dir ?: basename( $target_dir )
				);
				return $result;
			}
		}

		// Move the file (overwrite if exists)
		if ( rename( $file_path, $target_path ) ) {
			$result['success']  = true;
			$result['new_path'] = $target_path;
		} else {
			$result['error'] = sprintf(
				/* translators: %s is the filename that failed to be moved to trash */
				__( 'Failed to move file to trash: %s', 'media-sweep' ),
				basename( $file_path )
			);
		}

		return $result;
	}


	/**
	 * Restore a file from trash
	 *
	 * @param string $trash_file_path Path to file in trash
	 * @param string $restore_path    Path to restore to (optional)
	 * @return array Result with success status
	 */
	public static function restore_from_trash( $trash_file_path, $restore_path = null ) {
		$result = array(
			'success'      => false,
			'restore_path' => '',
			'error'        => '',
		);

		// Ensure it's complete path
		// Add trash folder to the path if it's not there
		if ( ! strpos( $trash_file_path, self::TRASH_FOLDER ) ) {
			$trash_file_path = trailingslashit( self::get_trash_directory() ) . $trash_file_path;
		}
		$trash_file_path = Path_Helper::ensure_absolute_path( $trash_file_path );

		// Validate trash file exists
		if ( ! file_exists( $trash_file_path ) ) {
			$result['error'] = wp_json_encode( array(
				'message' => __( 'Trash file does not exist', 'media-sweep' ),
				'file_path' => $trash_file_path,
				'trash_file_path' => $trash_file_path,
			) );
			return $result;
		}

		// If no restore path provided, try to determine original location
		if ( null === $restore_path ) {
			$restore_path = self::determine_original_path( $trash_file_path );
			if ( ! $restore_path ) {
				$result['error'] = __( 'Cannot determine original file location', 'media-sweep' );
				return $result;
			}
		}

		// Ensure restore directory exists
		$restore_dir = dirname( $restore_path );
		if ( ! file_exists( $restore_dir ) ) {
			if ( ! wp_mkdir_p( $restore_dir ) ) {
				$result['error'] = __( 'Failed to create restore directory', 'media-sweep' );
				return $result;
			}
		}

		// Handle filename conflicts
		if ( file_exists( $restore_path ) ) {
			$restore_path = self::generate_unique_restore_filename( $restore_path );
		}

		// Move the file back
		if ( rename( $trash_file_path, $restore_path ) ) {
			$result['success']      = true;
			$result['restore_path'] = $restore_path;
		} else {
			$result['error'] = sprintf(
				/* translators: %s is the filename that failed to be restored from trash */
				__( 'Failed to restore file from trash: %s', 'media-sweep' ),
				basename( $trash_file_path )
			);
		}

		return $result;
	}

	/**
	 * Determine original path from trash file path
	 *
	 * @param string $trash_file_path Path to file in trash
	 * @return string|false Original path or false if cannot determine
	 */
	protected static function determine_original_path( $trash_file_path ) {
		$upload_dir = wp_upload_dir();
		$trash_base = self::get_trash_directory();

		// Normalize paths for cross-platform compatibility
		$normalized_trash_file_path = wp_normalize_path( $trash_file_path );
		$normalized_trash_base      = wp_normalize_path( $trash_base );

		// Check if file is actually in trash
		if ( strpos( $normalized_trash_file_path, $normalized_trash_base ) !== 0 ) {
			return false;
		}

		// Get relative path from trash directory
		$relative_path = str_replace( trailingslashit( $normalized_trash_base ), '', $normalized_trash_file_path );

		// Construct original path in uploads directory
		$original_path = trailingslashit( $upload_dir['basedir'] ) . $relative_path;

		return wp_normalize_path( $original_path );
	}

	/**
	 * Generate unique filename for restore
	 *
	 * @param string $restore_path Original restore path
	 * @return string Unique restore path
	 */
	protected static function generate_unique_restore_filename( $restore_path ) {
		$file_info = pathinfo( $restore_path );
		$dir       = $file_info['dirname'];
		$basename  = $file_info['filename'];
		$extension = isset( $file_info['extension'] ) ? '.' . $file_info['extension'] : '';

		$counter = 1;
		do {
			$new_filename = $basename . '-restored-' . $counter . $extension;
			$new_path     = trailingslashit( $dir ) . $new_filename;
			++$counter;
		} while ( file_exists( $new_path ) );

		return $new_path;
	}

	/**
	 * Check if a path is within the trash directory
	 *
	 * @param string $path Path to check
	 * @return bool True if path is in trash
	 */
	public static function is_trash_path( $path ) {
		$trash_dir = self::get_trash_directory();

		// Normalize paths for cross-platform compatibility
		$normalized_path      = wp_normalize_path( $path );
		$normalized_trash_dir = wp_normalize_path( $trash_dir );

		// Try realpath first for more accurate comparison
		$real_path  = realpath( $path );
		$real_trash = realpath( $trash_dir );

		if ( false !== $real_path && false !== $real_trash ) {
			$normalized_real_path  = wp_normalize_path( $real_path );
			$normalized_real_trash = wp_normalize_path( $real_trash );
			return strpos( $normalized_real_path, $normalized_real_trash ) === 0;
		}

		// Fallback to normalized string comparison if realpath fails
		return strpos( $normalized_path, $normalized_trash_dir ) === 0 ||
				strpos( $normalized_path, self::TRASH_FOLDER ) !== false;
	}

	/**
	 * Get relative path from uploads directory
	 *
	 * @param string $absolute_path Absolute file path
	 * @return string Relative path from uploads
	 */
	public static function get_relative_trash_path( $absolute_path ) {
		$upload_dir = wp_upload_dir();
		return str_replace( trailingslashit( $upload_dir['basedir'] ), '', $absolute_path );
	}

	/**
	 * Clean up old trash files
	 *
	 * @param int $days_old Files older than this many days will be deleted
	 * @return array Cleanup results
	 */
	public static function cleanup_old_trash( $days_old = 30 ) {
		$result = array(
			'success'       => true,
			'deleted_count' => 0,
			'errors'        => array(),
		);

		$trash_dir = self::get_trash_directory();
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
				// Skip protection files (.htaccess, index.php)
				$filename = $file->getFilename();
				if ( in_array( $filename, array( '.htaccess', 'index.php' ) ) ) {
					continue;
				}

				if ( $file->isFile() && $file->getMTime() < $cutoff_time ) {
					if ( unlink( $file->getPathname() ) ) {
						++$result['deleted_count'];
					} else {
						$result['errors'][] = sprintf(
							/* translators: %s is the filename that failed to be deleted */
							__( 'Failed to delete old trash file: %s', 'media-sweep' ),
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
	 * Get trash statistics
	 *
	 * @return array Trash statistics
	 */
	public static function get_trash_stats() {
		$stats = array(
			'total_files'  => 0,
			'total_size'   => 0,
			'by_directory' => array(),
		);

		$trash_dir = self::get_trash_directory();
		if ( ! file_exists( $trash_dir ) ) {
			return $stats;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $trash_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$filename = $file->getFilename();

					// Skip protection files
					if ( in_array( $filename, array( '.htaccess', 'index.php' ) ) ) {
						continue;
					}

					// Get relative directory from trash root
					$relative_dir = str_replace( trailingslashit( $trash_dir ), '', dirname( $file->getPathname() ) );
					if ( empty( $relative_dir ) ) {
						$relative_dir = '/'; // Root of trash
					}

					++$stats['total_files'];
					$stats['total_size'] += $file->getSize();

					if ( ! isset( $stats['by_directory'][ $relative_dir ] ) ) {
						$stats['by_directory'][ $relative_dir ] = array(
							'files' => 0,
							'size'  => 0,
						);
					}

					++$stats['by_directory'][ $relative_dir ]['files'];
					$stats['by_directory'][ $relative_dir ]['size'] += $file->getSize();
				}
			}
		} catch ( \Exception $e ) {
			// Handle silently, return empty stats
		}

		return $stats;
	}
}
