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
	 * Scan lifecycle statuses.
	 */
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';

	/**
	 * Scan phases, in execution order. Extraction phases are shared by both
	 * scan modes; after extraction a media scan runs 'verdicts' server-side
	 * while a filesystem scan moves to 'enumerate' (frontend-driven folder
	 * walk + budgeted file batches).
	 */
	const PHASE_EXTRACT_POSTS   = 'extract_posts';
	const PHASE_EXTRACT_OPTIONS = 'extract_options';
	const PHASE_EXTRACT_DEEP    = 'extract_deep';
	const PHASE_VERDICTS        = 'verdicts';
	const PHASE_ENUMERATE       = 'enumerate';
	const PHASE_DONE            = 'done';

	/**
	 * A scan whose last tick is older than this many seconds is considered
	 * stale: the driving browser tab is gone and the UI should offer Resume
	 * instead of showing a phantom "In Progress".
	 */
	const STALE_AFTER_SECONDS = 120;

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
		'status',
		'phase',
		'checkpoint',
		'last_tick_at',
	);

	/**
	 * The attributes that should be cast.
	 *
	 * @var array
	 */
	protected $casts = array(
		'started_at'   => 'datetime',
		'finished_at'  => 'datetime',
		'options'      => 'array',
		'checkpoint'   => 'array',
		'last_tick_at' => 'datetime',
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
