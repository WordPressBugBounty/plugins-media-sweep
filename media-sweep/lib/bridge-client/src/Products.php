<?php
/**
 * Our plugins that registered with wpcreatix_bridge_client_product() on this request.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Normalized product list keyed by slug.
 */
final class Products {

	/**
	 * Normalized products, or null before the first read.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private $list = null;

	/**
	 * Raw registrations to use instead of the global (tests).
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private $raw;

	/**
	 * Raw registrations counted on the last read.
	 *
	 * @var int
	 */
	private $seen = 0;

	/**
	 * Products from the given raw list, or from the global registry.
	 *
	 * @param array<int,array<string,mixed>>|null $raw Raw registrations (tests).
	 */
	public function __construct( $raw = null ) {
		$this->raw = $raw;
	}

	/**
	 * All products keyed by slug (first registration of a slug wins).
	 *
	 * @return array<string,array<string,mixed>> Products.
	 */
	public function all() {
		if ( null !== $this->list && null === $this->raw ) {
			$count = isset( $GLOBALS['wpcreatix_bridge_client_products'] ) ? count( (array) $GLOBALS['wpcreatix_bridge_client_products'] ) : 0;

			// A plugin activated mid-request may register late.
			if ( $count === $this->seen ) {
				return $this->list;
			}
		}

		$raw        = null !== $this->raw ? $this->raw : ( isset( $GLOBALS['wpcreatix_bridge_client_products'] ) ? (array) $GLOBALS['wpcreatix_bridge_client_products'] : array() );
		$this->seen = count( $raw );
		$this->list = array();

		foreach ( $raw as $product ) {
			$product = self::normalize( $product );

			if ( null !== $product && ! isset( $this->list[ $product['slug'] ] ) ) {
				$this->list[ $product['slug'] ] = $product;
			}
		}

		return $this->list;
	}

	/**
	 * One product by slug.
	 *
	 * @param string $slug Slug.
	 * @return array<string,mixed>|null Product.
	 */
	public function get( $slug ) {
		$all = $this->all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Product whose main file has this basename.
	 *
	 * @param string $basename Plugin basename, e.g. media-sweep/media-sweep.php.
	 * @return array<string,mixed>|null Product.
	 */
	public function by_basename( $basename ) {
		foreach ( $this->all() as $product ) {
			if ( $product['basename'] === $basename ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Product that owns an admin screen id.
	 *
	 * @param string $screen_id Screen id.
	 * @return array<string,mixed>|null Product.
	 */
	public function by_screen( $screen_id ) {
		foreach ( $this->all() as $product ) {
			if ( in_array( $screen_id, $product['screens'], true ) ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * First product, used as the "via" when nothing better is known.
	 *
	 * @return array<string,mixed>|null Product.
	 */
	public function first() {
		$all = $this->all();

		return $all ? reset( $all ) : null;
	}

	/**
	 * Fingerprint of every product version; changes when any plugin updates.
	 *
	 * @param string $lib Client lib version.
	 * @return string Signature.
	 */
	public function signature( $lib ) {
		$parts = array( 'lib:' . $lib );

		foreach ( $this->all() as $slug => $product ) {
			$parts[] = $slug . ':' . $product['version'];
		}

		sort( $parts );

		return implode( '|', $parts );
	}

	/**
	 * Whether a stop taken at one signature lifts now: a stopped plugin changed version or the lib went up.
	 *
	 * @param string $stored  Signature saved with the stop.
	 * @param string $current Signature now.
	 * @return bool True when the stop lifts; a plugin added or removed alone does not lift it.
	 */
	public static function stop_lifts( $stored, $current ) {
		$then = self::parse_signature( $stored );
		$now  = self::parse_signature( $current );

		if ( ! isset( $then['lib'] ) ) {
			return $stored !== $current;
		}

		if ( isset( $now['lib'] ) && version_compare( $now['lib'], $then['lib'], '>' ) ) {
			return true;
		}

		foreach ( $then as $slug => $version ) {
			if ( 'lib' !== $slug && isset( $now[ $slug ] ) && $now[ $slug ] !== $version ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Slug to version map of a signature.
	 *
	 * @param string $signature Signature.
	 * @return array<string,string> Map, `lib` included.
	 */
	private static function parse_signature( $signature ) {
		$map = array();

		foreach ( explode( '|', (string) $signature ) as $part ) {
			$pos = strrpos( $part, ':' );

			if ( false !== $pos ) {
				$map[ substr( $part, 0, $pos ) ] = substr( $part, $pos + 1 );
			}
		}

		return $map;
	}

	/**
	 * Clean one raw registration; null when unusable.
	 *
	 * @param mixed $product Raw array.
	 * @return array<string,mixed>|null Normalized product.
	 */
	public static function normalize( $product ) {
		if ( ! is_array( $product ) || empty( $product['slug'] ) || empty( $product['file'] ) ) {
			return null;
		}

		$slug = strtolower( (string) $product['slug'] );

		if ( 1 !== preg_match( '/^[a-z0-9-]{1,40}$/', $slug ) ) {
			return null;
		}

		$file    = (string) $product['file'];
		$version = isset( $product['version'] ) ? (string) $product['version'] : self::header_version( $file );

		return array(
			'slug'     => $slug,
			'file'     => $file,
			'basename' => plugin_basename( $file ),
			'name'     => isset( $product['name'] ) ? (string) $product['name'] : $slug,
			'version'  => $version,
			'screens'  => isset( $product['screens'] ) ? array_values( array_map( 'strval', (array) $product['screens'] ) ) : array(),
			'card'     => isset( $product['card'] ) && 'manual' === $product['card'] ? 'manual' : 'auto',
			'icon'     => isset( $product['icon'] ) && is_string( $product['icon'] ) ? esc_url_raw( $product['icon'] ) : '',
		);
	}

	/**
	 * Version from the plugin header.
	 *
	 * @param string $file Main plugin file.
	 * @return string Version or empty.
	 */
	private static function header_version( $file ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$data = get_file_data( $file, array( 'Version' => 'Version' ) );

		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}
}
