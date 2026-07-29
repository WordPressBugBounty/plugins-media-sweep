<?php
/**
 * New Scanner Controller - Frontend-driven scanning REST API endpoints
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use Media_Sweep\REST_API\V1\REST_Controller;
use Media_Sweep\Interfaces\Media_Scanner;
use Media_Sweep\Interfaces\Filesystem_Scanner;
use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Services\Scan_Runner_Service;
use Media_Sweep\Utils\Time_Budget;
use WP_REST_Server;
use WP_Error;
use WP_REST_Response;
use WP_REST_Request;

/**
 * New Scanner Controller
 */
class Scanner_Controller extends REST_Controller {

	/**
	 * Media scanner service
	 *
	 * @var Media_Scanner
	 */
	protected $media_scanner;

	/**
	 * Filesystem scanner service
	 *
	 * @var Filesystem_Scanner
	 */
	protected $filesystem_scanner;

	/**
	 * Scan runner (tick orchestrator).
	 *
	 * @var Scan_Runner_Service
	 */
	protected $scan_runner;

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'scanner';

	/**
	 * Constructor
	 *
	 * @param Media_Scanner       $media_scanner      Media scanner service
	 * @param Filesystem_Scanner  $filesystem_scanner Filesystem scanner service
	 * @param Scan_Runner_Service $scan_runner        Scan runner service
	 */
	public function __construct( Media_Scanner $media_scanner, Filesystem_Scanner $filesystem_scanner, Scan_Runner_Service $scan_runner ) {
		$this->media_scanner      = $media_scanner;
		$this->filesystem_scanner = $filesystem_scanner;
		$this->scan_runner        = $scan_runner;
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Start scan endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_scan' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'scan_type' => array(
							'description' => __( 'Type of scan to perform.', 'media-sweep' ),
							'type'        => 'string',
							'enum'        => array( 'media_library', 'file_system' ),
							'required'    => true,
						),
						'options'   => array(
							'description' => __( 'Scan options.', 'media-sweep' ),
							'type'        => 'object',
							'default'     => array(),
						),
					),
				),
			)
		);

		// Resume scan endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>[\d]+)/resume',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resume_scan' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'scan_id' => array(
							'description' => __( 'Scan ID to resume.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get media attachments endpoint (for media library scan)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/media',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_media_attachments' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'page'     => array(
							'description' => __( 'Page number.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
							'minimum'     => 1,
						),
						'per_page' => array(
							'description' => __( 'Items per page.', 'media-sweep' ),
							'type'        => 'integer',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 500,
						),
					),
				),
			)
		);

		// Process media batch endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>[\d]+)/media/process',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_media_batch' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'scan_id'     => array(
							'description' => __( 'Scan ID.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'attachments' => array(
							'description' => __( 'Array of attachment IDs to process.', 'media-sweep' ),
							'type'        => 'array',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get main folder structure
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>\d+)/main-structure',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_main_structure' ),
				'permission_callback' => array( $this, 'check_private_permission' ),
			)
		);

		// Process folder recursively
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>\d+)/folder-recursive',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_folder_recursive' ),
				'permission_callback' => array( $this, 'check_private_permission' ),
				'args'                => array(
					'folder_path' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Folder path to process', 'media-sweep' ),
					),
					'resume_from' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Subfolder to resume from', 'media-sweep' ),
					),
				),
			)
		);

		// Process file batch
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>\d+)/process-batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_file_batch' ),
				'permission_callback' => array( $this, 'check_private_permission' ),
				'args'                => array(
					'file_paths' => array(
						'type'        => 'array',
						'required'    => true,
						'description' => __( 'Array of file paths to process', 'media-sweep' ),
					),
				),
			)
		);

		// Tick endpoint: one time-budgeted slice of server-side scan work.
		// The server owns all pagination state; the client just repeats the
		// call until done. Naturally idempotent - a retried tick simply
		// continues from the persisted checkpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>[\d]+)/tick',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'tick' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'scan_id'     => array(
							'description' => __( 'Scan ID.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'budget_hint' => array(
							'description' => __( 'Observed gateway ceiling in seconds; the server shrinks its slice to fit.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => false,
							'minimum'     => 2,
							'maximum'     => 60,
						),
					),
				),
			)
		);

		// Scan status endpoint: phase/progress/staleness for resume UI.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>[\d]+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);

		// Preflight endpoint: server self-probe the client uses to pick its
		// inter-request delay and client-side timeout.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preflight',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'preflight' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);

		// Get scan results endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>[\d]+)/results',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_scan_results' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'scan_id' => array(
							'description' => __( 'Scan ID.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);

		// Finish scan endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<scan_id>[\d]+)/finish',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'finish_scan' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'scan_id' => array(
							'description' => __( 'Scan ID.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Start a new scan
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan( $request ) {
		$scan_type = $request->get_param( 'scan_type' );
		$options   = $request->get_param( 'options' );

		try {
			if ( $scan_type === 'media_library' ) {
				$scan = $this->media_scanner->start_scan( $options );
			} elseif ( $scan_type === 'file_system' ) {
				$scan = $this->filesystem_scanner->start_scan( $options );
			} else {
				return new WP_Error(
					'invalid_scan_type',
					__( 'Invalid scan type.', 'media-sweep' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $scan ) {
				return new WP_Error(
					'scan_start_failed',
					__( 'Failed to start scan.', 'media-sweep' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Scan started successfully.', 'media-sweep' ),
					'scan'    => $scan,
				),
				201
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'scan_start_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Resume an existing scan
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_scan( $request ) {
		$scan_id = $request->get_param( 'scan_id' );

		// Validate scan exists
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error(
				'scan_not_found',
				__( 'Scan not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		// Check if scan can be resumed (not completed)
		if ( $scan->status === 'completed' ) {
			return new WP_Error(
				'scan_already_completed',
				__( 'Cannot resume a completed scan.', 'media-sweep' ),
				array( 'status' => 400 )
			);
		}

		try {
			if ( $scan->mode === 'media_library' ) {
				$resumed_scan = $this->media_scanner->resume_scan( $scan_id );
			} elseif ( $scan->mode === 'file_system' ) {
				$resumed_scan = $this->filesystem_scanner->resume_scan( $scan_id );
			} else {
				return new WP_Error(
					'invalid_scan_mode',
					__( 'Invalid scan mode.', 'media-sweep' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $resumed_scan ) {
				return new WP_Error(
					'scan_resume_failed',
					__( 'Failed to resume scan.', 'media-sweep' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Scan resumed successfully.', 'media-sweep' ),
					'scan'    => $resumed_scan,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'scan_resume_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get media attachments for processing
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_media_attachments( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		try {
			$result = $this->media_scanner->get_media_attachments( $page, $per_page, array() );

			return new WP_REST_Response( $result, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'get_media_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Process a batch of media attachments
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_media_batch( $request ) {
		$scan_id     = $request->get_param( 'scan_id' );
		$attachments = $request->get_param( 'attachments' );

		// Validate scan exists
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error(
				'scan_not_found',
				__( 'Scan not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		try {
			// Get options from the scan instead of request
			$options = $scan->options ?? array();
			$result  = $this->media_scanner->process_media_batch( $scan_id, $attachments, $options );

			return new WP_REST_Response( $result, 200 );

		} catch ( \Exception $e ) {
			return new WP_Error(
				'process_media_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get main folder structure
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_main_structure( $request ) {
		$scan_id = (int) $request->get_param( 'scan_id' );

		// Get scan to retrieve options
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		$options = $scan->options ?? array();
		$result  = $this->filesystem_scanner->get_main_folder_structure( $scan_id, $options );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Process folder recursively
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_folder_recursive( $request ) {
		$scan_id     = (int) $request->get_param( 'scan_id' );
		$folder_path = $request->get_param( 'folder_path' );
		$resume_from = $request->get_param( 'resume_from' );

		// Get scan to retrieve options
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		$options = $scan->options ?? array();
		$result  = $this->filesystem_scanner->get_folder_content_recursive( $scan_id, $folder_path, $resume_from, $options );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Process file batch
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_file_batch( $request ) {
		$scan_id    = (int) $request->get_param( 'scan_id' );
		$file_paths = $request->get_param( 'file_paths' );

		// Get scan to retrieve options
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		$result = $this->filesystem_scanner->process_file_batch( $scan_id, $file_paths, $scan->options );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Run one tick of server-side scan work.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function tick( $request ) {
		$scan_id = (int) $request->get_param( 'scan_id' );

		$this->clean_output_buffers();

		// A client that watched a previous tick get killed reports how long
		// it survived; run the next slice comfortably inside that ceiling.
		$budget_seconds = null;
		$budget_hint    = (int) $request->get_param( 'budget_hint' );
		if ( $budget_hint > 0 ) {
			$budget_seconds = max( 2.0, min( $budget_hint * 0.6, 15.0 ) );
		}

		$result = $this->scan_runner->run_tick( $scan_id, $budget_seconds );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get scan status (phase, progress, staleness) for the resume UI.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( $request ) {
		$scan_id = (int) $request->get_param( 'scan_id' );

		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found.', 'media-sweep' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->scan_runner->get_status( $scan ),
			),
			200
		);
	}

	/**
	 * Preflight: probe the server so the client can self-tune.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function preflight( $request ) {
		global $wpdb;

		// Time one trivial DB roundtrip.
		$t0 = microtime( true );
		$wpdb->get_var( 'SELECT 1' );
		$db_latency_ms = ( microtime( true ) - $t0 ) * 1000;

		$budget = new Time_Budget();

		$profile = 'ready';
		if ( $db_latency_ms > 750 ) {
			$profile = 'severely_constrained';
		} elseif ( $db_latency_ms > 250 ) {
			$profile = 'constrained';
		}

		$delay_ms = array(
			'ready'                => 150,
			'constrained'          => 500,
			'severely_constrained' => 1000,
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'db_latency_ms'      => (int) round( $db_latency_ms ),
					'time_budget'        => (int) round( $budget->remaining() ),
					'profile'            => $profile,
					'delay_ms'           => $delay_ms[ $profile ],
					// Client-side deadline: comfortably above the server
					// budget, still below common gateway ceilings.
					'request_timeout_ms' => 30000,
					'max_retries'        => 4,
				),
			),
			200
		);
	}

	/**
	 * Flush stray output buffers so PHP notices echoed by other plugins can
	 * never corrupt our JSON response.
	 */
	protected function clean_output_buffers() {
		while ( ob_get_level() > 0 ) {
			$content = ob_get_contents();
			// Only discard buffers containing unexpected output.
			if ( $content !== '' && $content !== false ) {
				ob_end_clean();
			} else {
				break;
			}
		}
	}

	/**
	 * Get scan results
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_scan_results( $request ) {
		$scan_id = $request->get_param( 'scan_id' );

		// Validate scan exists
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error(
				'scan_not_found',
				__( 'Scan not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		try {
			if ( $scan->mode === 'media_library' ) {
				$results = $this->media_scanner->get_scan_results( $scan_id );
			} elseif ( $scan->mode === 'file_system' ) {
				$results = $this->filesystem_scanner->get_scan_results( $scan_id );
			} else {
				return new WP_Error(
					'invalid_scan_mode',
					__( 'Invalid scan mode.', 'media-sweep' ),
					array( 'status' => 400 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Scan results retrieved successfully.', 'media-sweep' ),
					'results' => $results,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'get_results_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Finish a scan
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function finish_scan( $request ) {
		$scan_id = $request->get_param( 'scan_id' );

		// Validate scan exists
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error(
				'scan_not_found',
				__( 'Scan not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		if ( $scan->status === 'completed' ) {
			return new WP_Error(
				'scan_already_completed',
				__( 'Scan is already completed.', 'media-sweep' ),
				array( 'status' => 400 )
			);
		}

		try {
			if ( $scan->mode === 'media_library' ) {
				$result = $this->media_scanner->finish_scan( $scan_id );
			} elseif ( $scan->mode === 'file_system' ) {
				$result = $this->filesystem_scanner->finish_scan( $scan_id );
			} else {
				return new WP_Error(
					'invalid_scan_mode',
					__( 'Invalid scan mode.', 'media-sweep' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $result ) {
				return new WP_Error(
					'finish_scan_failed',
					__( 'Failed to finish scan.', 'media-sweep' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Scan finished successfully.', 'media-sweep' ),
					'scan'    => $scan,
					'results' => $result,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'finish_scan_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
