<?php
/**
 * Base Scanner Service - Abstract base class for all scanner services
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Models\File_Model;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Database\Tables\File_Scan_Table;
use Media_Sweep\Utils\Path_Helper;

/**
 * Abstract Base Scanner Service class
 */
abstract class Base_Scanner_Service {

	/**
	 * Maximum number of usage notes stored per file. Larger lists are
	 * truncated with a "+ N more usage locations" summary so a single image
	 * referenced in thousands of posts cannot bloat the database.
	 */
	const MAX_NOTES_PER_FILE = 10;


	/**
	 * System monitor instance
	 *
	 * @var System_Monitor_Service
	 */
	protected $system_monitor;

	/**
	 * Reference store (the per-scan media reference index).
	 *
	 * @var Reference_Store
	 */
	protected $reference_store;

	/**
	 * Default scan options (to be overridden by child classes)
	 *
	 * @var array
	 */
	protected $default_options = array();

	/**
	 * Constructor
	 *
	 * @param System_Monitor_Service $system_monitor  System monitor instance
	 * @param Reference_Store        $reference_store Reference store instance
	 */
	public function __construct( System_Monitor_Service $system_monitor, Reference_Store $reference_store ) {
		$this->system_monitor  = $system_monitor;
		$this->reference_store = $reference_store;
	}

	/**
	 * Resume an existing scan from its checkpoint.
	 *
	 * Resume continues from the persisted cursor with zero rework: existing
	 * file_scan rows and extracted references are kept (both are idempotent
	 * under unique keys), so an interrupted scan never restarts at 0%.
	 *
	 * @param int $scan_id The scan ID to resume
	 * @return Scan_Model|false
	 */
	public function resume_scan( $scan_id ) {
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return false;
		}

		// Reset system monitor for resumed scan
		$this->system_monitor->reset_monitoring();

		$scan->update(
			array(
				'status'       => Scan_Model::STATUS_RUNNING,
				'finished_at'  => null,
				'last_tick_at' => current_time( 'mysql' ),
			)
		);

		return $scan;
	}

	/**
	 * Get scan results for this scanner type
	 *
	 * @param int $scan_id Optional scan ID
	 * @return array
	 */
	public function get_scan_results( $scan_id = null ) {
		// Get counts grouped by status
		if ( $scan_id ) {
			$results = File_Scan_Model::where( 'scan_id', '=', $scan_id );
		} else {
			$results = File_Scan_Model::query();
		}

		$results = $results->select_raw( 'status, COUNT(*) as count' )
			->group_by( 'status' )
			->get_raw();

		// Transform to associative array
		$formatted = array(
			'in_media'     => 0,
			'not_in_media' => 0,
			'in_use'       => 0,
			'unused'       => 0,
			'orphaned'     => 0,
			'total_files'  => 0,
		);

		foreach ( $results as $result ) {
			$formatted[ $result->status ] = (int) $result->count;
			$formatted['total_files']    += (int) $result->count;
		}

		return $formatted;
	}

	/**
	 * Finish the scan (common implementation)
	 *
	 * @param int $scan_id The scan ID
	 * @return array|false
	 */
	public function finish_scan( $scan_id ) {
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return false;
		}

		$scan->update(
			array(
				'finished_at' => current_time( 'mysql' ),
				'status'      => Scan_Model::STATUS_COMPLETED,
				'phase'       => Scan_Model::PHASE_DONE,
			)
		);

		// The rendered usage notes live in the file_scan rows; the raw
		// reference index is no longer needed once the scan completes.
		$this->reference_store->delete_for_scan( $scan_id );

		$scan_results = $this->get_scan_results( $scan_id );

		return $scan_results;
	}

	/**
	 * Helper method to get post information
	 *
	 * @param int $post_id Post ID
	 * @return array Array with post_title, post_type, and display_title
	 */
	protected function get_post_info( $post_id ) {
		// Decode HTML entities (e.g. "V-Neck T-Shirt &#8211; Blue" -> "V-Neck T-Shirt – Blue")
		// so notes display human-readable titles instead of raw entity codes.
		$post_title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$post_type  = get_post_type( $post_id );

		// Fallback if title is empty
		$display_title = ! empty( $post_title ) ? $post_title : sprintf(
			/* translators: %s is the post type (e.g., post, page, attachment) */
			__( 'Untitled %s', 'media-sweep' ),
			$post_type
		);

		return array(
			'post_title'    => $post_title,
			'post_type'     => $post_type,
			'display_title' => $display_title,
		);
	}

	/**
	 * Helper method to create usage note
	 *
	 * @param string $context     Usage context
	 * @param int    $post_id     Post ID (optional)
	 * @param string $extra_info  Additional information
	 * @return string Formatted note
	 */
	protected function create_usage_note( $context, $post_id = 0, $extra_info = '' ) {
		switch ( $context ) {
			case 'featured':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used as featured image for %1$s: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used as featured image', 'media-sweep' );

			case 'content':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used in %1$s content: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in content', 'media-sweep' );

			case 'custom_field':
				if ( $post_id && $extra_info ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the custom field name, %2$s is the post type, %3$s is the post title */
						__( 'Used in custom field "%1$s" for %2$s: "%3$s"', 'media-sweep' ),
						$extra_info,
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in custom field', 'media-sweep' );

			case 'gallery_shortcode':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used in gallery shortcode in %1$s: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in gallery shortcode', 'media-sweep' );

			case 'gallery_block':
				if ( $post_id ) {
					$post_info = $this->get_post_info( $post_id );
					return sprintf(
						/* translators: %1$s is the post type, %2$s is the post title */
						__( 'Used in gallery block in %1$s: "%2$s"', 'media-sweep' ),
						$post_info['post_type'],
						$post_info['display_title']
					);
				}
				return __( 'Used in gallery block', 'media-sweep' );

			case 'option':
				return sprintf(
					/* translators: %s is the option/widget name */
					__( 'Used in site setting or widget: %s', 'media-sweep' ),
					$extra_info
				);

			case 'termmeta':
				return sprintf(
					/* translators: %s is the term/category name */
					__( 'Used as image for term/category: %s', 'media-sweep' ),
					$extra_info
				);

			case 'theme_plugin':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Used by theme or plugin: %s', 'media-sweep' ),
					$extra_info
				);

			case 'cache':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Cache file: %s', 'media-sweep' ),
					$extra_info
				);

			case 'backup':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Backup file: %s', 'media-sweep' ),
					$extra_info
				);

			case 'database':
				return sprintf(
					/* translators: %s is the extra information */
					__( 'Found in database table: %s', 'media-sweep' ),
					$extra_info
				);

			default:
				return $extra_info ?: sprintf(
					/* translators: %s is the extra information */
					__( 'File usage detected: %s', 'media-sweep' ),
					$extra_info
				);
		}
	}

	/**
	 * Helper method to execute database query and collect post IDs with notes
	 *
	 * @param string $query     SQL query
	 * @param array  $params    Query parameters
	 * @param string $context   Usage context
	 * @param string $extra_col Optional extra column name for additional info
	 * @return array Array of notes
	 */
	protected function execute_query_and_collect_notes( $query, $params, $context, $extra_col = '' ) {
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		$notes   = array();

		foreach ( $results as $result ) {
			$extra_info = ! empty( $extra_col ) && isset( $result->{$extra_col} ) ? $result->{$extra_col} : '';
			$notes[]    = $this->create_usage_note( $context, $result->ID, $extra_info );
		}

		return $notes;
	}

	/**
	 * Record one file's verdict as an upsert, so a repeated (scan_id, file_id) can never fail the write.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param int    $file_id File ID.
	 * @param string $status  Verdict status.
	 * @param array  $notes   Usage notes (capped before storage).
	 * @return bool True when the row was written.
	 */
	protected function record_file_scan( $scan_id, $file_id, $status, $notes ) {
		global $wpdb;

		$table = ( new File_Scan_Table() )->get_full_table_name();

		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (scan_id, file_id, status, notes, recorded_at)
			 VALUES (%d, %d, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), recorded_at = VALUES(recorded_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own schema class.
			(int) $scan_id,
			(int) $file_id,
			$status,
			maybe_serialize( $this->cap_notes( $notes ) ),
			current_time( 'mysql' )
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	}

	/**
	 * Get or create file record
	 *
	 * @param string   $file_path     The absolute file path
	 * @param int|null $attachment_id Optional attachment ID
	 * @param int|null $thumb_of      Optional parent attachment ID for thumbnails
	 * @return File_Model|null
	 */
	protected function get_or_create_file_record( $file_path, $attachment_id = null, $thumb_of = null ) {
		// Convert absolute path to relative path from uploads directory
		$relative_path = Path_Helper::ensure_relative_path( $file_path );
		$filepath_sha1 = sha1( $relative_path );

		// Try to find existing file record
		$file = File_Model::where( 'filepath_sha1', '=', $filepath_sha1 )->first();
		if ( ! $file ) {
			// Create new file record
			$file_info = pathinfo( $file_path );
			$file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;
			$file      = File_Model::create(
				array(
					'filepath'      => $relative_path,
					'filepath_sha1' => $filepath_sha1,
					'attachment_id' => $attachment_id,
					'file_type'     => isset( $file_info['extension'] ) ? $file_info['extension'] : null,
					'size_bytes'    => $file_size,
					'thumb_of'      => $thumb_of,
					'status'        => 'active',
				)
			);
		} else {
			// If file was found and is trashed or deleted, reactivate it
			if ( in_array( $file->status, array( 'trashed', 'deleted' ), true ) ) {
				$update_data = array(
					'status'   => 'active',
					'filepath' => $relative_path, // Update path in case it was moved
				);

				// Update attachment_id if provided
				if ( null !== $attachment_id ) {
					$update_data['attachment_id'] = $attachment_id;
				}

				// Update thumb_of if provided
				if ( null !== $thumb_of ) {
					$update_data['thumb_of'] = $thumb_of;
				}

				// Update file size if file exists
				if ( file_exists( $file_path ) ) {
					$update_data['size_bytes'] = filesize( $file_path );
				}

				$file->update( $update_data );
			}
		}

		return $file;
	}

	/**
	 * Check if processing should continue based on system resources
	 *
	 * @return array Array with 'should_continue' boolean and optional 'warning' message
	 */
	protected function check_system_resources() {
		return array(
			'should_continue' => $this->system_monitor->is_safe_to_continue(),
			'warning'         => $this->system_monitor->get_resource_warning(),
			'resources'       => $this->system_monitor->check_system_resources(),
		);
	}

	/**
	 * Render human-readable usage notes from reference rows - the same
	 * strings the 1.0.x engine produced, from the structured origins.
	 *
	 * @param array $refs Reference rows ({origin, hits}).
	 * @return string[] Notes, grouped by origin type in a stable order.
	 */
	public function render_notes_from_refs( $refs ) {
		// Dedupe origins (one file can match several lookup keys from the
		// same origin) while accumulating deep-scan hit counts.
		$origins = array();
		foreach ( $refs as $ref ) {
			if ( isset( $origins[ $ref->origin ] ) ) {
				$origins[ $ref->origin ] += (int) $ref->hits;
			} else {
				$origins[ $ref->origin ] = (int) $ref->hits;
			}
		}

		// Stable presentation order by origin type.
		$order = array( 'featured', 'content', 'custom_field', 'gallery_shortcode', 'gallery_block', 'option', 'termmeta', 'table' );

		$grouped = array_fill_keys( $order, array() );
		foreach ( $origins as $origin => $hits ) {
			$type = strtok( $origin, ':' );
			if ( isset( $grouped[ $type ] ) ) {
				$grouped[ $type ][ $origin ] = $hits;
			}
		}

		$notes = array();
		foreach ( $grouped as $type => $items ) {
			foreach ( $items as $origin => $hits ) {
				$note = $this->render_single_note( $type, $origin, $hits );
				if ( $note ) {
					$notes[] = $note;
				}
			}
		}

		return array_values( array_unique( $notes ) );
	}

	/**
	 * Render one origin into its note string.
	 *
	 * @param string $type   Origin type (prefix).
	 * @param string $origin Full structured origin.
	 * @param int    $hits   Sightings count (deep-scan notes).
	 * @return string|null
	 */
	protected function render_single_note( $type, $origin, $hits ) {
		$parts = explode( ':', $origin );

		switch ( $type ) {
			case 'featured':
				return $this->create_usage_note( 'featured', (int) $parts[1] );

			case 'content':
				return $this->create_usage_note( 'content', (int) $parts[1] );

			case 'custom_field':
				// custom_field:{meta_key}:{post_id} - meta_key may itself
				// contain colons, so the post ID is the LAST segment.
				$post_id  = (int) array_pop( $parts );
				$meta_key = implode( ':', array_slice( $parts, 1 ) );
				return $this->create_usage_note( 'custom_field', $post_id, $meta_key );

			case 'gallery_shortcode':
				return $this->create_usage_note( 'gallery_shortcode', (int) $parts[1] );

			case 'gallery_block':
				return $this->create_usage_note( 'gallery_block', (int) $parts[1] );

			case 'option':
				$name = implode( ':', array_slice( $parts, 1 ) );
				return $this->create_usage_note( 'option', 0, $name );

			case 'termmeta':
				$term_id = (int) array_pop( $parts );
				$term    = get_term( $term_id );
				$label   = ( $term && ! is_wp_error( $term ) ) ? $term->name : sprintf( '#%d', $term_id );
				return $this->create_usage_note( 'termmeta', 0, $label );

			case 'table':
				// table:{table}.{column}
				$location = implode( ':', array_slice( $parts, 1 ) );
				$dot      = strrpos( $location, '.' );
				$table    = $dot !== false ? substr( $location, 0, $dot ) : $location;
				$column   = $dot !== false ? substr( $location, $dot + 1 ) : '';
				return \Media_Sweep\Utils\Database_Query_Helper::create_database_usage_note( $table, $column, $hits, '' );
		}

		return null;
	}

	/**
	 * Cap a notes array to MAX_NOTES_PER_FILE entries, appending a summary
	 * line for the remainder. Keeps stored notes bounded for files that are
	 * referenced widely (logos, placeholders, etc.).
	 *
	 * @param array $notes Raw notes collected during scanning.
	 * @return array Capped list of notes ready for storage.
	 */
	protected function cap_notes( $notes ) {
		if ( ! is_array( $notes ) ) {
			return array();
		}

		$total = count( $notes );
		if ( $total <= self::MAX_NOTES_PER_FILE ) {
			return $notes;
		}

		$kept      = array_slice( $notes, 0, self::MAX_NOTES_PER_FILE );
		$remaining = $total - self::MAX_NOTES_PER_FILE;
		$kept[]    = sprintf(
			/* translators: %d is the number of additional usage locations not shown individually. */
			_n(
				'+ %d more usage location',
				'+ %d more usage locations',
				$remaining,
				'media-sweep'
			),
			$remaining
		);

		return $kept;
	}

	/**
	 * Abstract methods that must be implemented by child classes
	 *
	 * @param array $options Scan options
	 * @return mixed
	 */
	abstract public function start_scan( $options = array() );

	/**
	 * Get system monitor instance
	 *
	 * @return System_Monitor_Service
	 */
	public function get_system_monitor() {
		return $this->system_monitor;
	}
}
