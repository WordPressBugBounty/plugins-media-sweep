<?php
/**
 * REST API module
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API;

use Media_Sweep\REST_API\V1\Scans_Controller;
use Media_Sweep\REST_API\V1\Scan_Files_Controller;
use Media_Sweep\REST_API\V1\Scanner_Controller;
use Media_Sweep\REST_API\V1\Settings_Controller;
use Media_Sweep\REST_API\V1\Actions_Controller;
use Media_Sweep\REST_API\V1\Trash_Controller;
use Media_Sweep\REST_API\V1\Promo_Controller;
use Media_Sweep\Dependency_Resolver;
use Media_Sweep\Service_Container;

/**
 * REST API module
 */
class REST_API_Module {

	/**
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver
	 */
	private $resolver;

	/**
	 * Service container instance.
	 *
	 * @var Service_Container
	 */
	private $container;

	/**
	 * Controller classes.
	 *
	 * @var array
	 */
	private $controllers = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->container = Service_Container::instance();
		$this->resolver  = new Dependency_Resolver( $this->container );
		$this->register_controllers();
		$this->init();
	}

	/**
	 * Register controller classes
	 */
	private function register_controllers() {
		$this->controllers = array(
			Scans_Controller::class,
			Scan_Files_Controller::class,
			Scanner_Controller::class,
			Settings_Controller::class,
			Actions_Controller::class,
			Trash_Controller::class,
			Promo_Controller::class,
		);
	}

	/**
	 * Initialize the REST API.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Keep our own JSON responses well-formed (see capture_stray_output()).
		// The buffer opens at the very start of the REST lifecycle so output
		// printed by other code during dispatch - at any hook priority - lands
		// in it rather than in front of our JSON.
		add_action( 'rest_api_init', array( $this, 'start_response_buffer' ), 1 );
		add_filter( 'rest_pre_echo_response', array( $this, 'capture_stray_output' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'release_response_buffer' ), 0 );
	}

	/**
	 * Output-buffer level opened for the current request, if any.
	 *
	 * @var int|null
	 */
	private $buffer_level = null;

	/**
	 * Whether the given request targets one of our routes.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return bool
	 */
	private function is_our_route( $request ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		return $route && strpos( $route, '/media-sweep/' ) === 0;
	}

	/**
	 * Whether the request currently being served targets our namespace.
	 *
	 * Read at rest_api_init, before the route is dispatched, so it inspects the
	 * request target itself: the rest_route query var (plain permalinks) or the
	 * request path (pretty permalinks).
	 *
	 * @return bool
	 */
	private function request_targets_our_api() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only route check, no state changes.
		if ( isset( $_GET['rest_route'] ) ) {
			$rest_route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) );
			if ( strpos( $rest_route, '/media-sweep/' ) === 0 ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			if ( strpos( $uri, '/' . rest_get_url_prefix() . '/media-sweep/' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Open an output buffer for the duration of one of our REST requests.
	 */
	public function start_response_buffer() {
		if ( null === $this->buffer_level && $this->request_targets_our_api() ) {
			ob_start();
			$this->buffer_level = ob_get_level();
		}
	}

	/**
	 * Safety net: if a request ends without our response being echoed (an
	 * unmatched route, another plugin short-circuiting the REST server), close
	 * the buffer normally so nothing we captured is lost.
	 */
	public function release_response_buffer() {
		if ( null === $this->buffer_level ) {
			return;
		}

		while ( ob_get_level() > 0 && ob_get_level() >= $this->buffer_level ) {
			if ( ! ob_end_flush() ) {
				break;
			}
		}

		$this->buffer_level = null;
	}

	/**
	 * Remove anything that was printed during the request from our response.
	 *
	 * A REST response must be JSON and nothing else. Any output printed while
	 * the request runs - a notice from another plugin, a var_dump left in a
	 * theme, a database notice on a site running with WP_DEBUG_DISPLAY on -
	 * lands in front of our JSON and makes it unparseable, which the scanner
	 * then has to treat as a failed request.
	 *
	 * Buffering our own request and dropping that output is scoped and
	 * reversible: we change no PHP or database settings, we touch only our own
	 * routes, and nothing is hidden from the developer, since PHP and
	 * WordPress still log everything as usual (and we log what we dropped when
	 * WP_DEBUG is on, so the source is easy to find).
	 *
	 * @param mixed            $result  Response data about to be echoed.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed Unchanged $result.
	 */
	public function capture_stray_output( $result, $server, $request ) {
		if ( null === $this->buffer_level ) {
			return $result;
		}

		$stray = '';
		// Close our buffer (and any left open above it by the request).
		while ( ob_get_level() > 0 && ob_get_level() >= $this->buffer_level ) {
			$chunk = ob_get_clean();
			if ( false === $chunk ) {
				break;
			}
			$stray = $chunk . $stray;
		}

		$this->buffer_level = null;

		if ( '' !== trim( $stray ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Media Sweep: unexpected output was printed during %s and removed from the JSON response. First 500 characters: %s',
					$this->is_our_route( $request ) ? $request->get_route() : 'a REST request',
					substr( $stray, 0, 500 )
				)
			);
		}

		return $result;
	}

	/**
	 * Register REST routes
	 */
	public function register_rest_routes() {
		foreach ( $this->controllers as $controller_class ) {
			// Use dependency resolver to instantiate controller with its dependencies
			$dependencies = $this->resolver->resolve_dependencies( $controller_class );
			$reflection   = new \ReflectionClass( $controller_class );
			$controller   = $reflection->newInstanceArgs( $dependencies );

			$controller->register_routes();
		}
	}
}
