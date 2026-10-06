<?php
/**
 * Read-only audit abilities for Media Sweep.
 *
 * Everything in this file answers a question and changes nothing: what a scan
 * found, why a file was judged used or unused, what is in the trash, how much
 * headroom the server has, and which destructive settings are armed.
 *
 * The write counterparts are deliberately absent. There is no ability here to
 * change a file's verdict, empty the trash or edit the settings, because each
 * of those either widens the next sweep or removes the only undo.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abilities;

/**
 * Declares Media Sweep's read-only abilities.
 */
class Audit_Abilities {

	/**
	 * Build the ability definitions this provider owns.
	 *
	 * @param Abilities_Module $module Bootstrap module supplying the shared helpers.
	 * @return array Map of ability name => wp_register_ability() args.
	 */
	public static function definitions( $module ) {
		// Every ability in this file is a pure read, so they all share one
		// annotation set. readonly => true routes /run to GET, which is why
		// each description explains the bracket-encoded query string.
		$meta = $module->meta(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		return array(
			'media-sweep/list-scan-files'     => self::list_scan_files( $module, $meta ),
			'media-sweep/explain-file-usage'  => self::explain_file_usage( $module, $meta ),
			'media-sweep/get-trash-stats'     => self::trash_stats( $module, $meta ),
			'media-sweep/list-trash-files'    => self::list_trash_files( $module, $meta ),
			'media-sweep/get-server-capacity' => self::server_capacity( $module, $meta ),
			'media-sweep/get-settings'        => self::settings( $module, $meta ),
		);
	}

	/**
	 * The ability that shows a human exactly what a sweep would touch.
	 *
	 * No output schema: the payload is controller-owned rows plus an appended
	 * per-status totals block, and output validation is enforced with no
	 * escape hatch.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param array            $meta   Shared read-only meta block.
	 * @return array Ability args.
	 */
	private static function list_scan_files( $module, array $meta ) {
		return array(
			'label'               => __( 'List scan files', 'media-sweep' ),
			'description'         => __( 'Lists the files one scan recorded, each with its path, type, size in bytes and Media Library attachment ID, so a person can see what a sweep would actually touch before anything is deleted. The status vocabulary: "in_use" means the scanner found at least one reference to the file and it must not be swept; "unused" means the file is in the Media Library but no reference to it was found anywhere; "orphaned" means a Media Library record whose file is no longer on disk; "in_media" means a file found on disk that does have a Media Library record; "not_in_media" means a file found on disk with no Media Library record at all. The response is one page of rows plus a totals block counting every status in the scan. Because this ability is read-only its run endpoint is a GET and the input is read from the query string, so bracket-encode it: ?input[scan_id]=12&input[status]=unused&input[per_page]=50.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'scan_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the scan to read, from media-sweep/list-scans.', 'media-sweep' ),
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'in_use', 'unused', 'orphaned', 'in_media', 'not_in_media' ),
						'description' => __( 'Return only files carrying this verdict. Omit to return every file in the scan.', 'media-sweep' ),
					),
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Substring match on the file path.', 'media-sweep' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page of results to return. Defaults to 1.', 'media-sweep' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Rows per page, 1 to 100. Defaults to 25.', 'media-sweep' ),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'scan_id', 'file_id', 'status', 'recorded_at' ),
						'description' => __( 'Column to sort by: id, scan_id, file_id, status or recorded_at. Defaults to id.', 'media-sweep' ),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Sort direction. Defaults to desc.', 'media-sweep' ),
					),
				),
				'required'             => array( 'scan_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				// Dispatching rather than querying keeps the controller's
				// `fields` allowlist (CVE-2026-77824 hardening) and its
				// prepared search subquery in the path. `fields` itself is
				// not exposed: an agent has no reason to narrow columns.
				$params = $module->apply_defaults(
					$input,
					array(
						'page'     => 1,
						'per_page' => 25,
					)
				);

				return $module->dispatch( 'GET', '/media-sweep/v1/scan-files', $params );
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/list-scan-files' ),
			'meta'                => $meta,
		);
	}

	/**
	 * The evidence ability behind a single finding.
	 *
	 * No output schema: the row carries scan and file relations plus a
	 * rendered notes array whose shape is owned by the scanner service.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param array            $meta   Shared read-only meta block.
	 * @return array Ability args.
	 */
	private static function explain_file_usage( $module, array $meta ) {
		return array(
			'label'               => __( 'Explain file usage', 'media-sweep' ),
			'description'         => __( 'Returns one scan finding with its scan and file relations and a rendered "notes" array. This is how an agent JUSTIFIES a deletion instead of asserting one: every note is a concrete reference the scanner actually found. The formats are "content:123" (used in the content of post 123), "featured:123" (the featured image of post 123), "custom_field:key:123" (stored in the "key" custom field of post 123), "gallery_block:123" (inside a gallery on post 123), "option:site_icon" (stored in a WordPress option) and "table:x.y" (found in column y of database table x). At most ten notes are kept per file, so a heavily reused file shows a sample rather than every reference. The notes are rendered while the scan is still running: once media-sweep/finish-scan has purged the raw reference index they can no longer be re-rendered, so read the evidence before finishing a scan. Read-only, so the run endpoint is a GET with bracket-encoded input: ?input[file_scan_id]=4567.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'file_scan_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the scan finding, taken from the "id" column of media-sweep/list-scan-files. This is not the attachment ID and not the file ID.', 'media-sweep' ),
					),
				),
				'required'             => array( 'file_scan_id' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				$file_scan_id = is_array( $input ) && isset( $input['file_scan_id'] ) ? (int) $input['file_scan_id'] : 0;

				if ( $file_scan_id <= 0 ) {
					return $module->agent_error(
						'invalid_file_scan_id',
						__( 'No file_scan_id was supplied.', 'media-sweep' ),
						array(
							'status'     => 400,
							'agent_hint' => __( 'Read the "id" column of media-sweep/list-scan-files and pass it as file_scan_id. This is not the attachment ID and not the file ID.', 'media-sweep' ),
							'recovery'   => array(
								'ability' => 'media-sweep/list-scan-files',
								'input'   => null,
							),
						)
					);
				}

				return $module->dispatch( 'GET', '/media-sweep/v1/scan-files/' . $file_scan_id );
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/explain-file-usage' ),
			'meta'                => $meta,
		);
	}

	/**
	 * The number a human must see before approving anything destructive.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param array            $meta   Shared read-only meta block.
	 * @return array Ability args.
	 */
	private static function trash_stats( $module, array $meta ) {
		return array(
			'label'               => __( 'Get trash stats', 'media-sweep' ),
			'description'         => __( 'Reports how many files are sitting in the Media Sweep trash and how many bytes they occupy. This is the number a human must see before approving anything destructive, because the trash is the plugin\'s only undo: media-sweep/delete-scanned-files moves the original file there rather than unlinking it, so a file still in the trash can be restored and a file that has left it cannot. Takes no input.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'required'             => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'count'      => array(
						'type'        => 'integer',
						'description' => __( 'Number of files currently in the trash.', 'media-sweep' ),
					),
					'size_bytes' => array(
						'type'        => 'integer',
						'description' => __( 'Total size of those files, in bytes.', 'media-sweep' ),
					),
					'size_human' => array(
						'type'        => 'string',
						'description' => __( 'The same total formatted for a person, e.g. "1.2 GB".', 'media-sweep' ),
					),
				),
				'required'             => array( 'count', 'size_bytes', 'size_human' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the input schema is an empty object, so there is nothing to read.
				$stats = $module->service( 'trash_service' )->get_trash_stats();

				if ( ! is_array( $stats ) ) {
					return $module->agent_error(
						'media_sweep_trash_stats_unavailable',
						__( 'The Media Sweep trash could not be measured.', 'media-sweep' ),
						array(
							'status'     => 500,
							'agent_hint' => __( 'The trash folder under wp-content/uploads/mswp-trash is missing or unreadable. Do not read this as an empty trash: an unreadable trash means an earlier sweep may not be recoverable, so report it rather than proposing another deletion.', 'media-sweep' ),
							'recovery'   => array(
								'ability' => 'media-sweep/list-trash-files',
								'input'   => array( 'per_page' => 1 ),
							),
						)
					);
				}

				$size_bytes = isset( $stats['total_size'] ) ? (int) $stats['total_size'] : 0;
				$size_human = size_format( $size_bytes );

				return array(
					'count'      => isset( $stats['total_files'] ) ? (int) $stats['total_files'] : 0,
					'size_bytes' => $size_bytes,
					'size_human' => is_string( $size_human ) ? $size_human : (string) $size_bytes,
				);
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/get-trash-stats' ),
			'meta'                => $meta,
		);
	}

	/**
	 * Listing of what is currently recoverable from the trash.
	 *
	 * No output schema: this is a filesystem listing shaped by the service.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param array            $meta   Shared read-only meta block.
	 * @return array Ability args.
	 */
	private static function list_trash_files( $module, array $meta ) {
		return array(
			'label'               => __( 'List trash files', 'media-sweep' ),
			'description'         => __( 'Lists the files currently sitting in the Media Sweep trash, with filters for file type, directory, filename substring and minimum age. Each item carries an "age_days" value, so this is the way to preview exactly which files an age-based purge would reach. Warning: every call walks the whole trash folder on disk recursively, so keep per_page modest and page through rather than asking for everything at once. Read-only, so the run endpoint is a GET with bracket-encoded input: ?input[min_age_days]=30&input[file_type]=image.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'page'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page of results to return. Defaults to 1.', 'media-sweep' ),
					),
					'per_page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __( 'Files per page, 1 to 100. Defaults to 25. Every call is a full recursive walk, so prefer a small page.', 'media-sweep' ),
					),
					'file_type'    => array(
						'type'        => 'string',
						'enum'        => array( 'image', 'video', 'audio', 'document', 'other' ),
						'description' => __( 'Return only files of this type.', 'media-sweep' ),
					),
					'directory'    => array(
						'type'        => 'string',
						'description' => __( 'Return only files in this directory, relative to the trash root, e.g. "2024/06".', 'media-sweep' ),
					),
					'search'       => array(
						'type'        => 'string',
						'description' => __( 'Substring match on the file name.', 'media-sweep' ),
					),
					'min_age_days' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Return only files that have been in the trash for at least this many days.', 'media-sweep' ),
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				$params = $module->apply_defaults(
					$input,
					array(
						'page'     => 1,
						'per_page' => 25,
					)
				);

				$page     = (int) $params['page'];
				$per_page = (int) $params['per_page'];

				// Whatever is left over is a filter the service understands.
				unset( $params['page'], $params['per_page'] );

				$result = $module->service( 'trash_service' )->get_trash_files( $page, $per_page, $params );

				if ( ! is_array( $result ) ) {
					return $module->agent_error(
						'media_sweep_trash_listing_unavailable',
						__( 'The Media Sweep trash could not be listed.', 'media-sweep' ),
						array(
							'status'     => 500,
							'agent_hint' => __( 'The trash folder under wp-content/uploads/mswp-trash could not be walked. Check whether it exists at all before drawing any conclusion about what is recoverable.', 'media-sweep' ),
							'recovery'   => array(
								'ability' => 'media-sweep/get-trash-stats',
								'input'   => null,
							),
						)
					);
				}

				return $result;
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/list-trash-files' ),
			'meta'                => $meta,
		);
	}

	/**
	 * Measured server headroom, so batch sizes are chosen rather than guessed.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param array            $meta   Shared read-only meta block.
	 * @return array Ability args.
	 */
	private static function server_capacity( $module, array $meta ) {
		return array(
			'label'               => __( 'Get server capacity', 'media-sweep' ),
			'description'         => __( 'Reports this server\'s measured PHP time and memory headroom together with the batch size Media Sweep recommends for it. Pick the batch_size for media-sweep/delete-scanned-files from this measurement rather than guessing: too large a batch on a constrained host times out mid-sweep, which leaves the run half finished and forces a resume. Takes no input.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'required'             => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'recommended_batch_size' => array(
						'type'        => 'integer',
						'description' => __( 'Files per batch this server can be expected to handle. Note that media-sweep/delete-scanned-files caps batch_size at 50.', 'media-sweep' ),
					),
					'server'                 => array(
						'type'                 => 'object',
						'description'          => __( 'Raw server measurements: maximum execution time in seconds and memory limit in megabytes.', 'media-sweep' ),
						// The block is owned by the monitor service, so a new
						// measurement must not fail output validation here.
						'additionalProperties' => true,
					),
				),
				'required'             => array( 'recommended_batch_size', 'server' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the input schema is an empty object, so there is nothing to read.
				$monitor = $module->service( 'system_monitor_service' );
				$server  = $monitor->get_server_info();

				if ( ! is_array( $server ) || empty( $server ) ) {
					return $module->agent_error(
						'media_sweep_server_capacity_unavailable',
						__( 'Server capacity could not be measured.', 'media-sweep' ),
						array(
							'status'     => 500,
							'agent_hint' => __( 'No measurement is available, so do not guess a large batch. Use a batch_size of 10 for any batched Media Sweep operation until this reports again.', 'media-sweep' ),
						)
					);
				}

				return array(
					'recommended_batch_size' => (int) $monitor->get_recommended_batch_size(),
					'server'                 => (array) $server,
				);
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/get-server-capacity' ),
			'meta'                => $meta,
		);
	}

	/**
	 * The two settings that arm destructive behaviour, read-only on purpose.
	 *
	 * @param Abilities_Module $module Bootstrap module.
	 * @param array            $meta   Shared read-only meta block.
	 * @return array Ability args.
	 */
	private static function settings( $module, array $meta ) {
		return array(
			'label'               => __( 'Get settings', 'media-sweep' ),
			'description'         => __( 'Reads the two Media Sweep settings: "auto_delete_after_days", the age at which trashed files are permanently removed, and "delete_data_on_uninstall", whether uninstalling the plugin destroys its data. Read this early, because both describe destruction that later happens without anybody being asked again. This ability is read-only ON PURPOSE and there is deliberately no write counterpart: writing auto_delete_after_days arms a daily cron that permanently deletes trash files, and writing delete_data_on_uninstall arms the table-dropping branch of uninstall, so neither is reachable from an ability and both stay a human decision in wp-admin. Takes no input.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'required'             => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'auto_delete_after_days'   => array(
						'type'        => 'integer',
						'description' => __( 'Days a file stays in the trash before the daily cron deletes it permanently. 0 disables that cron entirely.', 'media-sweep' ),
					),
					'delete_data_on_uninstall' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether uninstalling the plugin drops its tables, deletes its options and empties the trash folder.', 'media-sweep' ),
					),
				),
				'required'             => array( 'auto_delete_after_days', 'delete_data_on_uninstall' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input = null ) use ( $module ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the input schema is an empty object, so there is nothing to read.
				// get_all_settings() returns the raw option, which is false on
				// a fresh install, so the stored values are merged over the
				// same defaults the settings controller uses.
				$settings = $module->service( 'settings' )->get_all_settings();
				$settings = wp_parse_args(
					is_array( $settings ) ? $settings : array(),
					array(
						'auto_delete_after_days'   => 30,
						'delete_data_on_uninstall' => false,
					)
				);

				return array(
					'auto_delete_after_days'   => (int) $settings['auto_delete_after_days'],
					'delete_data_on_uninstall' => (bool) $settings['delete_data_on_uninstall'],
				);
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/get-settings' ),
			'meta'                => $meta,
		);
	}
}
