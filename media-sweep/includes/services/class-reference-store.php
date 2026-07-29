<?php
/**
 * Reference Store - buffered writes and indexed lookups for the scan refs index.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Database\Tables\Scan_Refs_Table;
use Media_Sweep\Utils\Url_Normalizer;

/**
 * Reference Store class
 */
class Reference_Store {

	/**
	 * Rows buffered per multi-row INSERT flush.
	 */
	const FLUSH_SIZE = 500;

	/**
	 * Maximum origin length persisted (column is VARCHAR(191)).
	 */
	const MAX_ORIGIN_LENGTH = 191;

	/**
	 * Pending rows, keyed by ref_hash for in-memory dedupe.
	 * Value: array{attachment_id: int|null, path: string|null, origin: string, hits: int}
	 *
	 * @var array
	 */
	protected $buffer = array();

	/**
	 * Get the full refs table name.
	 *
	 * @return string
	 */
	protected function table() {
		return ( new Scan_Refs_Table() )->get_full_table_name();
	}

	/**
	 * Record an attachment-ID reference.
	 *
	 * @param int    $scan_id       Scan ID.
	 * @param int    $attachment_id Referenced attachment ID.
	 * @param string $origin        Structured origin (e.g. 'content:123').
	 */
	public function add_id_ref( $scan_id, $attachment_id, $origin ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		$origin = substr( $origin, 0, self::MAX_ORIGIN_LENGTH );
		$key    = md5( $scan_id . '|id:' . $attachment_id . '|' . $origin );

		$this->buffer_row(
			$scan_id,
			$key,
			array(
				'attachment_id' => $attachment_id,
				'path'          => null,
				'origin'        => $origin,
			)
		);
	}

	/**
	 * Record a path/filename reference (already stem-normalized).
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $path    Canonical stem path or bare filename.
	 * @param string $origin  Structured origin.
	 */
	public function add_path_ref( $scan_id, $path, $origin ) {
		if ( ! is_string( $path ) || $path === '' || strlen( $path ) > 1024 ) {
			return;
		}

		$origin = substr( $origin, 0, self::MAX_ORIGIN_LENGTH );
		$key    = md5( $scan_id . '|path:' . $path . '|' . $origin );

		$this->buffer_row(
			$scan_id,
			$key,
			array(
				'attachment_id' => null,
				'path'          => $path,
				'origin'        => $origin,
			)
		);
	}

	/**
	 * Buffer one row, folding duplicate sightings into hits.
	 *
	 * @param int    $scan_id  Scan ID.
	 * @param string $ref_hash Dedupe key.
	 * @param array  $row      Row data.
	 */
	protected function buffer_row( $scan_id, $ref_hash, $row ) {
		if ( isset( $this->buffer[ $ref_hash ] ) ) {
			++$this->buffer[ $ref_hash ]['hits'];
			return;
		}

		$row['scan_id']  = (int) $scan_id;
		$row['ref_hash'] = $ref_hash;
		$row['hits']     = 1;

		$this->buffer[ $ref_hash ] = $row;

		if ( count( $this->buffer ) >= self::FLUSH_SIZE ) {
			$this->flush();
		}
	}

	/**
	 * Flush buffered rows in one multi-row statement. ON DUPLICATE KEY makes
	 * retried extraction ticks idempotent while keeping hit counts exact.
	 */
	public function flush() {
		global $wpdb;

		if ( empty( $this->buffer ) ) {
			return;
		}

		// Built manually (not wpdb::prepare) because prepare() cannot emit SQL
		// NULL for the two nullable columns; every string passes esc_sql().
		$values = array();
		foreach ( $this->buffer as $row ) {
			$scan_id = (int) $row['scan_id'];
			$att     = $row['attachment_id'] !== null ? (string) (int) $row['attachment_id'] : 'NULL';
			$path    = $row['path'] !== null ? "'" . esc_sql( $row['path'] ) . "'" : 'NULL';
			$hash    = $row['path'] !== null ? "'" . Url_Normalizer::hash( $row['path'] ) . "'" : 'NULL';
			$origin  = "'" . esc_sql( $row['origin'] ) . "'";
			$refh    = "'" . esc_sql( $row['ref_hash'] ) . "'";
			$hits    = (int) $row['hits'];

			$values[] = "({$scan_id},{$att},{$path},{$hash},{$origin},{$refh},{$hits})";
		}

		$table = $this->table();
		$sql   = "INSERT INTO {$table} (scan_id, attachment_id, path, path_hash, origin, ref_hash, hits)
			VALUES " . implode( ',', $values ) . '
			ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)';

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- values escaped above.

		$this->buffer = array();
	}

	/**
	 * Fetch every reference row matching an attachment: by ID, or by any of
	 * its canonical lookup keys. Returns ALL rows (not LIMIT 1) because the
	 * rows are the data source for the detailed usage notes.
	 *
	 * @param int      $scan_id       Scan ID.
	 * @param int|null $attachment_id Attachment ID (null for filesystem-only files).
	 * @param string[] $lookup_keys   Canonical stems/basenames to match.
	 * @return array[] Rows: [{origin, hits, attachment_id, path}]
	 */
	public function get_refs( $scan_id, $attachment_id, array $lookup_keys ) {
		global $wpdb;

		$table      = $this->table();
		$conditions = array();
		$params     = array( (int) $scan_id );

		if ( $attachment_id ) {
			$conditions[] = 'attachment_id = %d';
			$params[]     = (int) $attachment_id;
		}

		if ( ! empty( $lookup_keys ) ) {
			$hashes = array_map( array( Url_Normalizer::class, 'hash' ), $lookup_keys );
			$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

			$conditions[] = "path_hash IN ({$in})";
			$params       = array_merge( $params, $hashes );
		}

		if ( empty( $conditions ) ) {
			return array();
		}

		$where = implode( ' OR ', $conditions );
		$sql   = "SELECT origin, hits, attachment_id, path FROM {$table}
			WHERE scan_id = %d AND ({$where})";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Whether any reference exists for the given lookup keys (cheap variant
	 * for callers that only need used/unused).
	 *
	 * @param int      $scan_id       Scan ID.
	 * @param int|null $attachment_id Attachment ID.
	 * @param string[] $lookup_keys   Canonical stems/basenames.
	 * @return bool
	 */
	public function has_ref( $scan_id, $attachment_id, array $lookup_keys ) {
		$refs = $this->get_refs( $scan_id, $attachment_id, $lookup_keys );
		return ! empty( $refs );
	}

	/**
	 * Delete all references for a scan (called when a scan finishes - the
	 * rendered notes live in the file_scan rows, so the raw index is no
	 * longer needed - and when a scan is deleted).
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function delete_for_scan( $scan_id ) {
		global $wpdb;

		$table = $this->table();
		// Bounded chunks so cleanup itself can never time out.
		do {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id = %d LIMIT 5000", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} while ( $deleted === 5000 );
	}

	/**
	 * Count reference rows for a scan (diagnostics/progress display).
	 *
	 * @param int $scan_id Scan ID.
	 * @return int
	 */
	public function count_for_scan( $scan_id ) {
		global $wpdb;

		$table = $this->table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %d", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
