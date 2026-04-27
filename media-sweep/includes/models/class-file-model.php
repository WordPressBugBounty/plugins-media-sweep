<?php
/**
 * File Model
 *
 * @package media-sweep
 */

namespace Media_Sweep\Models;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Database\Tables\Files_Table;
use Media_Sweep\Utils\Path_Helper;

/**
 * File Model
 */
class File_Model extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table_name = 'mswp_files';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = array(
		'filepath',
		'filepath_sha1',
		'attachment_id',
		'file_type',
		'size_bytes',
		'thumb_of',
		'status',
	);

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = array(
		'attachment_id' => 'int',
		'size_bytes'    => 'int',
		'thumb_of'      => 'int',
		'created_at'    => 'datetime',
		'updated_at'    => 'datetime',
	);

	/**
	 * Validation rules
	 *
	 * @var array
	 */
	public $rules = array(
		'filepath'      => 'required|string',
		'filepath_sha1' => 'required|string|min:40|max:40',
		'status'        => 'string|in:active,trashed,deleted',
	);

	/**
	 * Indicates if the model has an updated at column
	 *
	 * @var bool
	 */
	public $update_timestamp = true;

	/**
	 * Get the table instance
	 *
	 * @return Files_Table
	 */
	protected function get_table_instance() {
		return new Files_Table();
	}

	/**
	 * Get the file scans for this file
	 *
	 * @return \Media_Sweep\Utils\Relations\Has_Many
	 */
	public function file_scans() {
		return $this->has_many( File_Scan_Model::class, 'file_id' );
	}


	/**
	 * Auto-generate filepath_sha1 before saving
	 */
	public function save( $validate = null ) {
		if ( $this->filepath && ! $this->filepath_sha1 ) {
			$this->filepath_sha1 = sha1( $this->filepath );
		}

		// Set default status if not provided
		if ( ! $this->status ) {
			$this->status = 'active';
		}

		return parent::save( $validate );
	}

	/**
	 * Scope to get active files
	 *
	 * @return \Media_Sweep\Utils\Query_Builder
	 */
	public static function active() {
		return static::where( 'status', 'active' );
	}

	/**
	 * Scope to get trashed files
	 *
	 * @return \Media_Sweep\Utils\Query_Builder
	 */
	public static function trashed() {
		return static::where( 'status', 'trashed' );
	}

	/**
	 * Scope to get deleted files
	 *
	 * @return \Media_Sweep\Utils\Query_Builder
	 */
	public static function deleted() {
		return static::where( 'status', 'deleted' );
	}

	/**
	 * Move file to trash
	 *
	 * @return bool
	 */
	public function trash() {
		return $this->update( array( 'status' => 'trashed' ) );
	}

	/**
	 * Restore file from trash
	 *
	 * @return bool
	 */
	public function restore() {
		return $this->update( array( 'status' => 'active' ) );
	}

	/**
	 * Permanently delete file
	 *
	 * @return bool
	 */
	public function force_delete() {
		return $this->update( array( 'status' => 'deleted' ) );
	}

	/**
	 * Check if file is trashed
	 *
	 * @return bool
	 */
	public function is_trashed() {
		return $this->status === 'trashed';
	}

	/**
	 * Check if file is deleted
	 *
	 * @return bool
	 */
	public function is_deleted() {
		return $this->status === 'deleted';
	}

	/**
	 * Check if file is active
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->status === 'active';
	}

	/**
	 * Get absolute file path
	 *
	 * @return string
	 */
	public function get_absolute_path() {
		return Path_Helper::ensure_absolute_path( $this->filepath );
	}

	/**
	 * Get file URL
	 *
	 * @return string
	 */
	public function get_file_url() {
		return Path_Helper::get_file_url( $this->filepath );
	}

	/**
	 * Check if file exists on filesystem
	 *
	 * @return bool
	 */
	public function file_exists() {
		return file_exists( $this->get_absolute_path() );
	}

	/**
	 * Get file size in bytes
	 *
	 * @return int|false File size in bytes or false if file doesn't exist
	 */
	public function get_file_size() {
		$absolute_path = $this->get_absolute_path();
		return file_exists( $absolute_path ) ? filesize( $absolute_path ) : false;
	}
}
