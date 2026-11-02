<?php
/**
 * Environment detection (11 section 2.1): production, staging, development or local, with the reason.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Snapshot;

/**
 * First match wins; an explicit production only wins over nothing.
 */
final class Environment {

	/**
	 * Hosts ending with one of these are local copies.
	 *
	 * @var array<int,string>
	 */
	const LOCAL_TLDS = array( '.local', '.test', '.localhost', '.invalid', '.example', '.lndo.site', '.ddev.site' );

	/**
	 * First host labels that mean a test copy.
	 *
	 * @var array<int,string>
	 */
	const ENV_LABELS = array( 'staging', 'stage', 'stg', 'dev', 'develop', 'test', 'testing', 'qa', 'uat', 'preview', 'sandbox' );

	/**
	 * Numbered env labels such as staging2.
	 */
	const ENV_LABEL_PATTERN = '/^(staging|stage|stg|dev|test|qa|uat)\d*$/';

	/**
	 * Environment values WordPress allows.
	 *
	 * @var array<int,string>
	 */
	const TYPES = array( 'production', 'staging', 'development', 'local' );

	/**
	 * Detect for this site.
	 *
	 * @return array{environment:string,reason:string} Result.
	 */
	public static function current() {
		$home = is_multisite() ? network_home_url() : home_url();

		return self::detect( (string) wp_parse_url( (string) $home, PHP_URL_HOST ), self::explicit_type(), defined( 'WP_LOCAL_DEV' ) && WP_LOCAL_DEV );
	}

	/**
	 * WP_ENVIRONMENT_TYPE when set explicitly (constant or env var), as read by wp_get_environment_type(); null when not set.
	 *
	 * @return string|null Type.
	 */
	public static function explicit_type() {
		if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) && false === getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
			return null;
		}

		return function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : null;
	}

	/**
	 * Pure detection from the inputs.
	 *
	 * @param string      $host      Site host (no port).
	 * @param string|null $explicit  Explicit WP_ENVIRONMENT_TYPE, or null.
	 * @param bool        $local_dev WP_LOCAL_DEV is true.
	 * @return array{environment:string,reason:string} Result.
	 */
	public static function detect( $host, $explicit = null, $local_dev = false ) {
		$host     = strtolower( trim( (string) $host, '[]. ' ) );
		$explicit = in_array( $explicit, self::TYPES, true ) ? $explicit : null;

		if ( null !== $explicit && 'production' !== $explicit ) {
			return self::result( $explicit, 'wp_env' );
		}

		if ( self::is_private( $host ) ) {
			return self::result( 'local', 'private_ip' );
		}

		foreach ( self::LOCAL_TLDS as $tld ) {
			if ( substr( $host, -strlen( $tld ) ) === $tld ) {
				return self::result( 'local', 'local_tld' );
			}
		}

		$rule = HostRules::match( $host );

		if ( null !== $rule ) {
			return self::result( $rule['environment'], $rule['reason'] );
		}

		$label = self::env_label( $host );

		if ( '' !== $label ) {
			return self::result( 0 === strpos( $label, 'dev' ) ? 'development' : 'staging', 'env_prefix' );
		}

		if ( $local_dev ) {
			return self::result( 'local', 'wp_local_dev' );
		}

		return self::result( 'production', null === $explicit ? 'default' : 'wp_env' );
	}

	/**
	 * Whether a host is localhost or an IP in a private or loopback range.
	 *
	 * @param string $host Host.
	 * @return bool True when private.
	 */
	public static function is_private( $host ) {
		if ( 'localhost' === $host ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip = (int) sprintf( '%u', ip2long( $host ) );

			foreach ( array( array( '127.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '172.16.0.0', 12 ), array( '192.168.0.0', 16 ) ) as $range ) {
				$mask = ( 0xFFFFFFFF << ( 32 - $range[1] ) ) & 0xFFFFFFFF;

				if ( ( $ip & $mask ) === ( (int) sprintf( '%u', ip2long( $range[0] ) ) & $mask ) ) {
					return true;
				}
			}

			return false;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = (string) inet_pton( $host );

			return inet_pton( '::1' ) === $bin || 0xfc === ( ord( $bin[0] ) & 0xfe );
		}

		return false;
	}

	/**
	 * The env label of a host with a subdomain ('' when none); a bare domain like test.com is not a test copy.
	 *
	 * @param string $host Host.
	 * @return string Label.
	 */
	public static function env_label( $host ) {
		$labels = explode( '.', (string) $host );

		if ( count( $labels ) < 3 ) {
			return '';
		}

		return in_array( $labels[0], self::ENV_LABELS, true ) || 1 === preg_match( self::ENV_LABEL_PATTERN, $labels[0] ) ? $labels[0] : '';
	}

	/**
	 * Result pair.
	 *
	 * @param string $environment Environment.
	 * @param string $reason      Reason.
	 * @return array{environment:string,reason:string} Result.
	 */
	private static function result( $environment, $reason ) {
		return array(
			'environment' => $environment,
			'reason'      => $reason,
		);
	}
}
