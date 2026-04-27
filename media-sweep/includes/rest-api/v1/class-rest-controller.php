<?php
/**
 * Abstract class for REST controllers
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use WP_REST_Controller;

/**
 * Abstract class for REST controllers
 */
abstract class REST_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'media-sweep/v1';

	/**
	 * Base route
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Check public permission
	 *
	 * @return bool
	 */
	public function check_public_permission() {
		return true;
	}

	/**
	 * Check private permission
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public function check_private_permission( $request ) {
		return current_user_can( 'manage_options' ) && ! $request->get_param( 'frontend' );
	}
}
