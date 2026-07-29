<?php
/**
 * Media Sweep uninstall handler.
 *
 * Runs only when the user deletes the plugin from the WordPress admin
 * (not on deactivation). Data is removed ONLY if the user explicitly
 * opted in via Settings → "Delete all data on uninstall". The default
 * is to preserve everything, so an accidental delete/reinstall keeps
 * the user's scan history and trash intact.
 *
 * This file is intentionally standalone — it does not bootstrap the
 * plugin. It relies only on WordPress core functions, a fixed list of
 * the plugin's own table/option/meta names, and PHP 7.4-compatible
 * syntax, so it runs safely on any host.
 *
 * @package media-sweep
 */

// Exit if not invoked by WordPress' uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all Media Sweep data for the current site.
 *
 * Honors the per-site opt-in setting; bails out unless the user enabled
 * data deletion for this site.
 *
 * @return void
 */
function media_sweep_uninstall_cleanup() {
	global $wpdb;

	$settings = get_option( 'media_sweep_settings' );

	// Respect the opt-in. Keep all data unless the user turned this on.
	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// 1. Drop the plugin's custom tables. Names are built from a fixed
	// suffix plus the trusted site prefix — no user input involved.
	$tables = array(
		$wpdb->prefix . 'mswp_scan_refs',
		$wpdb->prefix . 'mswp_file_scan',
		$wpdb->prefix . 'mswp_files',
		$wpdb->prefix . 'mswp_scans',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	// 2. Delete options.
	delete_option( 'media_sweep_settings' );
	delete_option( 'mswp_db_schema_version' );

	// 3. Delete review-notice user meta for every user ($delete_all = true).
	$meta_keys = array(
		'mswp_review_notice_first_seen',
		'mswp_review_notice_dismissed',
		'mswp_review_notice_permanent_dismissed',
	);
	foreach ( $meta_keys as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}

	// 4. Clear any scheduled cron events.
	wp_clear_scheduled_hook( 'media_sweep_auto_delete_trash' );

	// 5. Remove the Media Sweep trash folder and any files still inside it.
	media_sweep_uninstall_remove_trash_dir();
}

/**
 * Recursively remove the Media Sweep trash directory inside uploads.
 *
 * @return void
 */
function media_sweep_uninstall_remove_trash_dir() {
	$upload_dir = wp_upload_dir();

	if ( empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$trash_dir = trailingslashit( $upload_dir['basedir'] ) . 'mswp-trash';

	// Safety: only ever act on a path that is exactly our own folder.
	if ( 'mswp-trash' !== basename( $trash_dir ) || ! is_dir( $trash_dir ) ) {
		return;
	}

	try {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $trash_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	} catch ( Exception $e ) {
		// A filesystem error here must never block uninstallation.
		return;
	}

	@rmdir( $trash_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

// Run cleanup once per site, with multisite support.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		media_sweep_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	media_sweep_uninstall_cleanup();
}
