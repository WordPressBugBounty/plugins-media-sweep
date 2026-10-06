<?php
/**
 * Scan Driver Service - a bounded multi-tick loop over Scan_Runner_Service.
 *
 * An agent driving a scan one tick at a time burns a tool call per slice, and
 * a large library can need dozens. This service runs several ticks inside one
 * request so the caller spends a handful of calls instead of forty.
 *
 * The budget is deliberately small (5-15 seconds, default 12). These plugins
 * target shared hosting, where max_execution_time and the gateway in front of
 * PHP - mod_fcgid at 40s, nginx/Apache at 60s - kill a silent request and
 * return an HTML error page. The whole checkpoint architecture exists to
 * survive exactly that by keeping every request short and resumable, so a long
 * in-request loop here would reintroduce the failure it was built to avoid.
 *
 * The single-tick primitive stays exposed alongside this loop on purpose: on a
 * constrained host even two ticks in one request is too much, and a caller that
 * has watched a request die needs to fall back to the smallest possible slice.
 * This service is the convenience; run_tick() is the contract.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

/**
 * Scan Driver Service class
 */
class Scan_Driver_Service {

	/**
	 * Lowest wall-clock budget accepted, in seconds.
	 */
	const MIN_BUDGET_SECONDS = 5;

	/**
	 * Highest wall-clock budget accepted, in seconds. Kept well inside the
	 * lowest common gateway ceiling after WP bootstrap and serialization.
	 */
	const MAX_BUDGET_SECONDS = 15;

	/**
	 * Per-tick budget handed to the runner, in seconds. Small enough that
	 * several ticks fit inside the wall clock and no single tick can overrun
	 * the whole budget on its own.
	 */
	const TICK_BUDGET_SECONDS = 5;

	/**
	 * Tick runner.
	 *
	 * @var Scan_Runner_Service
	 */
	protected $runner;

	/**
	 * Constructor.
	 *
	 * @param Scan_Runner_Service $runner Tick runner.
	 */
	public function __construct( Scan_Runner_Service $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Run ticks until the scan finishes, locks, errors, or the budget runs out.
	 *
	 * Returns after the FIRST tick that reports a lock, so the caller can wait
	 * out the other worker instead of spinning against a transient it will
	 * never win.
	 *
	 * @param int $scan_id     Scan ID.
	 * @param int $max_seconds Wall-clock budget, clamped to 5-15 seconds.
	 * @return array|\WP_Error Loop summary, or the runner's error verbatim.
	 */
	public function run( $scan_id, $max_seconds = 12 ) {
		$scan_id     = (int) $scan_id;
		$max_seconds = $this->clamp_budget( $max_seconds );

		$start   = microtime( true );
		$ticks   = 0;
		$elapsed = 0.0;
		$result  = array();

		do {
			$tick = $this->runner->run_tick( $scan_id, self::TICK_BUDGET_SECONDS );

			// A dead scan, a completed scan or a failed slice is the caller's
			// problem, not something to retry inside this loop.
			if ( is_wp_error( $tick ) ) {
				return $tick;
			}

			++$ticks;
			$result = is_array( $tick ) ? $tick : array();

			if ( ! empty( $result['locked'] ) || ! empty( $result['done'] ) ) {
				break;
			}

			$elapsed = microtime( true ) - $start;

			// Only start another tick when a whole one still fits, so the loop
			// stops inside the budget rather than one tick past it.
		} while ( ( $elapsed + self::TICK_BUDGET_SECONDS ) <= $max_seconds );

		return array(
			'phase'          => isset( $result['phase'] ) ? (string) $result['phase'] : '',
			'done'           => ! empty( $result['done'] ),
			'progress'       => isset( $result['progress'] ) && is_array( $result['progress'] ) ? $result['progress'] : array(),
			'ticks'          => (int) $ticks,
			'elapsed_ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'locked'         => ! empty( $result['locked'] ),
			'retry_after_ms' => isset( $result['retry_after_ms'] ) ? (int) $result['retry_after_ms'] : null,
		);
	}

	/**
	 * Clamp a requested budget into the accepted range.
	 *
	 * @param mixed $max_seconds Requested budget in seconds.
	 * @return int Clamped budget.
	 */
	protected function clamp_budget( $max_seconds ) {
		$max_seconds = (int) $max_seconds;

		return max( self::MIN_BUDGET_SECONDS, min( self::MAX_BUDGET_SECONDS, $max_seconds ) );
	}
}
