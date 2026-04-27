<?php
/**
 * Scans table
 *
 * @package media-sweep
 */

namespace Media_Sweep\Database\Tables;

use Media_Sweep\Abstracts\Table;

/**
 * Scans table
 */
class Scans_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'mswp_scans';
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
            `started_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            `mode` VARCHAR(32) NOT NULL,
            `options` LONGTEXT NULL,
            PRIMARY KEY (`id`),
            KEY `started_at` (`started_at`),
            KEY `mode` (`mode`)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
