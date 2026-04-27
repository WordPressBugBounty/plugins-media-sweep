<?php
/**
 * Single API Paginator class
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * API Paginator class - handles both full and simple pagination
 */
class Paginator implements \JsonSerializable {

	/**
	 * The items for the current page
	 *
	 * @var array
	 */
	protected $items;

	/**
	 * The total number of items (null for simple pagination)
	 *
	 * @var int|null
	 */
	protected $total;

	/**
	 * The number of items per page
	 *
	 * @var int
	 */
	protected $per_page;

	/**
	 * The current page number
	 *
	 * @var int
	 */
	protected $current_page;

	/**
	 * Whether there are more pages (for simple pagination)
	 *
	 * @var bool|null
	 */
	protected $has_more;

	/**
	 * Constructor
	 *
	 * @param array    $items
	 * @param int      $per_page
	 * @param int      $current_page
	 * @param int|null $total        Total items (null for simple pagination)
	 * @param bool     $has_more     Whether there are more pages (for simple pagination)
	 */
	public function __construct( $items, $per_page, $current_page, $total = null, $has_more = null ) {
		$this->items        = $items;
		$this->per_page     = $per_page;
		$this->current_page = $current_page;
		$this->total        = $total;
		$this->has_more     = $has_more;
	}

	/**
	 * Check if this is simple pagination (no total count)
	 *
	 * @return bool
	 */
	public function is_simple() {
		return $this->total === null;
	}

	/**
	 * Get the items for the current page
	 *
	 * @return array
	 */
	public function items() {
		return $this->items;
	}

	/**
	 * Get the total number of items (full pagination only)
	 *
	 * @return int|null
	 */
	public function total() {
		return $this->total;
	}

	/**
	 * Get the number of items per page
	 *
	 * @return int
	 */
	public function per_page() {
		return $this->per_page;
	}

	/**
	 * Get the current page number
	 *
	 * @return int
	 */
	public function current_page() {
		return $this->current_page;
	}

	/**
	 * Get the last page number (full pagination only)
	 *
	 * @return int|null
	 */
	public function last_page() {
		return $this->total ? (int) ceil( $this->total / $this->per_page ) : null;
	}

	/**
	 * Determine if there are more pages
	 *
	 * @return bool
	 */
	public function has_more_pages() {
		if ( $this->is_simple() ) {
			return $this->has_more;
		}
		return $this->current_page < $this->last_page();
	}

	/**
	 * Determine if there is a previous page
	 *
	 * @return bool
	 */
	public function has_previous_page() {
		return $this->current_page > 1;
	}

	/**
	 * Get the next page number
	 *
	 * @return int|null
	 */
	public function next_page() {
		return $this->has_more_pages() ? $this->current_page + 1 : null;
	}

	/**
	 * Get the previous page number
	 *
	 * @return int|null
	 */
	public function previous_page() {
		return $this->has_previous_page() ? $this->current_page - 1 : null;
	}

	/**
	 * Get the first item number on the page (full pagination only)
	 *
	 * @return int|null
	 */
	public function first_item() {
		if ( $this->is_simple() || $this->total === 0 ) {
			return null;
		}
		return ( $this->current_page - 1 ) * $this->per_page + 1;
	}

	/**
	 * Get the last item number on the page (full pagination only)
	 *
	 * @return int|null
	 */
	public function last_item() {
		if ( $this->is_simple() ) {
			return null;
		}
		return min( $this->current_page * $this->per_page, $this->total );
	}

	/**
	 * Convert to array for API responses
	 *
	 * @return array
	 */
	public function to_array() {
		// Transform items to arrays
		$data = array_map(
			function ( $item ) {
				return method_exists( $item, 'to_array' ) ? $item->to_array() : $item;
			},
			$this->items
		);

		if ( $this->is_simple() ) {
			// Simple pagination response
			return array(
				'data'       => $data,
				'pagination' => array(
					'current_page'  => $this->current_page,
					'per_page'      => $this->per_page,
					'has_more'      => $this->has_more_pages(),
					'has_previous'  => $this->has_previous_page(),
					'next_page'     => $this->next_page(),
					'previous_page' => $this->previous_page(),
				),
			);
		}

		// Full pagination response
		return array(
			'data'       => $data,
			'pagination' => array(
				'current_page'  => $this->current_page,
				'per_page'      => $this->per_page,
				'total'         => $this->total,
				'last_page'     => $this->last_page(),
				'from'          => $this->first_item(),
				'to'            => $this->last_item(),
				'has_previous'  => $this->has_previous_page(),
				'has_next'      => $this->has_more_pages(),
				'previous_page' => $this->previous_page(),
				'next_page'     => $this->next_page(),
			),
		);
	}

	/**
	 * Convert to JSON for API responses
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode( $this->to_array() );
	}

	/**
	 * Get only the data items (without pagination metadata)
	 *
	 * @return array
	 */
	public function data() {
		return array_map(
			function ( $item ) {
				return method_exists( $item, 'to_array' ) ? $item->to_array() : $item;
			},
			$this->items
		);
	}

	/**
	 * Get only pagination metadata (without data items)
	 *
	 * @return array
	 */
	public function meta() {
		$response = $this->to_array();
		return $response['pagination'];
	}

	/**
	 * Specify data which should be serialized to JSON
	 *
	 * @return array
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->to_array();
	}
}
