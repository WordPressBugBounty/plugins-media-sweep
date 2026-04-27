<?php
/**
 * Interface for Media Scanner Service.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Interfaces;

use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Services\System_Monitor_Service;

/**
 * Interface for Media Scanner Service.
 */
interface Media_Scanner {

	/**
	 * Start a new media library scan
	 *
	 * @param array $options Scan options
	 * @return Scan_Model|false
	 */
	public function start_scan( $options = array() );

	/**
	 * Resume an existing media library scan
	 *
	 * @param int $scan_id The scan ID to resume
	 * @return Scan_Model|false
	 */
	public function resume_scan( $scan_id );

	/**
	 * Get all media attachments for frontend processing
	 *
	 * @param int   $page     Page number (1-based)
	 * @param int   $per_page Items per page
	 * @param array $options  Scan options
	 * @return array
	 */
	public function get_media_attachments( $page = 1, $per_page = 100, $options = array() );

	/**
	 * Process a batch of media attachments
	 *
	 * @param int   $scan_id     The scan ID
	 * @param array $attachments Array of attachment IDs
	 * @param array $options     Scan options
	 * @return array Processing results
	 */
	public function process_media_batch( $scan_id, $attachments, $options = array() );

	/**
	 * Get scan results/counts
	 *
	 * @param int $scan_id The scan ID
	 * @return array
	 */
	public function get_scan_results( $scan_id );

	/**
	 * Finish the scan
	 *
	 * @param int $scan_id The scan ID
	 * @return bool
	 */
	public function finish_scan( $scan_id );

	/**
	 * Check media usage for a specific attachment
	 *
	 * @param int   $attachment_id The attachment ID
	 * @param array $options       Scan options
	 * @return array Array with 'status' and 'notes'
	 */
	public function check_media_usage( $attachment_id, $options = array() );

	/**
	 * Get system monitor instance
	 *
	 * @return System_Monitor_Service
	 */
	public function get_system_monitor();
}
