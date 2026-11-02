<?php
/**
 * Class autoloader for this copy of the client (newest copy only).
 *
 * @package WPCreatix\BridgeClient
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	/**
	 * Load a WPCreatix\BridgeClient\ class from this copy's src folder.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	static function ( $class_name ) {
		$prefix = 'WPCreatix\\BridgeClient\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
