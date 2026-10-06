<?php
/**
 * Scan lifecycle abilities.
 *
 * Everything an agent needs to look at scan history and to drive one scan from
 * start to sealed report: list, status, report, start, tick, bounded loop and
 * finish. Reads dispatch to the plugin's own REST routes or call a service;
 * nothing here contains business logic of its own.
 *
 * Not exposed, deliberately: delete_scan_record (destroys the evidence trail
 * for an earlier sweep) and the legacy client-driven scanner endpoints, which
 * only exist for pre-1.1.0 JS still cached in an open tab.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abilities;

/**
 * Declares the scan lifecycle abilities.
 */
class Scan_Abilities {

	/**
	 * Build the ability definitions for this provider.
	 *
	 * @param Abilities_Module $module Bootstrap module supplying the shared helpers.
	 * @return array Map of ability name => wp_register_ability() args.
	 */
	public static function definitions( $module ) {
		return array_merge(
			self::audit_definitions( $module ),
			self::action_definitions( $module )
		);
	}

	/**
	 * Read-only abilities: scan history, live status and the sealed report.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @return array Map of ability name => args.
	 */
	private static function audit_definitions( $module ) {
		return array(
			'media-sweep/list-scans'      => array(
				'label'               => __( 'List Media Sweep scans', 'media-sweep' ),
				'description'         => __( 'Lists past and running scans with their mode, status and phase. Use it to find a scan_id before asking for a status or a report. Read-only.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_AUDIT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'     => array(
							'type'        => 'integer',
							'description' => __( 'Page of the collection to return. Starts at 1.', 'media-sweep' ),
							'minimum'     => 1,
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => __( 'Scans per page, 1 to 100.', 'media-sweep' ),
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'mode'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to one scan mode, for example media_library or file_system.', 'media-sweep' ),
							// Both modes stay listable here: filesystem scans started
							// from wp-admin must remain visible even though start-scan
							// no longer offers that mode.
							'enum'        => array( 'media_library', 'file_system' ),
						),
						'orderby'  => array(
							'type'        => 'string',
							'description' => __( 'Column to sort by.', 'media-sweep' ),
							'enum'        => array( 'id', 'started_at', 'finished_at', 'mode' ),
						),
						'order'    => array(
							'type'        => 'string',
							'description' => __( 'Sort direction.', 'media-sweep' ),
							'enum'        => array( 'asc', 'desc' ),
						),
					),
					'required'             => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					// Dispatching keeps the route's own defaults, sanitize
					// callbacks and the CVE-2026-77824 `fields` allowlist in
					// the path. Only keys the caller actually sent are passed,
					// so the route's defaults still apply to the rest.
					$params = array();
					foreach ( array( 'page', 'per_page', 'mode', 'orderby', 'order' ) as $key ) {
						if ( is_array( $input ) && isset( $input[ $key ] ) ) {
							$params[ $key ] = $input[ $key ];
						}
					}

					return $module->dispatch( 'GET', '/media-sweep/v1/scans', $params );
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/list-scans' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'media-sweep/get-scan-status' => array(
				'label'               => __( 'Get scan status', 'media-sweep' ),
				'description'         => __( 'Returns the live phase, progress counts and status of one scan. This is the wait-loop primitive: poll it while a scan runs. When is_stale is true the heartbeat is older than 120 seconds, which means the driver stopped - resume the scan rather than keep polling.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_AUDIT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the scan to inspect.', 'media-sweep' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'scan_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'phase'     => array(
							// mswp_scans.phase is VARCHAR(32) NULL and get_status()
							// returns it raw, so a row that never recorded a phase
							// would fail output validation under a plain 'string'.
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Current phase of the scan. Null on a scan row that has not recorded one yet.', 'media-sweep' ),
						),
						// mswp_scans.status is NOT NULL DEFAULT 'running', so it can
						// never come back null and is deliberately not unioned.
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'Lifecycle status: running, paused, completed or failed.', 'media-sweep' ),
						),
						'progress'  => array(
							'type'        => 'object',
							'description' => __( 'Progress counters for the current phase.', 'media-sweep' ),
						),
						'resumable' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the scan can be resumed from its checkpoint.', 'media-sweep' ),
						),
						'is_stale'  => array(
							'type'        => 'boolean',
							'description' => __( 'True when the last tick is older than 120 seconds.', 'media-sweep' ),
						),
					),
					// The progress block varies by phase, and the payload also
					// carries id/mode/last_tick_at, so extra keys are allowed.
					'additionalProperties' => true,
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$scan_id = is_array( $input ) && isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;
					$scan    = \Media_Sweep\Models\Scan_Model::find( $scan_id );

					if ( ! $scan ) {
						return $module->agent_error(
							'scan_not_found',
							__( 'No scan exists with that ID.', 'media-sweep' ),
							array(
								'status'     => 404,
								'agent_hint' => 'List scans with media-sweep/list-scans to find a valid scan_id.',
								'recovery'   => array(
									'ability' => 'media-sweep/list-scans',
									'input'   => null,
								),
							)
						);
					}

					return $module->service( 'scan_runner_service' )->get_status( $scan );
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/get-scan-status' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'media-sweep/get-scan-report' => array(
				'label'               => __( 'Get scan report', 'media-sweep' ),
				'description'         => __( 'Returns the counts a scan produced: how many files are in the Media Library, how many are not, and how many are in use, unused or orphaned. Read-only, and safe to call on a scan that has not finished yet - the counts simply reflect what has been recorded so far.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_AUDIT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the scan to report on.', 'media-sweep' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'scan_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::report_output_schema(),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$scan_id = is_array( $input ) && isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;
					$scan    = \Media_Sweep\Models\Scan_Model::find( $scan_id );

					// get_scan_results() only counts rows, so an unknown scan_id
					// would read as a truthful all-zero report. Answer exactly as
					// get-scan-status does for the same input.
					if ( ! $scan ) {
						return $module->agent_error(
							'scan_not_found',
							__( 'No scan exists with that ID.', 'media-sweep' ),
							array(
								'status'     => 404,
								'agent_hint' => 'List scans with media-sweep/list-scans to find a valid scan_id.',
								'recovery'   => array(
									'ability' => 'media-sweep/list-scans',
									'input'   => null,
								),
							)
						);
					}

					$rejected = self::reject_non_media_scan( $module, $scan );
					if ( $rejected ) {
						return $rejected;
					}

					$results = $module->service( 'media_scanner_service' )->get_scan_results( $scan_id );

					return self::shape_report( $results );
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/get-scan-report' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
		);
	}

	/**
	 * Write abilities: start a scan, drive it, seal it.
	 *
	 * None of these delete a file. They write plugin tables only.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @return array Map of ability name => args.
	 */
	private static function action_definitions( $module ) {
		return array(
			'media-sweep/start-scan'    => array(
				'label'               => __( 'Start a scan', 'media-sweep' ),
				'description'         => __( 'Starts a new scan and returns its scan_id. Writes only this plugin\'s own tables and deletes nothing. The scan does not advance on its own: drive it with media-sweep/run-scan (or media-sweep/run-scan-tick) until done is true, then call media-sweep/finish-scan to seal it and get the report.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_ACTIONS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_type' => array(
							'type'        => 'string',
							'description' => __( 'Which scan to run. media_library finds Media Library attachments that nothing references. The filesystem scan is not offered here because its enumeration step is driven from the browser (/media-sweep/v1/scanner/{id}/main-structure, /folder-recursive, /process-batch) and is not exposed as an ability, so it has to be run from the Media Sweep screen in wp-admin.', 'media-sweep' ),
							'enum'        => array( 'media_library' ),
						),
						'deep_scan' => array(
							'type'        => 'boolean',
							'description' => __( 'Also read every other database table column looking for references. Much slower and much more thorough. Cost, not danger. Omit it and the scanner keeps its own default: media_library scans shallow.', 'media-sweep' ),
						),
					),
					'required'             => array( 'scan_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the new scan.', 'media-sweep' ),
						),
						'mode'    => array(
							'type'        => 'string',
							'description' => __( 'Scan mode that was started.', 'media-sweep' ),
						),
						'status'  => array(
							'type'        => 'string',
							'description' => __( 'Lifecycle status of the new scan.', 'media-sweep' ),
						),
					),
					'required'             => array( 'scan_id', 'mode', 'status' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$input = is_array( $input ) ? $input : array();

					$scan_type = isset( $input['scan_type'] ) ? (string) $input['scan_type'] : '';
					$service   = ( 'file_system' === $scan_type ) ? 'filesystem_scanner_service' : 'media_scanner_service';

					// Send deep_scan ONLY when the caller set it, so each scanner keeps its own
					// default: media_library defaults to false, file_system to true. A blanket
					// false here made an ability-started filesystem scan shallower than the same
					// scan from wp-admin, which pushes more files into the unused bucket.
					$options = array();
					if ( isset( $input['deep_scan'] ) ) {
						$options['deep_scan'] = (bool) $input['deep_scan'];
					}

					$scan = $module->service( $service )->start_scan( $options );

					if ( ! $scan ) {
						return $module->agent_error(
							'scan_start_failed',
							__( 'The scan record could not be created.', 'media-sweep' ),
							array(
								'status'     => 500,
								'agent_hint' => 'Check that the Media Sweep database tables exist, then try again.',
							)
						);
					}

					return array(
						'scan_id' => (int) $scan->id,
						'mode'    => (string) $scan->mode,
						'status'  => (string) $scan->status,
					);
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/start-scan' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			),

			'media-sweep/run-scan-tick' => array(
				'label'               => __( 'Run one scan tick', 'media-sweep' ),
				'description'         => __( 'Advances a scan by one short, time-budgeted slice and returns the new phase and progress. Call it repeatedly until done is true. It is genuinely idempotent: the checkpoint is owned by the server, reference inserts are UNIQUE-keyed, a transient lock makes a concurrent call return locked: true instead of running the slice twice, and a shutdown handler pauses the scan rather than killing it. On locked: true, wait retry_after_ms and call again.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_ACTIONS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_id'     => array(
							'type'        => 'integer',
							'description' => __( 'ID of the scan to advance.', 'media-sweep' ),
							'minimum'     => 1,
						),
						'budget_hint' => array(
							'type'        => 'integer',
							'description' => __( 'How many seconds a previous request survived on this host. The server takes a slice comfortably inside that ceiling.', 'media-sweep' ),
							'minimum'     => 2,
							'maximum'     => 60,
						),
					),
					'required'             => array( 'scan_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::tick_output_schema(),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$scan_id     = is_array( $input ) && isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;
					$budget_hint = is_array( $input ) && isset( $input['budget_hint'] ) ? (int) $input['budget_hint'] : 0;

					$rejected = self::reject_non_media_scan( $module, \Media_Sweep\Models\Scan_Model::find( $scan_id ) );
					if ( $rejected ) {
						return $rejected;
					}

					// run_tick() takes a real budget, not a hint: a hint of 60
					// passed straight through would BE a 60s budget. Convert it
					// the same way Scanner_Controller::tick() does - run the
					// slice comfortably inside the ceiling the caller survived.
					$budget_seconds = null;
					if ( $budget_hint > 0 ) {
						$budget_seconds = max( 2.0, min( $budget_hint * 0.6, 15.0 ) );
					}

					return $module->service( 'scan_runner_service' )->run_tick( $scan_id, $budget_seconds );
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/run-scan-tick' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => false,
						// Never destructive AND idempotent together: that pair
						// routes /run to DELETE, whose input is not decoded.
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'media-sweep/run-scan'      => array(
				'label'               => __( 'Run a scan for a few seconds', 'media-sweep' ),
				'description'         => __( 'Runs several scan ticks inside one request so an agent does not burn forty tool calls on one scan. It is still bounded - max_seconds is clamped to 5-15 - so it usually returns with done still false. Keep calling it until done is true. On a slow or constrained host prefer media-sweep/run-scan-tick, which takes the smallest possible slice. On locked: true, wait retry_after_ms and call again.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_ACTIONS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_id'     => array(
							'type'        => 'integer',
							'description' => __( 'ID of the scan to advance.', 'media-sweep' ),
							'minimum'     => 1,
						),
						'max_seconds' => array(
							'type'        => 'integer',
							'description' => __( 'Wall-clock budget for the whole loop, 5 to 15 seconds. Kept small so the request finishes well inside shared-hosting and gateway timeouts.', 'media-sweep' ),
							'minimum'     => 5,
							'maximum'     => 15,
						),
					),
					'required'             => array( 'scan_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::loop_output_schema(),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$scan_id     = is_array( $input ) && isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;
					$max_seconds = is_array( $input ) && isset( $input['max_seconds'] ) ? (int) $input['max_seconds'] : 12;

					$rejected = self::reject_non_media_scan( $module, \Media_Sweep\Models\Scan_Model::find( $scan_id ) );
					if ( $rejected ) {
						return $rejected;
					}

					return $module->service( 'scan_driver_service' )->run( $scan_id, $max_seconds );
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/run-scan' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),

			'media-sweep/finish-scan'   => array(
				'label'               => __( 'Finish a scan', 'media-sweep' ),
				'description'         => __( 'Seals a finished scan and returns its final counts. Sealing the scan PURGES its raw reference index (mswp_scan_refs), so per-file evidence can no longer be re-rendered from raw references afterwards. Notes already written onto file rows survive. Call media-sweep/explain-file-usage for anything you need to justify before finishing.', 'media-sweep' ),
				'category'            => Abilities_Module::CATEGORY_ACTIONS,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scan_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the scan to seal.', 'media-sweep' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'scan_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::report_output_schema(),
				'execute_callback'    => static function ( $input = null ) use ( $module ) {
					$scan_id = is_array( $input ) && isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;

					$rejected = self::reject_non_media_scan( $module, \Media_Sweep\Models\Scan_Model::find( $scan_id ) );
					if ( $rejected ) {
						return $rejected;
					}

					$results = $module->service( 'media_scanner_service' )->finish_scan( $scan_id );

					// finish_scan() returns false for an unknown scan; without
					// this the six counters would read as a truthful all-zero
					// report for a scan that does not exist.
					if ( ! is_array( $results ) ) {
						return $module->agent_error(
							'scan_not_found',
							__( 'No scan exists with that ID.', 'media-sweep' ),
							array(
								'status'     => 404,
								'agent_hint' => 'List scans with media-sweep/list-scans to find a valid scan_id.',
								'recovery'   => array(
									'ability' => 'media-sweep/list-scans',
									'input'   => null,
								),
							)
						);
					}

					return self::shape_report( $results );
				},
				'permission_callback' => $module->permission_callback( 'media-sweep/finish-scan' ),
				'meta'                => $module->meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			),
		);
	}

	/**
	 * Refuse a scan this layer cannot drive or read.
	 *
	 * A filesystem scan is enumerated by the browser, so through abilities its
	 * counters stay at zero however the scan is driven. Only media_library is
	 * answered here. A missing scan is left to the caller's own handling.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param object|null      $scan   Scan row, or null when it does not exist.
	 * @return \WP_Error|null Error to return, or null to continue.
	 */
	private static function reject_non_media_scan( $module, $scan ) {
		if ( ! $scan || 'media_library' === $scan->mode ) {
			return null;
		}

		return $module->agent_error(
			'filesystem_scan_not_supported',
			__( 'This is a file_system scan, and its counts cannot be read through abilities.', 'media-sweep' ),
			array(
				'status'     => 400,
				'agent_hint' => 'Do not report this scan as clean: a zero report here would NOT mean the site has no orphaned files, only that this layer cannot read a filesystem scan. Run and read filesystem scans on the Media Sweep screen in wp-admin. Through abilities, start a media_library scan instead with media-sweep/start-scan.',
			)
		);
	}

	/**
	 * Output schema shared by get-scan-report and finish-scan.
	 *
	 * A fixed, countable shape, so it is safe to declare strictly. Output
	 * validation is enforced by core and has no escape hatch here.
	 *
	 * @return array Output schema.
	 */
	private static function report_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'in_media'     => array(
					'type'        => 'integer',
					'description' => __( 'Files on disk that are in the Media Library.', 'media-sweep' ),
				),
				'not_in_media' => array(
					'type'        => 'integer',
					'description' => __( 'Files on disk that are not in the Media Library.', 'media-sweep' ),
				),
				'in_use'       => array(
					'type'        => 'integer',
					'description' => __( 'Files something references.', 'media-sweep' ),
				),
				'unused'       => array(
					'type'        => 'integer',
					'description' => __( 'Files nothing references.', 'media-sweep' ),
				),
				'orphaned'     => array(
					'type'        => 'integer',
					'description' => __( 'Attachment records whose file is missing.', 'media-sweep' ),
				),
				'total_files'  => array(
					'type'        => 'integer',
					'description' => __( 'Total files recorded for the scan.', 'media-sweep' ),
				),
			),
			'required'             => array( 'in_media', 'not_in_media', 'in_use', 'unused', 'orphaned', 'total_files' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema for run-scan-tick.
	 *
	 * Permissive on purpose. The locked branch returns a different shape from
	 * the normal branch (no elapsed_ms, plus retry_after_ms), so nothing may be
	 * required or output validation would reject a perfectly good lock result.
	 *
	 * @return array Output schema.
	 */
	private static function tick_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => self::tick_output_properties(),
			'additionalProperties' => true,
		);
	}

	/**
	 * Output schema for run-scan, the bounded loop.
	 *
	 * Same permissive shape as one tick, plus the number of ticks the loop got
	 * through before it ran out of budget.
	 *
	 * @return array Output schema.
	 */
	private static function loop_output_schema() {
		$properties = self::tick_output_properties();

		$properties['ticks'] = array(
			'type'        => 'integer',
			'description' => __( 'How many ticks ran inside this request.', 'media-sweep' ),
		);

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => true,
		);
	}

	/**
	 * Properties shared by the tick and loop output schemas.
	 *
	 * @return array Schema properties.
	 */
	private static function tick_output_properties() {
		return array(
			'phase'          => array(
				// The locked branch of run_tick() returns the scan row's phase
				// raw, and that column is nullable until a first tick records
				// one. The unlocked branch coalesces, so only a lock can be null.
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Phase the scan is in after this call. Null when the row has not recorded a phase yet.', 'media-sweep' ),
			),
			'done'           => array(
				'type'        => 'boolean',
				'description' => __( 'True when no further ticks are needed.', 'media-sweep' ),
			),
			'progress'       => array(
				'type'        => 'object',
				'description' => __( 'Progress counters for the current phase.', 'media-sweep' ),
			),
			'elapsed_ms'     => array(
				'type'        => 'integer',
				'description' => __( 'Milliseconds spent inside this call.', 'media-sweep' ),
			),
			'locked'         => array(
				'type'        => 'boolean',
				'description' => __( 'True when another worker holds the scan lock and nothing was run.', 'media-sweep' ),
			),
			'retry_after_ms' => array(
				// Nullable, not merely optional: one tick omits the key when
				// it is not locked, while the bounded loop always emits it and
				// sends an explicit null. Output validation is enforced, so a
				// plain 'integer' here rejects every unlocked loop result.
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'Milliseconds to wait before calling again after a lock. Null when nothing was locked.', 'media-sweep' ),
			),
		);
	}

	/**
	 * Rebuild the six scan counters by hand, cast to integers.
	 *
	 * @param array $results Raw counts from the scanner service.
	 * @return array Report matching report_output_schema().
	 */
	private static function shape_report( $results ) {
		$results = is_array( $results ) ? $results : array();

		return array(
			'in_media'     => isset( $results['in_media'] ) ? (int) $results['in_media'] : 0,
			'not_in_media' => isset( $results['not_in_media'] ) ? (int) $results['not_in_media'] : 0,
			'in_use'       => isset( $results['in_use'] ) ? (int) $results['in_use'] : 0,
			'unused'       => isset( $results['unused'] ) ? (int) $results['unused'] : 0,
			'orphaned'     => isset( $results['orphaned'] ) ? (int) $results['orphaned'] : 0,
			'total_files'  => isset( $results['total_files'] ) ? (int) $results['total_files'] : 0,
		);
	}
}
