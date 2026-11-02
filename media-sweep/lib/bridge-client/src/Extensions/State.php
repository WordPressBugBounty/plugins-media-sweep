<?php
/**
 * The wpcreatix_site_ext record (11 section 3.2): acked hashes, running full sync, timers.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Extensions;

use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Store;

/**
 * Plain array in its own option (autoload no, site option on multisite).
 */
final class State {

	/**
	 * Record schema.
	 */
	const SCHEMA = 1;

	/**
	 * Defaults for a site that never synced.
	 *
	 * @return array<string,mixed> State.
	 */
	public static function defaults() {
		return array(
			'schema'        => self::SCHEMA,
			'acked_hash'    => '',
			'acked'         => array(),
			'full'          => null,
			'dirty'         => false,
			'next_full_at'  => 0,
			'paused_until'  => 0,
			'scope_stop'    => 0,
			'backoff_until' => 0,
			'failures'      => 0,
		);
	}

	/**
	 * Current record merged over the defaults.
	 *
	 * @return array<string,mixed> State.
	 */
	public static function read() {
		wp_cache_delete( Config::EXT_OPTION, 'options' );

		$raw   = Store::read_option( Config::EXT_OPTION, array() );
		$state = array_merge( self::defaults(), is_array( $raw ) ? $raw : array() );

		$state['acked'] = is_array( $state['acked'] ) ? $state['acked'] : array();
		$state['full']  = is_array( $state['full'] ) && isset( $state['full']['sync_id'], $state['full']['parts'] ) ? $state['full'] : null;

		return $state;
	}

	/**
	 * Whether the record exists.
	 *
	 * @return bool True when stored.
	 */
	public static function exists() {
		return is_array( Store::read_option( Config::EXT_OPTION, false ) );
	}

	/**
	 * Save the whole record.
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	public static function write( array $state ) {
		Store::write_option( Config::EXT_OPTION, array_merge( self::defaults(), $state ) );
	}

	/**
	 * Merge values and save.
	 *
	 * @param array<string,mixed> $values Values.
	 * @return array<string,mixed> New state.
	 */
	public static function patch( array $values ) {
		$state = array_merge( self::read(), $values );
		self::write( $state );

		return $state;
	}

	/**
	 * Delete the record (usage withdrawn, erase, site gone).
	 *
	 * @return void
	 */
	public static function forget() {
		Store::delete_option( Config::EXT_OPTION );
	}

	/**
	 * The hash the server last acked ('' when none).
	 *
	 * @return string Hash.
	 */
	public static function acked_hash() {
		$state = self::read();

		return 1 === preg_match( '/^[0-9a-f]{40}$/', (string) $state['acked_hash'] ) ? (string) $state['acked_hash'] : '';
	}
}
