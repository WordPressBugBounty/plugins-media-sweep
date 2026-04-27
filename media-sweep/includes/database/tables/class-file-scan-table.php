<?php
/**
 * File_Scan table
 *
 * @package media-sweep
 */

namespace Media_Sweep\Database\Tables;

use Media_Sweep\Abstracts\Table;

/**
 * File_Scan table
 */
class File_Scan_Table extends Table {

	/**
	 * Get table name
	 */
	public function get_table_name() {
		return 'mswp_file_scan';
	}

	/**
	 * Get schema
	 */
	public function get_schema() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return "
            CREATE TABLE {$table} (
            id         BIGINT UNSIGNED AUTO_INCREMENT,
            scan_id    BIGINT UNSIGNED NOT NULL,
            file_id    BIGINT UNSIGNED NOT NULL,
            status     ENUM('in_media','not_in_media','in_use','unused','orphaned') NOT NULL,
            notes      TEXT NULL,
            recorded_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_pair (scan_id, file_id),
            KEY idx_scan (scan_id),
            KEY idx_file (file_id),
            KEY idx_status (status),
            KEY idx_scan_status (scan_id, status),
            KEY idx_recorded_at (recorded_at)
        ) {$wpdb->get_charset_collate()};
        ";
	}
}
