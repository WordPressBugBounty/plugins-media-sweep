<?php
/**
 * Has One Relationship
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils\Relations;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Abstracts\Relationship;

/**
 * Has One Relationship
 */
class Has_One extends Relationship {

	/**
	 * Foreign key in related table
	 *
	 * @var string
	 */
	protected $foreign_key;

	/**
	 * Local key in parent table
	 *
	 * @var string
	 */
	protected $local_key;

	/**
	 * Constructor
	 *
	 * @param Model  $parent
	 * @param string $related
	 * @param string $foreign_key
	 * @param string $local_key
	 */
	public function __construct( $parent, $related, $foreign_key = null, $local_key = 'id' ) {
		parent::__construct( $parent, $related );

		// Auto-generate foreign key if not provided
		if ( ! $foreign_key ) {
			$class_name  = basename( str_replace( '\\', '/', get_class( $parent ) ) );
			$foreign_key = strtolower( $class_name ) . '_id';
		}

		$this->foreign_key = $foreign_key;
		$this->local_key   = $local_key;
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
		$local_key_value = $this->parent->get_attribute( $this->local_key );

		if ( ! $local_key_value ) {
			return null;
		}

		$query = $this->related::where( $this->foreign_key, '=', $local_key_value );

		// Apply column selection if specific columns are requested
		if ( $this->columns !== array( '*' ) ) {
			$query->select( $this->columns );
		}

		return $query->first();
	}

	/**
	 * Create a new related model
	 *
	 * @param array $attributes
	 * @return Model
	 */
	public function create( $attributes ) {
		$local_key_value                  = $this->parent->get_attribute( $this->local_key );
		$attributes[ $this->foreign_key ] = $local_key_value;

		return $this->related::create( $attributes );
	}

	/**
	 * Update or create related model
	 *
	 * @param array $attributes
	 * @return Model
	 */
	public function update_or_create( $attributes ) {
		$existing = $this->get();

		if ( $existing ) {
			$existing->fill( $attributes );
			$existing->save();
			return $existing;
		}

		return $this->create( $attributes );
	}
}
