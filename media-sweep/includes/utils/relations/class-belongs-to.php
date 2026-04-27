<?php
/**
 * Belongs To Relationship (One-to-One inverse)
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils\Relations;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Abstracts\Relationship;

/**
 * Belongs To Relationship (One-to-One inverse)
 */
class Belongs_To extends Relationship {

	/**
	 * Foreign key in parent table
	 *
	 * @var string
	 */
	protected $foreign_key;

	/**
	 * Owner key in related table
	 *
	 * @var string
	 */
	protected $owner_key;

	/**
	 * Constructor
	 *
	 * @param Model  $parent
	 * @param string $related
	 * @param string $foreign_key
	 * @param string $owner_key
	 */
	public function __construct( $parent, $related, $foreign_key = null, $owner_key = 'id' ) {
		parent::__construct( $parent, $related );

		// Auto-generate foreign key if not provided
		if ( ! $foreign_key ) {
			$class_name  = basename( str_replace( '\\', '/', $related ) );
			$foreign_key = strtolower( $class_name ) . '_id';
		}

		$this->foreign_key = $foreign_key;
		$this->owner_key   = $owner_key;
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
	 * Get the related model
	 *
	 * @return Model|null
	 */
	public function get() {
		$foreign_key_value = $this->parent->get_attribute( $this->foreign_key );

		if ( ! $foreign_key_value ) {
			return null;
		}

		// Handle WordPress posts
		if ( $this->related === 'wp_post' ) {
			return get_post( $foreign_key_value );
		}

		// Create query builder
		$query = $this->related::where( $this->owner_key, '=', $foreign_key_value );

		// Apply column selection if specific columns are requested
		if ( $this->columns !== array( '*' ) ) {
			$query->select( $this->columns );
		}

		return $query->first();
	}
}
