<?php
/**
 * Module Loader for Media Sweep.
 *
 * @package media-sweep
 */

namespace Media_Sweep;

use Media_Sweep\Traits\Singleton;
use Media_Sweep\Admin\Admin_Module;
use Media_Sweep\Database\Database_Module;
use Media_Sweep\REST_API\REST_API_Module;

/**
 * Module loader for managing application modules/loaders (not services).
 */
class Module_Loader {
	use Singleton;

	/**
	 * Service container instance.
	 *
	 * @var Service_Container
	 */
	private $container;

	/**
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver
	 */
	private $resolver;

	/**
	 * Loaded modules.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Module definitions.
	 *
	 * @var array
	 */
	private $module_definitions = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = Service_Container::instance();
		$this->resolver  = new Dependency_Resolver( $this->container );
		$this->register_default_modules();
	}

	/**
	 * Register a module.
	 *
	 * @param string $name Module name.
	 * @param string $class Module class.
	 * @param array  $conditions Conditions for loading (e.g., ['admin' => true]).
	 * @param bool   $singleton Whether to treat as singleton.
	 */
	public function register_module( $name, $class, $conditions = array(), $singleton = true ) {
		$this->module_definitions[ $name ] = array(
			'class'      => $class,
			'conditions' => $conditions,
			'singleton'  => $singleton,
		);
	}

	/**
	 * Load a module with dependency injection.
	 *
	 * @param string $name Module name.
	 * @return mixed Module instance.
	 * @throws \Exception If module not found.
	 */
	public function load_module( $name ) {
		if ( ! isset( $this->module_definitions[ $name ] ) ) {
			throw new \Exception( "Module '{$name}' not found." );
		}

		$definition = $this->module_definitions[ $name ];

		// Return existing instance if singleton
		if ( $definition['singleton'] && isset( $this->modules[ $name ] ) ) {
			return $this->modules[ $name ];
		}

		// Check conditions
		if ( ! $this->check_conditions( $definition['conditions'] ) ) {
			return null;
		}

		// Resolve dependencies and create instance
		$dependencies = $this->resolver->resolve_dependencies( $definition['class'] );
		$reflection   = new \ReflectionClass( $definition['class'] );
		$instance     = $reflection->newInstanceArgs( $dependencies );

		// Store instance if singleton
		if ( $definition['singleton'] ) {
			$this->modules[ $name ] = $instance;
		}

		return $instance;
	}

	/**
	 * Load all registered modules.
	 */
	public function load_all_modules() {
		foreach ( $this->module_definitions as $name => $definition ) {
			$this->load_module( $name );
		}
	}

	/**
	 * Check if a module is loaded.
	 *
	 * @param string $name Module name.
	 * @return bool
	 */
	public function is_loaded( $name ) {
		return isset( $this->modules[ $name ] );
	}

	/**
	 * Get a loaded module.
	 *
	 * @param string $name Module name.
	 * @return mixed|null Module instance or null if not loaded.
	 */
	public function get_module( $name ) {
		return isset( $this->modules[ $name ] ) ? $this->modules[ $name ] : null;
	}



	/**
	 * Check if conditions are met for loading a module.
	 *
	 * @param array $conditions Conditions to check.
	 * @return bool
	 */
	private function check_conditions( $conditions ) {
		foreach ( $conditions as $condition => $value ) {
			switch ( $condition ) {
				case 'admin':
					if ( $value && ! is_admin() ) {
						return false;
					}
					if ( ! $value && is_admin() ) {
						return false;
					}
					break;
				case 'frontend':
					if ( $value && is_admin() ) {
						return false;
					}
					if ( ! $value && ! is_admin() ) {
						return false;
					}
					break;
				case 'ajax':
					if ( $value && ! wp_doing_ajax() ) {
						return false;
					}
					if ( ! $value && wp_doing_ajax() ) {
						return false;
					}
					break;
				case 'cron':
					if ( $value && ! wp_doing_cron() ) {
						return false;
					}
					if ( ! $value && wp_doing_cron() ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Register default modules.
	 */
	private function register_default_modules() {
		$this->register_module( 'admin_loader', Admin_Module::class, array( 'admin' => true ), true );
		$this->register_module( 'database', Database_Module::class, array(), true );
		$this->register_module( 'rest_api', REST_API_Module::class, array(), true );

		// Allow developers to register modules.
		do_action( 'media_sweep_register_modules', $this );
	}
}
