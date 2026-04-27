<?php
/**
 * Main Plugin Class for Media Sweep.
 *
 * @package media-sweep
 */

namespace Media_Sweep;

use Media_Sweep\Traits\Singleton;

/**
 * Main plugin class.
 */
class Plugin {

	use Singleton;

	/**
	 * Service container instance.
	 *
	 * @var Service_Container
	 */
	private $container;

	/**
	 * Module loader instance.
	 *
	 * @var Module_Loader
	 */
	private $module_loader;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container     = Service_Container::instance();
		$this->module_loader = Module_Loader::instance();
		$this->init();
	}

	/**
	 * Initialize the plugin.
	 */
	private function init() {
		// Load core modules.
		$this->modules()->load_all_modules();

		// Initialize scanner services to register their action hooks.
		$this->services()->initialize(
			array(
				'scanner_service',
				'media_scanner_service',
				'filesystem_scanner_service',
				'scheduler_service',
			)
		);
	}

	/**
	 * Get the service container instance.
	 *
	 * @return Service_Container
	 */
	public function services() {
		return $this->container;
	}

	/**
	 * Get the module loader instance.
	 *
	 * @return Module_Loader
	 */
	public function modules() {
		return $this->module_loader;
	}

	/**
	 * Bind an interface to an implementation.
	 *
	 * @param string $interface Interface name.
	 * @param string $implementation Implementation class or service name.
	 */
	public function bind( $interface, $implementation ) {
		$this->container->bind( $interface, $implementation );
	}
}
