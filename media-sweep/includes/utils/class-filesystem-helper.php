<?php
/**
 * Filesystem Helper - routes file access through the WordPress Filesystem API
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over WP_Filesystem.
 *
 * WP_Filesystem is the API WordPress expects plugins to use, and it is what the plugin review team looks
 * for. It is not guaranteed to be available though: on a host whose filesystem method is not 'direct',
 * WP_Filesystem() needs credentials that a REST or cron request cannot supply, and initialisation fails.
 * Every method here therefore falls back to the native call so that trashing and restoring keep working on
 * those hosts instead of failing outright.
 */
class Filesystem_Helper {

	/**
	 * Initialise and return the WordPress filesystem, or null when unavailable.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function get() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Only attempt the credential-free path; anything else cannot succeed without a user prompt.
		if ( 'direct' !== get_filesystem_method() ) {
			return null;
		}

		WP_Filesystem();

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Write a file.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 * @return bool True on success.
	 */
	public static function put_contents( $path, $contents ) {
		$fs = self::get();

		if ( $fs ) {
			return $fs->put_contents( $path, $contents, FS_CHMOD_FILE );
		}

		return false !== file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Read a file.
	 *
	 * @param string $path Absolute path.
	 * @return string|false Contents, or false on failure.
	 */
	public static function get_contents( $path ) {
		$fs = self::get();

		if ( $fs ) {
			return $fs->get_contents( $path );
		}

		return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * Delete a file.
	 *
	 * @param string $path Absolute path.
	 * @return bool True on success.
	 */
	public static function delete( $path ) {
		$fs = self::get();

		if ( $fs ) {
			return $fs->delete( $path, false, 'f' );
		}

		return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
	}

	/**
	 * Move a file, replacing any existing destination.
	 *
	 * @param string $from Absolute source path.
	 * @param string $to   Absolute destination path.
	 * @return bool True on success.
	 */
	public static function move( $from, $to ) {
		$fs = self::get();

		if ( $fs ) {
			return $fs->move( $from, $to, true );
		}

		return rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
	}
}
