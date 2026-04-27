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
		);
	}

	/**
	 * Initialize the REST API.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
