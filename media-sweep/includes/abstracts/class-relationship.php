<?php
/**
 * Eloquent-style Relationships
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abstracts;

use Media_Sweep\Abstracts\Model;

/**
 * Base Relationship class
 */
abstract class Relationship {

	/**
	 * Parent model
	 *
	 * @var Model
	 */
	protected $parent;

	/**
	 * Related model class
	 *
	 * @var string
	 */
	protected $related;

	/**
	 * Selected columns
	 *
	 * @var array
	 */
	protected $columns = array( '*' );

	/**
	 * Constructor
	 *
	 * @param Model  $parent
	 * @param string $related
	 */
	public function __construct( $parent, $related ) {
		$this->parent  = $parent;
		$this->related = $related;
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
	 * Get the results of the relationship
	 */
	abstract public function get();
}
