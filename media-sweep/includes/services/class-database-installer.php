<?php
/**
 * Database installer service.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Interfaces\Database_Installer as Database_Installer_Interface;
use Media_Sweep\Database\Tables\Scans_Table;
use Media_Sweep\Database\Tables\Files_Table;
use Media_Sweep\Database\Tables\File_Scan_Table;

/**
 * Database installer service.
 */
class Database_Installer implements Database_Installer_Interface {

	/**
	 * Tables to install.
	 *
	 * @var array
	 */
	protected $tables = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->tables = array(
			new Scans_Table(),
			new Files_Table(),
			new File_Scan_Table(),
		);
	}

	/**
	 * Install all tables.
	 *
	 * @return void
	 */
	public function install_tables() {
		foreach ( $this->tables as $table ) {
			$table->install();
		}
	}

	/**
	 * Check if all tables exist.
	 *
	 * @return bool
	 */
	public function tables_exist() {
		foreach ( $this->tables as $table ) {
			if ( ! $table->exists() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get missing tables.
	 *
	 * @return array
	 */
	public function get_missing_tables() {
		$missing = array();

		foreach ( $this->tables as $table ) {
			if ( ! $table->exists() ) {
				$missing[] = $table->get_full_table_name();
			}
		}

		return $missing;
	}
}
