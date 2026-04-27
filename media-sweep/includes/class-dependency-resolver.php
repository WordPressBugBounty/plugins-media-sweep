<?php
/**
 * Dependency Resolver for Media Sweep.
 *
 * @package media-sweep
 */

namespace Media_Sweep;

/**
 * Shared dependency resolver to eliminate code duplication.
 */
class Dependency_Resolver {

	/**
	 * Service container instance.
	 *
	 * @var Service_Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Service_Container $container Service container instance.
	 */
	public function __construct( Service_Container $container ) {
		$this->container = $container;
	}

	/**
	 * Resolve dependencies for a class from the service container.
	 *
	 * @param string $class Class name.
	 * @return array Resolved dependencies.
	 */
	public function resolve_dependencies( $class ) {
		if ( ! class_exists( $class ) ) {
			return array();
		}

		$reflection  = new \ReflectionClass( $class );
		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return array();
		}

		$dependencies = array();
		$parameters   = $constructor->getParameters();

		foreach ( $parameters as $parameter ) {
			$type = $parameter->getType();

			if ( $type && ! $type->isBuiltin() ) {
				$type_name = $type->getName();

				// Try to resolve from interface binding first
				$resolved_service = $this->resolve_interface_binding( $type_name );

				if ( $resolved_service ) {
					$dependencies[] = $resolved_service;
				} else {
					// Try to get from service container by class name
					$service_name = $this->find_service_by_class( $type_name );

					if ( $service_name ) {
						$dependencies[] = $this->container->get( $service_name );
					} else {
						// For non-service dependencies, handle gracefully
						if ( $parameter->isOptional() ) {
							$dependencies[] = $parameter->getDefaultValue();
						} else {
							throw new \Exception( "Cannot resolve dependency '{$type_name}' for class '{$class}'. Make sure it's registered as a service or bound to an interface." );
						}
					}
				}
			}
		}

		return $dependencies;
	}

	/**
	 * Resolve interface binding to service instance.
	 *
	 * @param string $interface Interface name.
	 * @return mixed|null Service instance or null if not bound.
	 */
	private function resolve_interface_binding( $interface ) {
		// Use reflection to access private interfaces array from container
		$reflection          = new \ReflectionClass( $this->container );
		$interfaces_property = $reflection->getProperty( 'interfaces' );
		$interfaces_property->setAccessible( true );
		$interfaces = $interfaces_property->getValue( $this->container );

		if ( isset( $interfaces[ $interface ] ) ) {
			$implementation = $interfaces[ $interface ];

			// If implementation is a service name, get it from container
			if ( $this->container->has( $implementation ) ) {
				return $this->container->get( $implementation );
			}

			// If implementation is a class name, instantiate it
			if ( class_exists( $implementation ) ) {
				$dependencies = $this->resolve_dependencies( $implementation );
				$reflection   = new \ReflectionClass( $implementation );
				return $reflection->newInstanceArgs( $dependencies );
			}
		}

		return null;
	}

	/**
	 * Find a service by its class name.
	 *
	 * @param string $class_name Class name to find.
	 * @return string|null Service name or null if not found.
	 */
	private function find_service_by_class( $class_name ) {
		// Use reflection to access private services array from container
		$reflection        = new \ReflectionClass( $this->container );
		$services_property = $reflection->getProperty( 'services' );
		$services_property->setAccessible( true );
		$services = $services_property->getValue( $this->container );

		foreach ( $services as $name => $service ) {
			if ( $service['class'] === $class_name ) {
				return $name;
			}
		}
		return null;
	}
}
