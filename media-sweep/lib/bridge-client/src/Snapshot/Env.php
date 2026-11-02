<?php
/**
 * The env part of a snapshot: only the allowlisted fields in 07 (data inventory).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Snapshot;

/**
 * Never reads site title, users, paths, DB names, hostnames, IPs or content.
 */
final class Env {

	/**
	 * Fixed flag list: flag => plugin folders (02 section 4.3, bits in server Sites/Flags.php).
	 *
	 * @var array<string,array<int,string>>
	 */
	const FLAGS = array(
		'woocommerce'               => array( 'woocommerce' ),
		'woocommerce_subscriptions' => array( 'woocommerce-subscriptions' ),
		'wpml'                      => array( 'sitepress-multilingual-cms' ),
		'polylang'                  => array( 'polylang', 'polylang-pro' ),
		'translatepress'            => array( 'translatepress-multilingual' ),
		'rank_math'                 => array( 'seo-by-rank-math' ),
		'yoast'                     => array( 'wordpress-seo', 'wordpress-seo-premium' ),
		'wp_rocket'                 => array( 'wp-rocket' ),
		'litespeed_cache'           => array( 'litespeed-cache' ),
		'fluentcrm'                 => array( 'fluent-crm' ),
	);

	/**
	 * Fixed builder list: builder => plugin folders; the first active one wins.
	 *
	 * @var array<string,array<int,string>>
	 */
	const BUILDERS = array(
		'elementor'      => array( 'elementor' ),
		'divi'           => array( 'divi-builder' ),
		'beaver-builder' => array( 'bb-plugin', 'beaver-builder-lite-version' ),
		'wpbakery'       => array( 'js_composer' ),
		'oxygen'         => array( 'oxygen' ),
		'breakdance'     => array( 'breakdance' ),
		'brizy'          => array( 'brizy' ),
	);

	/**
	 * Themes that are page builders: template slug => builder.
	 *
	 * @var array<string,string>
	 */
	const BUILDER_THEMES = array(
		'divi'   => 'divi',
		'extra'  => 'divi',
		'bricks' => 'bricks',
	);

	/**
	 * Build the env object.
	 *
	 * @param string      $server_hint Server name seen on an earlier web request ('' when unknown).
	 * @param string|null $ext_hash    Acked plugin and theme list hash (only when the usage text covers it).
	 * @return array<string,mixed> Env.
	 */
	public static function build( $server_hint = '', $ext_hash = null ) {
		global $wpdb;

		$folders = self::active_folders();
		$woo     = in_array( 'woocommerce', $folders, true );
		$env     = Environment::current();

		return self::assemble(
			array(
				'wp'          => self::version( (string) get_bloginfo( 'version' ) ),
				'php'         => self::version( PHP_VERSION ),
				'mysql'       => self::version( is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '' ),
				'server'      => '' !== self::server() ? self::server() : ( '' !== $server_hint ? $server_hint : 'other' ),
				'locale'      => substr( (string) get_locale(), 0, 20 ),
				'theme'       => substr( (string) preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) get_stylesheet() ), 0, 64 ),
				'env_type'    => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production',
				'environment' => $env['environment'],
				'env_reason'  => $env['reason'],
				'ext_hash'    => $ext_hash,
				'multisite'   => is_multisite(),
				'subsites'    => is_multisite() && function_exists( 'get_blog_count' ) ? Buckets::count( (int) get_blog_count() ) : null,
				'builder'     => self::builder( $folders ),
				'flags'       => self::flags( $folders ),
				'counts'      => self::counts( $woo ),
				'woo'         => $woo ? self::woo() : null,
			)
		);
	}

	/**
	 * Env object in wire order from collected values; ext_hash is left out when empty.
	 *
	 * @param array<string,mixed> $facts Values by key.
	 * @return array<string,mixed> Env.
	 */
	public static function assemble( array $facts ) {
		$env = array();

		foreach ( array( 'wp', 'php', 'mysql', 'server', 'locale', 'theme', 'env_type', 'environment', 'env_reason', 'ext_hash', 'multisite', 'subsites', 'builder', 'flags', 'counts', 'woo' ) as $key ) {
			if ( 'ext_hash' === $key && ( ! isset( $facts[ $key ] ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', (string) $facts[ $key ] ) ) ) {
				continue;
			}

			$env[ $key ] = array_key_exists( $key, $facts ) ? $facts[ $key ] : null;
		}

		return $env;
	}

	/**
	 * Web server family from SERVER_SOFTWARE ('' on CLI).
	 *
	 * @return string nginx, apache, litespeed, other or ''.
	 */
	public static function server() {
		$soft = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		if ( '' === $soft ) {
			return '';
		}

		foreach ( array( 'nginx', 'apache', 'litespeed' ) as $name ) {
			if ( false !== strpos( $soft, $name ) ) {
				return $name;
			}
		}

		return 'other';
	}

	/**
	 * Folders of plugins active on this site or network-wide.
	 *
	 * @return array<int,string> Folder names.
	 */
	public static function active_folders() {
		$basenames = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$basenames = array_merge( $basenames, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$folders = array();

		foreach ( $basenames as $basename ) {
			$folders[] = strtolower( dirname( (string) $basename ) );
		}

		return array_values( array_unique( $folders ) );
	}

	/**
	 * Flags from the fixed list, plus fse_theme.
	 *
	 * @param array<int,string> $folders Active plugin folders.
	 * @return array<int,string> Flag names.
	 */
	public static function flags( array $folders ) {
		$flags = array();

		foreach ( self::FLAGS as $flag => $candidates ) {
			if ( array_intersect( $candidates, $folders ) ) {
				$flags[] = $flag;
			}
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$flags[] = 'fse_theme';
		}

		return $flags;
	}

	/**
	 * Page builder from the fixed list, or null.
	 *
	 * @param array<int,string> $folders Active plugin folders.
	 * @return string|null Builder.
	 */
	public static function builder( array $folders ) {
		foreach ( self::BUILDERS as $builder => $candidates ) {
			if ( array_intersect( $candidates, $folders ) ) {
				return $builder;
			}
		}

		$template = strtolower( (string) get_template() );

		return isset( self::BUILDER_THEMES[ $template ] ) ? self::BUILDER_THEMES[ $template ] : null;
	}

	/**
	 * Bucketed content counts.
	 *
	 * @param bool $woo Whether WooCommerce is active.
	 * @return array<string,string> Buckets.
	 */
	public static function counts( $woo ) {
		$counts = array( 'posts' => Buckets::count( self::published( 'post', 'publish' ) ) );

		if ( $woo ) {
			$counts['products'] = Buckets::count( self::published( 'product', 'publish' ) );
		}

		$counts['media'] = Buckets::count( self::published( 'attachment', 'inherit' ) );

		return $counts;
	}

	/**
	 * Store currency and country (WooCommerce only).
	 *
	 * @return array<string,string|null> Currency and country.
	 */
	public static function woo() {
		$currency = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		$country  = strtoupper( (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) );

		return array(
			'currency' => 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : null,
			'country'  => 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : null,
		);
	}

	/**
	 * Leading x.y.z of a version string, max 20 chars.
	 *
	 * @param string $raw Raw version.
	 * @return string Clean version.
	 */
	public static function version( $raw ) {
		return 1 === preg_match( '/^\d+(\.\d+){0,3}/', (string) $raw, $m ) ? substr( $m[0], 0, 20 ) : '';
	}

	/**
	 * Count of one post type in one status.
	 *
	 * @param string $type   Post type.
	 * @param string $status Status.
	 * @return int Count.
	 */
	private static function published( $type, $status ) {
		$counts = wp_count_posts( $type );

		return is_object( $counts ) && isset( $counts->$status ) ? (int) $counts->$status : 0;
	}
}
