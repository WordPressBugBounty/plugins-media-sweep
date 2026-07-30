<?php
/**
 * Path Helper Utility
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * Path Helper class for handling file path conversions
 */
class Path_Helper {

	/**
	 * Convert absolute path to relative path from uploads directory
	 *
	 * @param string $absolute_path Absolute file path
	 * @return string Relative path from uploads directory
	 */
	public static function get_relative_path( $absolute_path ) {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Handle different path separators
		$base_dir      = str_replace( '\\', '/', $base_dir );
		$absolute_path = str_replace( '\\', '/', $absolute_path );

		// Remove base directory from path
		$relative_path = str_replace( $base_dir . '/', '', $absolute_path );

		// Ensure we don't have leading slashes
		$relative_path = ltrim( $relative_path, '/' );

		return $relative_path;
	}

	/**
	 * Convert relative path to absolute path
	 *
	 * @param string $relative_path Relative path from uploads directory
	 * @return string Absolute file path
	 */
	public static function get_absolute_path( $relative_path ) {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Normalize path separators
		$base_dir      = str_replace( '\\', '/', $base_dir );
		$relative_path = str_replace( '\\', '/', $relative_path );

		// Never prefix a path already inside uploads; that produced /uploads/C:/site/uploads/... which resolves to nothing.
		if ( '' !== $base_dir && strpos( $relative_path, $base_dir ) === 0 ) {
			return $relative_path;
		}

		// Remove leading slash if present
		$relative_path = ltrim( $relative_path, '/' );

		return $base_dir . '/' . $relative_path;
	}

	/**
	 * Get file URL from relative path
	 *
	 * @param string $relative_path Relative path from uploads directory
	 * @return string File URL
	 */
	public static function get_file_url( $relative_path ) {
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];

		// Normalize path separators
		$relative_path = str_replace( '\\', '/', $relative_path );
		$relative_path = ltrim( $relative_path, '/' );

		return $base_url . '/' . $relative_path;
	}

	/**
	 * Normalize path separators to forward slashes
	 *
	 * @param string $path File path
	 * @return string Normalized path
	 */
	public static function normalize_path( $path ) {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * Check if a path is relative to uploads directory
	 *
	 * Absolute means: leading slash, drive letter with either separator (C:/ and C:\), UNC prefix, or already inside the uploads directory.
	 *
	 * @param string $path File path to check
	 * @return bool True if path is relative, false if absolute
	 */
	public static function is_relative_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return true;
		}

		$normalized = self::normalize_path( $path );

		// Unix absolute, Windows drive letter (either separator), or UNC path.
		if ( preg_match( '#^(/|[a-zA-Z]:/|//)#', $normalized ) ) {
			return false;
		}

		// Already prefixed with the uploads directory.
		$upload_dir = wp_upload_dir();
		$base_dir   = self::normalize_path( $upload_dir['basedir'] );
		if ( '' !== $base_dir && strpos( $normalized, $base_dir ) === 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Ensure path is relative to uploads directory
	 *
	 * @param string $path File path (absolute or relative)
	 * @return string Relative path from uploads directory
	 */
	public static function ensure_relative_path( $path ) {
		if ( self::is_relative_path( $path ) ) {
			return self::normalize_path( $path );
		}

		return self::get_relative_path( $path );
	}

	/**
	 * Ensure path is absolute
	 *
	 * @param string $path File path (absolute or relative)
	 * @return string Absolute file path
	 */
	public static function ensure_absolute_path( $path ) {
		if ( self::is_relative_path( $path ) ) {
			return self::get_absolute_path( $path );
		}

		return self::normalize_path( $path );
	}

	/**
	 * Get uploads dir url
	 *
	 * @return string Uploads dir url
	 */
	public static function get_uploads_dir_url() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['baseurl'];
	}
}
