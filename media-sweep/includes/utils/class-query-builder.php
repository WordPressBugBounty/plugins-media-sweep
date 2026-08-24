<?php
/**
 * Query Builder class for models
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Utils\Paginator;

/**
 * Query Builder class
 */
class Query_Builder {

	/**
	 * The model instance
	 *
	 * @var Model
	 */
	protected $model;

	/**
	 * The where constraints for the query
	 *
	 * @var array
	 */
	protected $wheres = array();

			/**
			 * The orderings for the query
			 *
			 * @var array
			 */
	protected $orders = array();

	/**
	 * The group by clauses for the query
	 *
	 * @var array
	 */
	protected $groups = array();

	/**
	 * The joins for the query
	 *
	 * @var array
	 */
	protected $joins = array();

	/**
	 * The raw where clauses for the query
	 *
	 * @var array
	 */
	protected $raw_wheres = array();

	/**
	 * The table alias for the main table
	 *
	 * @var string|null
	 */
	protected $table_alias = null;

	/**
	 * The maximum number of records to return
	 *
	 * @var int
	 */
	protected $limit;

	/**
	 * The number of records to skip
	 *
	 * @var int
	 */
	protected $offset;

	/**
	 * The relationships that should be eager loaded
	 *
	 * @var array
	 */
	protected $eager_load = array();

	/**
	 * The relationships that should be eager loaded with specific columns
	 *
	 * @var array
	 */
	protected $with_relations = array();

	/**
	 * The columns to select
	 *
	 * @var array
	 */
	protected $columns = array( '*' );

	/**
	 * Raw select columns for aggregation queries
	 *
	 * @var string|null
	 */
	protected $raw_columns = null;

	/**
	 * Constructor
	 *
	 * @param Model $model
	 */
	public function __construct( Model $model ) {
		$this->model = $model;
	}

	/**
	 * Add a basic where clause to the query
	 *
	 * @param string $column
	 * @param mixed  $operator
	 * @param mixed  $value
	 * @param string $boolean
	 * @return $this
	 */
	public function where( $column, $operator = null, $value = null, $boolean = 'and' ) {
		// Handle where($column, $value) syntax
		if ( func_num_args() === 2 ) {
			$value    = $operator;
			$operator = '=';
		}

		$this->wheres[] = array(
			'type'     => 'basic',
			'column'   => $column,
			'operator' => $operator,
			'value'    => $value,
			'boolean'  => $boolean,
		);

		return $this;
	}

	/**
	 * Add an "or where" clause to the query
	 *
	 * @param string $column
	 * @param mixed  $operator
	 * @param mixed  $value
	 * @return $this
	 */
	public function or_where( $column, $operator = null, $value = null ) {
		return $this->where( $column, $operator, $value, 'or' );
	}

	/**
	 * Add a "where in" clause to the query
	 *
	 * @param string $column
	 * @param array  $values
	 * @param string $boolean
	 * @return $this
	 */
	public function where_in( $column, array $values, $boolean = 'and' ) {
		$this->wheres[] = array(
			'type'    => 'in',
			'column'  => $column,
			'values'  => $values,
			'boolean' => $boolean,
		);

		return $this;
	}

	/**
	 * Add a "where not in" clause to the query
	 *
	 * @param string $column
	 * @param array  $values
	 * @param string $boolean
	 * @return $this
	 */
	public function where_not_in( $column, array $values, $boolean = 'and' ) {
		$this->wheres[] = array(
			'type'    => 'not_in',
			'column'  => $column,
			'values'  => $values,
			'boolean' => $boolean,
		);

		return $this;
	}

	/**
	 * Add a "where null" clause to the query
	 *
	 * @param string $column
	 * @param string $boolean
	 * @return $this
	 */
	public function where_null( $column, $boolean = 'and' ) {
		$this->wheres[] = array(
			'type'    => 'null',
			'column'  => $column,
			'boolean' => $boolean,
		);

		return $this;
	}

	/**
	 * Add a "where not null" clause to the query
	 *
	 * @param string $column
	 * @param string $boolean
	 * @return $this
	 */
	public function where_not_null( $column, $boolean = 'and' ) {
		$this->wheres[] = array(
			'type'    => 'not_null',
			'column'  => $column,
			'boolean' => $boolean,
		);

		return $this;
	}

	/**
	 * Add an "order by" clause to the query
	 *
	 * @param string $column
	 * @param string $direction
	 * @return $this
	 */
	public function order_by( $column, $direction = 'asc' ) {
		$this->orders[] = array(
			'column'    => $column,
			'direction' => strtolower( $direction ) === 'desc' ? 'desc' : 'asc',
		);

		return $this;
	}

	/**
	 * Add a raw "order by" clause to the query
	 *
	 * @param string $raw
	 * @return $this
	 */
	public function order_by_raw( $raw ) {
		$this->orders[] = array(
			'raw' => $raw,
		);

		return $this;
	}

	/**
	 * Set the "limit" value of the query
	 *
	 * @param int $value
	 * @return $this
	 */
	public function limit( $value ) {
		$this->limit = $value;
		return $this;
	}

	/**
	 * Alias to set the "limit" value of the query
	 *
	 * @param int $value
	 * @return $this
	 */
	public function take( $value ) {
		return $this->limit( $value );
	}

	/**
	 * Set the "offset" value of the query
	 *
	 * @param int $value
	 * @return $this
	 */
	public function offset( $value ) {
		$this->offset = $value;
		return $this;
	}

	/**
	 * Alias to set the "offset" value of the query
	 *
	 * @param int $value
	 * @return $this
	 */
	public function skip( $value ) {
		return $this->offset( $value );
	}

	/**
	 * Set the relationships that should be eager loaded
	 *
	 * @param array|string $relations String relation name or array of relations with optional map functions
	 * @return $this
	 */
	public function with( $relations ) {
		// Handle string arguments (with method('user', 'products'))
		if ( is_string( $relations ) ) {
			$this->eager_load = func_get_args();
			return $this;
		}

		// Handle single relation configuration object
		if ( is_array( $relations ) && isset( $relations['relation'] ) ) {
			$relation     = $relations['relation'];
			$map_function = isset( $relations['map'] ) ? $relations['map'] : null;

			if ( ! isset( $this->with_relations[ $relation ] ) ) {
				$this->with_relations[ $relation ] = array();
			}

			if ( $map_function ) {
				$this->with_relations[ $relation ]['map'] = $map_function;
			}

			// Add to eager load list if not already there
			if ( ! in_array( $relation, $this->eager_load ) ) {
				$this->eager_load[] = $relation;
			}
			return $this;
		}

		// Handle array of relation names or associative array with configs
		if ( is_array( $relations ) ) {
			$eager_load = array();

			// Process each relation
			foreach ( $relations as $key => $value ) {
				if ( is_numeric( $key ) ) {
					// Simple relation name in indexed array
					$eager_load[] = $value;
				} else {
					// Relation name as key with config as value
					$relation     = $key;
					$eager_load[] = $relation;

					// Initialize relation config if needed
					if ( ! isset( $this->with_relations[ $relation ] ) ) {
						$this->with_relations[ $relation ] = array();
					}

					// Handle different value types
					if ( is_array( $value ) ) {
						// Array config with potential columns and map function
						if ( isset( $value['columns'] ) ) {
							$this->with_relations[ $relation ]['columns'] = $value['columns'];
						}

						if ( isset( $value['map'] ) && is_callable( $value['map'] ) ) {
							$this->with_relations[ $relation ]['map'] = $value['map'];
						}
					} elseif ( is_callable( $value ) ) {
						// Direct map function
						$this->with_relations[ $relation ]['map'] = $value;
					}
				}
			}

			$this->eager_load = $eager_load;
		}

		return $this;
	}

	/**
	 * Add with_relation_columns method to support selecting specific columns in relationships
	 *
	 * @param string        $relation The relation name
	 * @param array         $columns The columns to select
	 * @param callable|null $map_function Optional mapping function to transform results
	 * @return $this
	 */
	public function with_relation_columns( $relation, $columns, $map_function = null ) {
		if ( ! isset( $this->with_relations[ $relation ] ) ) {
			$this->with_relations[ $relation ] = array();
		}

		$this->with_relations[ $relation ]['columns'] = $columns;

		if ( $map_function !== null ) {
			$this->with_relations[ $relation ]['map'] = $map_function;
		}

		// Add to eager load list if not already there
		if ( ! in_array( $relation, $this->eager_load ) ) {
			$this->eager_load[] = $relation;
		}

		return $this;
	}

	/**
	 * Set the columns to be selected
	 *
	 * @param array|mixed $columns
	 * @return $this
	 */
	public function select( $columns = array( '*' ) ) {
		$columns       = is_array( $columns ) ? $columns : func_get_args();
		$this->columns = self::sanitize_column_identifiers( $columns );
		return $this;
	}

	/**
	 * Reduce a list of column references to identifiers that are safe to drop into a SELECT clause.
	 *
	 * These tokens are interpolated verbatim by to_sql(), never sent through $wpdb->prepare(), so any that
	 * reach the SQL must be trusted. Only a bare "*", a plain column name, or "alias.column" (with an
	 * optional "*") is allowed. Anything carrying a space, parenthesis, comma, quote or backtick - the shapes
	 * an injected subquery or aliased expression needs - is discarded. Every internal caller passes plain
	 * identifiers or "*", so nothing legitimate is lost; an empty result falls back to "*".
	 *
	 * @param array $columns Raw column references.
	 * @return array Safe column references.
	 */
	protected static function sanitize_column_identifiers( array $columns ) {
		$safe = array();

		foreach ( $columns as $column ) {
			if ( ! is_string( $column ) ) {
				continue;
			}

			$column = trim( $column );

			if ( '*' === $column
				|| preg_match( '/^[A-Za-z0-9_]+$/', $column )
				|| preg_match( '/^[A-Za-z0-9_]+\.([A-Za-z0-9_]+|\*)$/', $column ) ) {
				$safe[] = $column;
			}
		}

		return empty( $safe ) ? array( '*' ) : $safe;
	}

	/**
	 * Set raw SQL for the SELECT clause (for aggregation queries)
	 *
	 * @param string $raw_sql The raw SQL for SELECT clause
	 * @return $this
	 */
	public function select_raw( $raw_sql ) {
		$this->raw_columns = $raw_sql;
		return $this;
	}

	/**
	 * Add a table alias to the query
	 *
	 * @param string $alias The alias for the main table
	 * @return $this
	 */
	public function as_alias( $alias ) {
		$this->table_alias = $alias;
		return $this;
	}

	/**
	 * Execute the query and get the first result
	 *
	 * @return Model|null
	 */
	public function first() {
		$results = $this->limit( 1 )->get();
		return empty( $results ) ? null : $results[0];
	}

	/**
	 * Execute the query and get the first result or throw an exception
	 *
	 * @return Model
	 * @throws \Exception
	 */
	public function first_or_fail() {
		$model = $this->first();

		if ( ! $model ) {
			throw new \Exception( 'Model not found' );
		}

		return $model;
	}

	/**
	 * Execute the query as a "select" statement
	 *
	 * @return array
	 */
	public function get() {
		global $wpdb;

		$sql     = $this->to_sql();
		$results = $wpdb->get_results( $sql, ARRAY_A );

		$models = array();
		foreach ( $results as $result ) {
			$model         = new $this->model( $result );
			$model->exists = true;
			$model->sync_original();
			$models[] = $model;
		}

		// Load eager relationships if specified
		if ( ! empty( $this->eager_load ) ) {
			$this->load_relations( $models );
		}

		return $models;
	}

	/**
	 * Execute the query and get raw results without model instantiation
	 * Useful for aggregate queries or when you need raw data
	 *
	 * @param string $output_type OBJECT, ARRAY_A, or ARRAY_N
	 * @return array
	 */
	public function get_raw( $output_type = OBJECT ) {
		global $wpdb;

		$sql = $this->to_sql();
		return $wpdb->get_results( $sql, $output_type );
	}

	/**
	 * Paginate the given query with total count
	 *
	 * @param int $per_page
	 * @param int $current_page
	 * @return Paginator
	 */
	public function paginate( $per_page = 15, $current_page = null ) {
		$current_page = $current_page ?: 1;

		// Get total count for pagination
		$total = $this->count();

		// Get the current page of results
		$this->limit( $per_page );
		$this->offset( ( $current_page - 1 ) * $per_page );
		$items = $this->get();

		return new Paginator( $items, $per_page, $current_page, $total );
	}

	/**
	 * Simple paginate the given query without total count (more efficient)
	 *
	 * @param int $per_page
	 * @param int $current_page
	 * @return Paginator
	 */
	public function simple_paginate( $per_page = 15, $current_page = null ) {
		$current_page = $current_page ?: 1;

		// Get one more item than needed to determine if there are more pages
		$this->limit( $per_page + 1 );
		$this->offset( ( $current_page - 1 ) * $per_page );
		$items = $this->get();

		// Check if there are more pages
		$has_more = count( $items ) > $per_page;

		// Remove the extra item
		if ( $has_more ) {
			$items = array_slice( $items, 0, $per_page );
		}

		return new Paginator( $items, $per_page, $current_page, null, $has_more );
	}

	/**
	 * Get the count of the total records for the query
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// Build the full query with joins and all conditions
		$table = $this->model->get_full_table_name();

		// Add table alias if provided
		if ( $this->table_alias ) {
			$sql = "SELECT COUNT(*) FROM {$table} AS {$this->table_alias}";
		} else {
			$sql = "SELECT COUNT(*) FROM {$table}";
		}

		// Add joins if any
		if ( ! empty( $this->joins ) ) {
			$sql .= ' ' . implode( ' ', $this->joins );
		}

		// Build WHERE clause
		$where_parts = array();

		// Add standard where clauses
		if ( ! empty( $this->wheres ) ) {
			$where_parts[] = $this->compile_wheres();
		}

		// Add raw where clauses
		if ( ! empty( $this->raw_wheres ) ) {
			$where_parts[] = implode( ' AND ', $this->raw_wheres );
		}

		// Combine all where clauses
		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		// Add GROUP BY
		if ( ! empty( $this->groups ) ) {
			$sql .= ' GROUP BY ' . $this->compile_groups();
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get the sum of a column for the query
	 *
	 * @param string $column The column to sum
	 * @return int|float
	 */
	public function sum( $column ) {
		global $wpdb;

		// Build the full query with joins and all conditions
		$table = $this->model->get_full_table_name();

		// Add table alias if provided
		if ( $this->table_alias ) {
			$sql = "SELECT SUM(`{$column}`) FROM {$table} AS {$this->table_alias}";
		} else {
			$sql = "SELECT SUM(`{$column}`) FROM {$table}";
		}

		// Add joins if any
		if ( ! empty( $this->joins ) ) {
			$sql .= ' ' . implode( ' ', $this->joins );
		}

		// Build WHERE clause
		$where_parts = array();

		// Add standard where clauses
		if ( ! empty( $this->wheres ) ) {
			$where_parts[] = $this->compile_wheres();
		}

		// Add raw where clauses
		if ( ! empty( $this->raw_wheres ) ) {
			$where_parts[] = implode( ' AND ', $this->raw_wheres );
		}

		// Combine all where clauses
		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		// Add GROUP BY
		if ( ! empty( $this->groups ) ) {
			$sql .= ' GROUP BY ' . $this->compile_groups();
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get the count of distinct values for a column
	 *
	 * @param string $column The column to count distinct values for (can include table alias like 's.visitor_id')
	 * @return int
	 */
	public function count_distinct( $column ) {
		global $wpdb;

		// Build the full query with joins and all conditions
		$table = $this->model->get_full_table_name();

		// Handle column name - if it contains a dot, don't add backticks around the whole thing
		if ( strpos( $column, '.' ) !== false ) {
			$column_sql = $column; // Already has table alias
		} else {
			$column_sql = "`{$column}`"; // Add backticks for safety
		}

		// Add table alias if provided
		if ( $this->table_alias ) {
			$sql = "SELECT COUNT(DISTINCT {$column_sql}) FROM {$table} AS {$this->table_alias}";
		} else {
			$sql = "SELECT COUNT(DISTINCT {$column_sql}) FROM {$table}";
		}

		// Add joins if any
		if ( ! empty( $this->joins ) ) {
			$sql .= ' ' . implode( ' ', $this->joins );
		}

		// Build WHERE clause
		$where_parts = array();

		// Add standard where clauses
		if ( ! empty( $this->wheres ) ) {
			$where_parts[] = $this->compile_wheres();
		}

		// Add raw where clauses
		if ( ! empty( $this->raw_wheres ) ) {
			$where_parts[] = implode( ' AND ', $this->raw_wheres );
		}

		// Combine all where clauses
		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		// Add GROUP BY
		if ( ! empty( $this->groups ) ) {
			$sql .= ' GROUP BY ' . $this->compile_groups();
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get an array of values from a single column
	 *
	 * @param string      $column The column to pluck values from
	 * @param string|null $key    Optional column to use as array keys
	 * @return array Array of column values
	 */
	public function pluck( $column, $key = null ) {
		global $wpdb;

		// Build the columns to select
		$columns_to_select = array( $column );
		if ( $key && $key !== $column ) {
			$columns_to_select[] = $key;
		}

		// Temporarily set the columns for this query
		$original_columns = $this->columns;
		$this->columns    = $columns_to_select;

		// Get the raw results
		$results = $this->get_raw( ARRAY_A );

		// Restore original columns
		$this->columns = $original_columns;

		// Extract the values
		$plucked = array();

		foreach ( $results as $result ) {
			$value = isset( $result[ $column ] ) ? $result[ $column ] : null;

			if ( $key && isset( $result[ $key ] ) ) {
				// Use the key column as array key
				$plucked[ $result[ $key ] ] = $value;
			} else {
				// Use numeric keys
				$plucked[] = $value;
			}
		}

		return $plucked;
	}

	/**
	 * Determine if any rows exist for the current query
	 *
	 * @return bool
	 */
	public function exists() {
		return $this->count() > 0;
	}

	/**
	 * Update records in the database
	 *
	 * @param array $values
	 * @return int
	 */
	public function update( array $values ) {
		global $wpdb;

		if ( empty( $values ) ) {
			return 0;
		}

		$table_name = $this->model->get_full_table_name();

		// Build SET clause
		$set_clauses = array();
		foreach ( $values as $column => $value ) {
			$set_clauses[] = "`{$column}` = " . $wpdb->prepare( '%s', $value );
		}
		$set_clause = implode( ', ', $set_clauses );

		$sql = "UPDATE {$table_name} SET {$set_clause}";

		if ( ! empty( $this->wheres ) ) {
			$sql .= ' WHERE ' . $this->compile_wheres();
		}

		return $wpdb->query( $sql );
	}

	/**
	 * Delete records from the database
	 *
	 * @return int
	 */
	public function delete() {
		global $wpdb;

		$table_name = $this->model->get_full_table_name();
		$sql        = "DELETE FROM {$table_name}";

		if ( ! empty( $this->wheres ) ) {
			$sql .= ' WHERE ' . $this->compile_wheres();
		}

		return $wpdb->query( $sql );
	}

	/**
	 * Build the SQL query
	 *
	 * @return string
	 */
	protected function to_sql() {
		global $wpdb;

		$table = $this->model->get_full_table_name();

		// Build columns part - use raw columns if set, otherwise use regular columns
		if ( $this->raw_columns ) {
			$columns = $this->raw_columns;
		} else {
			$columns = implode(
				', ',
				array_map(
					function ( $column ) {
						if ( $column === '*' ) {
							return $this->table_alias ? "{$this->table_alias}.*" : $column;
						}

						// If column already contains a period (table.column) or AS, leave it as is
						if ( strpos( $column, '.' ) !== false || stripos( $column, ' as ' ) !== false ) {
							return $column;
						}

						// Otherwise, add table alias if available
						return $this->table_alias ? "{$this->table_alias}.{$column}" : $column;
					},
					$this->columns
				)
			);
		}

		// Add table alias if provided
		if ( $this->table_alias ) {
			$sql = "SELECT $columns FROM {$table} AS {$this->table_alias}";
		} else {
			$sql = "SELECT $columns FROM {$table}";
		}

		// Add joins if any
		if ( ! empty( $this->joins ) ) {
			$sql .= ' ' . implode( ' ', $this->joins );
		}

		// Build WHERE clause
		$where_parts = array();

		// Add standard where clauses
		if ( ! empty( $this->wheres ) ) {
			$where_parts[] = $this->compile_wheres();
		}

		// Add raw where clauses
		if ( ! empty( $this->raw_wheres ) ) {
			$where_parts[] = implode( ' AND ', $this->raw_wheres );
		}

		// Combine all where clauses
		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		// Add GROUP BY
		if ( ! empty( $this->groups ) ) {
			$sql .= ' GROUP BY ' . $this->compile_groups();
		}

		// Add ORDER BY
		if ( ! empty( $this->orders ) ) {
			$sql .= ' ORDER BY ' . $this->compile_orders();
		}

		// Add LIMIT and OFFSET
		if ( $this->limit ) {
			$sql .= ' LIMIT ' . (int) $this->limit;
		}

		if ( $this->offset ) {
			$sql .= ' OFFSET ' . (int) $this->offset;
		}

		return $sql;
	}

	/**
	 * Compile the "where" portions of the query
	 *
	 * @return string
	 */
	protected function compile_wheres() {
		global $wpdb;

		$clauses = array();

		foreach ( $this->wheres as $i => $where ) {
			$boolean = $i === 0 ? '' : strtoupper( $where['boolean'] ) . ' ';

			switch ( $where['type'] ) {
				case 'basic':
					// Fix: Use proper SQL syntax for the operator
					if ( $where['operator'] === 'like' ) {
						// Special handling for LIKE operator
						$clauses[] = $boolean . $wpdb->prepare(
							"`{$where['column']}` LIKE %s",
							$where['value']
						);
					} else {
						$operator = $where['operator'];
						$value    = $where['value'];

						// Handle different value types for proper preparation
						if ( is_int( $value ) ) {
							$clauses[] = $boolean . $wpdb->prepare(
								"`{$where['column']}` {$operator} %d",
								$value
							);
						} elseif ( is_float( $value ) ) {
							$clauses[] = $boolean . $wpdb->prepare(
								"`{$where['column']}` {$operator} %f",
								$value
							);
						} else {
							$clauses[] = $boolean . $wpdb->prepare(
								"`{$where['column']}` {$operator} %s",
								$value
							);
						}
					}
					break;

				case 'in':
					$placeholders = implode( ',', array_fill( 0, count( $where['values'] ), '%s' ) );
					$clauses[]    = $boolean . $wpdb->prepare(
						"`{$where['column']}` IN ({$placeholders})",
						$where['values']
					);
					break;

				case 'not_in':
					$placeholders = implode( ',', array_fill( 0, count( $where['values'] ), '%s' ) );
					$clauses[]    = $boolean . $wpdb->prepare(
						"`{$where['column']}` NOT IN ({$placeholders})",
						$where['values']
					);
					break;

				case 'null':
					$clauses[] = $boolean . "`{$where['column']}` IS NULL";
					break;

				case 'not_null':
					$clauses[] = $boolean . "`{$where['column']}` IS NOT NULL";
					break;
			}
		}

		return implode( ' ', $clauses );
	}

	/**
	 * Add a raw where clause to the query
	 *
	 * @param string $sql The raw SQL where clause
	 * @return $this
	 */
	public function where_raw( $sql ) {
		$this->raw_wheres[] = $sql;
		return $this;
	}

	/**
	 * Add an inner join to the query
	 *
	 * @param string $table      The table to join
	 * @param string $first      The first column for the join condition
	 * @param string $operator   The join operator (=, !=, etc.)
	 * @param string $second     The second column for the join condition
	 * @return $this
	 */
	public function join( $table, $first, $operator = null, $second = null ) {
		// Handle case where operator is omitted (defaults to '=')
		if ( func_num_args() === 3 ) {
			$second   = $operator;
			$operator = '=';
		}

		global $wpdb;

		// Add table prefix if needed
		if ( strpos( $table, $wpdb->prefix ) !== 0 && strpos( $table, ' ' ) === false ) {
			$table = $wpdb->prefix . $table;
		}

		$this->joins[] = "INNER JOIN {$table} ON {$first} {$operator} {$second}";
		return $this;
	}

	/**
	 * Add a left join to the query
	 *
	 * @param string $table      The table to join
	 * @param string $first      The first column for the join condition
	 * @param string $operator   The join operator (=, !=, etc.)
	 * @param string $second     The second column for the join condition
	 * @return $this
	 */
	public function left_join( $table, $first, $operator = null, $second = null ) {
		// Handle case where operator is omitted (defaults to '=')
		if ( func_num_args() === 3 ) {
			$second   = $operator;
			$operator = '=';
		}

		global $wpdb;

		// Add table prefix if needed
		if ( strpos( $table, $wpdb->prefix ) !== 0 && strpos( $table, ' ' ) === false ) {
			$table = $wpdb->prefix . $table;
		}

		$this->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
		return $this;
	}

	/**
	 * Add a right join to the query
	 *
	 * @param string $table      The table to join
	 * @param string $first      The first column for the join condition
	 * @param string $operator   The join operator (=, !=, etc.)
	 * @param string $second     The second column for the join condition
	 * @return $this
	 */
	public function right_join( $table, $first, $operator = null, $second = null ) {
		// Handle case where operator is omitted (defaults to '=')
		if ( func_num_args() === 3 ) {
			$second   = $operator;
			$operator = '=';
		}

		global $wpdb;

		// Add table prefix if needed
		if ( strpos( $table, $wpdb->prefix ) !== 0 && strpos( $table, ' ' ) === false ) {
			$table = $wpdb->prefix . $table;
		}

		$this->joins[] = "RIGHT JOIN {$table} ON {$first} {$operator} {$second}";
		return $this;
	}

	/**
	 * Add a raw join clause to the query
	 *
	 * @param string $sql The raw SQL join clause
	 * @return $this
	 */
	public function join_raw( $sql ) {
		$this->joins[] = $sql;
		return $this;
	}

	/**
	 * Compile the "order by" portions of the query
	 *
	 * @return string
	 */
	protected function compile_orders() {
		$clauses = array();

		foreach ( $this->orders as $order ) {
			if ( isset( $order['raw'] ) ) {
				// Raw order by clause
				$clauses[] = $order['raw'];
			} else {
				// Regular order by clause
				$clauses[] = "`{$order['column']}` " . strtoupper( $order['direction'] );
			}
		}

		return implode( ', ', $clauses );
	}

	/**
	 * Compile the "group by" portions of the query
	 *
	 * @return string
	 */
	protected function compile_groups() {
		$clauses = array();

		foreach ( $this->groups as $column ) {
			// If column already contains a period (table.column), don't add backticks
			if ( strpos( $column, '.' ) !== false ) {
				$clauses[] = $column;
			} else {
				// Otherwise, add backticks
				$clauses[] = "`{$column}`";
			}
		}

		return implode( ', ', $clauses );
	}

	/**
	 * Add a "group by" clause to the query
	 *
	 * @param string|array $columns
	 * @return $this
	 */
	public function group_by( $columns ) {
		if ( is_array( $columns ) ) {
			$this->groups = array_merge( $this->groups, $columns );
		} else {
			$this->groups[] = $columns;
		}

		return $this;
	}

	/**
	 * Load eager relationships for a collection of models
	 *
	 * @param array $models
	 * @return void
	 */
	protected function load_relations( array $models ) {
		// Skip if no models
		if ( empty( $models ) ) {
			return;
		}

		// Process standard eager loading
		foreach ( $this->eager_load as $relation ) {
			// Skip if relation is not a string (should never happen with our fixes)
			if ( ! is_string( $relation ) ) {
				continue;
			}

			// Check if the relation method exists on the model
			if ( ! method_exists( $models[0], $relation ) ) {
				continue;
			}

			// Load the relation for each model
			foreach ( $models as $model ) {
				// Get the relation instance
				$relation_method = $relation;
				$relation_obj    = $model->$relation_method();

				// Apply column selection if specified
				if ( isset( $this->with_relations[ $relation ]['columns'] ) ) {
					$relation_obj->select( $this->with_relations[ $relation ]['columns'] );
				}

				// Get the related model(s)
				$related = $relation_obj->get();

				// Check if there's a mapping function in with_relations
				if ( isset( $this->with_relations[ $relation ]['map'] ) && is_callable( $this->with_relations[ $relation ]['map'] ) ) {
					$related = call_user_func( $this->with_relations[ $relation ]['map'], $related );
				}

				// Set the relation on the model
				$model->set_relation( $relation, $related );
			}
		}
	}
}
