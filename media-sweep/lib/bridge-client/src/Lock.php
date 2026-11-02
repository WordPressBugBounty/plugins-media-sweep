<?php
/**
 * Register mutex via INSERT IGNORE (core add_option() upserts, so it is not atomic).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Lock row in the main site's options table; older than 60 s is stale.
 */
final class Lock {

	/**
	 * Seconds after which a lock is stale.
	 */
	const STALE = 60;

	/**
	 * Try to take the register lock.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return bool True when this request holds the lock.
	 */
	public static function acquire( $now = null ) {
		$now = null === $now ? time() : (int) $now;

		if ( self::insert( $now ) ) {
			return true;
		}

		$held = self::held_since();

		// A crashed request left the row behind; only the request that deletes that exact row may take over.
		if ( $held > 0 && $now - $held > self::STALE && self::take_over( $held ) ) {
			return self::insert( $now );
		}

		return false;
	}

	/**
	 * Delete the lock row only if it still holds the stale time that was read.
	 *
	 * @param int $held Lock time that was read.
	 * @return bool True when this request removed it.
	 */
	private static function take_over( $held ) {
		global $wpdb;

		$rows = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE option_name = %s AND option_value = %s', Config::LOCK_OPTION, (string) $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- compare-and-delete keeps the takeover atomic.
		self::flush_cache();

		return 1 === (int) $rows;
	}

	/**
	 * Release the register lock.
	 *
	 * @return void
	 */
	public static function release() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE option_name = %s', Config::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- atomic lock row, table name is core.
		self::flush_cache();
	}

	/**
	 * Lock time, or 0 when free.
	 *
	 * @return int Unix time the lock was taken.
	 */
	public static function held_since() {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . self::table() . ' WHERE option_name = %s', Config::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- must bypass the options cache.
	}

	/**
	 * Insert the lock row; false when another request holds it.
	 *
	 * @param int $now Unix time.
	 * @return bool True when inserted.
	 */
	private static function insert( $now ) {
		global $wpdb;

		$rows = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", Config::LOCK_OPTION, (string) $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- atomic insert is the mutex.
		self::flush_cache();

		return 1 === (int) $rows;
	}

	/**
	 * Main site options table (one lock per network on multisite).
	 *
	 * @return string Table name.
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->base_prefix . 'options';
	}

	/**
	 * Keep the options cache from lying about the lock row.
	 *
	 * @return void
	 */
	private static function flush_cache() {
		wp_cache_delete( Config::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
