<?php
/**
 * Belongs To Many Relationship (Many-to-Many)
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils\Relations;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Abstracts\Relationship;

/**
 * Belongs To Many Relationship (Many-to-Many)
 */
class Belongs_To_Many extends Relationship {

	/**
	 * Pivot table name
	 *
	 * @var string
	 */
	protected $pivot_table;

	/**
	 * Foreign key in pivot table for parent
	 *
	 * @var string
	 */
	protected $foreign_pivot_key;

	/**
	 * Related key in pivot table for related model
	 *
	 * @var string
	 */
	protected $related_pivot_key;

	/**
	 * Parent key
	 *
	 * @var string
	 */
	protected $parent_key;

	/**
	 * Related key
	 *
	 * @var string
	 */
	protected $related_key;

	/**
	 * Constructor
	 *
	 * @param Model  $parent
	 * @param string $related
	 * @param string $pivot_table
	 * @param string $foreign_pivot_key
	 * @param string $related_pivot_key
	 * @param string $parent_key
	 * @param string $related_key
	 */
	public function __construct( $parent, $related, $pivot_table = null, $foreign_pivot_key = null, $related_pivot_key = null, $parent_key = 'id', $related_key = 'id' ) {
		parent::__construct( $parent, $related );

		global $wpdb;
		$this->pivot_table       = $wpdb->prefix . $pivot_table;
		$this->foreign_pivot_key = $foreign_pivot_key;
		$this->related_pivot_key = $related_pivot_key;
		$this->parent_key        = $parent_key;
		$this->related_key       = $related_key;
	}

	/**
	 * Select specific columns
	 *
	 * @param array|string $columns Columns to select
	 * @return $this
	 */
	public function select( $columns ) {
		$this->columns = is_array( $columns ) ? $columns : func_get_args();
		return $this;
	}

	/**
	 * Get the related models
	 *
	 * @return array
	 */
	public function get() {
		global $wpdb;

		$parent_id = $this->parent->get_attribute( $this->parent_key );
		if ( ! $parent_id ) {
			return array();
		}

		if ( $this->related === 'wp_post' ) {
			// Get WordPress posts
			$sql = $wpdb->prepare(
				"SELECT p.* FROM {$wpdb->posts} p
				INNER JOIN {$this->pivot_table} pv ON p.ID = pv.{$this->related_pivot_key}
				WHERE pv.{$this->foreign_pivot_key} = %d AND p.post_status = 'publish'",
				$parent_id
			);

			$results = $wpdb->get_results( $sql );
			return array_map(
				function ( $post ) {
					return get_post( $post->ID );
				},
				$results
			);
		} else {
			// Get custom model records
			$sql = $wpdb->prepare(
				"SELECT {$this->related_pivot_key} FROM {$this->pivot_table} WHERE {$this->foreign_pivot_key} = %d",
				$parent_id
			);

			$ids = $wpdb->get_col( $sql );
			if ( empty( $ids ) ) {
				return array();
			}

			$query = $this->related::where_in( $this->related_key, $ids );

			// Apply column selection if specific columns are requested
			if ( $this->columns !== array( '*' ) ) {
				$query->select( $this->columns );
			}

			return $query->get();
		}
	}

	/**
	 * Get related IDs only
	 *
	 * @return array
	 */
	public function pluck() {
		global $wpdb;

		$parent_id = $this->parent->get_attribute( $this->parent_key );
		if ( ! $parent_id ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT {$this->related_pivot_key} FROM {$this->pivot_table} WHERE {$this->foreign_pivot_key} = %d",
			$parent_id
		);

		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Attach related models
	 *
	 * @param array|int $ids
	 * @return bool
	 */
	public function attach( $ids ) {
		global $wpdb;

		$parent_id = $this->parent->get_attribute( $this->parent_key );
		if ( ! $parent_id ) {
			return false;
		}

		$ids     = is_array( $ids ) ? $ids : array( $ids );
		$success = true;

		foreach ( $ids as $id ) {
			// Check if relationship already exists
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->pivot_table} 
				WHERE {$this->foreign_pivot_key} = %d AND {$this->related_pivot_key} = %d",
					$parent_id,
					$id
				)
			);

			if ( ! $exists ) {
				$result = $wpdb->insert(
					$this->pivot_table,
					array(
						$this->foreign_pivot_key => $parent_id,
						$this->related_pivot_key => $id,
					),
					array( '%d', '%d' )
				);

				if ( $result === false ) {
					$success = false;
				}
			}
		}

		return $success;
	}

	/**
	 * Detach related models
	 *
	 * @param array|int|null $ids If null, detach all
	 * @return bool
	 */
	public function detach( $ids = null ) {
		global $wpdb;

		$parent_id = $this->parent->get_attribute( $this->parent_key );
		if ( ! $parent_id ) {
			return false;
		}

		if ( $ids === null ) {
			// Detach all
			return $wpdb->delete(
				$this->pivot_table,
				array( $this->foreign_pivot_key => $parent_id ),
				array( '%d' )
			) !== false;
		}

		$ids     = is_array( $ids ) ? $ids : array( $ids );
		$success = true;

		foreach ( $ids as $id ) {
			$result = $wpdb->delete(
				$this->pivot_table,
				array(
					$this->foreign_pivot_key => $parent_id,
					$this->related_pivot_key => $id,
				),
				array( '%d', '%d' )
			);

			if ( $result === false ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Sync related models (replace all with new ones)
	 *
	 * @param array $ids
	 * @return bool
	 */
	public function sync( array $ids ) {
		// First detach all
		$this->detach();

		// Then attach new ones
		return empty( $ids ) ? true : $this->attach( $ids );
	}

	/**
	 * Check if related model exists
	 *
	 * @param int $id
	 * @return bool
	 */
	public function exists( $id ) {
		global $wpdb;

		$parent_id = $this->parent->get_attribute( $this->parent_key );
		if ( ! $parent_id ) {
			return false;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->pivot_table} 
			WHERE {$this->foreign_pivot_key} = %d AND {$this->related_pivot_key} = %d",
				$parent_id,
				$id
			)
		);

		return $count > 0;
	}

	/**
	 * Count related models
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		$parent_id = $this->parent->get_attribute( $this->parent_key );
		if ( ! $parent_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->pivot_table} WHERE {$this->foreign_pivot_key} = %d",
				$parent_id
			)
		);
	}
}
