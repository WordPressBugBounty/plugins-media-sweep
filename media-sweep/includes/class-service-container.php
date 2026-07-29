<?php
/**
 * Service Container for Media Sweep.
 *
 * @package media-sweep
 */

namespace Media_Sweep;

use Media_Sweep\Traits\Singleton;
use Media_Sweep\Services;
use Media_Sweep\Services\Database_Installer;
use Media_Sweep\Interfaces\Database_Installer as Database_Installer_Interface;
use Media_Sweep\Services\Settings;
use Media_Sweep\Interfaces\Settings as Settings_Interface;
use Media_Sweep\Services\Media_Scanner_Service;
use Media_Sweep\Services\Filesystem_Scanner_Service;
use Media_Sweep\Interfaces\Media_Scanner;
use Media_Sweep\Interfaces\Filesystem_Scanner;
use Media_Sweep\Services\System_Monitor_Service;
use Media_Sweep\Services\Action_Service;
use Media_Sweep\Services\Trash_Service;
use Media_Sweep\Services\Scheduler_Service;

/**
 * Simple service container with dependency injection.
 */
class Service_Container {
	use Singleton;

	/**
	 * Registered services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Service instances.
	 *
	 * @var array
	 */
	private $instances = array();

	/**
	 * Interface to implementation mapping.
	 *
	 * @var array
	 */
	private $interfaces = array();

	/**
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->resolver = new Dependency_Resolver( $this );
		$this->register_default_services();
	}

	/**
	 * Register a service.
	 *
	 * @param string $name Service name.
	 * @param string $class Service class.
	 * @param bool   $singleton Whether to treat as singleton.
	 */
	public function register( $name, $class, $singleton = true ) {
		$this->services[ $name ] = array(
			'class'     => $class,
			'singleton' => $singleton,
		);
	}

	/**
	 * Bind an interface to an implementation.
	 *
	 * @param string $interface Interface name.
	 * @param string $implementation Implementation class or service name.
	 */
	public function bind( $interface, $implementation ) {
		$this->interfaces[ $interface ] = $implementation;
	}

	/**
	 * Get a service instance.
	 *
	 * @param string $name Service name.
	 * @return mixed Service instance.
	 * @throws \Exception If service not found or circular dependency detected.
	 */
	public function get( $name ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			throw new \Exception( "Service '{$name}' not found." );
		}

		$service = $this->services[ $name ];

		// Return existing instance if singleton
		if ( $service['singleton'] && isset( $this->instances[ $name ] ) ) {
			return $this->instances[ $name ];
		}

		// Resolve dependencies
		$dependencies = $this->resolver->resolve_dependencies( $service['class'] );

		// Create instance
		$reflection = new \ReflectionClass( $service['class'] );
		$instance   = $reflection->newInstanceArgs( $dependencies );

		// Store instance if singleton
		if ( $service['singleton'] ) {
			$this->instances[ $name ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if service is registered.
	 *
	 * @param string $name Service name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->services[ $name ] );
	}

	/**
	 * Initialize specific services without returning them.
	 * This is useful for services that need to be instantiated for their side effects
	 * (like registering hooks) but don't need to be used immediately.
	 *
	 * @param array $service_names Array of service names to initialize.
	 * @return void
	 */
	public function initialize( array $service_names ) {
		foreach ( $service_names as $service_name ) {
			if ( $this->has( $service_name ) ) {
				// Just instantiate the service (triggers constructor and init hooks)
				$this->get( $service_name );
			}
		}
	}

	/**
	 * Register default services.
	 */
	private function register_default_services() {
		$this->register( 'database_installer', Database_Installer::class, true );
		$this->bind( Database_Installer_Interface::class, Database_Installer::class );

		$this->register( 'settings', Settings::class, true );
		$this->bind( Settings_Interface::class, Settings::class );

		// Reference index services (extraction + storage) and the tick runner.
		$this->register( 'reference_store', Services\Reference_Store::class, true );
		$this->register( 'reference_extractor_service', Services\Reference_Extractor_Service::class, true );
		$this->register( 'scan_runner_service', Services\Scan_Runner_Service::class, true );

		// Register scanner services
		$this->register( 'media_scanner_service', Media_Scanner_Service::class, true );
		$this->register( 'filesystem_scanner_service', Filesystem_Scanner_Service::class, true );

		// Bind interfaces to implementations
		$this->bind( Media_Scanner::class, Media_Scanner_Service::class );
		$this->bind( Filesystem_Scanner::class, Filesystem_Scanner_Service::class );

		// Register system monitor service
		$this->register( 'system_monitor_service', System_Monitor_Service::class, true );

		// Register action service
		$this->register( 'action_service', Action_Service::class, true );

		// Register trash service
		$this->register( 'trash_service', Trash_Service::class, true );

		// Register scheduler service
		$this->register( 'scheduler_service', Scheduler_Service::class, true );

		// Allow developers to register services.
		do_action( 'media_sweep_register_services', $this );
	}
}
