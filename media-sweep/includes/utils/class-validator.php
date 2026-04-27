<?php
/**
 * WordPress-style Validator System with sprintf translations
 *
 * @package media-sweep
 */

namespace Media_Sweep\Utils;

/**
 * Validator class
 */
class Validator {

	/**
	 * The data under validation
	 *
	 * @var array
	 */
	public $data;

	/**
	 * The validation rules
	 *
	 * @var array
	 */
	public $rules;

	/**
	 * Custom error messages
	 *
	 * @var array
	 */
	protected $messages;

	/**
	 * Custom attribute names
	 *
	 * @var array
	 */
	protected $attributes;

	/**
	 * The validation errors
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * Constructor
	 *
	 * @param array $data
	 * @param array $rules
	 * @param array $messages
	 * @param array $attributes
	 */
	public function __construct( $data, $rules, $messages = array(), $attributes = array() ) {
		$this->data       = $data;
		$this->rules      = $rules;
		$this->messages   = $messages;
		$this->attributes = $attributes;
	}

	/**
	 * Create a new validator instance
	 *
	 * @param array $data
	 * @param array $rules
	 * @param array $messages
	 * @param array $attributes
	 * @return Validator
	 */
	public static function make( $data, $rules, $messages = array(), $attributes = array() ) {
		return new static( $data, $rules, $messages, $attributes );
	}

	/**
	 * Determine if the validation passes
	 *
	 * @return bool
	 */
	public function passes() {
		$this->errors = array();

		foreach ( $this->rules as $field => $rule_set ) {
			$rules = is_string( $rule_set ) ? explode( '|', $rule_set ) : $rule_set;
			$value = isset( $this->data[ $field ] ) ? $this->data[ $field ] : null;

			// Check if field is required
			$is_required = in_array( 'required', $rules, true );
			$is_empty    = $value === null || $value === '';

			// If field is not required and empty, skip validation
			if ( ! $is_required && $is_empty ) {
				$has_required_if = false;
				foreach ( $rules as $rule ) {
					if ( strpos( $rule, 'required_if:' ) === 0 ) {
						$has_required_if = true;
						break;
					}
				}

				// Only skip if there's no required_if rule
				if ( ! $has_required_if ) {
					continue;
				}
			}

			// If field is required and empty, add error
			if ( $is_required && $is_empty ) {
				$field_name = $this->get_attribute_name( $field );
				$this->add_error(
					$field,
					sprintf(
						/* translators: %s: field name */
						__( 'The %s field is required.', 'media-sweep' ),
						$field_name
					)
				);
				continue;
			}

			// Validate other rules if field is not empty
			if ( ! $is_empty ) {
				foreach ( $rules as $rule ) {
					if ( $rule === 'required' ) {
						continue; // Already handled
					}

					if ( ! $this->validate_rule( $field, $value, $rule ) ) {
						break; // Stop on first failure for this field
					}
				}
			} else {
				// Field is empty but not required, still check required_if rules
				foreach ( $rules as $rule ) {
					if ( strpos( $rule, 'required_if:' ) === 0 ) {
						$this->validate_rule( $field, $value, $rule );
					}
				}
			}
		}

		return empty( $this->errors );
	}

	/**
	 * Determine if the validation fails
	 *
	 * @return bool
	 */
	public function fails() {
		return ! $this->passes();
	}

	/**
	 * Get all validation errors
	 *
	 * @return array
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * Get the first error for a field
	 *
	 * @param string $field
	 * @return string|null
	 */
	public function first( $field = null ) {
		if ( $field ) {
			return isset( $this->errors[ $field ] ) ? $this->errors[ $field ][0] : null;
		}

		foreach ( $this->errors as $field_errors ) {
			return $field_errors[0];
		}

		return null;
	}

	/**
	 * Validate a single rule
	 *
	 * @param string $field
	 * @param mixed  $value
	 * @param string $rule
	 * @return bool
	 */
	protected function validate_rule( $field, $value, $rule ) {
		$rule_parts = explode( ':', $rule, 2 );
		$rule_name  = $rule_parts[0];
		$parameters = isset( $rule_parts[1] ) ? explode( ',', $rule_parts[1] ) : array();

		// Special handling for required_if rule - we need to validate it even if the field is empty
		if ( $rule_name === 'required_if' ) {
			$method = 'validate_' . $rule_name;
			if ( method_exists( $this, $method ) ) {
				return $this->$method( $field, $value, $parameters );
			}
		}

		$method = 'validate_' . $rule_name;

		if ( method_exists( $this, $method ) ) {
			$result = $this->$method( $field, $value, $parameters );
			return $result;
		} else {
			return true; // Unknown rules pass by default
		}
	}

	/**
	 * Add an error message for a field
	 *
	 * @param string $field
	 * @param string $message
	 */
	protected function add_error( $field, $message ) {
		// Check for custom message override
		$custom_key = $field . '.' . $message;
		if ( isset( $this->messages[ $custom_key ] ) ) {
			$message = $this->messages[ $custom_key ];
		}

		if ( ! isset( $this->errors[ $field ] ) ) {
			$this->errors[ $field ] = array();
		}

		$this->errors[ $field ][] = $message;
	}

	/**
	 * Get the display name for an attribute
	 *
	 * @param string $field
	 * @return string
	 */
	protected function get_attribute_name( $field ) {
		return isset( $this->attributes[ $field ] ) ? $this->attributes[ $field ] : str_replace( '_', ' ', $field );
	}

	/**
	 * Validate required rule
	 */
	protected function validate_required( $field, $value, $parameters ) {
		$field_name = $this->get_attribute_name( $field );

		if ( is_bool( $value ) ) {
			return true;
		}

		if ( is_array( $value ) && count( $value ) === 0 ) {
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field is required.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}

		if ( null === $value || '' === $value ) {
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field is required.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Validate string rule
	 */
	protected function validate_string( $field, $value, $parameters ) {
		if ( ! is_string( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be a string.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate integer rule
	 */
	protected function validate_integer( $field, $value, $parameters ) {
		if ( ! is_numeric( $value ) || (int) $value != $value ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be an integer.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate numeric rule
	 */
	protected function validate_numeric( $field, $value, $parameters ) {
		if ( ! is_numeric( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be numeric.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate email rule
	 */
	protected function validate_email( $field, $value, $parameters ) {
		if ( ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be a valid email address.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate URL rule
	 */
	protected function validate_url( $field, $value, $parameters ) {
		if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be a valid URL.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate min rule
	 */
	protected function validate_min( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		$min        = (int) $parameters[0];
		$field_name = $this->get_attribute_name( $field );

		if ( is_numeric( $value ) ) {
			if ( $value < $min ) {
				$this->add_error(
					$field,
					sprintf(
					/* translators: 1: field name, 2: minimum value */
						__( 'The %1$s field must be at least %2$s.', 'media-sweep' ),
						$field_name,
						$min
					)
				);
				return false;
			}
		} else {
			if ( strlen( $value ) < $min ) {
				$this->add_error(
					$field,
					sprintf(
					/* translators: 1: field name, 2: minimum characters */
						__( 'The %1$s field must be at least %2$s characters.', 'media-sweep' ),
						$field_name,
						$min
					)
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate max rule
	 */
	protected function validate_max( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		$max        = (int) $parameters[0];
		$field_name = $this->get_attribute_name( $field );

		if ( is_numeric( $value ) ) {
			if ( $value > $max ) {
				$this->add_error(
					$field,
					sprintf(
					/* translators: 1: field name, 2: maximum value */
						__( 'The %1$s field must not be greater than %2$s.', 'media-sweep' ),
						$field_name,
						$max
					)
				);
				return false;
			}
		} else {
			if ( strlen( $value ) > $max ) {
				$this->add_error(
					$field,
					sprintf(
					/* translators: 1: field name, 2: maximum characters */
						__( 'The %1$s field must not be greater than %2$s characters.', 'media-sweep' ),
						$field_name,
						$max
					)
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate between rule
	 */
	protected function validate_between( $field, $value, $parameters ) {
		if ( count( $parameters ) < 2 ) {
			return true;
		}

		$min        = (int) $parameters[0];
		$max        = (int) $parameters[1];
		$field_name = $this->get_attribute_name( $field );

		if ( is_numeric( $value ) ) {
			if ( $value < $min || $value > $max ) {
				$this->add_error(
					$field,
					sprintf(
					/* translators: 1: field name, 2: minimum value, 3: maximum value */
						__( 'The %1$s field must be between %2$s and %3$s.', 'media-sweep' ),
						$field_name,
						$min,
						$max
					)
				);
				return false;
			}
		} else {
			$length = strlen( $value );
			if ( $length < $min || $length > $max ) {
				$this->add_error(
					$field,
					sprintf(
					/* translators: 1: field name, 2: minimum characters, 3: maximum characters */
						__( 'The %1$s field must be between %2$s and %3$s characters.', 'media-sweep' ),
						$field_name,
						$min,
						$max
					)
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate in rule
	 */
	protected function validate_in( $field, $value, $parameters ) {
		if ( ! in_array( $value, $parameters, true ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: allowed values */
					__( 'The %1$s field must be one of: %2$s.', 'media-sweep' ),
					$field_name,
					implode( ', ', $parameters )
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate not_in rule
	 */
	protected function validate_not_in( $field, $value, $parameters ) {
		if ( in_array( $value, $parameters, true ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: forbidden values */
					__( 'The %1$s field must not be one of: %2$s.', 'media-sweep' ),
					$field_name,
					implode( ', ', $parameters )
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate confirmed rule
	 */
	protected function validate_confirmed( $field, $value, $parameters ) {
		$confirmation_field = $field . '_confirmation';
		$confirmation_value = isset( $this->data[ $confirmation_field ] ) ? $this->data[ $confirmation_field ] : null;

		if ( $value !== $confirmation_value ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s confirmation does not match.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate same rule
	 */
	protected function validate_same( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		$other_field = $parameters[0];
		$other_value = isset( $this->data[ $other_field ] ) ? $this->data[ $other_field ] : null;

		if ( $value !== $other_value ) {
			$field_name       = $this->get_attribute_name( $field );
			$other_field_name = $this->get_attribute_name( $other_field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: other field name */
					__( 'The %1$s and %2$s must match.', 'media-sweep' ),
					$field_name,
					$other_field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate different rule
	 */
	protected function validate_different( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		$other_field = $parameters[0];
		$other_value = isset( $this->data[ $other_field ] ) ? $this->data[ $other_field ] : null;

		if ( $value === $other_value ) {
			$field_name       = $this->get_attribute_name( $field );
			$other_field_name = $this->get_attribute_name( $other_field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: other field name */
					__( 'The %1$s and %2$s must be different.', 'media-sweep' ),
					$field_name,
					$other_field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate alpha rule
	 */
	protected function validate_alpha( $field, $value, $parameters ) {
		if ( ! ctype_alpha( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must only contain letters.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate alpha_num rule
	 */
	protected function validate_alpha_num( $field, $value, $parameters ) {
		if ( ! ctype_alnum( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must only contain letters and numbers.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate alpha_dash rule
	 */
	protected function validate_alpha_dash( $field, $value, $parameters ) {
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must only contain letters, numbers, dashes and underscores.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate regex rule
	 */
	protected function validate_regex( $field, $value, $parameters ) {
		if ( empty( $parameters ) || ! preg_match( $parameters[0], $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field format is invalid.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate date rule
	 */
	protected function validate_date( $field, $value, $parameters ) {
		if ( ! strtotime( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be a valid date.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate before rule
	 */
	protected function validate_before( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		$before_date      = $parameters[0] === 'now' ? date( 'Y-m-d H:i:s' ) : $parameters[0];
		$before_timestamp = strtotime( $before_date );
		$value_timestamp  = strtotime( $value );

		if ( $value_timestamp === false || $before_timestamp === false || $value_timestamp >= $before_timestamp ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: before date */
					__( 'The %1$s field must be a date before %2$s.', 'media-sweep' ),
					$field_name,
					$before_date
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate after rule
	 */
	protected function validate_after( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		$after_date      = $parameters[0] === 'now' ? date( 'Y-m-d H:i:s' ) : $parameters[0];
		$after_timestamp = strtotime( $after_date );
		$value_timestamp = strtotime( $value );

		if ( $value_timestamp === false || $after_timestamp === false || $value_timestamp <= $after_timestamp ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: after date */
					__( 'The %1$s field must be a date after %2$s.', 'media-sweep' ),
					$field_name,
					$after_date
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate boolean rule
	 */
	protected function validate_boolean( $field, $value, $parameters ) {
		$accepted = array( true, false, 0, 1, '0', '1', 'true', 'false' );

		if ( ! in_array( $value, $accepted, true ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be true or false.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate array rule
	 */
	protected function validate_array( $field, $value, $parameters ) {
		if ( ! is_array( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be an array.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate JSON rule
	 */
	protected function validate_json( $field, $value, $parameters ) {
		if ( ! is_string( $value ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be a valid JSON string.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}

		json_decode( $value );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s field must be a valid JSON string.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate unique rule (for database uniqueness)
	 */
	protected function validate_unique( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		global $wpdb;
		$table     = $parameters[0];
		$column    = isset( $parameters[1] ) ? $parameters[1] : $field;
		$except_id = isset( $parameters[2] ) ? $parameters[2] : null;
		$id_column = isset( $parameters[3] ) ? $parameters[3] : 'id';

		// Add prefix if needed
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			$table = $wpdb->prefix . $table;
		}

		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %s", $value );

		if ( $except_id ) {
			$sql .= $wpdb->prepare( " AND {$id_column} != %s", $except_id );
		}

		$count = $wpdb->get_var( $sql );

		if ( $count > 0 ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The %s has already been taken.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate exists rule (check if value exists in database)
	 */
	protected function validate_exists( $field, $value, $parameters ) {
		if ( empty( $parameters ) ) {
			return true;
		}

		global $wpdb;
		$table  = $parameters[0];
		$column = isset( $parameters[1] ) ? $parameters[1] : $field;

		// Add prefix if needed
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			$table = $wpdb->prefix . $table;
		}

		$sql   = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %s", $value );
		$count = $wpdb->get_var( $sql );

		if ( $count == 0 ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: %s: field name */
					__( 'The selected %s is invalid.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Validate required_if rule
	 */
	protected function validate_required_if( $field, $value, $parameters ) {
		if ( count( $parameters ) < 2 ) {
			return true;
		}

		$other_field    = $parameters[0];
		$expected_value = $parameters[1];
		$other_value    = isset( $this->data[ $other_field ] ) ? $this->data[ $other_field ] : null;

		// If the other field doesn't match the expected value, this field is not required
		if ( $other_value != $expected_value ) {
			return true;
		}

		// If the other field matches, this field is required
		if ( null === $value || '' === $value ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name */
					__( 'The %1$s field is required.', 'media-sweep' ),
					$field_name
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Validate mimes rule (file extensions)
	 */
	protected function validate_mimes( $field, $value, $parameters ) {
		if ( empty( $parameters ) || null === $value || '' === $value ) {
			return true;
		}

		$allowed_extensions = array_map( 'trim', $parameters );
		$file_extension     = pathinfo( $value, PATHINFO_EXTENSION );

		if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
			$field_name = $this->get_attribute_name( $field );
			$this->add_error(
				$field,
				sprintf(
				/* translators: 1: field name, 2: allowed extensions */
					__( 'The %1$s field must be a file of type: %2$s.', 'media-sweep' ),
					$field_name,
					implode( ', ', $allowed_extensions )
				)
			);
			return false;
		}
		return true;
	}
}
