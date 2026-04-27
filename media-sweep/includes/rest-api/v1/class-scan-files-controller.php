<?php
/**
 * Scan Files controller
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use Media_Sweep\REST_API\V1\REST_Controller;
use Media_Sweep\Models\File_Scan_Model;
use WP_REST_Server;
use WP_Error;
use WP_REST_Response;

/**
 * Scan Files controller
 */
class Scan_Files_Controller extends REST_Controller {

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'scan-files';

	/**
	 * Register routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);

		// Bulk actions endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'bulk_update' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'ids'    => array(
							'description' => __( 'Array of scan file IDs to update.', 'media-sweep' ),
							'type'        => 'array',
							'required'    => true,
						),
						'status' => array(
							'description' => __( 'New status for the scan files.', 'media-sweep' ),
							'type'        => 'string',
							'enum'        => array( 'in_use', 'unused', 'orphaned' ),
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Get item schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'scan_file',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the scan file.', 'media-sweep' ),
					'readonly'    => true,
				),
				'scan_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the scan.', 'media-sweep' ),
					'required'    => true,
				),
				'file_id'     => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the file.', 'media-sweep' ),
					'required'    => true,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'The status of the file in the scan.', 'media-sweep' ),
					'enum'        => array( 'in_use', 'unused', 'orphaned' ),
					'required'    => true,
				),
				'recorded_at' => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'When the file status was recorded.', 'media-sweep' ),
					'required'    => true,
				),
			),
		);
	}

	/**
	 * Get collection parameters
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'media-sweep' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'minimum'           => 1,
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'media-sweep' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'scan_id'  => array(
				'description'       => __( 'Limit results to specific scan.', 'media-sweep' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'file_id'  => array(
				'description'       => __( 'Limit results to specific file.', 'media-sweep' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'status'   => array(
				'description' => __( 'Limit results to specific status.', 'media-sweep' ),
				'type'        => 'string',
			),
			'orderby'  => array(
				'description' => __( 'Sort collection by object attribute.', 'media-sweep' ),
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'scan_id', 'file_id', 'status', 'recorded_at' ),
			),
			'order'    => array(
				'description' => __( 'Order sort attribute ascending or descending.', 'media-sweep' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			'fields'   => array(
				'description' => __( 'Comma-separated list of fields to include in the response.', 'media-sweep' ),
				'type'        => 'string',
			),
			'search'   => array(
				'description' => __( 'Search for files by filepath.', 'media-sweep' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Get items
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function get_items( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$scan_id  = $request->get_param( 'scan_id' );
		$file_id  = $request->get_param( 'file_id' );
		$status   = $request->get_param( 'status' );
		$orderby  = $request->get_param( 'orderby' );
		$order    = $request->get_param( 'order' );
		$fields   = $request->get_param( 'fields' );
		$search   = $request->get_param( 'search' );
		$query    = File_Scan_Model::query();

		// Load relationships
		$relations = array(
			'file' => array(
				'columns' => array( 'id', 'filepath', 'file_type', 'size_bytes', 'attachment_id', 'thumb_of', 'status', 'updated_at' ),
			),
		);

		$query->with( $relations );

		if ( $scan_id ) {
			$query->where( 'scan_id', '=', $scan_id );
		}

		if ( $file_id ) {
			$query->where( 'file_id', '=', $file_id );
		}

		if ( $status ) {
			$query->where( 'status', '=', $status );
		}

		if ( $search ) {
			$query->where( 'filepath', 'like', '%' . $search . '%' );
		}

		if ( $orderby ) {
			$query->order_by( $orderby, $order );
		}

		if ( $fields ) {
			$selected_fields = explode( ',', $fields );
			$query->select( $selected_fields );
		}

		$scan_files = $query->paginate( $per_page, $page );

		$result = $scan_files->to_array();

		// Add status counts for the response
		if ( $scan_id ) {
			$totals = array(
				'in_use'   => File_Scan_Model::where( 'scan_id', '=', $scan_id )->where( 'status', '=', 'in_use' )->count(),
				'unused'   => File_Scan_Model::where( 'scan_id', '=', $scan_id )->where( 'status', '=', 'unused' )->count(),
				'orphaned' => File_Scan_Model::where( 'scan_id', '=', $scan_id )->where( 'status', '=', 'orphaned' )->count(),
			);

			$result['totals'] = $totals;
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Create item
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function create_item( $request ) {
		try {
			$params = $request->get_params();

			// Set recorded_at if not provided
			if ( ! isset( $params['recorded_at'] ) ) {
				$params['recorded_at'] = current_time( 'mysql' );
			}

			$scan_file = File_Scan_Model::create( $params );

			// Load relationships
			$scan_file->load(
				array(
					'scan' => array(
						'columns' => array( 'id', 'mode', 'started_at' ),
					),
					'file' => array(
						'columns' => array( 'id', 'filepath', 'file_type', 'size_bytes' ),
					),
				)
			);

			return new WP_REST_Response( $scan_file, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'scan_file_creation_failed',
				__( 'Validation failed', 'media-sweep' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Get item
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function get_item( $request ) {
		$id        = $request->get_param( 'id' );
		$scan_file = File_Scan_Model::find( $id );

		if ( ! $scan_file ) {
			return new WP_Error( 'scan_file_not_found', __( 'Scan file not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		// Load relationships
		$scan_file->load(
			array(
				'scan' => array(
					'columns' => array( 'id', 'mode', 'started_at', 'finished_at' ),
				),
				'file' => array(
					'columns' => array( 'id', 'filepath', 'file_type', 'size_bytes', 'attachment_id' ),
				),
			)
		);

		return new WP_REST_Response( $scan_file->to_array(), 200 );
	}

	/**
	 * Update item
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function update_item( $request ) {
		$id        = $request->get_param( 'id' );
		$scan_file = File_Scan_Model::find( $id );

		if ( ! $scan_file ) {
			return new WP_Error( 'scan_file_not_found', __( 'Scan file not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		try {
			$params = $request->get_params();
			$scan_file->update( $params );

			// Load relationships
			$scan_file->load(
				array(
					'scan' => array(
						'columns' => array( 'id', 'mode', 'started_at' ),
					),
					'file' => array(
						'columns' => array( 'id', 'filepath', 'file_type', 'size_bytes' ),
					),
				)
			);

			return new WP_REST_Response( $scan_file, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'scan_file_update_failed',
				__( 'Validation failed', 'media-sweep' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}

	/**
	 * Delete item
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function delete_item( $request ) {
		$id        = $request->get_param( 'id' );
		$scan_file = File_Scan_Model::find( $id );

		if ( ! $scan_file ) {
			return new WP_Error( 'scan_file_not_found', __( 'Scan file not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		$result = $scan_file->delete();

		if ( $result ) {
			return new WP_REST_Response( null, 204 );
		}

		return new WP_Error( 'scan_file_delete_failed', __( 'Failed to delete scan file', 'media-sweep' ), array( 'status' => 500 ) );
	}

	/**
	 * Bulk update scan files
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function bulk_update( $request ) {
		$ids    = $request->get_param( 'ids' );
		$status = $request->get_param( 'status' );

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return new WP_Error( 'invalid_ids', __( 'Invalid IDs provided', 'media-sweep' ), array( 'status' => 400 ) );
		}

		try {
			$updated_count = 0;
			$errors        = array();

			foreach ( $ids as $id ) {
				$scan_file = File_Scan_Model::find( $id );

				if ( ! $scan_file ) {
					$errors[] = sprintf(
						/* translators: %d is the file scan ID */
						__( 'Scan file with ID %d not found', 'media-sweep' ),
						$id
					);
					continue;
				}

				// Ensure notes is properly handled as an array for validation
				$current_notes = $scan_file->notes;
				if ( ! is_array( $current_notes ) ) {
					$current_notes = ! empty( $current_notes ) ? array( $current_notes ) : array();
				}

				$scan_file->update(
					array(
						'status' => $status,
						'notes'  => $current_notes,
					)
				);
				++$updated_count;
			}

			$response = array(
				'updated' => $updated_count,
				'status'  => $status,
			);

			if ( ! empty( $errors ) ) {
				$response['errors'] = $errors;
			}

			return new WP_REST_Response( $response, 200 );

		} catch ( \Exception $e ) {
			return new WP_Error(
				'bulk_update_failed',
				__( 'Bulk update failed', 'media-sweep' ),
				array(
					'status' => 400,
					'errors' => $e->errors(),
				)
			);
		}
	}
}
