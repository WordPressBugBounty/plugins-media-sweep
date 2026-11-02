<?php
/**
 * The plugins part of a snapshot: only our plugins (registered ones plus the fixed known list).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Snapshot;

use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Products;

/**
 * Never lists third-party plugins.
 */
final class Plugins {

	/**
	 * Max feature keys per plugin.
	 */
	const MAX_FEATURES = 20;

	/**
	 * Our plugins on this site, active or not.
	 *
	 * @param Products                          $products Registered products.
	 * @param array<string,array<string,mixed>> $records  Local records from Store (slug => version, installed_at).
	 * @return array<int,array<string,mixed>> Plugin rows.
	 */
	public static function build( Products $products, array $records ) {
		$rows = array();

		foreach ( $products->all() as $slug => $product ) {
			$rows[ $slug ] = self::row( $slug, $product['version'], self::is_active( $product['basename'] ), $records, true );
		}

		foreach ( self::installed_known() as $slug => $info ) {
			if ( ! isset( $rows[ $slug ] ) ) {
				$rows[ $slug ] = self::row( $slug, $info['version'], self::is_active( $info['basename'] ), $records, false );
			}
		}

		return array_values( $rows );
	}

	/**
	 * Known plugins found on disk: slug => basename and version.
	 *
	 * @return array<string,array{basename:string,version:string}> Found plugins.
	 */
	public static function installed_known() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found = array();

		foreach ( Config::KNOWN_PLUGINS as $slug => $folder ) {
			if ( ! is_dir( WP_PLUGIN_DIR . '/' . $folder ) ) {
				continue;
			}

			foreach ( get_plugins( '/' . $folder ) as $file => $data ) {
				$found[ $slug ] = array(
					'basename' => $folder . '/' . $file,
					'version'  => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				);
				break;
			}
		}

		return $found;
	}

	/**
	 * Whether a plugin is active here, network-wide, or (multisite) on any subsite.
	 *
	 * @param string $basename Plugin basename.
	 * @return bool True when active.
	 */
	public static function is_active( $basename ) {
		if ( in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		if ( ! is_multisite() ) {
			return false;
		}

		if ( isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $basename ] ) ) {
			return true;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
			)
		) as $blog_id ) {
			if ( in_array( $basename, (array) get_blog_option( (int) $blog_id, 'active_plugins', array() ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One plugin row.
	 *
	 * @param string                            $slug    Slug.
	 * @param string                            $version Version.
	 * @param bool                              $active  Whether active.
	 * @param array<string,array<string,mixed>> $records Local records.
	 * @param bool                              $loaded  Whether its code runs on this request (features come from it).
	 * @return array<string,mixed> Row.
	 */
	private static function row( $slug, $version, $active, array $records, $loaded ) {
		$installed = isset( $records[ $slug ]['installed_at'] ) && is_string( $records[ $slug ]['installed_at'] ) ? $records[ $slug ]['installed_at'] : null;

		return array(
			'slug'         => $slug,
			'version'      => Env::version( $version ),
			'status'       => $active ? 'active' : 'inactive',
			'installed_at' => $installed,
			'features'     => $loaded && $active ? self::features( $slug ) : new \stdClass(),
		);
	}

	/**
	 * Bucketed feature counters a plugin reports through the wpcreatix_bridge_client_features filter.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,string>|\stdClass Buckets (empty object when none).
	 */
	public static function features( $slug ) {
		$raw  = apply_filters( 'wpcreatix_bridge_client_features', array(), $slug );
		$safe = array();

		foreach ( is_array( $raw ) ? $raw : array() as $key => $value ) {
			if ( count( $safe ) >= self::MAX_FEATURES || ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_]{1,32}$/', $key ) ) {
				continue;
			}

			if ( is_int( $value ) || is_float( $value ) ) {
				$safe[ $key ] = Buckets::count( (int) $value );
			} elseif ( is_string( $value ) && in_array( $value, Buckets::COUNTS, true ) ) {
				$safe[ $key ] = $value;
			}
		}

		return $safe ? $safe : new \stdClass();
	}
}
