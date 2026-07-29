<?php
/**
 * Reference Extractor Service - single-pass media reference extraction.
 *
 * Walks posts, postmeta, options/termmeta and (optionally) other plugin
 * tables ONCE per scan, extracting every attachment ID and uploads URL into
 * the indexed refs table. All matching happens in PHP with compiled regexes
 * (never SQL LIKE/REGEXP), so each table page is read exactly once.
 *
 * Every run_*_slice() method is resumable: it processes items until the
 * given Time_Budget says stop, persists its cursor into the checkpoint
 * array, and reports whether the phase finished. Writes are idempotent
 * (refs dedupe on ref_hash), so a retried slice can never double-write.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Services;

use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Utils\Time_Budget;
use Media_Sweep\Utils\Url_Normalizer;

/**
 * Reference Extractor Service class
 */
class Reference_Extractor_Service {

	/**
	 * Posts fetched per inner page during content extraction.
	 */
	const POSTS_PER_PAGE = 50;

	/**
	 * Rows fetched per inner page during deep-table extraction.
	 */
	const DEEP_ROWS_PER_PAGE = 200;

	/**
	 * Maximum bytes of a single document (post content / meta value) parsed.
	 * One giant page-builder blob must not consume the whole request budget.
	 */
	const MAX_DOCUMENT_BYTES = 8388608; // 8MB

	/**
	 * Attachment-core meta keys that only describe the attachment's own
	 * files; indexing them would make every attachment reference itself.
	 * (_wp_attachment_backup_sizes previously caused every edited image to
	 * be reported as "in use" by its own backup entry.)
	 *
	 * @var string[]
	 */
	protected $excluded_meta_keys = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_thumbnail_id', // Handled explicitly as a featured-image reference.
	);

	/**
	 * Reference store.
	 *
	 * @var Reference_Store
	 */
	protected $store;

	/**
	 * Constructor.
	 *
	 * @param Reference_Store $store Reference store.
	 */
	public function __construct( Reference_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Drive the extraction phases forward under one budget, advancing the
	 * scan's phase through extract_posts -> extract_options -> extract_deep.
	 * Shared by the tick runner and the legacy batch endpoint.
	 *
	 * @param int         $scan_id    Scan ID.
	 * @param array       $checkpoint Checkpoint (by reference).
	 * @param string      $phase      Current phase (by reference; updated).
	 * @param array       $options    Scan options (deep_scan gate).
	 * @param Time_Budget $budget     Request budget.
	 * @return bool True when every extraction phase is complete.
	 */
	public function advance_extraction( $scan_id, array &$checkpoint, &$phase, $options, Time_Budget $budget ) {
		$deep = ! empty( $options['deep_scan'] );

		while ( ! $budget->should_stop() ) {
			if ( Scan_Model::PHASE_EXTRACT_POSTS === $phase ) {
				if ( ! $this->run_posts_slice( $scan_id, $checkpoint, $budget ) ) {
					return false;
				}
				$phase = Scan_Model::PHASE_EXTRACT_OPTIONS;
				continue;
			}

			if ( Scan_Model::PHASE_EXTRACT_OPTIONS === $phase ) {
				if ( ! $this->run_options_slice( $scan_id, $checkpoint, $budget ) ) {
					return false;
				}
				if ( ! $deep ) {
					return true;
				}
				$phase = Scan_Model::PHASE_EXTRACT_DEEP;
				continue;
			}

			if ( Scan_Model::PHASE_EXTRACT_DEEP === $phase ) {
				return $this->run_deep_slice( $scan_id, $checkpoint, $budget );
			}

			// Any non-extraction phase means extraction already finished.
			return true;
		}

		return false;
	}

	/**
	 * Whether a phase value is one of the extraction phases.
	 *
	 * @param string $phase Phase.
	 * @return bool
	 */
	public static function is_extraction_phase( $phase ) {
		return in_array(
			$phase,
			array(
				Scan_Model::PHASE_EXTRACT_POSTS,
				Scan_Model::PHASE_EXTRACT_OPTIONS,
				Scan_Model::PHASE_EXTRACT_DEEP,
			),
			true
		);
	}

	/**
	 * Extract references from a slice of posts (content + excerpt + meta).
	 *
	 * Checkpoint keys used: posts_cursor, posts_total, posts_done.
	 *
	 * @param int         $scan_id    Scan ID.
	 * @param array       $checkpoint Checkpoint (by reference).
	 * @param Time_Budget $budget     Request budget.
	 * @return bool True when the posts phase is complete.
	 */
	public function run_posts_slice( $scan_id, array &$checkpoint, Time_Budget $budget ) {
		global $wpdb;

		if ( ! isset( $checkpoint['posts_total'] ) ) {
			$checkpoint['posts_total'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				 AND post_type NOT IN ('revision')"
			);
			$checkpoint['posts_cursor'] = 0;
			$checkpoint['posts_done']   = 0;
		}

		while ( ! $budget->should_stop() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_type, post_content, post_excerpt FROM {$wpdb->posts}
					 WHERE ID > %d
					 AND post_status NOT IN ('trash','auto-draft')
					 AND post_type NOT IN ('revision')
					 ORDER BY ID ASC
					 LIMIT %d",
					$checkpoint['posts_cursor'],
					self::POSTS_PER_PAGE
				)
			);

			if ( empty( $rows ) ) {
				$this->store->flush();
				return true;
			}

			$post_ids = wp_list_pluck( $rows, 'ID' );
			$meta     = $this->fetch_meta_for_posts( $post_ids );

			foreach ( $rows as $row ) {
				if ( $budget->should_stop() ) {
					break 2;
				}

				$budget->run_item(
					function () use ( $scan_id, $row, $meta ) {
						// Attachments' own content is their description/caption -
						// not a usage location (1.0.x parity: content queries
						// excluded post_type attachment).
						if ( $row->post_type !== 'attachment' ) {
							$this->extract_from_content( $scan_id, (int) $row->ID, (string) $row->post_content . ' ' . (string) $row->post_excerpt );
						}

						if ( isset( $meta[ $row->ID ] ) ) {
							$this->extract_from_meta( $scan_id, (int) $row->ID, $meta[ $row->ID ] );
						}
					}
				);

				$checkpoint['posts_cursor'] = (int) $row->ID;
				++$checkpoint['posts_done'];
			}
		}

		$this->store->flush();
		return false;
	}

	/**
	 * Extract references from options, theme mods, widgets and termmeta.
	 * Small enough to normally complete in one slice, but still budgeted.
	 *
	 * Checkpoint keys used: options_done, termmeta_cursor.
	 *
	 * @param int         $scan_id    Scan ID.
	 * @param array       $checkpoint Checkpoint (by reference).
	 * @param Time_Budget $budget     Request budget.
	 * @return bool True when complete.
	 */
	public function run_options_slice( $scan_id, array &$checkpoint, Time_Budget $budget ) {
		global $wpdb;

		if ( empty( $checkpoint['options_done'] ) ) {
			// Direct media settings.
			foreach ( array( 'site_icon', 'site_logo' ) as $name ) {
				$value = get_option( $name );
				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$this->store->add_id_ref( $scan_id, (int) $value, 'option:' . $name );
				}
			}

			// Current theme's mods (custom_logo, header/background images...).
			$mods = get_option( 'theme_mods_' . get_option( 'stylesheet' ) );
			if ( is_array( $mods ) ) {
				$this->extract_from_value_blob( $scan_id, $mods, 'option:theme_mods' );
			}

			// Widget instances.
			$widget_rows = $wpdb->get_results(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE 'widget\\_%'"
			);
			foreach ( $widget_rows as $widget_row ) {
				$this->extract_from_text( $scan_id, (string) $widget_row->option_value, 'option:' . $widget_row->option_name );
			}

			$checkpoint['options_done'] = 1;
			$this->store->flush();
		}

		// Termmeta: thumbnail_id (WooCommerce category images etc.) plus any
		// meta value carrying an uploads path.
		if ( ! isset( $checkpoint['termmeta_cursor'] ) ) {
			$checkpoint['termmeta_cursor'] = 0;
		}

		while ( ! $budget->should_stop() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta}
					 WHERE meta_id > %d
					 ORDER BY meta_id ASC
					 LIMIT 500",
					$checkpoint['termmeta_cursor']
				)
			);

			if ( empty( $rows ) ) {
				$this->store->flush();
				return true;
			}

			foreach ( $rows as $row ) {
				$checkpoint['termmeta_cursor'] = (int) $row->meta_id;

				if ( $row->meta_key === 'thumbnail_id' && is_numeric( $row->meta_value ) ) {
					$this->store->add_id_ref( $scan_id, (int) $row->meta_value, 'termmeta:thumbnail_id:' . $row->term_id );
					continue;
				}

				if ( is_string( $row->meta_value ) && stripos( $row->meta_value, 'uploads' ) !== false ) {
					$this->extract_from_text( $scan_id, $row->meta_value, 'termmeta:' . $row->meta_key . ':' . $row->term_id, false );
				}
			}
		}

		$this->store->flush();
		return false;
	}

	/**
	 * Extract references from other plugin tables ("deep scan").
	 *
	 * Instead of the 1.0.x approach (N LIKE scans per attachment per column),
	 * each text column is read once: rows containing an uploads path are
	 * fetched page by page and parsed in PHP. hits counting preserves the
	 * "Found N time(s) in table x.y" notes.
	 *
	 * Checkpoint keys used: deep_columns (frozen work list), deep_index,
	 * deep_offset.
	 *
	 * @param int         $scan_id    Scan ID.
	 * @param array       $checkpoint Checkpoint (by reference).
	 * @param Time_Budget $budget     Request budget.
	 * @return bool True when complete.
	 */
	public function run_deep_slice( $scan_id, array &$checkpoint, Time_Budget $budget ) {
		global $wpdb;

		if ( ! isset( $checkpoint['deep_columns'] ) ) {
			$checkpoint['deep_columns'] = $this->build_deep_column_list();
			$checkpoint['deep_index']   = 0;
			$checkpoint['deep_offset']  = 0;
		}

		$columns = $checkpoint['deep_columns'];

		while ( $checkpoint['deep_index'] < count( $columns ) ) {
			if ( $budget->should_stop() ) {
				$this->store->flush();
				return false;
			}

			list( $table, $column ) = $columns[ $checkpoint['deep_index'] ];

			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT `{$column}` FROM `{$table}`
					 WHERE `{$column}` LIKE %s
					 LIMIT %d OFFSET %d",
					'%uploads%',
					self::DEEP_ROWS_PER_PAGE,
					$checkpoint['deep_offset']
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers come from SHOW TABLES/DESCRIBE.

			foreach ( $rows as $value ) {
				$this->extract_from_text( $scan_id, (string) $value, 'table:' . $table . '.' . $column, false );
			}

			if ( count( $rows ) < self::DEEP_ROWS_PER_PAGE ) {
				++$checkpoint['deep_index'];
				$checkpoint['deep_offset'] = 0;
			} else {
				$checkpoint['deep_offset'] += self::DEEP_ROWS_PER_PAGE;
			}
		}

		$this->store->flush();
		return true;
	}

	/**
	 * Extract references from one post's content/excerpt HTML.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param int    $post_id Post ID.
	 * @param string $content Combined content + excerpt.
	 */
	protected function extract_from_content( $scan_id, $post_id, $content ) {
		if ( $content === '' || trim( $content ) === '' ) {
			return;
		}

		if ( strlen( $content ) > self::MAX_DOCUMENT_BYTES ) {
			$content = substr( $content, 0, self::MAX_DOCUMENT_BYTES );
		}

		// URLs + bare filename mentions -> content origin.
		$this->extract_from_text( $scan_id, $content, 'content:' . $post_id );

		// Rendered image classnames carry the attachment ID exactly.
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $id ) {
				$this->store->add_id_ref( $scan_id, (int) $id, 'content:' . $post_id );
			}
		}

		// Gallery shortcodes: [gallery ids="1,2,3"], also include= / id= attrs.
		if ( strpos( $content, '[' ) !== false && preg_match_all( '/\[([^\]]+)\]/', $content, $matches ) ) {
			foreach ( $matches[1] as $shortcode ) {
				if ( preg_match_all( '/(?:ids|include|id)=["\']([\d,\s]+)["\']/', $shortcode, $attr_matches ) ) {
					foreach ( $attr_matches[1] as $id_list ) {
						foreach ( explode( ',', $id_list ) as $id ) {
							$this->store->add_id_ref( $scan_id, (int) trim( $id ), 'gallery_shortcode:' . $post_id );
						}
					}
				}
			}
		}

		// Block attributes: {"id":123,...} and {"ids":[1,2,3],...}.
		if ( strpos( $content, '"id' ) !== false ) {
			if ( preg_match_all( '/"id":\s*(\d+)/', $content, $matches ) ) {
				foreach ( array_unique( $matches[1] ) as $id ) {
					$this->store->add_id_ref( $scan_id, (int) $id, 'gallery_block:' . $post_id );
				}
			}
			if ( preg_match_all( '/"ids":\s*\[([\d,\s]*)\]/', $content, $matches ) ) {
				foreach ( $matches[1] as $id_list ) {
					foreach ( explode( ',', $id_list ) as $id ) {
						$this->store->add_id_ref( $scan_id, (int) trim( $id ), 'gallery_block:' . $post_id );
					}
				}
			}
		}

		/**
		 * Extensibility: builder/plugin parsers can record additional
		 * references for this post via the passed store.
		 *
		 * @param string          $content Content being parsed.
		 * @param int             $post_id Post ID.
		 * @param int             $scan_id Scan ID.
		 * @param Reference_Store $store   Reference store (add_id_ref/add_path_ref).
		 */
		do_action( 'media_sweep_extract_post', $content, $post_id, $scan_id, $this->store );
	}

	/**
	 * Extract references from one post's meta rows.
	 *
	 * @param int   $scan_id   Scan ID.
	 * @param int   $post_id   Post ID.
	 * @param array $meta_rows Array of {meta_key, meta_value}.
	 */
	protected function extract_from_meta( $scan_id, $post_id, $meta_rows ) {
		foreach ( $meta_rows as $meta ) {
			$key   = $meta->meta_key;
			$value = (string) $meta->meta_value;

			if ( $key === '_thumbnail_id' ) {
				if ( is_numeric( $value ) ) {
					$this->store->add_id_ref( $scan_id, (int) $value, 'featured:' . $post_id );
				}
				continue;
			}

			if ( in_array( $key, $this->excluded_meta_keys, true ) || $value === '' ) {
				continue;
			}

			$origin = 'custom_field:' . $key . ':' . $post_id;

			// Whole-value numeric meta = direct attachment ID reference
			// (1.0.x parity: meta_value = '<id>' equality check).
			if ( is_numeric( $value ) ) {
				$this->store->add_id_ref( $scan_id, (int) $value, $origin );
				continue;
			}

			if ( strlen( $value ) > self::MAX_DOCUMENT_BYTES ) {
				$value = substr( $value, 0, self::MAX_DOCUMENT_BYTES );
			}

			// Raw regex extraction covers plain HTML, serialized PHP and
			// escaped JSON (Elementor & friends) without unserializing.
			$this->extract_from_text( $scan_id, $value, $origin );

			// Builder JSON also references attachments by bare ID next to the
			// URL ({"id":123,"url":"..."}); capture those exactly.
			if ( $key === '_elementor_data' && preg_match_all( '/"id":\s*(\d+)/', $value, $matches ) ) {
				foreach ( array_unique( $matches[1] ) as $id ) {
					$this->store->add_id_ref( $scan_id, (int) $id, $origin );
				}
			}
		}

		/**
		 * Extensibility hook mirroring media_sweep_extract_post for meta.
		 *
		 * @param array           $meta_rows Meta rows for this post.
		 * @param int             $post_id   Post ID.
		 * @param int             $scan_id   Scan ID.
		 * @param Reference_Store $store     Reference store.
		 */
		do_action( 'media_sweep_extract_meta', $meta_rows, $post_id, $scan_id, $this->store );
	}

	/**
	 * Extract URL stems (and optionally bare filenames) from arbitrary text
	 * into the store under one origin.
	 *
	 * @param int    $scan_id           Scan ID.
	 * @param string $text              Raw text.
	 * @param string $origin            Structured origin.
	 * @param bool   $include_filenames Whether bare filename mentions count.
	 */
	protected function extract_from_text( $scan_id, $text, $origin, $include_filenames = true ) {
		$refs = Url_Normalizer::extract_references( $text );

		foreach ( $refs['stems'] as $stem ) {
			$this->store->add_path_ref( $scan_id, $stem, $origin );
		}

		if ( $include_filenames ) {
			foreach ( $refs['filenames'] as $filename ) {
				$this->store->add_path_ref( $scan_id, $filename, $origin );
			}
		}
	}

	/**
	 * Recursively extract references from an array blob (theme mods etc.):
	 * numeric leaves under media-ish keys become ID refs, string leaves are
	 * scanned for uploads paths.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param array  $data    Array data.
	 * @param string $origin  Structured origin.
	 * @param int    $depth   Recursion guard.
	 */
	protected function extract_from_value_blob( $scan_id, $data, $origin, $depth = 0 ) {
		if ( $depth > 8 ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->extract_from_value_blob( $scan_id, $value, $origin, $depth + 1 );
				continue;
			}

			if ( is_numeric( $value ) && is_string( $key ) && preg_match( '/logo|image|icon|background|thumbnail|attachment|media/i', $key ) ) {
				$this->store->add_id_ref( $scan_id, (int) $value, $origin );
				continue;
			}

			if ( is_string( $value ) && stripos( $value, 'uploads' ) !== false ) {
				$this->extract_from_text( $scan_id, $value, $origin, false );
			}
		}
	}

	/**
	 * Fetch all meta rows for a page of posts in one indexed query.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array Map of post_id => array of {meta_key, meta_value}.
	 */
	protected function fetch_meta_for_posts( array $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$in   = implode( ',', array_map( 'intval', $post_ids ) );
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$in})"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs cast to int above.

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->post_id ][] = $row;
		}

		return $map;
	}

	/**
	 * Build the deep-scan work list: every text-ish column of every
	 * non-core, non-plugin table under the site prefix.
	 *
	 * @return array[] List of [table, column] pairs.
	 */
	protected function build_deep_column_list() {
		global $wpdb;

		$all_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

		// Tables covered by the dedicated extraction phases, plus our own.
		$excluded = array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->termmeta );

		$columns = array();
		foreach ( $all_tables as $table ) {
			if ( in_array( $table, $excluded, true ) ) {
				continue;
			}

			$clean = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $table, 1 );
			if ( strpos( $clean, 'mswp_' ) === 0 ) {
				continue;
			}

			$table_columns = $wpdb->get_results( "DESCRIBE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $table_columns as $col ) {
				if ( preg_match( '/text|varchar|char/i', $col->Type ) ) {
					$columns[] = array( $table, $col->Field );
				}
			}
		}

		return $columns;
	}
}
