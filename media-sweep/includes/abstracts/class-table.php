<?php
/**
 * Abstract class for tables
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abstracts;

/**
 * Abstract class for tables
 */
abstract class Table {

	/**
	 * Return the raw table name (without prefix).
	 *
	 * @return string
	 */
	abstract protected function get_table_name();

	/**
	 * Return the full schema (CREATE TABLE SQL).
	 *
	 * @return string
	 */
	abstract protected function get_schema();

	/**
	 * Return full table name with prefix.
	 *
	 * @return string
	 */
	public function get_full_table_name() {
		global $wpdb;
		return $wpdb->prefix . $this->get_table_name();
	}

	/**
	 * Install the table if it doesn't exist.
	 */
	public function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$schema = $this->get_schema();

		dbDelta( $schema );
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool
	 */
	public function exists() {
		global $wpdb;
		$table = $this->get_full_table_name();

		return $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;
	}
}
