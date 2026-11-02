<?php
/**
 * Full snapshot (02 section 4.3), sent only with usage consent.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Snapshot;

use WPCreatix\BridgeClient\Extensions\State;
use WPCreatix\BridgeClient\Products;
use WPCreatix\BridgeClient\Store;

/**
 * Index of the snapshot parts: env + our plugins; ext_hash only under a usage text that covers extensions.
 */
final class Snapshot {

	/**
	 * Build the current snapshot.
	 *
	 * @param Store    $store    Client state.
	 * @param Products $products Registered products.
	 * @param int|null $now      Unix time (tests).
	 * @return array<string,mixed> Snapshot.
	 */
	public static function build( Store $store, Products $products, $now = null ) {
		$records = $store->get( 'plugins' );
		$hash    = $store->usage_covers( 'extensions' ) ? State::acked_hash() : '';

		return array(
			'taken_at' => gmdate( 'Y-m-d\TH:i:s\Z', null === $now ? time() : (int) $now ),
			'env'      => Env::build( (string) $store->get( 'server' ), '' === $hash ? null : $hash ),
			'plugins'  => Plugins::build( $products, is_array( $records ) ? $records : array() ),
		);
	}
}
