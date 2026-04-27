<?php
/**
 * Scheduler Service
 *
 * Handles scheduled tasks like automatic trash deletion.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Interfaces\Settings;

/**
 * Scheduler Service Class
 */
class Scheduler_Service {

	/**
	 * Settings service
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Trash service
	 *
	 * @var Trash_Service
	 */
	private $trash_service;

	/**
	 * Cron hook name for auto-deletion
	 *
	 * @var string
	 */
	const AUTO_DELETE_HOOK = 'media_sweep_auto_delete_trash';

	/**
	 * Constructor
	 *
	 * @param Settings      $settings Settings service.
	 * @param Trash_Service $trash_service Trash service.
	 */
	public function __construct( Settings $settings, Trash_Service $trash_service ) {
		$this->settings      = $settings;
		$this->trash_service = $trash_service;
		$this->init();
	}

	/**
	 * Initialize scheduler
	 */
	private function init() {
		// Register the cron hook
		add_action( self::AUTO_DELETE_HOOK, array( $this, 'run_auto_delete_trash' ) );

		// Schedule the cron job if not already scheduled
		add_action( 'init', array( $this, 'schedule_auto_delete' ) );

		// Reschedule when settings are updated
		add_action( 'media_sweep_settings_updated', array( $this, 'reschedule_on_settings_change' ) );

		// Clear scheduled event on plugin deactivation
		register_deactivation_hook( MEDIA_SWEEP_PLUGIN_FILE, array( $this, 'clear_scheduled_events' ) );
	}

	/**
	 * Schedule automatic trash deletion
	 */
	public function schedule_auto_delete() {
		$settings         = $this->settings->get_all_settings();
		$auto_delete_days = isset( $settings['auto_delete_after_days'] ) ? (int) $settings['auto_delete_after_days'] : 0;

		// If auto-delete is disabled (0 days), clear any existing scheduled event
		if ( 0 === $auto_delete_days ) {
			$this->clear_scheduled_events();
			return;
		}

		// Schedule if not already scheduled
		if ( ! wp_next_scheduled( self::AUTO_DELETE_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::AUTO_DELETE_HOOK );
		}
	}

	/**
	 * Run automatic trash deletion
	 *
	 * This is triggered by the cron job daily.
	 */
	public function run_auto_delete_trash() {
		$settings         = $this->settings->get_all_settings();
		$auto_delete_days = isset( $settings['auto_delete_after_days'] ) ? (int) $settings['auto_delete_after_days'] : 0;

		// Skip if auto-delete is disabled
		if ( 0 === $auto_delete_days ) {
			return;
		}

		// Log the start of auto-deletion
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'Media Sweep: Starting auto-deletion of trash files older than %d days', $auto_delete_days ) );
		}

		try {
			// Get files older than the specified days
			$result = $this->trash_service->auto_delete_old_files( $auto_delete_days );

			// Log the result
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					sprintf(
						'Media Sweep: Auto-deleted %d files, %d errors',
						$result['deleted_count'],
						$result['error_count']
					)
				);
			}

			// Trigger action hook for developers
			do_action( 'media_sweep_auto_delete_completed', $result );

		} catch ( \Exception $e ) {
			// Log any errors
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Media Sweep: Auto-deletion failed - ' . $e->getMessage() );
			}

			// Trigger error hook for developers
			do_action( 'media_sweep_auto_delete_failed', $e );
		}
	}

	/**
	 * Clear all scheduled events
	 */
	public function clear_scheduled_events() {
		$timestamp = wp_next_scheduled( self::AUTO_DELETE_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::AUTO_DELETE_HOOK );
		}
	}

	/**
	 * Reschedule when settings change
	 *
	 * @param array $new_settings Updated settings.
	 */
	public function reschedule_on_settings_change( $new_settings ) {
		// Clear existing schedule
		$this->clear_scheduled_events();

		// Reschedule with new settings
		$this->schedule_auto_delete();
	}

	/**
	 * Get next scheduled run time
	 *
	 * @return int|false Unix timestamp of next run, or false if not scheduled.
	 */
	public function get_next_scheduled_time() {
		return wp_next_scheduled( self::AUTO_DELETE_HOOK );
	}

	/**
	 * Force run auto-delete immediately (useful for testing)
	 *
	 * @return array Result of the deletion.
	 */
	public function force_run_auto_delete() {
		$settings         = $this->settings->get_all_settings();
		$auto_delete_days = isset( $settings['auto_delete_after_days'] ) ? (int) $settings['auto_delete_after_days'] : 0;

		if ( 0 === $auto_delete_days ) {
			return array(
				'success' => false,
				'message' => __( 'Auto-delete is disabled.', 'media-sweep' ),
			);
		}

		$result = $this->trash_service->auto_delete_old_files( $auto_delete_days );

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
