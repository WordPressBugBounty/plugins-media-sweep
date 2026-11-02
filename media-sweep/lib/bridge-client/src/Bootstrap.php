<?php
/**
 * Boots the newest client copy on this site (required once by the picker in load.php).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/autoload.php';

// A client bug must never break the host site.
try {
	Client::boot( defined( 'WPCREATIX_BRIDGE_CLIENT_VERSION' ) ? (string) WPCREATIX_BRIDGE_CLIENT_VERSION : '0.0.0', dirname( __DIR__ ) );
} catch ( \Throwable $e ) {
	unset( $e );
}
