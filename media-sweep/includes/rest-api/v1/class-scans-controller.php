<?php
/**
 * Scans controller
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use Media_Sweep\REST_API\V1\REST_Controller;
use Media_Sweep\Models\Scan_Model;
use Media_Sweep\Services\Reference_Store;
use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Scans controller
 */
class Scans_Controller extends REST_Controller {

	/**
	 * Reference store (per-scan media reference index).
	 *
	 * @var Reference_Store
	 */
	protected $reference_store;

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'scans';

	/**
	 * Constructor
	 *
	 * @param Reference_Store $reference_store Reference store.
	 */
	public function __construct( Reference_Store $reference_store ) {
		$this->reference_store = $reference_store;
	}

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
	}

	/**
	 * Get item schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'scan',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the scan.', 'media-sweep' ),
					'readonly'    => true,
				),
				'started_at'     => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'When the scan was started.', 'media-sweep' ),
					'required'    => true,
				),
				'finished_at'    => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'When the scan was finished.', 'media-sweep' ),
				),
				'mode'           => array(
					'type'        => 'string',
					'description' => __( 'The scan mode.', 'media-sweep' ),
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'options'        => array(
					'type'        => 'object',
					'description' => __( 'The scan options.', 'media-sweep' ),
				),
				'total_in_use'   => array(
					'type'        => 'integer',
					'description' => __( 'Total files in use.', 'media-sweep' ),
					'default'     => 0,
				),
				'total_unused'   => array(
					'type'        => 'integer',
					'description' => __( 'Total unused files.', 'media-sweep' ),
					'default'     => 0,
				),
				'total_orphaned' => array(
					'type'        => 'integer',
					'description' => __( 'Total orphaned files.', 'media-sweep' ),
					'default'     => 0,
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Additional notes about the scan.', 'media-sweep' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
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
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'mode'     => array(
				'description'       => __( 'Limit results to scans with specific mode.', 'media-sweep' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'description' => __( 'Sort collection by object attribute.', 'media-sweep' ),
				'type'        => 'string',
				'default'     => 'id',
				'enum'        => array( 'id', 'started_at', 'finished_at', 'mode' ),
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
		$mode     = $request->get_param( 'mode' );
		$orderby  = $request->get_param( 'orderby' );
		$order    = $request->get_param( 'order' );
		$fields   = $request->get_param( 'fields' );

		$query = Scan_Model::query();

		if ( $mode ) {
			$query->where( 'mode', $mode );
		}

		if ( $orderby ) {
			$query->order_by( $orderby, $order );
		}

		if ( $fields ) {
			$selected_fields = explode( ',', $fields );
			$query->select( $selected_fields );
		}

		$scans = $query->paginate( $per_page, $page );

		return new WP_REST_Response( $scans, 200 );
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

			// Set started_at if not provided
			if ( ! isset( $params['started_at'] ) ) {
				$params['started_at'] = current_time( 'mysql' );
			}

			$scan = Scan_Model::create( $params );

			return new WP_REST_Response( $scan, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'scan_creation_failed',
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
		$id   = $request->get_param( 'id' );
		$scan = Scan_Model::find( $id );

		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $scan->to_array(), 200 );
	}

	/**
	 * Update item
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The response object.
	 */
	public function update_item( $request ) {
		$id   = $request->get_param( 'id' );
		$scan = Scan_Model::find( $id );

		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		try {
			$params = $request->get_params();
			$scan->update( $params );

			return new WP_REST_Response( $scan, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'scan_update_failed',
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
		$id   = $request->get_param( 'id' );
		$scan = Scan_Model::find( $id );

		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found', 'media-sweep' ), array( 'status' => 404 ) );
		}

		// Delete related file scans first
		$scan->file_scans()->query()->delete();

		// Purge the scan's reference index. A completed scan already purged
		// it at finish; this covers unfinished scans deleted mid-flight,
		// whose refs would otherwise be orphaned forever.
		$this->reference_store->delete_for_scan( $id );

		// Delete the scan
		$result = $scan->delete();

		if ( $result ) {
			return new WP_REST_Response( null, 204 );
		}

		return new WP_Error( 'scan_delete_failed', __( 'Failed to delete scan', 'media-sweep' ), array( 'status' => 500 ) );
	}
}
