<?php
/**
 * Interface for Filesystem Scanner Service.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Interfaces;

use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Services\System_Monitor_Service;

/**
 * Interface for Filesystem Scanner Service.
 */
interface Filesystem_Scanner {

	/**
	 * Start a new filesystem scan
	 *
	 * @param array $options Scan options
	 * @return Scan_Model|false
	 */
	public function start_scan( $options = array() );

	/**
	 * Resume an existing filesystem scan
	 *
	 * @param int $scan_id The scan ID to resume
	 * @return Scan_Model|false
	 */
	public function resume_scan( $scan_id );

	/**
	 * Get main uploads folder content (files and immediate subfolders)
	 *
	 * @param int $scan_id Scan ID
	 * @return array Folder content with files and subfolders
	 */
	public function get_main_folder_content( $scan_id );

	/**
	 * Get specific folder content (files and immediate subfolders)
	 *
	 * @param int    $scan_id     Scan ID
	 * @param string $folder_path Folder path relative to uploads directory
	 * @return array Folder content with files and subfolders
	 */
	public function get_folder_content( $scan_id, $folder_path );

	/**
	 * Process a batch of files
	 *
	 * @param int   $scan_id    The scan ID
	 * @param array $file_paths Array of relative file paths
	 * @param array $options    Scan options
	 * @return array Processing results
	 */
	public function process_file_batch( $scan_id, $file_paths, $options = array() );

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
	 * Get system monitor instance
	 *
	 * @return System_Monitor_Service
	 */
	public function get_system_monitor();
}
