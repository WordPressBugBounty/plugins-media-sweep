<?php
/**
 * Scan_Refs table - the per-scan media reference index.
 *
 * Populated once per scan by walking posts/postmeta/options/etc. and
 * extracting every attachment ID and uploads URL. Attachment verdicts are
 * then indexed point lookups against this table instead of LIKE full-table
 * scans over wp_posts/wp_postmeta.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Database\Tables;

use Media_Sweep\Abstracts\Table;

/**
 * Scan_Refs table
 */
class Scan_Refs_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'mswp_scan_refs';
	}

	/**
	 * Get schema.
	 *
	 * - `path` is TEXT and therefore unindexable under utf8mb4 key-length
	 *   limits; `path_hash` (sha256) is the indexed lookup key, with the raw
	 *   path kept as a collision guard and for debugging.
	 * - `origin` is structured, e.g. 'content:123', 'featured:123',
	 *   'custom_field:{meta_key}:123', 'gallery_shortcode:123',
	 *   'gallery_block:123', 'option:site_icon', 'termmeta:thumbnail_id:7',
	 *   'table:{table}.{column}' - it is the data source for the detailed
	 *   per-file usage notes, so it must stay rich enough to render them.
	 * - `ref_hash` UNIQUE per scan makes inserts idempotent (INSERT ... ON
	 *   DUPLICATE KEY), so retried extraction ticks can never double-write.
	 * - `hits` counts duplicate sightings of the same (target, origin) pair,
	 *   which keeps the deep-scan "Found N time(s) in table x.y" note exact.
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "
            CREATE TABLE {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `scan_id` BIGINT UNSIGNED NOT NULL,
            `attachment_id` BIGINT UNSIGNED NULL,
            `path` TEXT NULL,
            `path_hash` CHAR(64) NULL,
            `origin` VARCHAR(191) NOT NULL,
            `ref_hash` CHAR(32) NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `scan_ref` (`scan_id`, `ref_hash`),
            KEY `scan_media` (`scan_id`, `attachment_id`),
            KEY `scan_url` (`scan_id`, `path_hash`)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
