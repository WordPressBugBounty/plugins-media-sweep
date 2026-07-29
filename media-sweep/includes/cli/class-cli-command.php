<?php
/**
 * WP-CLI command - the sanctioned unattended path for huge sites.
 *
 * Runs the same tick engine as the admin UI, but from the shell where no
 * gateway timeout exists, with generous per-slice budgets.
 *
 * @package media-sweep
 */

namespace Media_Sweep\CLI;

use Media_Sweep\Models\Scan_Model;
use WP_CLI;

/**
 * Scan the media library for unused files from the command line.
 */
class CLI_Command {

	/**
	 * Run a media library scan to completion.
	 *
	 * ## OPTIONS
	 *
	 * [--deep]
	 * : Also scan non-core database tables for media usage.
	 *
	 * [--no-thumbnails]
	 * : Skip thumbnail files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-sweep scan
	 *     wp media-sweep scan --deep
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function scan( $args, $assoc_args ) {
		$container = \media_sweep()->services();

		$media_scanner = $container->get( 'media_scanner_service' );
		$runner        = $container->get( 'scan_runner_service' );

		$options = array(
			'deep_scan'          => ! empty( $assoc_args['deep'] ),
			'include_thumbnails' => ! isset( $assoc_args['no-thumbnails'] ),
		);

		$scan = $media_scanner->start_scan( $options );
		if ( ! $scan ) {
			WP_CLI::error( 'Failed to start scan.' );
		}

		WP_CLI::log( sprintf( 'Scan #%d started.', $scan->id ) );

		$last_phase = '';
		do {
			// 60s slices: no gateway exists on the CLI, but bounded slices
			// keep checkpoints fresh so Ctrl+C is always resumable.
			$result = $runner->run_tick( $scan->id, 60 );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$progress = $result['progress'];
			if ( $result['phase'] !== $last_phase ) {
				$last_phase = $result['phase'];
				WP_CLI::log( 'Phase: ' . $last_phase );
			}

			if ( $progress['att_total'] > 0 ) {
				WP_CLI::log( sprintf( '  attachments %d / %d', $progress['att_done'], $progress['att_total'] ) );
			} elseif ( $progress['posts_total'] > 0 ) {
				WP_CLI::log( sprintf( '  content items %d / %d', $progress['posts_done'], $progress['posts_total'] ) );
			}
		} while ( empty( $result['done'] ) );

		$results = $media_scanner->finish_scan( $scan->id );

		WP_CLI::success(
			sprintf(
				'Scan complete. Files: %d | In use: %d | Unused: %d | Orphaned: %d',
				$results['total_files'],
				$results['in_use'],
				$results['unused'],
				$results['orphaned']
			)
		);
	}

	/**
	 * Show the status of the most recent scans.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-sweep status
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function status( $args, $assoc_args ) {
		$scans = Scan_Model::query()->order_by( 'id', 'DESC' )->limit( 5 )->get();

		if ( empty( $scans ) ) {
			WP_CLI::log( 'No scans yet.' );
			return;
		}

		foreach ( $scans as $scan ) {
			WP_CLI::log(
				sprintf(
					'#%d  %s  %s  phase=%s  started=%s  finished=%s',
					$scan->id,
					$scan->mode,
					$scan->status ? $scan->status : '-',
					$scan->phase ? $scan->phase : '-',
					$scan->started_at,
					$scan->finished_at ? $scan->finished_at : '-'
				)
			);
		}
	}
}
