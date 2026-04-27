<?php
/**
 * Autoloader for Media Sweep.
 *
 * @package media-sweep
 */

namespace Media_Sweep;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( __NAMESPACE__ . '\autoload' );

/**
 * Autoloader function
 *
 * @param string $class class name.
 * @return void
 */
function autoload( $class ) {
	$class_breakdown = explode( '\\', $class );
	if ( array_shift( $class_breakdown ) === __NAMESPACE__ ) {
		$class_breakdown = array_map(
			function ( $value ) {
				return str_replace( '_', '-', strtolower( $value ) );
			},
			$class_breakdown
		);

		$last_part = array_pop( $class_breakdown );

		// Determine the file prefix based on the class type
		$file_prefix = 'class-';

		// Check for special directory types in the class path
		foreach ( $class_breakdown as $part ) {
			switch ( $part ) {
				case 'traits':
					$file_prefix = 'trait-';
					break;
				case 'interfaces':
					$file_prefix = 'interface-';
					break;
				case 'abstracts':
					$file_prefix = 'class-';
					break;
			}
		}

		$class_breakdown[] = $file_prefix . $last_part;

		$class_file = __DIR__ . '/' . implode( '/', $class_breakdown ) . '.php';
		if ( file_exists( $class_file ) ) {
			include $class_file;
		}
	}
}
