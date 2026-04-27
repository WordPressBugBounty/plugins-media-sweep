<?php
/**
 * Database Module for media sweep.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Database;

use Media_Sweep\Interfaces\Database_Installer;

/**
 * Database module class.
 */
class Database_Module {

	/**
	 * Database installer.
	 *
	 * @var Database_Installer
	 */
	private $installer;

	/**
	 * Constructor.
	 *
	 * @param Database_Installer $installer Database installer.
	 */
	public function __construct( Database_Installer $installer ) {
		$this->installer = $installer;
		$this->init();
	}

	/**
	 * Initialize the database module.
	 */
	private function init() {
		// Register activation hook for table installation.
		register_activation_hook( MEDIA_SWEEP_PLUGIN_FILE, array( $this, 'install_tables' ) );

		// Check if tables exist, if not, install them.
		add_action( 'plugins_loaded', array( $this, 'maybe_install_tables' ), 20 );
	}

	/**
	 * Install database tables.
	 */
	public function install_tables() {
		$this->installer->install_tables();
	}

	/**
	 * Maybe install tables if they don't exist.
	 */
	public function maybe_install_tables() {
		if ( ! $this->installer->tables_exist() ) {
			$this->installer->install_tables();
		}
	}
}
