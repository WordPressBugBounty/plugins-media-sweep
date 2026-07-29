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
	 * Option key tracking the schema version most recently applied via dbDelta.
	 */
	const SCHEMA_VERSION_OPTION = 'mswp_db_schema_version';

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
	 * Install database tables and record the schema version.
	 */
	public function install_tables() {
		$this->installer->install_tables();
		$this->backfill_scan_statuses();
		update_option( self::SCHEMA_VERSION_OPTION, MEDIA_SWEEP_VERSION );
	}

	/**
	 * Data migration for pre-1.1.0 scans: the status column is new and
	 * defaults to 'running', but a scan with finished_at set was completed.
	 * Without this, upgraded sites would show old finished scans as running.
	 */
	private function backfill_scan_statuses() {
		global $wpdb;

		$table = $wpdb->prefix . 'mswp_scans';
		$wpdb->query(
			"UPDATE {$table} SET status = 'completed', phase = 'done'
			 WHERE finished_at IS NOT NULL AND status = 'running'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name, no user input.
	}

	/**
	 * Install on first activation, or re-run dbDelta when the plugin has been
	 * upgraded since we last applied the schema (so column type changes are
	 * picked up on existing installs without requiring deactivate/reactivate).
	 */
	public function maybe_install_tables() {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION );

		if ( ! $this->installer->tables_exist() || $installed_version !== MEDIA_SWEEP_VERSION ) {
			$this->install_tables();
		}
	}
}
