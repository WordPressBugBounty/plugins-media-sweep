<?php
/**
 * Site identity: uid, the home URL it was registered with, and URL change (move or clone) checks.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Stateless helpers; state lives in Store.
 */
final class Identity {

	/**
	 * New random UUID v4.
	 *
	 * @return string UUID.
	 */
	public static function new_uid() {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Whether a string is a UUID v4.
	 *
	 * @param string $uid Candidate.
	 * @return bool True when valid.
	 */
	public static function is_uid( $uid ) {
		return is_string( $uid ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uid );
	}

	/**
	 * Home URL sent with usage consent (network home on multisite).
	 *
	 * @return string URL without a trailing slash.
	 */
	public static function home() {
		$home = is_multisite() ? network_home_url() : home_url();

		return untrailingslashit( (string) $home );
	}

	/**
	 * Comparable form of a home URL: no scheme, no leading www., no trailing slash, lower case.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	public static function normalize( $url ) {
		$url = strtolower( trim( (string) $url ) );
		$url = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $url );
		$url = (string) preg_replace( '#^www\.#', '', $url );

		return rtrim( $url, '/' );
	}

	/**
	 * Whether this site's home URL differs from the one it registered with.
	 *
	 * @param string $registered_home Stored registered_home.
	 * @param string $current         Current home URL.
	 * @return bool True when it moved or was cloned.
	 */
	public static function url_changed( $registered_home, $current ) {
		if ( '' === (string) $registered_home ) {
			return false;
		}

		return self::normalize( $registered_home ) !== self::normalize( $current );
	}

	/**
	 * Hour offset into the week for the heartbeat, seeded by the uid.
	 *
	 * @param string $uid Site uid.
	 * @return int 0..167.
	 */
	public static function seeded_hour( $uid ) {
		return self::crc( $uid ) % 168;
	}

	/**
	 * Seconds of delay after a version change, seeded by the uid (0 to 6 h).
	 *
	 * @param string $uid Site uid.
	 * @return int 0..21599.
	 */
	public static function seeded_jitter( $uid ) {
		return self::crc( $uid . '|version' ) % 21600;
	}

	/**
	 * Unsigned crc32 (same value on 32-bit PHP).
	 *
	 * @param string $value Input.
	 * @return int Unsigned checksum.
	 */
	private static function crc( $value ) {
		return (int) sprintf( '%u', crc32( (string) $value ) );
	}
}
