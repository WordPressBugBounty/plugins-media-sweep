<?php
/**
 * Media Scanner Service - reference-index verdicts with time-budgeted slices.
 *
 * 1.1.0 replaces the per-attachment LIKE-scan engine (~35-41 unindexed
 * full-table scans per attachment, measured at ~86s each on mid-size sites)
 * with indexed lookups against the per-scan reference index built by
 * Reference_Extractor_Service. A batch of 100 attachments drops from hours
 * to milliseconds, and every request self-terminates inside a Time_Budget so
 * no gateway timeout can ever kill it mid-flight.
 *
 * Report parity: the refs rows keep a structured origin per reference, and
 * verdicts fetch ALL matching rows (never LIMIT 1) so the detailed per-file
 * usage notes render exactly as before - same strings, same cap - plus new
 * origins (Elementor/builder JSON, widgets, site settings, term images) the
 * old LIKE patterns could never see.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Interfaces\Media_Scanner;
use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Utils\Time_Budget;
use Media_Sweep\Utils\Url_Normalizer;
use Media_Sweep\Utils\Database_Query_Helper;

/**
 * Media Scanner Service class
 */
class Media_Scanner_Service extends Base_Scanner_Service implements Media_Scanner {

	/**
	 * Attachments fetched per inner page during the verdicts phase.
	 */
	const ATTACHMENTS_PER_PAGE = 50;

	/**
	 * Reference extractor.
	 *
	 * @var Reference_Extractor_Service
	 */
	protected $extractor;

	/**
	 * Default scan options
	 *
	 * @var array
	 */
	protected $default_options = array(
		'deep_scan'           => false, // Scan all database tables vs just standard WordPress tables
		'include_thumbnails'  => true,  // Include thumbnail files in scan
		'check_custom_fields' => true,  // Check custom fields for media usage
		'check_shortcodes'    => true,  // Check for gallery shortcodes
		'check_blocks'        => true,  // Check for gallery blocks
	);

	/**
	 * Constructor
	 *
	 * @param System_Monitor_Service      $system_monitor  System monitor.
	 * @param Reference_Store             $reference_store Reference store.
	 * @param Reference_Extractor_Service $extractor       Reference extractor.
	 */
	public function __construct( System_Monitor_Service $system_monitor, Reference_Store $reference_store, Reference_Extractor_Service $extractor ) {
		parent::__construct( $system_monitor, $reference_store );
		$this->extractor = $extractor;
	}

	/**
	 * Start a new media library scan
	 *
	 * @param array $options Scan options
	 * @return Scan_Model|false
	 */
	public function start_scan( $options = array() ) {
		// Merge with default options
		$options = wp_parse_args( $options, $this->default_options );

		// Reset system monitor for this scan
		$this->system_monitor->reset_monitoring();

		// Create scan record, checkpointed from the first extraction phase.
		$scan = Scan_Model::create(
			array(
				'mode'         => 'media_library',
				'status'       => Scan_Model::STATUS_RUNNING,
				'phase'        => Scan_Model::PHASE_EXTRACT_POSTS,
				'checkpoint'   => array(),
				'options'      => $options,
				'started_at'   => current_time( 'mysql' ),
				'last_tick_at' => current_time( 'mysql' ),
			)
		);

		return $scan;
	}

	/**
	 * Get all media attachments for frontend processing
	 *
	 * Kept for the legacy (pre-1.1.0) client flow; the tick-driven flow pages
	 * attachments server-side and never calls this.
	 *
	 * @param int   $page     Page number (1-based)
	 * @param int   $per_page Items per page
	 * @param array $options  Scan options
	 * @return array
	 */
	public function get_media_attachments( $page = 1, $per_page = 100, $options = array() ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		// Get attachment IDs only
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment'
				 AND post_status = 'inherit'
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// Convert to integers
		$attachment_ids = array_map( 'intval', $attachment_ids );

		// Get total count for pagination info
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		return array(
			'attachments'  => $attachment_ids,
			'total'        => (int) $total,
			'page'         => $page,
			'per_page'     => $per_page,
			'total_pages'  => ceil( $total / $per_page ),
			'has_more'     => ( $page * $per_page ) < $total,
			'should_pause' => false,
			'pause_reason' => '',
		);
	}

	/**
	 * Run one budgeted slice of the verdicts phase: page attachments by ID
	 * cursor and verdict each against the reference index.
	 *
	 * Checkpoint keys used: att_total, att_cursor, att_done, att_errors.
	 *
	 * @param int         $scan_id    Scan ID.
	 * @param array       $checkpoint Checkpoint (by reference).
	 * @param array       $options    Scan options.
	 * @param Time_Budget $budget     Request budget.
	 * @return bool True when every attachment has a verdict.
	 */
	public function run_verdicts_slice( $scan_id, array &$checkpoint, $options, Time_Budget $budget ) {
		global $wpdb;

		$options   = wp_parse_args( $options, $this->default_options );
		$last_save = microtime( true );

		if ( ! isset( $checkpoint['att_total'] ) ) {
			$checkpoint['att_total'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
			);
			$checkpoint['att_cursor'] = 0;
			$checkpoint['att_done']   = 0;
			$checkpoint['att_errors'] = 0;
		}

		while ( ! $budget->should_stop() ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'attachment' AND post_status = 'inherit' AND ID > %d
					 ORDER BY ID ASC
					 LIMIT %d",
					$checkpoint['att_cursor'],
					self::ATTACHMENTS_PER_PAGE
				)
			);

			if ( empty( $ids ) ) {
				return true;
			}

			foreach ( $ids as $attachment_id ) {
				if ( $budget->should_stop() ) {
					return false;
				}

				$attachment_id = (int) $attachment_id;

				$ok = $budget->run_item(
					function () use ( $scan_id, $attachment_id, $options ) {
						return $this->record_attachment_verdict( $scan_id, $attachment_id, $options );
					}
				);

				if ( ! $ok ) {
					++$checkpoint['att_errors'];
				}

				$checkpoint['att_cursor'] = $attachment_id;
				++$checkpoint['att_done'];

				// Persist as we go, so a killed request never leaves written rows behind a stale cursor.
				if ( microtime( true ) - $last_save >= 2.0 ) {
					$this->persist_checkpoint( $scan_id, $checkpoint );
					$last_save = microtime( true );
				}
			}
		}

		return false;
	}

	/**
	 * Write the checkpoint mid-slice without touching the rest of the scan row.
	 *
	 * @param int   $scan_id    Scan ID.
	 * @param array $checkpoint Checkpoint data.
	 */
	protected function persist_checkpoint( $scan_id, array $checkpoint ) {
		global $wpdb;

		$table = $wpdb->prefix . 'mswp_scans';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET checkpoint = %s, last_tick_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
				maybe_serialize( $checkpoint ),
				current_time( 'mysql' ),
				(int) $scan_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	}

	/**
	 * Verdict one attachment and persist its file + file_scan (+thumbnails)
	 * rows. Idempotent: the unique (scan_id, file_id) key dedupes retries.
	 *
	 * @param int   $scan_id       Scan ID.
	 * @param int   $attachment_id Attachment ID.
	 * @param array $options       Scan options.
	 * @return bool False when the attachment could not be recorded.
	 */
	public function record_attachment_verdict( $scan_id, $attachment_id, $options ) {
		$verdict = $this->check_media_usage( $attachment_id, $options, $scan_id );
		$status  = $verdict['status'];
		$notes   = $verdict['notes'];

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return false;
		}

		$file = $this->get_or_create_file_record( $file_path, $attachment_id );
		if ( ! $file ) {
			return false;
		}

		// Sizes registered at identical dimensions share one generated file, so the same file_id can recur.
		$written = array( (int) $file->id => true );

		$this->record_file_scan( $scan_id, $file->id, $status, $notes );

		if ( ! empty( $options['include_thumbnails'] ) ) {
			$this->process_attachment_thumbnails( $scan_id, $attachment_id, $status, $notes, $written );
		}

		return true;
	}

	/**
	 * Process a batch of media attachments (legacy pre-1.1.0 client contract).
	 *
	 * The response shape is unchanged, but the internals are new: if the
	 * reference index is not built yet this request advances extraction under
	 * its time budget and asks the client to pause/retry; once the index
	 * exists, verdicting 100 attachments is milliseconds and always completes.
	 *
	 * @param int   $scan_id     The scan ID
	 * @param array $attachments Array of attachment IDs
	 * @param array $options     Scan options
	 * @return array Processing results with essential information only
	 */
	public function process_media_batch( $scan_id, $attachments, $options = array() ) {
		$options = wp_parse_args( $options, $this->default_options );
		$budget  = new Time_Budget();
		$results = array(
			'success'      => true,
			'processed'    => 0,
			'errors'       => 0,
			'should_pause' => false,
			'pause_reason' => '',
			'resume_info'  => null,
		);

		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			$results['success'] = false;
			return $results;
		}

		// Lazily build the reference index for scans driven by the legacy
		// client (which never calls the tick endpoint).
		$checkpoint = is_array( $scan->checkpoint ) ? $scan->checkpoint : array();
		$phase      = $scan->phase ? $scan->phase : Scan_Model::PHASE_EXTRACT_POSTS;

		if ( Reference_Extractor_Service::is_extraction_phase( $phase ) ) {
			$done = $this->extractor->advance_extraction( $scan_id, $checkpoint, $phase, $options, $budget );

			if ( $done ) {
				$phase = Scan_Model::PHASE_VERDICTS;
			}

			$scan->update(
				array(
					'phase'        => $phase,
					'checkpoint'   => $checkpoint,
					'last_tick_at' => current_time( 'mysql' ),
				)
			);

			if ( ! $done ) {
				$results['should_pause'] = true;
				$results['pause_reason'] = __( 'Building the media reference index...', 'media-sweep' );
				$results['resume_info']  = array(
					'attachment_index' => 0,
					'remaining_count'  => count( $attachments ),
				);
				return $results;
			}
		}

		foreach ( $attachments as $index => $attachment_id ) {
			if ( $budget->should_stop() ) {
				$results['should_pause'] = true;
				$results['pause_reason'] = __( 'Pausing before the server time limit.', 'media-sweep' );
				$results['resume_info']  = array(
					'attachment_index' => $index,
					'remaining_count'  => count( $attachments ) - $index,
				);
				break;
			}

			$ok = $budget->run_item(
				function () use ( $scan_id, $attachment_id, $options ) {
					return $this->record_attachment_verdict( $scan_id, (int) $attachment_id, $options );
				}
			);

			if ( ! $ok ) {
				++$results['errors'];
			}

			++$results['processed'];
		}

		$scan->update( array( 'last_tick_at' => current_time( 'mysql' ) ) );

		return $results;
	}

	/**
	 * Check media usage for a specific attachment against the scan's
	 * reference index.
	 *
	 * @param int      $attachment_id The attachment ID
	 * @param array    $options       Scan options
	 * @param int|null $scan_id       Scan whose reference index to consult.
	 *                                Falls back to the latest unfinished
	 *                                media scan when omitted (interface
	 *                                compatibility).
	 * @return array Array with 'status' and 'notes'
	 */
	public function check_media_usage( $attachment_id, $options = array(), $scan_id = null ) {
		$options = wp_parse_args( $options, $this->default_options );
		$notes   = array();

		// Check if attachment still exists
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			$notes[] = sprintf(
				/* translators: %d is the attachment ID */
				__( 'Attachment post no longer exists in database (ID: %d)', 'media-sweep' ),
				$attachment_id
			);
			return array(
				'status' => 'orphaned',
				'notes'  => $notes,
			);
		}

		// Check if file exists
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$notes[] = sprintf(
				/* translators: %s is the file path */
				__( 'Physical file is missing from server: %s', 'media-sweep' ),
				$file_path
			);
			return array(
				'status' => 'orphaned',
				'notes'  => $notes,
			);
		}

		if ( null === $scan_id ) {
			$scan_id = $this->find_current_scan_id();
		}

		$refs = array();
		if ( $scan_id ) {
			$keys = Url_Normalizer::lookup_keys_for_attachment( $attachment_id );
			$refs = $this->reference_store->get_refs( $scan_id, $attachment_id, $keys );
			$refs = $this->filter_refs_by_options( $refs, $options );
		}

		// Split what decides the verdict from what is merely recorded elsewhere. Both are reported; only the
		// first sets "in use".
		list( $deciding, $mentions ) = $this->partition_refs_by_evidence( $refs );

		if ( ! empty( $deciding ) ) {
			return array(
				'status' => 'in_use',
				'notes'  => array_merge(
					$this->render_notes_from_refs( $deciding ),
					$this->render_mention_notes( $mentions )
				),
			);
		}

		$notes = $this->render_mention_notes( $mentions );
		$notes[] = __( 'No usage found', 'media-sweep' );

		return array(
			'status' => 'unused',
			'notes'  => $notes,
		);
	}

	/**
	 * Split reference rows into those that may decide "in use" and those that are only worth reporting.
	 *
	 * Everything found in the site's own content decides: posts, custom fields, options, term meta,
	 * galleries, featured images. A hit inside another plugin's table only decides when that table holds
	 * content a visitor sees - see Database_Query_Helper::table_evidence().
	 *
	 * @param array $refs Reference rows.
	 * @return array[] [ $deciding, $mentions ].
	 */
	protected function partition_refs_by_evidence( $refs ) {
		global $wpdb;

		$deciding = array();
		$mentions = array();
		$prefix   = '/^' . preg_quote( $wpdb->prefix, '/' ) . '/';

		foreach ( $refs as $ref ) {
			if ( strpos( $ref->origin, 'table:' ) !== 0 ) {
				$deciding[] = $ref;
				continue;
			}

			$location = substr( $ref->origin, strlen( 'table:' ) );
			$dot      = strrpos( $location, '.' );
			$table    = false !== $dot ? substr( $location, 0, $dot ) : $location;
			$column   = false !== $dot ? substr( $location, $dot + 1 ) : '';
			$clean    = preg_replace( $prefix, '', $table, 1 );

			if ( Database_Query_Helper::EVIDENCE_USAGE === Database_Query_Helper::table_evidence( $clean, $column ) ) {
				$deciding[] = $ref;
			} else {
				$mentions[] = $ref;
			}
		}

		return array( $deciding, $mentions );
	}

	/**
	 * Render bookkeeping matches as clearly non-deciding notes.
	 *
	 * @param array $mentions Reference rows.
	 * @return string[]
	 */
	protected function render_mention_notes( $mentions ) {
		if ( empty( $mentions ) ) {
			return array();
		}

		global $wpdb;

		// Accumulate hits per origin, as render_notes_from_refs() does: one file can match several lookup
		// keys from the same column.
		$origins = array();
		foreach ( $mentions as $ref ) {
			if ( isset( $origins[ $ref->origin ] ) ) {
				$origins[ $ref->origin ] += (int) $ref->hits;
			} else {
				$origins[ $ref->origin ] = (int) $ref->hits;
			}
		}

		$notes = array();
		foreach ( $origins as $origin => $hits ) {
			// Mentions only ever come from table: origins (see partition_refs_by_evidence()).
			$location = substr( $origin, strlen( 'table:' ) );
			$dot      = strrpos( $location, '.' );
			$table    = false !== $dot ? substr( $location, 0, $dot ) : $location;
			$column   = false !== $dot ? substr( $location, $dot + 1 ) : '';
			$clean    = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $table, 1 );

			$notes[] = Database_Query_Helper::create_database_mention_note( $clean, $column, $hits );
		}

		return array_values( array_unique( $notes ) );
	}

	/**
	 * Drop reference rows whose origin type the scan options disable
	 * (extraction is always complete; options filter at verdict time).
	 *
	 * @param array $refs    Reference rows.
	 * @param array $options Scan options.
	 * @return array Filtered rows.
	 */
	protected function filter_refs_by_options( $refs, $options ) {
		$skip = array();
		if ( empty( $options['check_custom_fields'] ) ) {
			$skip[] = 'custom_field:';
		}
		if ( empty( $options['check_shortcodes'] ) ) {
			$skip[] = 'gallery_shortcode:';
		}
		if ( empty( $options['check_blocks'] ) ) {
			$skip[] = 'gallery_block:';
		}
		if ( empty( $options['deep_scan'] ) ) {
			$skip[] = 'table:';
		}

		if ( empty( $skip ) ) {
			return $refs;
		}

		return array_values(
			array_filter(
				$refs,
				function ( $ref ) use ( $skip ) {
					foreach ( $skip as $prefix ) {
						if ( strpos( $ref->origin, $prefix ) === 0 ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Latest unfinished media scan ID (interface-compatibility fallback for
	 * check_media_usage() calls without an explicit scan).
	 *
	 * @return int|null
	 */
	protected function find_current_scan_id() {
		$scan = Scan_Model::where( 'mode', '=', 'media_library' )
			->where( 'status', '=', Scan_Model::STATUS_RUNNING )
			->order_by( 'id', 'DESC' )
			->first();

		return $scan ? (int) $scan->id : null;
	}

	/**
	 * Process attachment thumbnails
	 *
	 * @param int    $scan_id       The scan ID
	 * @param int    $attachment_id The attachment ID
	 * @param string $parent_status Parent file status
	 * @param array  $parent_notes  Parent file notes
	 * @param array  $written       File IDs already recorded for this
	 *                              attachment, by reference.
	 */
	protected function process_attachment_thumbnails( $scan_id, $attachment_id, $parent_status, $parent_notes = array(), &$written = array() ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata['sizes'] ) ) {
			return;
		}

		$base_path = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );

		foreach ( $metadata['sizes'] as $size => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}

			$thumb_path = $base_path . $size_data['file'];

			if ( ! file_exists( $thumb_path ) ) {
				continue;
			}

			// Create file record for thumbnail
			$thumb_file = $this->get_or_create_file_record( $thumb_path, null, $attachment_id );

			if ( ! $thumb_file ) {
				continue;
			}

			// Sizes registered with identical dimensions resolve to the same
			// generated file; record it once, under the first size name.
			if ( isset( $written[ (int) $thumb_file->id ] ) ) {
				continue;
			}
			$written[ (int) $thumb_file->id ] = true;

			$thumb_notes = array_merge(
				array(
					sprintf(
						/* translators: %1$s is the thumbnail size (e.g., medium, large), %2$d is the attachment ID */
						__( 'Thumbnail (%1$s) of attachment ID %2$d', 'media-sweep' ),
						$size,
						$attachment_id
					),
				),
				$parent_notes
			);

			$this->record_file_scan( $scan_id, $thumb_file->id, $parent_status, $thumb_notes );
		}
	}

	/**
	 * Get the scanner type identifier
	 *
	 * @return string
	 */
	public function get_scanner_type() {
		return 'media_library';
	}
}
