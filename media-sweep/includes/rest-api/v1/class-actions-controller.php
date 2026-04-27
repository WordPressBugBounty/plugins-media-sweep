<?php
/**
 * Actions Controller - Handle batch file actions (stateless)
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use Media_Sweep\REST_API\V1\REST_Controller;
use Media_Sweep\Services\Action_Service;
use Media_Sweep\Models\File_Scan_Model;
use Media_Sweep\Models\Scan_Model;
use WP_REST_Server;
use WP_Error;
use WP_REST_Response;
use WP_REST_Request;

/**
 * Actions Controller
 */
class Actions_Controller extends REST_Controller {

	/**
	 * Action service
	 *
	 * @var Action_Service
	 */
	protected $action_service;

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'actions';

	/**
	 * Constructor
	 *
	 * @param Action_Service $action_service Action service
	 */
	public function __construct( Action_Service $action_service ) {
		$this->action_service = $action_service;
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Get batch files endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch-files',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_batch_files' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'action'  => array(
							'description' => __( 'Action to perform.', 'media-sweep' ),
							'type'        => 'string',
							'enum'        => array( 'delete', 'restore' ),
							'required'    => true,
						),
						'scan_id' => array(
							'description' => __( 'Scan ID to process files from.', 'media-sweep' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'status'  => array(
							'description' => __( 'Filter by file status (optional).', 'media-sweep' ),
							'type'        => 'string',
							'enum'        => array( 'in_media', 'not_in_media', 'in_use', 'unused', 'orphaned' ),
						),
						'ids'     => array(
							'description' => __( 'Specific file scan IDs to process (optional).', 'media-sweep' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		// Process batch endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/process-batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_batch' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'action'        => array(
							'description' => __( 'Action to perform.', 'media-sweep' ),
							'type'        => 'string',
							'enum'        => array( 'delete', 'restore' ),
							'required'    => true,
						),
						'file_scan_ids' => array(
							'description' => __( 'Array of file scan IDs to process.', 'media-sweep' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
						'start_index'   => array(
							'description' => __( 'Index to start processing from.', 'media-sweep' ),
							'type'        => 'integer',
							'default'     => 0,
						),
						'batch_size'    => array(
							'description' => __( 'Number of files to process in this batch.', 'media-sweep' ),
							'type'        => 'integer',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
			)
		);
	}

	/**
	 * Get batch files for processing
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_batch_files( $request ) {
		$action  = $request->get_param( 'action' );
		$scan_id = $request->get_param( 'scan_id' );
		$status  = $request->get_param( 'status' );
		$ids     = $request->get_param( 'ids' );

		// Validate scan exists
		$scan = Scan_Model::find( $scan_id );
		if ( ! $scan ) {
			return new WP_Error(
				'scan_not_found',
				__( 'Scan not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		// Validate action-specific constraints
		if ( 'restore' === $action ) {
			// Restore only works with not_in_media status
			if ( $status && 'not_in_media' !== $status ) {
				return new WP_Error(
					'invalid_restore_status',
					__( 'Restore action can only be performed on files with "not_in_media" status.', 'media-sweep' ),
					array( 'status' => 400 )
				);
			}

			// If IDs provided, validate they are all not_in_media
			if ( $ids ) {
				$invalid_ids = File_Scan_Model::where_in( 'id', $ids )
					->where( 'status', '!=', 'not_in_media' )
					->pluck( 'id' );

				if ( ! empty( $invalid_ids ) ) {
					return new WP_Error(
						'invalid_restore_ids',
						sprintf(
							/* translators: %s is a list of file scan IDs */
							__( 'The following file scan IDs cannot be restored as they are not "not_in_media" status: %s', 'media-sweep' ),
							implode( ', ', $invalid_ids )
						),
						array( 'status' => 400 )
					);
				}
			}

			// Set status to not_in_media if not specified
			if ( ! $status ) {
				$status = 'not_in_media';
			}
		}

		try {
			$file_scan_ids = $this->action_service->get_batch_files( $scan_id, $status, $ids );
			$total         = count( $file_scan_ids );

			if ( 0 === $total ) {
				return new WP_Error(
					'no_files_found',
					__( 'No files found matching the criteria.', 'media-sweep' ),
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response(
				array(
					'success'       => true,
					'message'       => sprintf(
						/* translators: %1$d is the number of files found, %2$s is the action name */
						__( 'Found %1$d files for batch %2$s action.', 'media-sweep' ),
						$total,
						$action
					),
					'file_scan_ids' => $file_scan_ids,
					'total'         => $total,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'get_batch_files_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Process a batch of files
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_batch( $request ) {
		$action        = $request->get_param( 'action' );
		$file_scan_ids = $request->get_param( 'file_scan_ids' );
		$start_index   = $request->get_param( 'start_index' );
		$batch_size    = $request->get_param( 'batch_size' );

		// Validate file_scan_ids
		if ( empty( $file_scan_ids ) || ! is_array( $file_scan_ids ) ) {
			return new WP_Error(
				'invalid_file_scan_ids',
				__( 'file_scan_ids must be a non-empty array.', 'media-sweep' ),
				array( 'status' => 400 )
			);
		}

		try {
			$result = $this->action_service->process_batch( $action, $file_scan_ids, $start_index, $batch_size );

			return new WP_REST_Response( $result, 200 );

		} catch ( \Exception $e ) {
			return new WP_Error(
				'batch_process_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
