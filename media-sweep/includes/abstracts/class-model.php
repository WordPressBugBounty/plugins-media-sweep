<?php
/**
 * Enhanced Model class with Eloquent-style relationships
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abstracts;

use Media_Sweep\Abstracts\Table;
use Media_Sweep\Utils\Query_Builder;
use Media_Sweep\Utils\Paginator;
use Media_Sweep\Utils\Relations\Belongs_To;
use Media_Sweep\Utils\Relations\Has_One;
use Media_Sweep\Utils\Relations\Has_Many;
use Media_Sweep\Utils\Relations\Belongs_To_Many;
use Media_Sweep\Utils\Validator;
use WP_Error;

/**
 * Abstract Model class providing Eloquent-like functionality
 */
abstract class Model implements \JsonSerializable {

	/**
	 * The table instance
	 *
	 * @var Table|null
	 */
	protected $table;

	/**
	 * The table name (if using direct table name instead of Table class)
	 *
	 * @var string|null
	 */
	protected $table_name;

	/**
	 * Whether to add WordPress prefix to table name
	 *
	 * @var bool
	 */
	protected $use_prefix = true;

	/**
	 * The model's attributes
	 *
	 * @var array
	 */
	protected $attributes = array();

	/**
	 * The model's original attributes
	 *
	 * @var array
	 */
	protected $original = array();

	/**
	 * Indicates if the model exists in database
	 *
	 * @var bool
	 */
	public $exists = false;

	/**
	 * The primary key for the model
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * The attributes that are mass assignable
	 *
	 * @var array
	 */
	protected $fillable = array();

	/**
	 * The attributes that aren't mass assignable
	 *
	 * @var array
	 */
	protected $guarded = array( 'id' );

	/**
	 * The attributes that should be hidden for arrays
	 *
	 * @var array
	 */
	protected $hidden = array();

	/**
	 * The attributes that should be visible in arrays (takes precedence over $hidden)
	 * If empty, all attributes except $hidden ones will be visible
	 *
	 * @var array
	 */
	protected $visible = array();

	/**
	 * The attributes that should be cast to native types
	 *
	 * @var array
	 */
	protected $casts = array();

	/**
	 * The accessors to append to the model's array form
	 *
	 * @var array
	 */
	protected $appends = array();

	/**
	 * Indicates if the model should be timestamped
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Indicates if the model has an updated at column
	 *
	 * @var bool
	 */
	public $update_timestamp = true;

	/**
	 * Validation rules for creating
	 *
	 * @var array
	 */
	public $rules = array();

	/**
	 * Validation rules for updating (optional - falls back to $rules)
	 *
	 * @var array
	 */
	protected $update_rules = array();

	/**
	 * Custom validation messages
	 *
	 * @var array
	 */
	protected $messages = array();

	/**
	 * Custom attribute names for validation
	 *
	 * @var array
	 */
	protected $custom_attributes = array();

	/**
	 * Whether to automatically validate on save
	 *
	 * @var bool
	 */
	protected $auto_validate = true;

	/**
	 * Validation errors from last validation attempt
	 *
	 * @var array
	 */
	protected $validation_errors = array();

	/**
	 * Loaded relationships
	 *
	 * @var array
	 */
	protected $relations = array();

	/**
	 * The name of the "created at" column
	 *
	 * @var string
	 */
	const CREATED_AT = 'created_at';

	/**
	 * The name of the "updated at" column
	 *
	 * @var string
	 */
	const UPDATED_AT = 'updated_at';

	/**
	 * Constructor
	 *
	 * @param array $attributes Initial attributes
	 */
	public function __construct( $attributes = array() ) {
		// Set up table - either Table class or direct table name
		if ( $this->table_name ) {
			$this->table = null; // Using direct table name
		} else {
			$this->table = $this->get_table_instance();
		}

		$this->fill( $attributes );
		$this->sync_original();
	}


	/**
	 * Validate the model
	 *
	 * @param array|null $rules Optional custom rules
	 * @return bool
	 */
	public function validate( $rules = null ) {
		// Get validation rules
		if ( $rules === null ) {
			if ( $this->exists && ! empty( $this->update_rules ) ) {
				$rules = $this->update_rules;
			} else {
				$rules = $this->rules;
			}
		}

		// No rules means validation passes
		if ( empty( $rules ) ) {
			$this->validation_errors = array();
			return true;
		}

		// Prepare rules for updates (replace :id with actual ID)
		if ( $this->exists ) {
			$rules = $this->prepare_update_rules( $rules );
		}

		// Create validator
		$validator = Validator::make(
			$this->attributes,
			$rules,
			$this->messages,
			$this->custom_attributes
		);

		// Store validation result
		if ( $validator->passes() ) {
			$this->validation_errors = array();
			return true;
		} else {
			$this->validation_errors = $validator->errors();
			return false;
		}
	}

	/**
	 * Validate or fail
	 *
	 * @param array|null $rules
	 * @throws \Exception
	 */
	public function validate_or_fail( $rules = null ) {
		if ( ! $this->validate( $rules ) ) {
			$validation_rules = $rules;
			if ( $validation_rules === null ) {
				$validation_rules = ( $this->exists && ! empty( $this->update_rules ) ) ? $this->update_rules : $this->rules;
			}

			$validator = Validator::make(
				$this->attributes,
				$validation_rules,
				$this->messages,
				$this->custom_attributes
			);

			if ( $validator->fails() ) {
				throw new \Exception(
					$validator->first(),
				);
			}
		}
	}

	/**
	 * Check if model is valid
	 *
	 * @return bool
	 */
	public function is_valid() {
		return $this->validate();
	}

	/**
	 * Check if model is invalid
	 *
	 * @return bool
	 */
	public function is_invalid() {
		return ! $this->is_valid();
	}

	/**
	 * Get validation errors
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->validation_errors;
	}

	/**
	 * Get first validation error
	 *
	 * @param string|null $field
	 * @return string|null
	 */
	public function get_first_error( $field = null ) {
		if ( $field && isset( $this->validation_errors[ $field ] ) ) {
			return $this->validation_errors[ $field ][0];
		}

		foreach ( $this->validation_errors as $field_errors ) {
			return $field_errors[0];
		}

		return null;
	}

	/**
	 * Prepare update rules by replacing :id placeholder
	 *
	 * @param array $rules
	 * @return array
	 */
	protected function prepare_update_rules( $rules ) {
		$prepared = array();
		$model_id = $this->get_key();

		foreach ( $rules as $field => $rule_set ) {
			if ( is_string( $rule_set ) ) {
				$rule_set = str_replace( ':id', $model_id, $rule_set );
			} elseif ( is_array( $rule_set ) ) {
				$rule_set = array_map(
					function ( $rule ) use ( $model_id ) {
						return str_replace( ':id', $model_id, $rule );
					},
					$rule_set
				);
			}
			$prepared[ $field ] = $rule_set;
		}

		return $prepared;
	}

	/**
	 * Override save to include validation
	 *
	 * @param bool $validate Whether to validate before saving
	 * @return static|false Returns the model instance on success, false on failure
	 * @throws \Exception
	 */
	public function save( $validate = null ) {
		$should_validate = $validate !== null ? $validate : $this->auto_validate;

		if ( $should_validate && ! empty( $this->rules ) ) {
			$this->validate_or_fail();
		}

		// Call original save logic
		global $wpdb;

		if ( $this->timestamps ) {
			$this->update_timestamps();
		}

		$table_name = $this->get_full_table_name();

		if ( $this->exists ) {
			$data   = $this->get_dirty_attributes();
			$where  = array( $this->primary_key => $this->get_key() );
			$result = $wpdb->update( $table_name, $data, $where );

			if ( $result === false ) {
				// Get WordPress database error
				$error_message = $wpdb->last_error ?: 'Failed to update record in database';
				throw new \Exception( $error_message );
			}
		} else {
			$data   = $this->prepare_attributes_for_storage( $this->attributes );
			$result = $wpdb->insert( $table_name, $data );

			if ( $result === false ) {
				// Get WordPress database error
				$error_message = $wpdb->last_error ?: 'Failed to insert record into database';
				throw new \Exception( $error_message );
			}

			if ( ! $this->get_key() ) {
				$this->set_attribute( $this->primary_key, $wpdb->insert_id );
			}

			$this->exists = true;
		}

		$this->sync_original();
		return $this;
	}

	/**
	 * Save without validation
	 *
	 * @return static|false Returns the model instance on success, false on failure
	 */
	public function save_without_validation() {
		return $this->save( false );
	}

	/**
	 * Create model with validation
	 *
	 * @param array $attributes
	 * @return static
	 * @throws \Exception
	 */
	public static function create( $attributes = array() ) {
		// Remove primary key if it's set in the attributes
		$instance = new static();
		if ( isset( $attributes[ $instance->primary_key ] ) ) {
			unset( $attributes[ $instance->primary_key ] );
		}

		$model = new static( $attributes );
		$model->save(); // Will auto-validate
		return $model;
	}

	/**
	 * Create model without validation
	 *
	 * @param array $attributes
	 * @return static
	 */
	public static function create_without_validation( $attributes = array() ) {
		// Remove primary key if it's set in the attributes
		$instance = new static();
		if ( isset( $attributes[ $instance->primary_key ] ) ) {
			unset( $attributes[ $instance->primary_key ] );
		}

		$model = new static( $attributes );
		$model->save( false );
		return $model;
	}

	/**
	 * Validate given data against model rules
	 *
	 * @param array      $data
	 * @param array|null $rules
	 * @return Validator
	 */
	public static function validator( $data, $rules = null ) {
		$instance = new static();

		if ( $rules === null ) {
			$rules = $instance->rules;
		}

		return Validator::make(
			$data,
			$rules,
			$instance->messages,
			$instance->custom_attributes
		);
	}

	/**
	 * Get the table instance for this model (optional - can use table_name instead)
	 *
	 * @return Table|null
	 */
	protected function get_table_instance() {
		return null;
	}

	/**
	 * Get the full table name with or without prefix
	 *
	 * @return string
	 */
	public function get_full_table_name() {
		if ( $this->table ) {
			// Using Table class
			return $this->table->get_full_table_name();
		}

		// Using direct table name
		if ( $this->use_prefix ) {
			global $wpdb;
			return $wpdb->prefix . $this->table_name;
		}

		return $this->table_name;
	}

	/**
	 * Get the raw table name without prefix
	 *
	 * @return string
	 */
	public function get_table_name() {
		if ( $this->table ) {
			return $this->table->get_table_name();
		}

		return $this->table_name;
	}

	/**
	 * Define a belongs to relationship (One-to-One inverse)
	 *
	 * @param string      $related
	 * @param string|null $foreign_key
	 * @param string      $owner_key
	 * @return Belongs_To
	 */
	protected function belongs_to( $related, $foreign_key = null, $owner_key = 'id' ) {
		return new Belongs_To( $this, $related, $foreign_key, $owner_key );
	}

	/**
	 * Define a has one relationship
	 *
	 * @param string      $related
	 * @param string|null $foreign_key
	 * @param string      $local_key
	 * @return Has_One
	 */
	protected function has_one( $related, $foreign_key = null, $local_key = 'id' ) {
		return new Has_One( $this, $related, $foreign_key, $local_key );
	}

	/**
	 * Define a has many relationship
	 *
	 * @param string      $related
	 * @param string|null $foreign_key
	 * @param string      $local_key
	 * @return Has_Many
	 */
	protected function has_many( $related, $foreign_key = null, $local_key = 'id' ) {
		return new Has_Many( $this, $related, $foreign_key, $local_key );
	}

	/**
	 * Define a belongs to many relationship (Many-to-Many)
	 *
	 * @param string      $related
	 * @param string|null $pivot_table
	 * @param string|null $foreign_pivot_key
	 * @param string|null $related_pivot_key
	 * @param string      $parent_key
	 * @param string      $related_key
	 * @return Belongs_To_Many
	 */
	protected function belongs_to_many( $related, $pivot_table = null, $foreign_pivot_key = null, $related_pivot_key = null, $parent_key = 'id', $related_key = 'id' ) {
		return new Belongs_To_Many( $this, $related, $pivot_table, $foreign_pivot_key, $related_pivot_key, $parent_key, $related_key );
	}

	/**
	 * Fill the model with an array of attributes
	 *
	 * @param array $attributes
	 * @return static
	 */
	public function fill( $attributes ) {
		// Handle primary key separately if present
		if ( isset( $attributes[ $this->primary_key ] ) ) {
			$this->attributes[ $this->primary_key ] = $attributes[ $this->primary_key ];
		}

		foreach ( $attributes as $key => $value ) {
			// Skip the primary key as we've already handled it
			if ( $key === $this->primary_key ) {
				continue;
			}

			if ( $this->is_fillable( $key ) ) {
				$this->set_attribute( $key, $value );
			}
		}
		return $this;
	}

	/**
	 * Check if an attribute is fillable
	 *
	 * @param string $key
	 * @return bool
	 */
	protected function is_fillable( $key ) {
		// Automatically allow timestamp fields if timestamps are enabled
		if ( $this->timestamps && ( $key === static::CREATED_AT || $key === static::UPDATED_AT ) ) {
			return true;
		}

		// First check if it's in the guarded array
		if ( in_array( $key, $this->guarded, true ) ) {
			return false;
		}

		// Then check if it's in the fillable array (if specified)
		if ( ! empty( $this->fillable ) ) {
			return in_array( $key, $this->fillable, true );
		}

		// If fillable is empty and not in guarded, it's fillable
		return true;
	}

	/**
	 * Set a given attribute on the model
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return static
	 */
	public function set_attribute( $key, $value ) {
		$this->attributes[ $key ] = $value;
		return $this;
	}

	/**
	 * Get an attribute from the model
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get_attribute( $key, $default = null ) {
		// Check if it's a relationship method
		if ( method_exists( $this, $key ) ) {
			$relation = $this->$key();
			if ( $relation instanceof Belongs_To || $relation instanceof Has_One ) {
				return $relation->get();
			}
			if ( $relation instanceof Has_Many || $relation instanceof Belongs_To_Many ) {
				return $relation->get();
			}
		}

		// Regular attribute
		if ( array_key_exists( $key, $this->attributes ) ) {
			return $this->cast_attribute( $key, $this->attributes[ $key ] );
		}

		// Check for accessor method
		$accessor_value = $this->get_accessor_attribute( $key );
		if ( $accessor_value !== null ) {
			return $accessor_value;
		}

		return $default;
	}

	/**
	 * Cast an attribute to a native PHP type
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return mixed
	 */
	protected function cast_attribute( $key, $value ) {
		// Automatically cast timestamp fields as strings if timestamps are enabled and no explicit cast is set
		if ( $this->timestamps && ( $key === static::CREATED_AT || $key === static::UPDATED_AT ) && ! isset( $this->casts[ $key ] ) ) {
			return (string) $value;
		}

		if ( ! isset( $this->casts[ $key ] ) ) {
			return $value;
		}

		$cast_type = $this->casts[ $key ];

		if ( is_null( $value ) ) {
			return $value;
		}

		switch ( $cast_type ) {
			case 'int':
			case 'integer':
				return (int) $value;
			case 'real':
			case 'float':
			case 'double':
				return (float) $value;
			case 'string':
				return (string) $value;
			case 'bool':
			case 'boolean':
				return (bool) $value;
			case 'array':
				$unserialized = maybe_unserialize( $value );
				return is_array( $unserialized ) ? $unserialized : array();
			case 'json':
				return $value ? json_decode( json_encode( $value ), true ) : array();
			case 'datetime':
				$value = $value ? new \DateTime( $value ) : null;
				return $value->format( 'Y-m-d H:i:s' );
			default:
				return $value;
		}
	}

	/**
	 * Get an accessor attribute value
	 *
	 * @param string $key
	 * @return mixed
	 */
	protected function get_accessor_attribute( $key ) {
		$method = 'get' . $this->studly_case( $key ) . 'Attribute';

		if ( method_exists( $this, $method ) ) {
			return $this->{$method}();
		}

		return null;
	}

	/**
	 * Convert a string to studly case (PascalCase)
	 *
	 * @param string $value
	 * @return string
	 */
	protected function studly_case( $value ) {
		$value = str_replace( array( '-', '_' ), ' ', $value );
		$value = ucwords( $value );
		return str_replace( ' ', '', $value );
	}

	/**
	 * Magic getter for attributes
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __get( $key ) {
		return $this->get_attribute( $key );
	}

	/**
	 * Magic setter for attributes
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public function __set( $key, $value ) {
		$this->set_attribute( $key, $value );
	}

	/**
	 * Magic isset for attributes
	 *
	 * @param string $key
	 * @return bool
	 */
	public function __isset( $key ) {
		return array_key_exists( $key, $this->attributes );
	}

	/**
	 * Delete the model from the database
	 *
	 * @param bool $throw_on_error Whether to throw exception on database error
	 * @return bool|null Returns true on successful deletion, false on failure, null if model doesn't exist
	 * @throws \Exception
	 */
	public function delete( $throw_on_error = false ) {
		if ( ! $this->exists ) {
			return null;
		}

		global $wpdb;
		$table_name = $this->get_full_table_name();
		$where      = array( $this->primary_key => $this->get_key() );

		$result = $wpdb->delete( $table_name, $where );

		if ( $result !== false ) {
			$this->exists = false;
			return true;
		}

		if ( $throw_on_error ) {
			$error_message = $wpdb->last_error ?: 'Failed to delete record from database';
			throw new \Exception( $error_message );
		}

		return false;
	}

	/**
	 * Delete the model from the database or fail with exception
	 *
	 * @return bool|null
	 * @throws \Exception
	 */
	public function delete_or_fail() {
		return $this->delete( true );
	}

	/**
	 * Get the primary key value
	 *
	 * @return mixed
	 */
	public function get_key() {
		return $this->get_attribute( $this->primary_key );
	}

	/**
	 * Get only the dirty attributes (changed since last sync)
	 *
	 * @return array
	 */
	protected function get_dirty_attributes() {
		$dirty            = array();
		$current_prepared = $this->prepare_attributes_for_storage( $this->attributes );

		foreach ( $current_prepared as $key => $value ) {
			if ( ! array_key_exists( $key, $this->original ) || $this->original[ $key ] !== $value ) {
				$dirty[ $key ] = $value;
			}
		}

		return $dirty;
	}

	/**
	 * Prepare attributes for database storage (handle serialization)
	 *
	 * @param array $attributes
	 * @return array
	 */
	protected function prepare_attributes_for_storage( $attributes ) {
		$prepared = array();

		foreach ( $attributes as $key => $value ) {
			// Check if this attribute should be serialized for storage
			if ( isset( $this->casts[ $key ] ) && $this->casts[ $key ] === 'array' && is_array( $value ) ) {
				// Use WordPress maybe_serialize for storing array data
				$prepared[ $key ] = maybe_serialize( $value );
			} else {
				$prepared[ $key ] = $value;
			}
		}

		return $prepared;
	}

	/**
	 * Sync the original attributes with the current
	 *
	 * @return static
	 */
	public function sync_original() {
		// Store serialized version for comparison in dirty checking
		$this->original = $this->prepare_attributes_for_storage( $this->attributes );
		return $this;
	}

	/**
	 * Update the model in the database
	 *
	 * @param array $attributes
	 * @param array $options
	 * @return static|false Returns the updated model instance on success, false on failure
	 */
	public function update( $attributes = array(), $options = array() ) {
		if ( ! $this->exists ) {
			return false;
		}

		$this->fill( $attributes );

		$validate = isset( $options['validate'] ) ? $options['validate'] : null;
		return $this->save( $validate );
	}

	/**
	 * Create or update a model matching the attributes, and fill it with values
	 *
	 * @param array $attributes
	 * @param array $values
	 * @return static
	 */
	public static function update_or_create( $attributes, $values = array() ) {
		$instance = static::first_or_new( $attributes );

		$instance->fill( array_merge( $attributes, $values ) );
		$instance->save();

		return $instance;
	}

	/**
	 * Get the first record matching the attributes or instantiate it
	 *
	 * @param array $attributes
	 * @param array $values
	 * @return static
	 */
	public static function first_or_new( $attributes, $values = array() ) {
		$query = static::query();

		foreach ( $attributes as $key => $value ) {
			$query->where( $key, $value );
		}

		$instance = $query->first();

		if ( $instance === null ) {
			$instance = new static( array_merge( $attributes, $values ) );
		}

		return $instance;
	}

	/**
	 * Get the first record matching the attributes or create it
	 *
	 * @param array $attributes
	 * @param array $values
	 * @return static
	 */
	public static function first_or_create( $attributes, $values = array() ) {
		$instance = static::first_or_new( $attributes, $values );

		if ( ! $instance->exists ) {
			$instance->save();
		}

		return $instance;
	}

	/**
	 * Refresh the model from the database
	 *
	 * @return static|null
	 */
	public function refresh() {
		if ( ! $this->exists ) {
			return null;
		}

		$fresh_model = static::find( $this->get_key() );

		if ( $fresh_model ) {
			$this->attributes = $fresh_model->attributes;
			$this->original   = $fresh_model->original;
			$this->exists     = $fresh_model->exists;
		}

		return $this;
	}

	/**
	 * Get a fresh model instance from the database
	 *
	 * @return static|null
	 */
	public function fresh() {
		if ( ! $this->exists ) {
			return null;
		}

		return static::find( $this->get_key() );
	}

	/**
	 * Update the creation and update timestamps
	 */
	protected function update_timestamps() {
		$time = current_time( 'mysql' );

		if ( ! $this->exists && static::CREATED_AT ) {
			$this->set_attribute( static::CREATED_AT, $time );
		}

		if ( static::UPDATED_AT && $this->update_timestamp ) {
			$this->set_attribute( static::UPDATED_AT, $time );
		}
	}

	/**
	 * Find a model by its primary key
	 *
	 * @param mixed $id
	 * @return static|null
	 */
	public static function find( $id ) {
		$instance = new static();
		global $wpdb;

		$table_name  = $instance->get_full_table_name();
		$primary_key = $instance->primary_key;

		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE {$primary_key} = %s", $id ),
			ARRAY_A
		);

		if ( $result ) {
			$instance         = new static( $result );
			$instance->exists = true;
			return $instance;
		}

		return null;
	}

	/**
	 * Load relationships after model retrieval
	 *
	 * @param array|string $relations
	 * @return $this
	 */
	public function load( $relations ) {
		// Handle simple array of relation names or string arguments
		if ( is_string( $relations ) || ( is_array( $relations ) && isset( $relations[0] ) && is_string( $relations[0] ) ) ) {
			$relations = is_array( $relations ) ? $relations : func_get_args();

			foreach ( $relations as $relation ) {
				$this->load_relation( $relation );
			}

			return $this;
		}

		// Handle associative array with configuration
		if ( is_array( $relations ) ) {
			foreach ( $relations as $relation => $config ) {
				if ( is_array( $config ) ) {
					$columns      = isset( $config['columns'] ) ? $config['columns'] : array( '*' );
					$map_function = isset( $config['map'] ) ? $config['map'] : null;

					$this->load_relation( $relation, $columns, $map_function );
				} else {
					// If config is not an array, treat it as a simple relation name
					$this->load_relation( $config );
				}
			}
		}

		return $this;
	}

	/**
	 * Load a specific relationship with column selection
	 *
	 * @param string        $relation
	 * @param array         $columns
	 * @param callable|null $map_function Optional function to transform the relation results
	 * @return $this
	 */
	public function load_relation( $relation, $columns = array( '*' ), $map_function = null ) {
		if ( method_exists( $this, $relation ) ) {
			$relation_obj = $this->$relation();

			if ( $columns !== array( '*' ) ) {
				$relation_obj->select( $columns );
			}

			$related = $relation_obj->get();

			// Apply mapping function if provided
			if ( $map_function !== null && is_callable( $map_function ) ) {
				$related = call_user_func( $map_function, $related );
			}

			$this->set_relation( $relation, $related );
		}

		return $this;
	}

	/**
	 * Find a model by its primary key or throw an exception
	 *
	 * @param mixed $id
	 * @return static
	 * @throws \Exception
	 */
	public static function find_or_fail( $id ) {
		$model = static::find( $id );

		if ( ! $model ) {
			throw new \Exception( 'Model not found' );
		}

		return $model;
	}

	/**
	 * Get all models
	 *
	 * @param array|null $relations Optional relations to eager load
	 * @return array
	 */
	public static function all( $relations = null ) {
		$instance = new static();
		global $wpdb;

		$table_name = $instance->get_full_table_name();
		$results    = $wpdb->get_results( "SELECT * FROM {$table_name}", ARRAY_A );

		$models = array();
		foreach ( $results as $result ) {
			$models[] = new static( $result );
		}

		// Load relations if specified
		if ( $relations ) {
			foreach ( $models as $model ) {
				$model->load( $relations );
			}
		}

		return $models;
	}

	/**
	 * Begin querying the model
	 *
	 * @return Query_Builder
	 */
	public static function query() {
		$instance = new static();
		return new Query_Builder( $instance );
	}

	/**
	 * Begin querying a model with eager loading
	 *
	 * @param array|string $relations
	 * @return Query_Builder
	 */
	public static function with( $relations ) {
		return static::query()->with( $relations );
	}

	/**
	 * Begin querying a model with eager loading and map function
	 *
	 * @param string   $relation Relation name
	 * @param callable $map_function Function to transform the relation results
	 * @return Query_Builder
	 */
	public static function with_map( $relation, $map_function ) {
		return static::query()->with(
			array(
				'relation' => $relation,
				'map'      => $map_function,
			)
		);
	}

	/**
	 * Find models matching the query constraints
	 *
	 * @param string $column
	 * @param mixed  $operator
	 * @param mixed  $value
	 * @return Query_Builder
	 */
	public static function where( $column, $operator = null, $value = null ) {
		return static::query()->where( $column, $operator, $value );
	}

	/**
	 * Find models where column is in array of values
	 *
	 * @param string $column
	 * @param array  $values
	 * @return Query_Builder
	 */
	public static function where_in( $column, $values ) {
		return static::query()->where_in( $column, $values );
	}

	/**
	 * Paginate models (with total count)
	 *
	 * @param int $per_page
	 * @param int $current_page
	 * @return Paginator
	 */
	public static function paginate( $per_page = 15, $current_page = null ) {
		return static::query()->paginate( $per_page, $current_page );
	}

	/**
	 * Simple paginate models (without total count - more efficient)
	 *
	 * @param int $per_page
	 * @param int $current_page
	 * @return Paginator
	 */
	public static function simple_paginate( $per_page = 15, $current_page = null ) {
		return static::query()->simple_paginate( $per_page, $current_page );
	}

	/**
	 * Get the total count of models
	 *
	 * @return int
	 */
	public static function count() {
		return static::query()->count();
	}

	/**
	 * Set a loaded relationship
	 *
	 * @param string $relation
	 * @param mixed  $value
	 * @return $this
	 */
	public function set_relation( $relation, $value ) {
		$this->relations[ $relation ] = $value;
		return $this;
	}

	/**
	 * Get a loaded relationship
	 *
	 * @param string $relation
	 * @return mixed|null
	 */
	public function get_relation( $relation ) {
		return isset( $this->relations[ $relation ] ) ? $this->relations[ $relation ] : null;
	}

	/**
	 * Convert the model instance to an array
	 *
	 * @return array
	 */
	public function to_array() {
		$attributes = array();

		// If $visible is specified, only include those attributes
		if ( ! empty( $this->visible ) ) {
			foreach ( $this->visible as $key ) {
				if ( array_key_exists( $key, $this->attributes ) ) {
					$attributes[ $key ] = $this->cast_attribute( $key, $this->attributes[ $key ] );
				}
			}
		} else {
			// Otherwise include all except hidden
			foreach ( $this->attributes as $key => $value ) {
				if ( ! in_array( $key, $this->hidden, true ) ) {
					$attributes[ $key ] = $this->cast_attribute( $key, $value );
				}
			}
		}

		// Add loaded relationships
		foreach ( $this->relations as $relation => $value ) {
			if ( is_object( $value ) && method_exists( $value, 'to_array' ) ) {
				$attributes[ $relation ] = $value->to_array();
			} elseif ( is_array( $value ) ) {
				$attributes[ $relation ] = array_map(
					function ( $item ) {
						return is_object( $item ) && method_exists( $item, 'to_array' ) ? $item->to_array() : $item;
					},
					$value
				);
			} else {
				$attributes[ $relation ] = $value;
			}
		}

		// Add appended attributes
		foreach ( $this->appends as $append ) {
			if ( ! in_array( $append, $this->hidden, true ) ) {
				$attributes[ $append ] = $this->get_accessor_attribute( $append );
			}
		}

		return $attributes;
	}

	/**
	 * Convert the model instance to JSON
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode( $this->to_array() );
	}

	/**
	 * Specify data which should be serialized to JSON
	 *
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->to_array();
	}

	/**
	 * Determine if the model or any of the given attribute(s) have been modified
	 *
	 * @param array|string|null $attributes
	 * @return bool
	 */
	public function is_dirty( $attributes = null ) {
		return $this->has_changes( $attributes );
	}

	/**
	 * Determine if the model or any of the given attribute(s) have been modified
	 *
	 * @param array|string|null $attributes
	 * @return bool
	 */
	public function has_changes( $attributes = null ) {
		if ( is_null( $attributes ) ) {
			return ! empty( $this->get_dirty_attributes() );
		}

		$attributes = is_array( $attributes ) ? $attributes : func_get_args();
		$dirty      = $this->get_dirty_attributes();

		foreach ( $attributes as $attribute ) {
			if ( array_key_exists( $attribute, $dirty ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Set the attributes that should be visible in arrays
	 *
	 * @param array $attributes
	 * @return $this
	 */
	public function only( $attributes ) {
		if ( is_string( $attributes ) ) {
			$attributes = func_get_args();
		}

		$this->visible = $attributes;
		return $this;
	}

	/**
	 * Set the attributes that should be hidden in arrays
	 *
	 * @param array $attributes
	 * @return $this
	 */
	public function except( $attributes ) {
		if ( is_string( $attributes ) ) {
			$attributes = func_get_args();
		}

		$this->hidden  = $attributes;
		$this->visible = array();
		return $this;
	}
}
