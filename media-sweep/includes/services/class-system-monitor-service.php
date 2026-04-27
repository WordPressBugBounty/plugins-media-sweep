<?php
/**
 * System Monitor Service - Simplified resource monitoring for pause/resume
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

/**
 * System Monitor Service class - Focused on pause/resume decisions
 */
class System_Monitor_Service {

	/**
	 * Memory threshold (75%)
	 *
	 * @var float
	 */
	protected $memory_threshold = 0.75;

	/**
	 * Time threshold (75%)
	 *
	 * @var float
	 */
	protected $time_threshold = 0.75;

	/**
	 * Maximum execution time
	 *
	 * @var int
	 */
	protected $max_execution_time;

	/**
	 * Start time
	 *
	 * @var float
	 */
	protected $start_time;

	/**
	 * Memory limit in bytes
	 *
	 * @var int
	 */
	protected $memory_limit;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_monitoring();
	}

	/**
	 * Initialize monitoring
	 */
	protected function init_monitoring() {
		// Get memory limit
		$memory_limit = ini_get( 'memory_limit' );
		if ( $memory_limit === '-1' ) {
			$this->memory_limit = 1024 * 1024 * 1024; // 1GB default
		} else {
			$this->memory_limit = $this->convert_to_bytes( $memory_limit );
		}

		// Get execution time limit
		$max_execution_time       = ini_get( 'max_execution_time' );
		$this->max_execution_time = $max_execution_time > 0 ? $max_execution_time : 30;

		// Set start time
		$this->start_time = microtime( true );
	}

	/**
	 * Reset monitoring for new operation
	 */
	public function reset_monitoring() {
		$this->start_time = microtime( true );
	}

	/**
	 * Set memory threshold
	 *
	 * @param float $threshold Memory threshold (0.0 to 1.0)
	 */
	public function set_memory_threshold( $threshold ) {
		$this->memory_threshold = max( 0.1, min( 1.0, $threshold ) );
	}

	/**
	 * Set time threshold
	 *
	 * @param float $threshold Time threshold (0.0 to 1.0)
	 */
	public function set_time_threshold( $threshold ) {
		$this->time_threshold = max( 0.1, min( 1.0, $threshold ) );
	}

	/**
	 * Check if processing should pause
	 *
	 * @return array Array with 'pause' boolean and 'reason' string
	 */
	public function should_pause() {
		$resources = $this->check_system_resources();

		if ( $resources['memory_usage_percent'] >= $this->memory_threshold ) {
			return array(
				'pause'  => true,
				'reason' => sprintf(
					/* translators: %s is the memory usage percentage */
					__( 'Memory usage reached %s%%', 'media-sweep' ),
					round( $resources['memory_usage_percent'] * 100, 1 )
				),
			);
		}

		if ( $resources['time_usage_percent'] >= $this->time_threshold ) {
			return array(
				'pause'  => true,
				'reason' => sprintf(
					/* translators: %s is the execution time percentage */
					__( 'Execution time reached %s%%', 'media-sweep' ),
					round( $resources['time_usage_percent'] * 100, 1 )
				),
			);
		}

		return array(
			'pause'  => false,
			'reason' => '',
		);
	}

	/**
	 * Check if it's safe to continue processing
	 *
	 * @return bool True if safe to continue
	 */
	public function is_safe_to_continue() {
		$check = $this->should_pause();
		return ! $check['pause'];
	}

	/**
	 * Check current system resources
	 *
	 * @return array Resource information
	 */
	public function check_system_resources() {
		// Memory usage
		$memory_used          = memory_get_usage( true );
		$memory_usage_percent = $memory_used / $this->memory_limit;

		// Time usage
		$time_elapsed       = microtime( true ) - $this->start_time;
		$time_usage_percent = $time_elapsed / $this->max_execution_time;

		return array(
			'memory_used'          => $memory_used,
			'memory_limit'         => $this->memory_limit,
			'memory_usage_percent' => $memory_usage_percent,
			'memory_usage_mb'      => round( $memory_used / 1024 / 1024, 2 ),
			'memory_limit_mb'      => round( $this->memory_limit / 1024 / 1024, 2 ),
			'time_elapsed'         => $time_elapsed,
			'time_limit'           => $this->max_execution_time,
			'time_usage_percent'   => $time_usage_percent,
			'time_elapsed_seconds' => round( $time_elapsed, 2 ),
			'memory_threshold'     => $this->memory_threshold,
			'time_threshold'       => $this->time_threshold,
		);
	}

	/**
	 * Get resource warning message if any threshold is exceeded
	 *
	 * @return string|null Warning message or null if safe
	 */
	public function get_resource_warning() {
		$resources = $this->check_system_resources();

		if ( $resources['memory_usage_percent'] >= $this->memory_threshold ) {
			return sprintf(
				/* translators: %1$s is the memory usage percentage, %2$s is current memory usage in MB, %3$s is memory limit in MB */
				__( 'Memory usage reached %1$s%% of limit (%2$s MB / %3$s MB)', 'media-sweep' ),
				round( $resources['memory_usage_percent'] * 100, 1 ),
				$resources['memory_usage_mb'],
				$resources['memory_limit_mb']
			);
		}

		if ( $resources['time_usage_percent'] >= $this->time_threshold ) {
			return sprintf(
				/* translators: %1$s is the execution time percentage, %2$s is elapsed time in seconds, %3$s is time limit in seconds */
				__( 'Execution time reached %1$s%% of limit (%2$s seconds / %3$s seconds)', 'media-sweep' ),
				round( $resources['time_usage_percent'] * 100, 1 ),
				$resources['time_elapsed_seconds'],
				$resources['time_limit']
			);
		}

		return null;
	}

	/**
	 * Get resource status for display
	 *
	 * @return array Resource status information
	 */
	public function get_resource_status() {
		$resources = $this->check_system_resources();
		$warning   = $this->get_resource_warning();

		return array(
			'status'       => $warning ? 'warning' : 'ok',
			'message'      => $warning ?: __( 'Resources within normal limits', 'media-sweep' ),
			'should_pause' => ! $this->is_safe_to_continue(),
			'resources'    => $resources,
		);
	}

	/**
	 * Get recommended batch size based on server resources
	 *
	 * @return int Recommended batch size (20-100)
	 */
	public function get_recommended_batch_size() {
		// Base batch size on max_execution_time
		// Lower timeout = smaller batches for more frequent pauses
		if ( $this->max_execution_time <= 30 ) {
			$batch_size = 20; // Very limited server
		} elseif ( $this->max_execution_time <= 60 ) {
			$batch_size = 30; // Limited server
		} elseif ( $this->max_execution_time <= 120 ) {
			$batch_size = 50; // Standard server
		} else {
			$batch_size = 100; // Well-resourced server
		}

		// Adjust based on memory limit
		$memory_mb = $this->memory_limit / 1024 / 1024;
		if ( $memory_mb < 128 ) {
			$batch_size = min( $batch_size, 20 ); // Very limited memory
		} elseif ( $memory_mb < 256 ) {
			$batch_size = min( $batch_size, 30 ); // Limited memory
		}

		return $batch_size;
	}

	/**
	 * Get server resource info for frontend
	 *
	 * @return array Server resource information
	 */
	public function get_server_info() {
		return array(
			'max_execution_time' => $this->max_execution_time,
			'memory_limit_mb'    => round( $this->memory_limit / 1024 / 1024 ),
			'recommended_batch'  => $this->get_recommended_batch_size(),
		);
	}

	/**
	 * Convert memory notation to bytes
	 *
	 * @param string $value Memory value (e.g., '256M', '1G')
	 * @return int Memory in bytes
	 */
	protected function convert_to_bytes( $value ) {
		$value = trim( $value );
		$last  = strtolower( $value[ strlen( $value ) - 1 ] );
		$num   = (int) $value;

		switch ( $last ) {
			case 'g':
				$num *= 1024;
			case 'm':
				$num *= 1024;
			case 'k':
				$num *= 1024;
		}

		return $num;
	}
}
