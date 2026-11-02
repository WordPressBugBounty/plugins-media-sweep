<?php
/**
 * Uninstall helper: the host plugin's uninstall.php requires this file; the shared record is removed only when no other plugin bundles the client.
 *
 * @package WPCreatix\BridgeClient
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'wpcreatix_bridge_client_uninstall' ) ) {
	/**
	 * Delete the client options and the tick when this is the last plugin with the client.
	 *
	 * @param string $own_plugin_dir Folder of the plugin being uninstalled.
	 * @return bool True when the data was removed.
	 */
	function wpcreatix_bridge_client_uninstall( $own_plugin_dir ) {
		$own = wp_normalize_path( untrailingslashit( (string) $own_plugin_dir ) );

		foreach ( (array) glob( WP_PLUGIN_DIR . '/*/lib/bridge-client/load.php' ) as $load ) {
			if ( wp_normalize_path( dirname( (string) $load, 3 ) ) !== $own ) {
				return false;
			}
		}

		foreach ( array( 'wpcreatix_site', 'wpcreatix_site_queue', 'wpcreatix_site_lock', 'wpcreatix_site_ext', 'wpcreatix_newsletter_box' ) as $name ) {
			delete_option( $name );

			if ( is_multisite() ) {
				delete_site_option( $name );
			}
		}

		wp_clear_scheduled_hook( 'wpcreatix_bridge_client_tick' );

		return true;
	}
}

wpcreatix_bridge_client_uninstall( dirname( __DIR__, 2 ) );
