<?php
/**
 * Sweep abilities for Media Sweep - preview, delete and restore.
 *
 * This is the destructive corner of the ability surface, and most of its
 * safety is subtraction. Emptying the trash, deleting a trashed file, purging
 * old trash, deleting a scan record, bulk-editing verdicts and writing
 * settings are all deliberately absent: an agent that can sweep files but
 * cannot empty the trash always leaves the site an undo, and cannot arm the
 * daily purge cron or the uninstall table-drop branch.
 *
 * What is left is gated five ways - a read-only preview that is the only
 * issuer of a plan token, a token bound to the exact file set, a file-count
 * checksum, no `force` at any layer, and hard caps on batch size.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abilities;

use Media_Sweep\Models\File_Model;
use Media_Sweep\Models\File_Scan_Model;

/**
 * Declares the preview, delete and restore abilities.
 */
class Sweep_Abilities {

	/**
	 * How many file paths the preview shows as a sample.
	 */
	const MAX_SAMPLE_PATHS = 25;

	/**
	 * Largest number of files one delete or restore call accepts.
	 */
	const MAX_BATCH_IDS = 50;

	/**
	 * File scan verdicts, copied verbatim from File_Scan_Model::$rules.
	 */
	const STATUSES = array( 'in_media', 'not_in_media', 'in_use', 'unused', 'orphaned' );

	/**
	 * Build the ability definitions for this provider.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @return array Map of ability name to registration args.
	 */
	public static function definitions( $module ) {
		return array(
			'media-sweep/preview-sweep'         => self::preview_sweep( $module ),
			'media-sweep/delete-scanned-files'  => self::delete_scanned_files( $module ),
			'media-sweep/restore-scanned-files' => self::restore_scanned_files( $module ),
		);
	}

	/**
	 * The dry run, and the only issuer of a confirmation token.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @return array Registration args.
	 */
	private static function preview_sweep( $module ) {
		return array(
			'label'               => __( 'Preview a media sweep', 'media-sweep' ),
			'description'         => __( 'Dry run for media-sweep/delete-scanned-files. It touches nothing: no file is moved, no row is written, not one transient is set. It resolves exactly which scanned files a sweep would cover and reports the real blast radius - total bytes, a breakdown by file type, a sample of paths, how many Media Library attachments are involved and how many generated thumbnails would be destroyed permanently - then returns a single-use confirmation token bound to that exact set of files. One call returns at most 50 file IDs, which is the most one delete call accepts, so the plan it mints is always sweepable exactly as returned; matching_total reports how many files match the filter altogether. Sweep a larger set one batch at a time: preview, sweep, then preview again with the next_offset this ability returns. media-sweep/delete-scanned-files accepts only an explicit list of IDs and refuses to run without this token, so this ability must run first and a human must see the numbers before anything is deleted.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_AUDIT,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'scan_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Scan whose findings the plan is built from.', 'media-sweep' ),
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => self::STATUSES,
						'description' => __( 'Only include files the scan gave this verdict. Ignored when "ids" is supplied.', 'media-sweep' ),
					),
					'ids'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'Restrict the plan to these file_scan IDs.', 'media-sweep' ),
					),
					'limit'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_BATCH_IDS,
						'description' => __( 'How many matching files this plan covers, 1 to 50. Defaults to 50, which is the most one delete call accepts.', 'media-sweep' ),
					),
					'offset'  => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'How many matching files to skip before building the plan. Use next_offset from the previous preview to walk a large candidate set batch by batch. Defaults to 0.', 'media-sweep' ),
					),
				),
				'required'             => array( 'scan_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::preview_output_schema(),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				return self::run_preview( $module, $input );
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/preview-sweep' ),
			'meta'                => $module->meta(
				array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				)
			),
		);
	}

	/**
	 * The one destructive ability.
	 *
	 * Annotated idempotent:false on purpose. Destructive AND idempotent routes
	 * /run to DELETE, and a DELETE request's `input` query parameter is never
	 * JSON-decoded, so this object-shaped input could not survive the trip.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @return array Registration args.
	 */
	private static function delete_scanned_files( $module ) {
		return array(
			'label'               => __( 'Delete scanned files', 'media-sweep' ),
			'description'         => __( 'Sweeps an explicit, previewed list of scanned files. Requires a confirmation_token minted by media-sweep/preview-sweep for this exact set of files, plus a confirm_file_count equal to the total that preview reported. The file itself is MOVED to the plugin trash and can be restored, but this also calls wp_delete_attachment with force: the attachment post and every generated thumbnail are destroyed permanently, and a later restore creates a NEW attachment ID, so posts referencing the old ID stay broken. Emptying the trash is not available through abilities, so the trash always remains as the undo. Files the scan reports as in use are skipped and counted in skipped_in_use; there is no override for that guard.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_ACTIONS,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'file_scan_ids'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'minItems'    => 1,
						'maxItems'    => self::MAX_BATCH_IDS,
						'uniqueItems' => true,
						'description' => __( 'The exact file_scan IDs to sweep, as returned by media-sweep/preview-sweep. There is no status filter and no "all matching" form: only an explicit list is accepted.', 'media-sweep' ),
					),
					'scan_id'            => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Scan the files belong to. Part of the plan fingerprint, so it must match the scan the preview ran against.', 'media-sweep' ),
					),
					'confirmation_token' => array(
						'type'        => 'string',
						'minLength'   => 32,
						'maxLength'   => 32,
						'description' => __( 'The token returned by media-sweep/preview-sweep for this exact set of files.', 'media-sweep' ),
					),
					'confirm_file_count' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Must equal the total reported by preview-sweep. A boolean is set reflexively by a model; a count can only be obtained by having read the preview.', 'media-sweep' ),
					),
					'start_index'        => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Index into file_scan_ids to start at. Use the resume_index from the previous call. Default 0.', 'media-sweep' ),
					),
					'batch_size'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_BATCH_IDS,
						'description' => __( 'How many files to process in this call. Default 10.', 'media-sweep' ),
					),
				),
				'required'             => array( 'file_scan_ids', 'scan_id', 'confirmation_token', 'confirm_file_count' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::batch_output_schema(),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				return self::run_delete( $module, $input );
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/delete-scanned-files' ),
			'meta'                => $module->meta(
				array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				)
			),
		);
	}

	/**
	 * The recovery path. Needs no token: it is how a mistake gets undone.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @return array Registration args.
	 */
	private static function restore_scanned_files( $module ) {
		return array(
			'label'               => __( 'Restore scanned files', 'media-sweep' ),
			'description'         => __( 'Re-imports a scanned file into the Media Library. It acts only on a file_scan row still marked "not_in_media" whose file record the plugin has trashed: that file is lifted out of the plugin trash and imported as a NEW attachment. A file that is still active on disk is NOT re-imported - it is counted in skipped, with the reason in error_details - and a file already swept cannot be reached here at all, because a sweep removes its file_scan rows; the Media Sweep trash screen in wp-admin is the undo for that. This is the recovery path, so it needs no confirmation token: the restore itself re-checks every target and skips anything it cannot restore. Restored attachments receive a NEW attachment ID; posts that referenced the old ID stay broken.', 'media-sweep' ),
			'category'            => Abilities_Module::CATEGORY_ACTIONS,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'file_scan_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'minItems'    => 1,
						'maxItems'    => self::MAX_BATCH_IDS,
						'uniqueItems' => true,
						'description' => __( 'The file_scan IDs to restore. Only rows still marked "not_in_media" are acted on.', 'media-sweep' ),
					),
					'start_index'   => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Index into file_scan_ids to start at. Use the resume_index from the previous call. Default 0.', 'media-sweep' ),
					),
					'batch_size'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_BATCH_IDS,
						'description' => __( 'How many files to process in this call. Default 10.', 'media-sweep' ),
					),
				),
				'required'             => array( 'file_scan_ids' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::batch_output_schema(),
			'execute_callback'    => static function ( $input = null ) use ( $module ) {
				return self::run_restore( $module, $input );
			},
			'permission_callback' => $module->permission_callback( 'media-sweep/restore-scanned-files' ),
			'meta'                => $module->meta(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				)
			),
		);
	}

	/**
	 * Resolve a sweep plan and mint its token. Writes nothing.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @param mixed                                   $input  Ability input.
	 * @return array|\WP_Error Plan payload, or an agent error.
	 */
	private static function run_preview( $module, $input ) {
		$input = $module->apply_defaults(
			$input,
			array(
				'status' => '',
				'ids'    => array(),
				'limit'  => self::MAX_BATCH_IDS,
				'offset' => 0,
			)
		);

		$scan_id = isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;
		$status  = is_string( $input['status'] ) && '' !== $input['status'] ? $input['status'] : null;
		$ids     = is_array( $input['ids'] ) && ! empty( $input['ids'] ) ? array_map( 'intval', $input['ids'] ) : null;

		$service           = $module->service( 'action_service' );
		$all_file_scan_ids = self::to_int_list( $service->get_batch_files( $scan_id, $status, $ids ) );

		if ( empty( $all_file_scan_ids ) ) {
			return $module->agent_error(
				'no_files_found',
				__( 'No scanned files match this plan.', 'media-sweep' ),
				array(
					'status'     => 400,
					'agent_hint' => __( 'This scan has no files with that verdict, or the supplied IDs belong to another scan. Read the per-status counts with media-sweep/get-scan-report before previewing again.', 'media-sweep' ),
					'recovery'   => array(
						'ability' => 'media-sweep/get-scan-report',
						'input'   => array( 'scan_id' => $scan_id ),
					),
				)
			);
		}

		// One preview mints one sweepable batch: the plan never exceeds what a
		// single delete call accepts, so its output can be passed straight on.
		$matching_total = count( $all_file_scan_ids );
		$limit          = min( self::MAX_BATCH_IDS, max( 1, (int) $input['limit'] ) );
		$offset         = max( 0, (int) $input['offset'] );
		$file_scan_ids  = array_slice( $all_file_scan_ids, $offset, $limit );

		if ( empty( $file_scan_ids ) ) {
			return $module->agent_error(
				'offset_past_end',
				__( 'The offset is past the last matching file, so this plan covers nothing.', 'media-sweep' ),
				array(
					'status'         => 400,
					'matching_total' => $matching_total,
					'agent_hint'     => __( 'Fewer files match than the offset skips. Preview again from offset 0 and walk forward with the next_offset each preview returns.', 'media-sweep' ),
					'recovery'       => array(
						'ability' => 'media-sweep/preview-sweep',
						'input'   => array(
							'scan_id' => $scan_id,
							'offset'  => 0,
						),
					),
				)
			);
		}

		$blast       = self::measure_blast_radius( $file_scan_ids );
		$total       = count( $file_scan_ids );
		$next_offset = ( $offset + $total ) < $matching_total ? $offset + $total : null;

		$next_actions = array(
			array(
				'ability' => 'media-sweep/delete-scanned-files',
				'reason'  => __( 'Show a human these numbers first. Then sweep by passing file_scan_ids exactly as returned here, together with confirmation_token, scan_id, and confirm_file_count set to "total". The token is bound to this exact set of files, so changing the list invalidates it.', 'media-sweep' ),
				'input'   => array(
					'scan_id'            => $scan_id,
					'file_scan_ids'      => $file_scan_ids,
					'confirm_file_count' => $total,
					'start_index'        => 0,
				),
			),
		);

		if ( null !== $next_offset ) {
			$next_actions[] = array(
				'ability' => 'media-sweep/preview-sweep',
				'reason'  => __( 'More files match than one delete call accepts. Sweep this batch first, then preview the next one with the offset below to mint its own token.', 'media-sweep' ),
				'input'   => array(
					'scan_id' => $scan_id,
					'offset'  => $next_offset,
					'limit'   => $limit,
				),
			);
		}

		return array(
			'file_scan_ids'                    => $file_scan_ids,
			'total'                            => $total,
			'matching_total'                   => $matching_total,
			'offset'                           => $offset,
			'next_offset'                      => $next_offset,
			'total_bytes'                      => $blast['total_bytes'],
			'by_type'                          => $blast['by_type'],
			'sample_paths'                     => $blast['sample_paths'],
			'attachments_affected'             => $blast['attachments_affected'],
			'thumbnails_permanently_destroyed' => $blast['thumbnails_permanently_destroyed'],
			'confirmation_token'               => Sweep_Plan::issue( $scan_id, $file_scan_ids ),
			'expires_in_seconds'               => Sweep_Plan::expires_in_seconds(),
			'next_actions'                     => $next_actions,
		);
	}

	/**
	 * Verify the plan, claim the token, then sweep.
	 *
	 * The order is load-bearing, and all of it happens here rather than in the
	 * permission callback: a REST /run request runs normalize, validate and the
	 * permission check TWICE, so a token claimed in a permission callback would
	 * be burnt before execution ever started.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @param mixed                                   $input  Ability input.
	 * @return array|\WP_Error Batch result, or an agent error.
	 */
	private static function run_delete( $module, $input ) {
		$input = $module->apply_defaults(
			$input,
			array(
				'start_index' => 0,
				'batch_size'  => 10,
			)
		);

		$file_scan_ids = self::to_int_list( isset( $input['file_scan_ids'] ) ? $input['file_scan_ids'] : array() );
		$scan_id       = isset( $input['scan_id'] ) ? (int) $input['scan_id'] : 0;
		$token         = isset( $input['confirmation_token'] ) ? (string) $input['confirmation_token'] : '';
		$confirm_count = isset( $input['confirm_file_count'] ) ? (int) $input['confirm_file_count'] : 0;
		$start_index   = max( 0, (int) $input['start_index'] );
		$batch_size    = min( self::MAX_BATCH_IDS, max( 1, (int) $input['batch_size'] ) );

		$replan = array(
			'ability' => 'media-sweep/preview-sweep',
			'input'   => array(
				'scan_id' => $scan_id,
				'ids'     => $file_scan_ids,
			),
		);

		if ( count( $file_scan_ids ) !== $confirm_count ) {
			return $module->agent_error(
				'confirm_count_mismatch',
				sprintf(
					/* translators: 1: number of IDs in the request, 2: number the caller confirmed */
					__( 'This call lists %1$d files but confirm_file_count says %2$d. Nothing was deleted.', 'media-sweep' ),
					count( $file_scan_ids ),
					$confirm_count
				),
				array(
					'status'     => 409,
					'agent_hint' => __( 'confirm_file_count must equal the "total" that media-sweep/preview-sweep reported for this exact set of files. Do not compute or guess it: run the preview and copy the number it returns.', 'media-sweep' ),
					'recovery'   => $replan,
				)
			);
		}

		if ( ! Sweep_Plan::verify( $token, $scan_id, $file_scan_ids ) ) {
			return $module->agent_error(
				'plan_drifted',
				__( 'This confirmation token does not match the files in this request. Nothing was deleted.', 'media-sweep' ),
				array(
					'status'     => 409,
					'agent_hint' => __( 'The file set changed since the preview, or the plan expired. The token is bound to one exact list of file_scan IDs, one scan and one user, and it is only good for 15 to 30 minutes. Run media-sweep/preview-sweep again for the files you actually want to sweep and use the token it returns.', 'media-sweep' ),
					'recovery'   => $replan,
				)
			);
		}

		// process_batch() checks the same thing and returns having deleted
		// nothing, so check it here first rather than burn the plan for free.
		// The reset matters: elapsed time is measured from it, exactly as
		// process_batch() measures it a moment later.
		$monitor = $module->service( 'system_monitor_service' );
		$monitor->reset_monitoring();
		$pause = $monitor->should_pause();

		if ( ! empty( $pause['pause'] ) ) {
			return $module->agent_error(
				'server_busy',
				__( 'The server is short of memory or time, so nothing was swept and the confirmation token was not spent.', 'media-sweep' ),
				array(
					'status'     => 503,
					'agent_hint' => __( 'Wait a few seconds and call this ability again with exactly the same input, including the same confirmation_token: it has not been used. If this repeats, read media-sweep/get-server-capacity and lower batch_size.', 'media-sweep' ),
					'recovery'   => array(
						'ability' => 'media-sweep/delete-scanned-files',
						'input'   => $input,
					),
				)
			);
		}

		if ( ! Sweep_Plan::consume( $token ) ) {
			return $module->agent_error(
				'plan_already_used',
				__( 'This confirmation token has already been used. Nothing was deleted.', 'media-sweep' ),
				array(
					'status'     => 409,
					'agent_hint' => __( 'Each plan authorises exactly one sweep, so a retry - including resuming a partly processed batch - needs a fresh token. Run media-sweep/preview-sweep again and read the new numbers before sweeping.', 'media-sweep' ),
					'recovery'   => $replan,
				)
			);
		}

		// `force` is never exposed as input and is hard-coded false here, so
		// the in-use status guard inside Action_Service cannot be bypassed.
		$result = $module->service( 'action_service' )->process_batch( 'delete', $file_scan_ids, $start_index, $batch_size, false );

		// Deleting a parent attachment purges the file_scan rows of every thumbnail
		// of it, and those can sit later in this same list, so the unswept tail is
		// re-resolved against live rows before it is handed back as a resume hint.
		$remaining   = self::surviving_ids( array_slice( $file_scan_ids, isset( $result['resume_index'] ) ? (int) $result['resume_index'] : 0 ) );
		$is_complete = ! empty( $result['is_complete'] ) || empty( $remaining );

		return self::narrow_batch_result( $result, self::delete_next_actions( $scan_id, $remaining ), $is_complete );
	}

	/**
	 * Restore files back into the Media Library.
	 *
	 * @param \Media_Sweep\Abilities\Abilities_Module $module Abilities bootstrap.
	 * @param mixed                                   $input  Ability input.
	 * @return array Batch result.
	 */
	private static function run_restore( $module, $input ) {
		$input = $module->apply_defaults(
			$input,
			array(
				'start_index' => 0,
				'batch_size'  => 10,
			)
		);

		$file_scan_ids = self::to_int_list( isset( $input['file_scan_ids'] ) ? $input['file_scan_ids'] : array() );
		$start_index   = max( 0, (int) $input['start_index'] );
		$batch_size    = min( self::MAX_BATCH_IDS, max( 1, (int) $input['batch_size'] ) );

		$result = $module->service( 'action_service' )->process_batch( 'restore', $file_scan_ids, $start_index, $batch_size, false );

		return self::narrow_batch_result( $result, self::restore_next_actions( $result, $file_scan_ids, self::resolve_scan_id( $file_scan_ids ) ) );
	}

	/**
	 * Measure what a sweep of these files would actually cost.
	 *
	 * @param array $file_scan_ids file_scan IDs in the plan.
	 * @return array Blast radius figures.
	 */
	private static function measure_blast_radius( array $file_scan_ids ) {
		$rows = File_Scan_Model::where_in( 'id', $file_scan_ids )->get();

		// Deliberately not with(): the builder loads a relation one query per row,
		// and $row->file re-queries anyway because the magic getter resolves the
		// relation method before the eager-loaded cache. One grouped query instead.
		$file_ids = array();

		foreach ( $rows as $row ) {
			$file_id = (int) $row->file_id;

			if ( $file_id > 0 ) {
				$file_ids[ $file_id ] = true;
			}
		}

		$files = array();

		if ( ! empty( $file_ids ) ) {
			foreach ( File_Model::where_in( 'id', array_keys( $file_ids ) )->get() as $file_row ) {
				$files[ (int) $file_row->id ] = $file_row;
			}
		}

		$total_bytes  = 0;
		$by_type      = array();
		$sample_paths = array();
		$attachments  = array();

		foreach ( $rows as $row ) {
			$file_id = (int) $row->file_id;
			$file    = isset( $files[ $file_id ] ) ? $files[ $file_id ] : null;

			if ( ! $file ) {
				continue;
			}

			$total_bytes += (int) $file->size_bytes;

			$type             = is_string( $file->file_type ) && '' !== $file->file_type ? $file->file_type : 'unknown';
			$by_type[ $type ] = isset( $by_type[ $type ] ) ? $by_type[ $type ] + 1 : 1;

			if ( $file->filepath && count( $sample_paths ) < self::MAX_SAMPLE_PATHS ) {
				$sample_paths[] = (string) $file->filepath;
			}

			$attachment_id = (int) $file->attachment_id;
			if ( $attachment_id > 0 ) {
				$attachments[ $attachment_id ] = true;
			}
		}

		// Deleting a file that has an attachment destroys every generated size
		// of it permanently, and only the original ends up in the trash. This
		// number is the part of a sweep that nothing can undo.
		// One grouped count, not one per attachment. The empty guard is
		// required: an empty value list builds `IN ()`, which is a SQL error.
		$thumbnails     = 0;
		$attachment_ids = array_keys( $attachments );

		if ( ! empty( $attachment_ids ) ) {
			$thumbnails = (int) File_Model::where_in( 'thumb_of', $attachment_ids )->count();
		}

		return array(
			'total_bytes'                      => $total_bytes,
			'by_type'                          => $by_type,
			'sample_paths'                     => $sample_paths,
			'attachments_affected'             => count( $attachments ),
			'thumbnails_permanently_destroyed' => $thumbnails,
		);
	}

	/**
	 * Narrow the service result down to the declared output shape.
	 *
	 * Built by hand rather than passed through: the service array carries
	 * extra keys (success, pause_reason, total) that the schema does not
	 * declare, and output validation is enforced with no escape hatch.
	 *
	 * @param mixed     $result       Raw Action_Service::process_batch() return.
	 * @param array     $next_actions Suggested follow-up abilities.
	 * @param bool|null $is_complete  Overrides the service's own is_complete when given.
	 * @return array Narrowed batch result.
	 */
	private static function narrow_batch_result( $result, array $next_actions, $is_complete = null ) {
		$result = is_array( $result ) ? $result : array();

		$error_details = array();
		if ( isset( $result['error_details'] ) && is_array( $result['error_details'] ) ) {
			foreach ( $result['error_details'] as $detail ) {
				$error_details[] = (string) $detail;
			}
		}

		return array(
			'processed'      => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
			'success_count'  => isset( $result['success_count'] ) ? (int) $result['success_count'] : 0,
			'errors'         => isset( $result['errors'] ) ? (int) $result['errors'] : 0,
			'skipped'        => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
			'skipped_in_use' => isset( $result['skipped_in_use'] ) ? (int) $result['skipped_in_use'] : 0,
			'resume_index'   => isset( $result['resume_index'] ) ? (int) $result['resume_index'] : 0,
			'is_complete'    => null === $is_complete ? ! empty( $result['is_complete'] ) : (bool) $is_complete,
			'should_pause'   => ! empty( $result['should_pause'] ),
			'error_details'  => $error_details,
			'next_actions'   => $next_actions,
		);
	}

	/**
	 * Follow-up abilities after a delete batch.
	 *
	 * @param int   $scan_id   Scan the files belong to.
	 * @param array $remaining Unswept file_scan IDs whose rows still exist.
	 * @return array Next actions.
	 */
	private static function delete_next_actions( $scan_id, array $remaining ) {
		if ( ! empty( $remaining ) ) {
			return array(
				array(
					'ability' => 'media-sweep/preview-sweep',
					'reason'  => __( 'The batch is not finished, and the confirmation token was single use. Re-previewing the SAME list mints the same, already-spent token, so the new preview must cover exactly the file_scan IDs that are still unswept - the list below.', 'media-sweep' ),
					'input'   => array(
						'scan_id' => (int) $scan_id,
						'ids'     => $remaining,
					),
				),
				array(
					'ability' => 'media-sweep/delete-scanned-files',
					'reason'  => __( 'Then sweep the rest with the token that preview returns. start_index is 0 because this list already starts at the first unswept file.', 'media-sweep' ),
					'input'   => array(
						'scan_id'            => (int) $scan_id,
						'file_scan_ids'      => $remaining,
						'confirm_file_count' => count( $remaining ),
						'start_index'        => 0,
					),
				),
			);
		}

		return array(
			array(
				'ability' => 'media-sweep/get-scan-report',
				'reason'  => __( 'The sweep finished. Read the scan report to see what each verdict counts now.', 'media-sweep' ),
				'input'   => array( 'scan_id' => (int) $scan_id ),
			),
		);
	}

	/**
	 * Follow-up abilities after a restore batch.
	 *
	 * The ability requires file_scan_ids, so the resume hint has to carry the
	 * remaining IDs — start_index alone fails validation.
	 *
	 * @param mixed $result        Raw batch result.
	 * @param array $file_scan_ids IDs the caller asked to restore.
	 * @param int   $scan_id       Scan those rows belong to, or 0 when none survives.
	 * @return array Next actions.
	 */
	private static function restore_next_actions( $result, array $file_scan_ids, $scan_id = 0 ) {
		if ( empty( $result['is_complete'] ) ) {
			$remaining = array_slice( $file_scan_ids, isset( $result['resume_index'] ) ? (int) $result['resume_index'] : 0 );

			if ( ! empty( $remaining ) ) {
				return array(
					array(
						'ability' => 'media-sweep/restore-scanned-files',
						'reason'  => __( 'The batch is not finished. Call this ability again with the file_scan_ids below, which are the ones still unrestored. start_index is 0 because this list already starts at the first of them.', 'media-sweep' ),
						'input'   => array(
							'file_scan_ids' => $remaining,
							'start_index'   => 0,
						),
					),
				);
			}
		}

		// list-scan-files requires scan_id, so a hint without one cannot be
		// followed. No surviving row means no scan to name: emit nothing.
		if ( $scan_id < 1 ) {
			return array();
		}

		return array(
			array(
				'ability' => 'media-sweep/list-scan-files',
				'reason'  => __( 'Restored files are back in the Media Library under NEW attachment IDs. List the scan files to see the current records.', 'media-sweep' ),
				'input'   => array( 'scan_id' => (int) $scan_id ),
			),
		);
	}

	/**
	 * Keep only the file_scan IDs whose rows still exist, in the caller's order.
	 *
	 * @param array $file_scan_ids Candidate file_scan IDs.
	 * @return array The surviving IDs.
	 */
	private static function surviving_ids( array $file_scan_ids ) {
		// One grouped query. The empty guard is required: an empty value list
		// builds `IN ()`, which is a SQL error.
		if ( empty( $file_scan_ids ) ) {
			return array();
		}

		$alive = array();

		foreach ( File_Scan_Model::where_in( 'id', $file_scan_ids )->get() as $row ) {
			$alive[ (int) $row->id ] = true;
		}

		$surviving = array();

		foreach ( $file_scan_ids as $file_scan_id ) {
			if ( isset( $alive[ (int) $file_scan_id ] ) ) {
				$surviving[] = (int) $file_scan_id;
			}
		}

		return $surviving;
	}

	/**
	 * Scan ID of the first of these file_scan rows that still exists.
	 *
	 * The restore ability is never given a scan_id, so its completion hint has
	 * to resolve one from the rows the caller named.
	 *
	 * @param array $file_scan_ids file_scan IDs the caller passed.
	 * @return int Scan ID, or 0 when no row survives.
	 */
	private static function resolve_scan_id( array $file_scan_ids ) {
		// Same empty guard as above: `IN ()` is a SQL error.
		if ( empty( $file_scan_ids ) ) {
			return 0;
		}

		foreach ( File_Scan_Model::where_in( 'id', $file_scan_ids )->get() as $row ) {
			return (int) $row->scan_id;
		}

		return 0;
	}

	/**
	 * Output schema for the preview.
	 *
	 * Narrow and closed, because every value in it is hand-built and countable.
	 *
	 * @return array Output schema.
	 */
	private static function preview_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_scan_ids'                    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => __( 'Exactly the file_scan IDs this plan covers. Pass this list verbatim to media-sweep/delete-scanned-files.', 'media-sweep' ),
				),
				'total'                            => array(
					'type'        => 'integer',
					'description' => __( 'Number of files in the plan. This is the value confirm_file_count must equal.', 'media-sweep' ),
				),
				'matching_total'                   => array(
					'type'        => 'integer',
					'description' => __( 'How many files match this scan_id/status/ids filter in total, ignoring limit and offset. Compare it with total to see whether more batches remain.', 'media-sweep' ),
				),
				'offset'                           => array(
					'type'        => 'integer',
					'description' => __( 'How many matching files were skipped before this plan was built.', 'media-sweep' ),
				),
				'next_offset'                      => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The offset to pass to the next media-sweep/preview-sweep call, or null when this batch is the last one.', 'media-sweep' ),
				),
				'total_bytes'                      => array(
					'type'        => 'integer',
					'description' => __( 'Total size on disk of the files in the plan.', 'media-sweep' ),
				),
				'by_type'                          => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'File count keyed by file type.', 'media-sweep' ),
				),
				'sample_paths'                     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Up to 25 of the file paths in the plan, so a human can sanity-check what is about to go.', 'media-sweep' ),
				),
				'attachments_affected'             => array(
					'type'        => 'integer',
					'description' => __( 'How many distinct Media Library attachments the sweep would delete.', 'media-sweep' ),
				),
				'thumbnails_permanently_destroyed' => array(
					'type'        => 'integer',
					'description' => __( 'Generated thumbnails that would be destroyed permanently. Only the original files reach the trash; these do not, and they cannot be restored.', 'media-sweep' ),
				),
				'confirmation_token'               => array(
					'type'        => 'string',
					'description' => __( 'Single-use token bound to this exact set of files, this scan and this user.', 'media-sweep' ),
				),
				'expires_in_seconds'               => array(
					'type'        => 'integer',
					'description' => __( 'How long the token stays valid.', 'media-sweep' ),
				),
				'next_actions'                     => self::next_actions_schema(),
			),
			'required'             => array(
				'file_scan_ids',
				'total',
				'matching_total',
				'offset',
				'next_offset',
				'total_bytes',
				'by_type',
				'sample_paths',
				'attachments_affected',
				'thumbnails_permanently_destroyed',
				'confirmation_token',
				'expires_in_seconds',
				'next_actions',
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema shared by the delete and restore abilities.
	 *
	 * @return array Output schema.
	 */
	private static function batch_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'processed'      => array(
					'type'        => 'integer',
					'description' => __( 'Files examined in this call.', 'media-sweep' ),
				),
				'success_count'  => array(
					'type'        => 'integer',
					'description' => __( 'Files the action succeeded on.', 'media-sweep' ),
				),
				'errors'         => array(
					'type'        => 'integer',
					'description' => __( 'Files that failed.', 'media-sweep' ),
				),
				'skipped'        => array(
					'type'        => 'integer',
					'description' => __( 'Files left alone on purpose. A skip is not a failure: a record that is already gone was already dealt with.', 'media-sweep' ),
				),
				'skipped_in_use' => array(
					'type'        => 'integer',
					'description' => __( 'Of the skipped files, how many were protected because the scan reports them as in use. There is no override.', 'media-sweep' ),
				),
				'resume_index'   => array(
					'type'        => 'integer',
					'description' => __( 'Index into file_scan_ids to pass as start_index on the next call.', 'media-sweep' ),
				),
				'is_complete'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the whole list has been processed.', 'media-sweep' ),
				),
				'should_pause'   => array(
					'type'        => 'boolean',
					'description' => __( 'The server ran short of memory or time and stopped early. Wait, then resume from resume_index.', 'media-sweep' ),
				),
				'error_details'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'One message per failed file.', 'media-sweep' ),
				),
				'next_actions'   => self::next_actions_schema(),
			),
			'required'             => array(
				'processed',
				'success_count',
				'errors',
				'skipped',
				'skipped_in_use',
				'resume_index',
				'is_complete',
				'should_pause',
				'error_details',
				'next_actions',
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema for the next_actions hint list.
	 *
	 * @return array Schema fragment.
	 */
	private static function next_actions_schema() {
		return array(
			'type'        => 'array',
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'ability' => array(
						'type'        => 'string',
						'description' => __( 'Ability to call next.', 'media-sweep' ),
					),
					'reason'  => array(
						'type'        => 'string',
						'description' => __( 'Why it is the sensible next step.', 'media-sweep' ),
					),
					'input'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'Input values already known for that call.', 'media-sweep' ),
					),
				),
				'required'             => array( 'ability', 'reason' ),
				'additionalProperties' => false,
			),
			'description' => __( 'Suggested follow-up abilities.', 'media-sweep' ),
		);
	}

	/**
	 * Cast a raw ID list to a unique, re-indexed list of integers.
	 *
	 * Plucked IDs come back from the database as strings, and both the
	 * fingerprint and the output schema expect integers.
	 *
	 * @param mixed $ids Raw ID list.
	 * @return array List of integers.
	 */
	private static function to_int_list( $ids ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}
