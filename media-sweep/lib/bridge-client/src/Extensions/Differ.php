<?php
/**
 * Pure helpers for the plugin and theme list: item hash, list hash, diff ops (11 section 3.2).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Extensions;

/**
 * No I/O; the unit tests call these directly.
 */
final class Differ {

	/**
	 * Op order in a diff body.
	 *
	 * @var array<int,string>
	 */
	const OPS = array( 'added', 'updated', 'activated', 'deactivated', 'removed' );

	/**
	 * Item key: type|slug.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return string Key.
	 */
	public static function key( array $item ) {
		return (string) $item['type'] . '|' . (string) $item['slug'];
	}

	/**
	 * SHA-1 of type|slug|version|active|network|parent|name|author (booleans as 1 or 0, no parent as '').
	 *
	 * @param array<string,mixed> $item Item.
	 * @return string Hex hash.
	 */
	public static function item_hash( array $item ) {
		return sha1(
			implode(
				'|',
				array(
					(string) $item['type'],
					(string) $item['slug'],
					(string) $item['version'],
					empty( $item['active'] ) ? '0' : '1',
					empty( $item['network'] ) ? '0' : '1',
					isset( $item['parent'] ) ? (string) $item['parent'] : '',
					(string) $item['name'],
					(string) $item['author'],
				)
			)
		);
	}

	/**
	 * Key => item hash for a list.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<string,string> Hashes.
	 */
	public static function hashes( array $items ) {
		$hashes = array();

		foreach ( $items as $item ) {
			$hashes[ self::key( $item ) ] = self::item_hash( $item );
		}

		return $hashes;
	}

	/**
	 * SHA-1 of the sorted key=item_hash lines joined by a newline.
	 *
	 * @param array<string,string> $hashes Key => item hash.
	 * @return string Hex hash.
	 */
	public static function list_hash( array $hashes ) {
		ksort( $hashes, SORT_STRING );

		$lines = array();

		foreach ( $hashes as $key => $hash ) {
			$lines[] = $key . '=' . $hash;
		}

		return sha1( implode( "\n", $lines ) );
	}

	/**
	 * Diff ops from the acked hashes to the current items; one op per item, updated wins over (de)activated.
	 *
	 * @param array<int,array<string,mixed>> $items Current items.
	 * @param array<string,string>           $acked Key => item hash last acked by the server.
	 * @return array<int,array<string,mixed>> Changes in OPS order, keys sorted inside each op.
	 */
	public static function diff( array $items, array $acked ) {
		$by_op = array_fill_keys( self::OPS, array() );
		$seen  = array();

		foreach ( $items as $item ) {
			$key          = self::key( $item );
			$seen[ $key ] = true;

			if ( ! isset( $acked[ $key ] ) ) {
				$by_op['added'][ $key ] = array(
					'op'   => 'added',
					'item' => $item,
				);
				continue;
			}

			if ( self::item_hash( $item ) === $acked[ $key ] ) {
				continue;
			}

			$flipped           = $item;
			$flipped['active'] = empty( $item['active'] );

			// Only the active flag changed when the hash with the old flag matches.
			if ( self::item_hash( $flipped ) === $acked[ $key ] ) {
				$op                   = empty( $item['active'] ) ? 'deactivated' : 'activated';
				$by_op[ $op ][ $key ] = self::bare( $op, $item['type'], $item['slug'] );
				continue;
			}

			$by_op['updated'][ $key ] = array(
				'op'   => 'updated',
				'item' => $item,
			);
		}

		foreach ( array_keys( $acked ) as $key ) {
			if ( ! isset( $seen[ $key ] ) && false !== strpos( (string) $key, '|' ) ) {
				list( $type, $slug )      = explode( '|', (string) $key, 2 );
				$by_op['removed'][ $key ] = self::bare( 'removed', $type, $slug );
			}
		}

		$changes = array();

		foreach ( $by_op as $ops ) {
			ksort( $ops, SORT_STRING );
			$changes = array_merge( $changes, array_values( $ops ) );
		}

		return $changes;
	}

	/**
	 * Split items into parts of at most $size items and $bytes encoded bytes (at least one part, even when empty).
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param int                            $size  Part size.
	 * @param int                            $bytes Max encoded item bytes per part (0 = no limit).
	 * @return array<int,array<int,array<string,mixed>>> Parts, 0-based.
	 */
	public static function parts( array $items, $size, $bytes = 0 ) {
		$size  = max( 1, (int) $size );
		$parts = array();
		$part  = array();
		$used  = 0;

		foreach ( array_values( $items ) as $item ) {
			$len = (int) $bytes > 0 ? self::bytes( $item ) + 1 : 0;

			if ( $part && ( count( $part ) >= $size || ( (int) $bytes > 0 && $used + $len > (int) $bytes ) ) ) {
				$parts[] = $part;
				$part    = array();
				$used    = 0;
			}

			$part[] = $item;
			$used  += $len;
		}

		if ( $part ) {
			$parts[] = $part;
		}

		return $parts ? $parts : array( array() );
	}

	/**
	 * Encoded size of one item or change as it goes on the wire.
	 *
	 * @param array<string,mixed> $item Item or change.
	 * @return int Bytes.
	 */
	public static function bytes( array $item ) {
		return strlen( (string) wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Op without the item body.
	 *
	 * @param string $op   Op.
	 * @param string $type Type.
	 * @param string $slug Slug.
	 * @return array<string,string> Change.
	 */
	private static function bare( $op, $type, $slug ) {
		return array(
			'op'   => $op,
			'type' => (string) $type,
			'slug' => (string) $slug,
		);
	}
}
