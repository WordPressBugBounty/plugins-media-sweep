<?php
/**
 * Scan Model
 *
 * @package media-sweep
 */

namespace Media_Sweep\Models;

use Media_Sweep\Abstracts\Model;
use Media_Sweep\Database\Tables\Scans_Table;
use Media_Sweep\Models\File_Scan_Model;

/**
 * Scan Model
 */
class Scan_Model extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table_name = 'mswp_scans';

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var array
	 */
	protected $fillable = array(
		'started_at',
		'finished_at',
		'mode',
		'options',
	);

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = array(
		'started_at'  => 'datetime',
		'finished_at' => 'datetime',
		'options'     => 'array',
	);

	/**
	 * Validation rules
	 *
	 * @var array
	 */
	public $rules = array(
		'started_at' => 'required|date',
		'mode'       => 'required|string|max:32',
	);

	/**
	 * Appends
	 */
	public $appends = array(
		'total_files',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 */
	public $timestamps = false;

	/**
	 * Get the table instance
	 *
	 * @return Scans_Table
	 */
	protected function get_table_instance() {
		return new Scans_Table();
	}

	/**
	 * Get the file scans for this scan
	 *
	 * @return \Media_Sweep\Utils\Relations\Has_Many
	 */
	public function file_scans() {
		return $this->has_many( File_Scan_Model::class, 'scan_id' );
	}

	/**
	 * Get the total files for this scan
	 *
	 * @return int
	 */
	public function getTotalFilesAttribute() {
		return $this->file_scans()->count();
	}
}
