<?php
/**
 * Files table
 *
 * @package media-sweep
 */

namespace Media_Sweep\Database\Tables;

use Media_Sweep\Abstracts\Table;

/**
 * Files table
 */
class Files_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'mswp_files';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "
            CREATE TABLE {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `filepath` TEXT NOT NULL,
            `filepath_sha1` CHAR(40) NOT NULL,
            `attachment_id` BIGINT UNSIGNED NULL,
            `file_type` VARCHAR(20) NULL,
            `size_bytes` BIGINT UNSIGNED NULL,
            `thumb_of` BIGINT UNSIGNED NULL,
            `status` ENUM('active','trashed','deleted') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_filepath` (`filepath_sha1`),
            KEY `attachment_id` (`attachment_id`),
            KEY `file_type` (`file_type`),
            KEY `status` (`status`),
            KEY `created_at` (`created_at`)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
