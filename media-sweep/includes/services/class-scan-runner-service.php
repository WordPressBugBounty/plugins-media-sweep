<?php
/**
 * Scan Runner Service - the tick orchestrator.
 *
 * One tick = one time-budgeted REST request that advances a scan from its
 * persisted checkpoint through whatever phases fit in the budget, then
 * returns progress + the next phase. The server owns all pagination state,
 * so the client loop is trivial (POST tick until done) and any retried or
 * duplicated tick is naturally idempotent.
 *
 * Safety nets:
 * - transient lock so a second tab / impatient refresh cannot run two ticks
 *   concurrently over the same scan;
 * - shutdown handler that converts a hard PHP fatal (max execution time,
 *   out of memory) into a resumable 'paused' scan instead of a dead one;
 * - last_tick_at heartbeat so the UI can distinguish a live scan from a
 *   stale "In Progress" left behind by a closed tab.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Utils\Time_Budget;

/**
 * Scan Runner Service class
 */
class Scan_Runner_Service {

	/**
	 * Tick lock lifetime in seconds. Longer than any budget so a crashed
	 * tick cannot leave a lock that outlives its own request for long.
	 */
	const LOCK_SECONDS = 30;

	/**
	 * A lock older than this is stale: its tick was killed before it could
	 * release (gateway kills skip shutdown handlers), so the next tick takes
	 * over instead of waiting out the transient TTL.
	 */
	const LOCK_STEAL_SECONDS = 18;

	/**
	 * Scan currently being ticked (for the shutdown handler).
	 *
	 * @var int|null
	 */
	protected static $active_scan_id = null;

	/**
	 * Whether the shutdown handler is registered.
	 *
	 * @var bool
	 */
	protected static $shutdown_registered = false;

	/**
	 * Media scanner.
	 *
	 * @var Media_Scanner_Service
	 */
	protected $media_scanner;

	/**
	 * Reference extractor.
	 *
	 * @var Reference_Extractor_Service
	 */
	protected $extractor;

	/**
	 * Constructor.
	 *
	 * @param Media_Scanner_Service       $media_scanner Media scanner.
	 * @param Reference_Extractor_Service $extractor     Reference extractor.
	 */
	public function __construct( Media_Scanner_Service $media_scanner, Reference_Extractor_Service $extractor ) {
		$this->media_scanner = $media_scanner;
		$this->extractor     = $extractor;
	}

	/**
	 * Run one tick of a scan.
	 *
	 * @param int        $scan_id        Scan ID.
	 * @param float|null $budget_seconds Optional explicit budget (WP-CLI).
	 * @return array|\WP_Error Tick payload.
	 */
	public function run_tick( $scan_id, $budget_seconds = null ) {
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new \WP_Error( 'scan_not_found', __( 'Scan not found.', 'media-sweep' ), array( 'status' => 404 ) );
		}

		if ( Scan_Model::STATUS_COMPLETED === $scan->status ) {
			return new \WP_Error( 'scan_already_completed', __( 'Scan is already completed.', 'media-sweep' ), array( 'status' => 400 ) );
		}

		$lock_key  = 'mswp_tick_lock_' . (int) $scan_id;
		$lock_time = (int) get_transient( $lock_key );
		if ( $lock_time && ( time() - $lock_time ) < self::LOCK_STEAL_SECONDS ) {
			return array(
				'success'        => true,
				'locked'         => true,
				'retry_after_ms' => 3000,
				'phase'          => $scan->phase,
				'done'           => false,
				'progress'       => $this->build_progress( $scan->phase, is_array( $scan->checkpoint ) ? $scan->checkpoint : array() ),
			);
		}
		set_transient( $lock_key, time(), self::LOCK_SECONDS );

		$this->arm_shutdown_net( $scan_id );

		$budget     = new Time_Budget( $budget_seconds );
		$checkpoint = is_array( $scan->checkpoint ) ? $scan->checkpoint : array();
		$phase      = $scan->phase ? $scan->phase : Scan_Model::PHASE_EXTRACT_POSTS;
		$options    = is_array( $scan->options ) ? $scan->options : array();
		$done       = false;

		try {
			while ( ! $budget->should_stop() && ! $done ) {
				if ( Reference_Extractor_Service::is_extraction_phase( $phase ) ) {
					$extraction_done = $this->extractor->advance_extraction( $scan_id, $checkpoint, $phase, $options, $budget );
					if ( ! $extraction_done ) {
						break;
					}

					// Extraction finished: media scans verdict server-side;
					// filesystem scans hand enumeration back to the client.
					if ( 'media_library' === $scan->mode ) {
						$phase = Scan_Model::PHASE_VERDICTS;
					} else {
						$phase = Scan_Model::PHASE_ENUMERATE;
						$done  = true;
					}
					continue;
				}

				if ( Scan_Model::PHASE_VERDICTS === $phase ) {
					if ( $this->media_scanner->run_verdicts_slice( $scan_id, $checkpoint, $options, $budget ) ) {
						$phase = Scan_Model::PHASE_DONE;
						$done  = true;
					}
					continue;
				}

				// PHASE_ENUMERATE (filesystem) and PHASE_DONE are terminal
				// for the tick loop - the client takes over from here.
				$done = true;
			}
		} catch ( \Throwable $e ) {
			$scan->update(
				array(
					'status'       => Scan_Model::STATUS_PAUSED,
					'checkpoint'   => $checkpoint,
					'phase'        => $phase,
					'last_tick_at' => current_time( 'mysql' ),
				)
			);
			delete_transient( $lock_key );
			self::$active_scan_id = null;

			return new \WP_Error(
				'tick_failed',
				$e->getMessage(),
				array(
					'status'    => 500,
					'retryable' => true,
					'phase'     => $phase,
				)
			);
		}

		$scan->update(
			array(
				'status'       => Scan_Model::STATUS_RUNNING,
				'checkpoint'   => $checkpoint,
				'phase'        => $phase,
				'last_tick_at' => current_time( 'mysql' ),
			)
		);

		delete_transient( $lock_key );
		self::$active_scan_id = null;

		return array(
			'success'    => true,
			'locked'     => false,
			'phase'      => $phase,
			'done'       => $done && in_array( $phase, array( Scan_Model::PHASE_DONE, Scan_Model::PHASE_ENUMERATE ), true ),
			'progress'   => $this->build_progress( $phase, $checkpoint ),
			'elapsed_ms' => (int) round( $budget->elapsed() * 1000 ),
		);
	}

	/**
	 * Build the progress payload the UI renders (counts, never percentages -
	 * the client maps phases to progress-bar ranges).
	 *
	 * @param string $phase      Current phase.
	 * @param array  $checkpoint Checkpoint.
	 * @return array
	 */
	public function build_progress( $phase, array $checkpoint ) {
		return array(
			'phase'       => $phase,
			'posts_total' => isset( $checkpoint['posts_total'] ) ? (int) $checkpoint['posts_total'] : 0,
			'posts_done'  => isset( $checkpoint['posts_done'] ) ? (int) $checkpoint['posts_done'] : 0,
			'deep_total'  => isset( $checkpoint['deep_columns'] ) ? count( $checkpoint['deep_columns'] ) : 0,
			'deep_done'   => isset( $checkpoint['deep_index'] ) ? (int) $checkpoint['deep_index'] : 0,
			'att_total'   => isset( $checkpoint['att_total'] ) ? (int) $checkpoint['att_total'] : 0,
			'att_done'    => isset( $checkpoint['att_done'] ) ? (int) $checkpoint['att_done'] : 0,
			'att_errors'  => isset( $checkpoint['att_errors'] ) ? (int) $checkpoint['att_errors'] : 0,
		);
	}

	/**
	 * Register the shutdown net once: a fatal that matches resource
	 * exhaustion marks the active scan paused (resumable), not dead.
	 *
	 * @param int $scan_id Scan being ticked.
	 */
	protected function arm_shutdown_net( $scan_id ) {
		self::$active_scan_id = (int) $scan_id;

		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;

		register_shutdown_function(
			function () {
				if ( ! self::$active_scan_id ) {
					return;
				}

				$error = error_get_last();
				if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
					return;
				}

				global $wpdb;
				$table = $wpdb->prefix . 'mswp_scans';
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = %s, last_tick_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						Scan_Model::STATUS_PAUSED,
						current_time( 'mysql' ),
						self::$active_scan_id
					)
				);
				delete_transient( 'mswp_tick_lock_' . self::$active_scan_id );
			}
		);
	}

	/**
	 * Build the status payload for a scan: phase, progress, and whether the
	 * scan is stale (heartbeat older than the threshold - the driving tab is
	 * gone and the UI should offer Resume instead of "In Progress").
	 *
	 * @param Scan_Model $scan Scan.
	 * @return array
	 */
	public function get_status( $scan ) {
		$checkpoint = is_array( $scan->checkpoint ) ? $scan->checkpoint : array();

		$is_stale = false;
		if ( Scan_Model::STATUS_RUNNING === $scan->status && $scan->last_tick_at ) {
			$last     = strtotime( $scan->last_tick_at );
			$is_stale = $last && ( current_time( 'timestamp' ) - $last ) > Scan_Model::STALE_AFTER_SECONDS;
		}

		return array(
			'id'           => (int) $scan->id,
			'mode'         => $scan->mode,
			'status'       => $scan->status,
			'phase'        => $scan->phase,
			'is_stale'     => $is_stale,
			'resumable'    => in_array( $scan->status, array( Scan_Model::STATUS_RUNNING, Scan_Model::STATUS_PAUSED ), true ) && ! $scan->finished_at,
			'last_tick_at' => $scan->last_tick_at,
			'progress'     => $this->build_progress( (string) $scan->phase, $checkpoint ),
		);
	}
}
