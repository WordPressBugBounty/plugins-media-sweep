<?php
/**
 * Event queue (max 50; consent events are never dropped, custom events go first).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Events waiting to be sent, stored in wpcreatix_site_queue.
 */
final class Queue {

	/**
	 * Closed event type list (02 section 4.2).
	 *
	 * @var array<int,string>
	 */
	const TYPES = array( 'plugin.installed', 'plugin.activated', 'plugin.deactivated', 'plugin.uninstalled', 'plugin.updated', 'consent.granted', 'consent.withdrawn' );

	/**
	 * Custom event types from our plugins (11 section 6.1); the server decides whether a type exists.
	 */
	const CUSTOM_TYPE = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,2}$/';

	/**
	 * A non-consent event older than this is given up.
	 */
	const GIVE_UP = 604800;

	/**
	 * Build one event (02 section 4.2 field order).
	 *
	 * @param Store               $store   State (owns the seq counter).
	 * @param string              $type    Event type.
	 * @param string              $plugin  Plugin slug.
	 * @param string              $version Plugin version.
	 * @param array<string,mixed> $data    Event data.
	 * @param int|null            $now     Unix time (tests).
	 * @param string|null         $id      Event id (tests).
	 * @return array<string,mixed> Event.
	 */
	public static function make( Store $store, $type, $plugin, $version, array $data = array(), $now = null, $id = null ) {
		return array(
			'id'      => null === $id ? self::new_id() : (string) $id,
			'seq'     => $store->next_seq(),
			'time'    => gmdate( 'Y-m-d\TH:i:s\Z', null === $now ? time() : (int) $now ),
			'type'    => (string) $type,
			'plugin'  => (string) $plugin,
			'version' => (string) $version,
			'data'    => $data ? $data : new \stdClass(),
		);
	}

	/**
	 * Event id: 16 random bytes, base64url, 22 chars.
	 *
	 * @return string Id.
	 */
	public static function new_id() {
		return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- id encoding, not obfuscation.
	}

	/**
	 * Whether an event is a consent event.
	 *
	 * @param array<string,mixed> $event Event.
	 * @return bool True for consent.*.
	 */
	public static function is_consent( array $event ) {
		return isset( $event['type'] ) && 0 === strpos( (string) $event['type'], 'consent.' );
	}

	/**
	 * Whether an event is a custom type (not in the closed system list).
	 *
	 * @param array<string,mixed> $event Event.
	 * @return bool True for custom.
	 */
	public static function is_custom( array $event ) {
		return isset( $event['type'] ) && ! in_array( (string) $event['type'], self::TYPES, true );
	}

	/**
	 * Whether a type may be queued: a system type, or a custom type that matches the pattern and is not in a system namespace.
	 *
	 * @param string $type Event type.
	 * @return bool True when allowed.
	 */
	public static function allowed_type( $type ) {
		$type = (string) $type;

		if ( in_array( $type, self::TYPES, true ) ) {
			return true;
		}

		return 1 === preg_match( self::CUSTOM_TYPE, $type ) && 0 !== strpos( $type, 'plugin.' ) && 0 !== strpos( $type, 'consent.' );
	}

	/**
	 * Queued events, oldest first.
	 *
	 * @return array<int,array<string,mixed>> Events.
	 */
	public static function all() {
		$events = Store::read_option( Config::QUEUE_OPTION, array() );

		return is_array( $events ) ? array_values( array_filter( $events, 'is_array' ) ) : array();
	}

	/**
	 * Add an event; when full, drop the oldest custom events, then the oldest other non-consent events.
	 *
	 * @param array<string,mixed> $event Event.
	 * @return void
	 */
	public static function push( array $event ) {
		$events   = self::all();
		$events[] = $event;
		$over     = count( $events ) - Config::MAX_EVENTS;

		// Custom events go first, then plugin events; consent events stay even over the limit.
		foreach ( array( 'custom', 'system' ) as $pass ) {
			foreach ( $events as $i => $queued ) {
				if ( $over <= 0 ) {
					break 2;
				}

				if ( self::is_consent( $queued ) || ( 'custom' === $pass ) !== self::is_custom( $queued ) ) {
					continue;
				}

				unset( $events[ $i ] );
				--$over;
			}
		}

		self::save( $events );
	}

	/**
	 * Remove events by id.
	 *
	 * @param array<int,string> $ids Event ids.
	 * @return void
	 */
	public static function remove( array $ids ) {
		if ( ! $ids ) {
			return;
		}

		$keep = array();

		foreach ( self::all() as $event ) {
			if ( ! in_array( isset( $event['id'] ) ? $event['id'] : '', $ids, true ) ) {
				$keep[] = $event;
			}
		}

		self::save( $keep );
	}

	/**
	 * Drop plugin.* events (usage withdrawn) and non-consent events older than 7 days.
	 *
	 * @param bool     $usage Whether usage is still granted.
	 * @param int|null $now   Unix time (tests).
	 * @return void
	 */
	public static function prune( $usage, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$keep = array();

		foreach ( self::all() as $event ) {
			if ( self::is_consent( $event ) ) {
				$keep[] = $event;
				continue;
			}

			$time = isset( $event['time'] ) ? strtotime( (string) $event['time'] ) : 0;

			if ( $usage && $time && $now - $time <= self::GIVE_UP ) {
				$keep[] = $event;
			}
		}

		self::save( $keep );
	}

	/**
	 * Remove unsent consent.granted events so a later withdraw never sends the grant.
	 *
	 * @param string|null $purpose usage; null for every purpose.
	 * @return void
	 */
	public static function drop_grants( $purpose = null ) {
		$keep = array();

		foreach ( self::all() as $event ) {
			$data  = isset( $event['data'] ) ? (array) $event['data'] : array();
			$grant = isset( $event['type'] ) && 'consent.granted' === $event['type'];

			if ( $grant && ( null === $purpose || ( isset( $data['purpose'] ) && $purpose === $data['purpose'] ) ) ) {
				continue;
			}

			$keep[] = $event;
		}

		self::save( $keep );
	}

	/**
	 * Whether any consent event is queued (these go out without waiting for a delay).
	 *
	 * @return bool True when one is queued.
	 */
	public static function has_consent() {
		foreach ( self::all() as $event ) {
			if ( self::is_consent( $event ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Empty the queue.
	 *
	 * @return void
	 */
	public static function clear() {
		Store::delete_option( Config::QUEUE_OPTION );
	}

	/**
	 * Store the queue.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return void
	 */
	private static function save( array $events ) {
		if ( ! $events ) {
			self::clear();
			return;
		}

		Store::write_option( Config::QUEUE_OPTION, array_values( $events ) );
	}
}
