<?php
/**
 * File Type Helper - Handles file type detection and categorization
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * File Type Helper class
 */
class File_Type_Helper {

	/**
	 * Generic cache directories to exclude
	 *
	 * @var array
	 */
	protected static $cache_directories = array(
		'cache',
		'tmp',
		'temp',
		'temporary',
		'w3tc',
		'wp-rocket',
		'litespeed',
		'wp-super-cache',
		'wp-fastest-cache',
		'autoptimize',
		'cache-enabler',
		'wp-optimize',
	);

	/**
	 * Generic backup directories to exclude
	 *
	 * @var array
	 */
	protected static $backup_directories = array(
		'backup',
		'backups',
		'snapshots',
		'archives',
		'updraftplus',
		'backwpup',
		'wp-migrate-db',
		'all-in-one-wp-migration',
		'duplicator',
		'backupbuddy',
		'wp-db-backup',
	);

	/**
	 * Check if file is a generic cache or backup file
	 *
	 * @param string $file_path The file path
	 * @return array Array of notes if file is cache/backup type
	 */
	public static function check_generic_file_type( $file_path ) {
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $file_path );
		$filename      = basename( $file_path );
		$notes         = array();

		// Check for cache directories
		foreach ( self::$cache_directories as $cache_dir ) {
			if ( strpos( strtolower( $relative_path ), $cache_dir ) !== false ) {
				$notes[] = sprintf(
					/* translators: %s is the cache directory name */
					__( 'Cache file in %s directory', 'media-sweep' ),
					$cache_dir
				);
				return $notes; // Return early since we found the type
			}
		}

		// Check for backup directories
		foreach ( self::$backup_directories as $backup_dir ) {
			if ( strpos( strtolower( $relative_path ), $backup_dir ) !== false ) {
				$notes[] = sprintf(
					/* translators: %s is the backup directory name */
					__( 'Backup file in %s directory', 'media-sweep' ),
					$backup_dir
				);
				return $notes; // Return early since we found the type
			}
		}

		// Check for cache file patterns in filename
		if ( self::is_cache_file( $filename ) ) {
			$notes[] = __( 'Cache file (detected by filename pattern)', 'media-sweep' );
			return $notes;
		}

		// Check for backup file patterns in filename
		if ( self::is_backup_file( $filename ) ) {
			$notes[] = __( 'Backup file (detected by filename pattern)', 'media-sweep' );
			return $notes;
		}

		// Check for temporary files
		if ( self::is_temporary_file( $filename ) ) {
			$notes[] = __( 'Temporary file', 'media-sweep' );
			return $notes;
		}

		return array();
	}

	/**
	 * Check if filename indicates a cache file
	 *
	 * @param string $filename The filename
	 * @return bool True if filename suggests cache file
	 */
	protected static function is_cache_file( $filename ) {
		$cache_patterns = array(
			'-cache',
			'_cache',
			'.cache',
			'-cached',
			'_cached',
			'.cached',
			'-minified',
			'_minified',
			'.min.',
			'-optimized',
			'_optimized',
			'-compressed',
			'_compressed',
		);

		$filename_lower = strtolower( $filename );

		foreach ( $cache_patterns as $pattern ) {
			if ( strpos( $filename_lower, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if filename indicates a backup file
	 *
	 * @param string $filename The filename
	 * @return bool True if filename suggests backup file
	 */
	protected static function is_backup_file( $filename ) {
		$backup_patterns = array(
			'-backup',
			'_backup',
			'.backup',
			'-bak',
			'_bak',
			'.bak',
			'-copy',
			'_copy',
			'.copy',
			'-old',
			'_old',
			'.old',
			'-archive',
			'_archive',
			'.archive',
			'-snapshot',
			'_snapshot',
		);

		$filename_lower = strtolower( $filename );

		foreach ( $backup_patterns as $pattern ) {
			if ( strpos( $filename_lower, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if filename indicates a temporary file
	 *
	 * @param string $filename The filename
	 * @return bool True if filename suggests temporary file
	 */
	protected static function is_temporary_file( $filename ) {
		$temp_patterns = array(
			'.tmp',
			'.temp',
			'~',
			'.lock',
			'.part',
			'-tmp',
			'_tmp',
			'-temp',
			'_temp',
			'.processing',
			'-processing',
		);

		$filename_lower = strtolower( $filename );

		foreach ( $temp_patterns as $pattern ) {
			if ( strpos( $filename_lower, $pattern ) !== false ) {
				return true;
			}
		}

		// Files starting with dot (hidden files) except common ones
		if ( strpos( $filename, '.' ) === 0 && ! in_array( $filename, array( '.htaccess', '.well-known' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if item should be excluded based on options
	 *
	 * @param string $item_path Relative path of item
	 * @param string $item_name Name of item
	 * @param array  $options   Scan options
	 * @return bool True if item should be excluded
	 */
	public static function should_exclude_item( $item_path, $item_name, $options ) {
		// Exclude cache directories if option is enabled
		if ( ! empty( $options['exclude_cache_dirs'] ) ) {
			foreach ( self::$cache_directories as $cache_dir ) {
				if ( strpos( strtolower( $item_path ), $cache_dir ) !== false || strpos( strtolower( $item_name ), $cache_dir ) !== false ) {
					return true;
				}
			}
		}

		// Exclude backup directories if option is enabled
		if ( ! empty( $options['exclude_backup_dirs'] ) ) {
			foreach ( self::$backup_directories as $backup_dir ) {
				if ( strpos( strtolower( $item_path ), $backup_dir ) !== false || strpos( strtolower( $item_name ), $backup_dir ) !== false ) {
					return true;
				}
			}
		}

		// Apply include regex if provided
		if ( ! empty( $options['include_regex'] ) ) {
			if ( ! preg_match( '/' . $options['include_regex'] . '/i', $item_name ) ) {
				return true;
			}
		}

		// Apply exclude regex if provided
		if ( ! empty( $options['exclude_regex'] ) ) {
			if ( preg_match( '/' . $options['exclude_regex'] . '/i', $item_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get file extension from file path
	 *
	 * @param string $file_path The file path
	 * @return string File extension (without dot)
	 */
	public static function get_file_extension( $file_path ) {
		return strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Check if file is an image
	 *
	 * @param string $file_path The file path
	 * @return bool True if file is an image
	 */
	public static function is_image( $file_path ) {
		$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico' );
		$extension        = self::get_file_extension( $file_path );

		return in_array( $extension, $image_extensions );
	}

	/**
	 * Check if file is a video
	 *
	 * @param string $file_path The file path
	 * @return bool True if file is a video
	 */
	public static function is_video( $file_path ) {
		$video_extensions = array( 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v' );
		$extension        = self::get_file_extension( $file_path );

		return in_array( $extension, $video_extensions );
	}

	/**
	 * Check if file is audio
	 *
	 * @param string $file_path The file path
	 * @return bool True if file is audio
	 */
	public static function is_audio( $file_path ) {
		$audio_extensions = array( 'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma' );
		$extension        = self::get_file_extension( $file_path );

		return in_array( $extension, $audio_extensions );
	}

	/**
	 * Check if file is a document
	 *
	 * @param string $file_path The file path
	 * @return bool True if file is a document
	 */
	public static function is_document( $file_path ) {
		$doc_extensions = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp' );
		$extension      = self::get_file_extension( $file_path );

		return in_array( $extension, $doc_extensions );
	}

	/**
	 * Get file type category
	 *
	 * @param string $file_path The file path
	 * @return string File type category
	 */
	public static function get_file_type_category( $file_path ) {
		if ( self::is_image( $file_path ) ) {
			return 'image';
		}

		if ( self::is_video( $file_path ) ) {
			return 'video';
		}

		if ( self::is_audio( $file_path ) ) {
			return 'audio';
		}

		if ( self::is_document( $file_path ) ) {
			return 'document';
		}

		return 'other';
	}
}
