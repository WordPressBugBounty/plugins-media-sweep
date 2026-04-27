<?php
/**
 * Settings Controller
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use Media_Sweep\Interfaces\Settings;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Settings Controller
 */
class Settings_Controller extends REST_Controller {

	/**
	 * Settings service
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Constructor
	 *
	 * @param Settings $settings Settings service
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
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
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_items' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);
	}

	/**
	 * Get schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'media-sweep',
			'type'       => 'object',
			'properties' => array(
				'auto_delete_after_days' => array(
					'type'        => 'integer',
					'description' => __( 'Auto Delete After Days', 'media-sweep' ),
					'default'     => 30,
				),
			),
			'default'    => array(
				'auto_delete_after_days' => 30,
			),
		);
	}

	/**
	 * Get items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$schema   = $this->get_schema();
		$default  = $schema['default'];
		$settings = $this->settings->get_all_settings();
		$settings = wp_parse_args( $settings, $default );

		return new WP_REST_Response( $settings );
	}

	/**
	 * Update items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function update_items( $request ) {
		$schema   = $this->get_schema();
		$default  = $schema['default'];
		$settings = $this->settings->get_all_settings();
		$settings = wp_parse_args( $settings, $default );
		$params   = $request->get_param( 'settings' );
		foreach ( $params as $key => $value ) {
			if ( isset( $settings[ $key ] ) ) {
				$settings[ $key ] = $value;
			}
		}

		$updated = $this->settings->update_all_settings( $settings );

		return new WP_REST_Response( $this->settings->get_all_settings() );
	}

	/**
	 * Delete items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function delete_items( $request ) {
		$this->settings->delete_all_settings();

		return new WP_REST_Response( true );
	}
}
