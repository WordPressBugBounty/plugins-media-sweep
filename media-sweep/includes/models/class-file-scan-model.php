<?php
/**
 * File Scan Model
 *
 * @package media-sweep
 */

namespace Media_Sweep\Models;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Database\Tables\File_Scan_Table;
use Media_Sweep\Models\Scan_Model;

/**
 * File Scan Model - represents the relationship between files and scans
 */
class File_Scan_Model extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table_name = 'mswp_file_scan';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = array(
		'scan_id',
		'file_id',
		'status',
		'notes',
		'recorded_at',
	);

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = array(
		'scan_id'     => 'int',
		'file_id'     => 'int',
		'notes'       => 'array',
		'recorded_at' => 'datetime',
	);

	/**
	 * Validation rules
	 *
	 * @var array
	 */
	public $rules = array(
		'scan_id'     => 'required|integer',
		'file_id'     => 'required|integer',
		'status'      => 'required|string|in:in_media,not_in_media,in_use,unused,orphaned',
		'notes'       => 'nullable|array',
		'recorded_at' => 'required|date',
	);

	/**
	 * Indicates if the model should be timestamped
	 *
	 * @var bool
	 */
	public $timestamps = false;

	/**
	 * Get the table instance
	 *
	 * @return File_Scan_Table
	 */
	protected function get_table_instance() {
		return new File_Scan_Table();
	}

	/**
	 * Create model with validation and duplicate handling
	 *
	 * @param array $attributes
	 * @return static
	 * @throws \Exception
	 */
	public static function create( $attributes = array() ) {
		$attributes['recorded_at'] = current_time( 'mysql' );

		return parent::create( $attributes );
	}

	/**
	 * Get the scan that this file scan belongs to
	 *
	 * @return \Media_Sweep\Utils\Relations\Belongs_To
	 */
	public function scan() {
		return $this->belongs_to( Scan_Model::class, 'scan_id' );
	}

	/**
	 * Get the file that this scan record belongs to
	 *
	 * @return \Media_Sweep\Utils\Relations\Belongs_To
	 */
	public function file() {
		return $this->belongs_to( File_Model::class, 'file_id' );
	}
}
