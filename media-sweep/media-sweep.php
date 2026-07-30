<?php
/*
Plugin Name: Media Sweep
Description: Clean up your WordPress Media Library by finding and removing unused files. Safely scan, preview, and sweep away orphaned media to keep your site fast and organized.
Version: 1.1.2
Author: WPCreatix
Author URI: https://wpcreatix.com/
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: media-sweep
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEDIA_SWEEP_VERSION', '1.1.2' );
define( 'MEDIA_SWEEP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIA_SWEEP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIA_SWEEP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MEDIA_SWEEP_PLUGIN_FILE', __FILE__ );

// Load the autoloader.
require_once MEDIA_SWEEP_PLUGIN_DIR . 'includes/autoload.php';

/**
 * Load the main plugin class.
 */
function media_sweep() {
	return \Media_Sweep\Plugin::instance();
}

media_sweep();

// Load plugin.
add_action(
	'plugins_loaded',
	function () {
		do_action( 'media_sweep_loaded' );
	}
);

// WP-CLI: `wp media-sweep scan` - the unattended path for huge sites.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'media-sweep', \Media_Sweep\CLI\CLI_Command::class );
}
