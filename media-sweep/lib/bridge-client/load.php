<?php
/**
 * WPCreatix Bridge client loader. Registers this copy; the newest copy on the site boots.
 *
 * This file MUST stay tiny and never change shape: old copies of it live on sites for years.
 *
 * @package WPCreatix\BridgeClient
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['wpcreatix_bridge_client_copies']['1.3.0'] = __DIR__;

if ( ! function_exists( 'wpcreatix_bridge_client_product' ) ) {
	/**
	 * Register one of our plugins with the client (call from the plugin's main file).
	 *
	 * @param array<string,mixed> $product slug, file, name, version (optional, read from the header when missing), screens, icon (optional URL).
	 * @return void
	 */
	function wpcreatix_bridge_client_product( array $product ) {
		$GLOBALS['wpcreatix_bridge_client_products'][] = $product;
	}
}

if ( ! function_exists( 'wpcreatix_bridge_client_pick' ) ) {
	/**
	 * Boot only the newest registered copy (Action Scheduler pattern).
	 *
	 * @return void
	 */
	function wpcreatix_bridge_client_pick() {
		if ( defined( 'WPCREATIX_BRIDGE_CLIENT_VERSION' ) ) {
			return;
		}

		$copies = isset( $GLOBALS['wpcreatix_bridge_client_copies'] ) ? (array) $GLOBALS['wpcreatix_bridge_client_copies'] : array();

		uksort( $copies, 'version_compare' );

		$version = (string) array_key_last( $copies );
		$dir     = (string) end( $copies );

		define( 'WPCREATIX_BRIDGE_CLIENT_VERSION', $version );
		define( 'WPCREATIX_BRIDGE_CLIENT_DIR', $dir );

		if ( is_readable( $dir . '/src/Bootstrap.php' ) ) {
			require_once $dir . '/src/Bootstrap.php';
		}
	}

	if ( did_action( 'plugins_loaded' ) ) {
		wpcreatix_bridge_client_pick();
	} else {
		add_action( 'plugins_loaded', 'wpcreatix_bridge_client_pick', 1 );
	}
}
