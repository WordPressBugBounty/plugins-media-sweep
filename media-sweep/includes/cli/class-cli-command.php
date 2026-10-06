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

	/**
	 * List the abilities Media Sweep registered with the WordPress Abilities API.
	 *
	 * Registration failures are silent on production - core reports them with
	 * _doing_it_wrong() and returns null - and the whole feature is a no-op
	 * below WordPress 6.9. This is the only in-product way to see what is
	 * actually registered, which capability guards it, and whether an MCP
	 * client can reach it.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-sweep abilities
	 *     wp media-sweep abilities --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function abilities( $args, $assoc_args ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			WP_CLI::warning( 'The Abilities API requires WordPress 6.9 or newer. Media Sweep registers no abilities on this version.' );
			return;
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$fields = array( 'name', 'category', 'capability', 'readonly', 'destructive', 'idempotent', 'mcp' );

		$items = array();

		foreach ( wp_get_abilities( array( 'namespace' => 'media-sweep' ) ) as $ability ) {
			$name = $ability->get_name();

			// The $args filtering on wp_get_abilities() is 7.1; on 6.9 and 7.0
			// the extra argument is ignored and every ability comes back.
			if ( strpos( $name, 'media-sweep/' ) !== 0 ) {
				continue;
			}

			$meta        = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$mcp_public  = isset( $meta['mcp']['public'] ) ? (bool) $meta['mcp']['public'] : false;

			$items[] = array(
				'name'        => $name,
				'category'    => $ability->get_category(),
				/** This filter is documented in includes/abilities/class-abilities-module.php */
				'capability'  => apply_filters( 'media_sweep_ability_capability', 'manage_options', $name ),
				'readonly'    => $this->flag( isset( $annotations['readonly'] ) ? $annotations['readonly'] : null ),
				'destructive' => $this->flag( isset( $annotations['destructive'] ) ? $annotations['destructive'] : null ),
				'idempotent'  => $this->flag( isset( $annotations['idempotent'] ) ? $annotations['idempotent'] : null ),
				'mcp'         => $this->flag( $mcp_public ),
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::warning( 'No Media Sweep abilities are registered. Check the error log for _doing_it_wrong() notices from the Abilities API.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Render an annotation flag for the table.
	 *
	 * Core defaults every annotation to null, which is not the same claim as
	 * false, so an undeclared flag is shown as such rather than as "no".
	 *
	 * @param bool|null $value Flag value.
	 * @return string Rendered flag.
	 */
	private function flag( $value ) {
		if ( null === $value ) {
			return '-';
		}

		return $value ? 'yes' : 'no';
	}
}
