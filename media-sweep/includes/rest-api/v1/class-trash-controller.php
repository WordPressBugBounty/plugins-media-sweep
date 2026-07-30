<?php
/**
 * Trash REST API Controller
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use Media_Sweep\REST_API\V1\REST_Controller;
use Media_Sweep\Services\Trash_Service;
use Media_Sweep\Utils\Trash_Helper;
use Media_Sweep\Utils\Filesystem_Helper;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Trash Controller class
 */
class Trash_Controller extends REST_Controller {

	/**
	 * Trash service instance
	 *
	 * @var Trash_Service
	 */
	protected $trash_service;

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'trash';

	/**
	 * Constructor
	 *
	 * @param Trash_Service $trash_service Trash service instance
	 */
	public function __construct( Trash_Service $trash_service ) {
		$this->trash_service = $trash_service;
	}

	/**
	 * Register routes
	 */
	public function register_routes() {
		// Get trash files with pagination
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_trash_files' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		// Delete trash file permanently
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/files/(?P<file_id>[a-zA-Z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_trash_file' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'file_id' => array(
							'description' => __( 'File ID (MD5 hash of relative path).', 'media-sweep' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Restore trash file
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/files/(?P<file_id>[a-zA-Z0-9]+)/restore',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_trash_file' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'file_id' => array(
							'description' => __( 'File ID (MD5 hash of relative path).', 'media-sweep' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Stream an image preview. The trash folder is deliberately not web-readable, and on nginx no
		// dropped-in config file can make it so, therefore previews are served through the REST API where
		// the same capability check applies on every server.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/files/(?P<file_id>[a-zA-Z0-9]+)/preview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_trash_file_preview' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'file_id' => array(
							'description' => __( 'File ID (MD5 hash of relative path).', 'media-sweep' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Batch operations
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch_operation' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'action'   => array(
							'description' => __( 'Batch action to perform.', 'media-sweep' ),
							'type'        => 'string',
							'enum'        => array( 'delete', 'restore' ),
							'required'    => true,
						),
						'file_ids' => array(
							'description' => __( 'Array of file IDs to process.', 'media-sweep' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'required'    => true,
						),
					),
				),
			)
		);

		// Get trash statistics
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_trash_stats' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);

		// Empty entire trash
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/empty',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'empty_trash' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);
	}

	/**
	 * Get trash files with pagination
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_trash_files( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		// Build filters
		$filters = array();
		if ( $request->get_param( 'file_type' ) ) {
			$filters['file_type'] = $request->get_param( 'file_type' );
		}
		if ( $request->get_param( 'directory' ) ) {
			$filters['directory'] = $request->get_param( 'directory' );
		}
		if ( $request->get_param( 'search' ) ) {
			$filters['search'] = $request->get_param( 'search' );
		}
		if ( $request->get_param( 'min_age_days' ) ) {
			$filters['min_age_days'] = $request->get_param( 'min_age_days' );
		}

		try {
			$result = $this->trash_service->get_trash_files( $page, $per_page, $filters );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'trash_files_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Stream a trashed image so the trash list can show a real thumbnail.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|void Exits on success.
	 */
	public function get_trash_file_preview( $request ) {
		$file_info = $this->find_file_by_id( $request->get_param( 'file_id' ) );

		if ( ! $file_info || 'image' !== $file_info['file_type'] ) {
			return new WP_Error(
				'preview_not_available',
				__( 'No preview is available for this file.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		// Containment check against the resolved path so no symlink or traversal can read outside the
		// trash folder, whatever the ID claims.
		$real_file  = realpath( $file_info['absolute_path'] );
		$real_trash = realpath( Trash_Helper::get_trash_directory() );

		if ( ! $real_file || ! $real_trash
			|| strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_trash ) ) ) !== 0 ) {
			return new WP_Error(
				'preview_not_available',
				__( 'No preview is available for this file.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		$mime = wp_check_filetype( $real_file );
		if ( empty( $mime['type'] ) || strpos( $mime['type'], 'image/' ) !== 0 ) {
			return new WP_Error(
				'preview_not_available',
				__( 'No preview is available for this file.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		$bytes = Filesystem_Helper::get_contents( $real_file );

		if ( false === $bytes ) {
			return new WP_Error(
				'preview_not_available',
				__( 'No preview is available for this file.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		// Anything another plugin printed during this request is still buffered; flushing it would prepend
		// text to the image bytes and corrupt it, so it is discarded rather than sent.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: ' . $mime['type'] );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $file_info['filename'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=300' );

		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw image bytes.
		exit;
	}

	/**
	 * Delete trash file permanently
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_trash_file( $request ) {
		$file_id = $request->get_param( 'file_id' );

		// Find file by ID
		$file_info = $this->find_file_by_id( $file_id );
		if ( ! $file_info ) {
			return new WP_Error(
				'file_not_found',
				__( 'Trash file not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		try {
			$result = $this->trash_service->delete_trash_file( $file_info['relative_path'] );

			if ( $result['success'] ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => __( 'File deleted permanently.', 'media-sweep' ),
					),
					200
				);
			} else {
				return new WP_Error(
					'delete_failed',
					$result['error'],
					array( 'status' => 400 )
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'delete_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Restore trash file
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_trash_file( $request ) {
		$file_id      = $request->get_param( 'file_id' );
		$restore_type = $request->get_param( 'restore_type' ); // 'files' or 'media'

		// Find file by ID
		$file_info = $this->find_file_by_id( $file_id );
		if ( ! $file_info ) {
			return new WP_Error(
				'file_not_found',
				__( 'Trash file not found.', 'media-sweep' ),
				array( 'status' => 404 )
			);
		}

		try {
			$result = $this->trash_service->restore_trash_file( $file_info['relative_path'], $restore_type );

			if ( $result['success'] ) {
				$message = $restore_type === 'media'
					? __( 'File restored to media library successfully.', 'media-sweep' )
					: __( 'File restored to filesystem successfully.', 'media-sweep' );

				return new WP_REST_Response(
					array(
						'success'       => true,
						'message'       => $message,
						'restore_path'  => $result['restore_path'],
						'restore_type'  => $restore_type,
						'attachment_id' => $result['attachment_id'] ?? null,
					),
					200
				);
			} else {
				return new WP_Error(
					'restore_failed',
					$result['error'],
					array( 'status' => 400 )
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'restore_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Batch operation on multiple files
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch_operation( $request ) {
		$action       = $request->get_param( 'action' );
		$file_ids     = $request->get_param( 'file_ids' );
		$restore_type = $request->get_param( 'restore_type' ); // For restore actions

		if ( empty( $file_ids ) ) {
			return new WP_Error(
				'no_files',
				__( 'No files specified for batch operation.', 'media-sweep' ),
				array( 'status' => 400 )
			);
		}

		$results = array(
			'success_count' => 0,
			'error_count'   => 0,
			'errors'        => array(),
		);

		foreach ( $file_ids as $file_id ) {
			$file_info = $this->find_file_by_id( $file_id );
			if ( ! $file_info ) {
				++$results['error_count'];
				$results['errors'][] = sprintf(
					/* translators: %s is the file ID that was not found */
					__( 'File not found: %s', 'media-sweep' ),
					$file_id
				);
				continue;
			}

			try {
				if ( 'delete' === $action ) {
					$result = $this->trash_service->delete_trash_file( $file_info['relative_path'] );
				} else {
					$result = $this->trash_service->restore_trash_file( $file_info['relative_path'], $restore_type );
				}

				if ( $result['success'] ) {
					++$results['success_count'];
				} else {
					++$results['error_count'];
					$results['errors'][] = $result['error'];
				}
			} catch ( \Exception $e ) {
				++$results['error_count'];
				$results['errors'][] = $e->getMessage();
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $results,
			),
			200
		);
	}

	/**
	 * Get trash statistics
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_trash_stats( $request ) {
		try {
			$stats = $this->trash_service->get_trash_stats();

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $stats,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'stats_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Empty entire trash
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function empty_trash( $request ) {
		try {
			$result = $this->trash_service->empty_trash();

			if ( $result['success'] ) {
				return new WP_REST_Response(
					array(
						'success'       => true,
						'message'       => sprintf(
							/* translators: %d is the number of files deleted */
							__( 'Trash emptied successfully. %d files deleted.', 'media-sweep' ),
							$result['deleted_count']
						),
						'deleted_count' => $result['deleted_count'],
						'errors'        => $result['errors'],
					),
					200
				);
			} else {
				return new WP_Error(
					'empty_trash_failed',
					__( 'Failed to empty trash.', 'media-sweep' ),
					array( 'status' => 400 )
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'empty_trash_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Find file by ID (MD5 hash of relative path)
	 *
	 * @param string $file_id File ID
	 * @return array|null File info or null if not found
	 */
	protected function find_file_by_id( $file_id ) {
		return $this->trash_service->get_trash_file_by_id( $file_id );
	}

	/**
	 * Get collection parameters
	 *
	 * @return array Collection parameters
	 */
	public function get_collection_params() {
		return array(
			'page'         => array(
				'description'       => __( 'Current page of the collection.', 'media-sweep' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'     => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'media-sweep' ),
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'file_type'    => array(
				'description' => __( 'Filter by file type.', 'media-sweep' ),
				'type'        => 'string',
				'enum'        => array( 'image', 'video', 'audio', 'document', 'other' ),
			),
			'directory'    => array(
				'description' => __( 'Filter by directory.', 'media-sweep' ),
				'type'        => 'string',
			),
			'search'       => array(
				'description' => __( 'Search in filenames.', 'media-sweep' ),
				'type'        => 'string',
			),
			'min_age_days' => array(
				'description'       => __( 'Filter files older than specified days.', 'media-sweep' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get item schema
	 *
	 * @return array Item schema
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'trash_file',
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'type'        => 'string',
					'description' => __( 'The unique ID of the trash file.', 'media-sweep' ),
					'readonly'    => true,
				),
				'filename'      => array(
					'type'        => 'string',
					'description' => __( 'The filename.', 'media-sweep' ),
					'readonly'    => true,
				),
				'relative_path' => array(
					'type'        => 'string',
					'description' => __( 'The relative path in trash.', 'media-sweep' ),
					'readonly'    => true,
				),
				'directory'     => array(
					'type'        => 'string',
					'description' => __( 'The directory path.', 'media-sweep' ),
					'readonly'    => true,
				),
				'size'          => array(
					'type'        => 'integer',
					'description' => __( 'File size in bytes.', 'media-sweep' ),
					'readonly'    => true,
				),
				'file_type'     => array(
					'type'        => 'string',
					'description' => __( 'The file type category.', 'media-sweep' ),
					'enum'        => array( 'image', 'video', 'audio', 'document', 'other' ),
					'readonly'    => true,
				),
				'modified_time' => array(
					'type'        => 'integer',
					'description' => __( 'Last modified timestamp.', 'media-sweep' ),
					'readonly'    => true,
				),
				'age_days'      => array(
					'type'        => 'integer',
					'description' => __( 'Age in days.', 'media-sweep' ),
					'readonly'    => true,
				),
			),
		);
	}
}
