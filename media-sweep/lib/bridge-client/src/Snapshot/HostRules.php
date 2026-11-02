<?php
/**
 * Managed-host patterns (11 section 2.2); the same RULES array lives in the server's src/Sites/HostRules.php.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Snapshot;

/**
 * Data plus one matcher; first match wins, reason is 'host_' . name.
 */
final class HostRules {

	/**
	 * Host rules in match order, copied from the server's src/Sites/HostRules.php (sources from spike S20).
	 *
	 * @var array<int,array{name:string,host:string,label_prefix:string,environment:string}>
	 */
	public const RULES = array(
		// Source: https://wpengine.com/support/environments/ (temporary CNAME `myprdenv.wpenginepowered.com`).
		array(
			'name'         => 'wpengine',
			'host'         => 'wpenginepowered.com',
			'label_prefix' => '',
			'environment'  => 'staging',
		),
		// Source: https://kinsta.com/docs/staging-environment/ (`stg-sitename-environmentname.kinsta.cloud`).
		array(
			'name'         => 'kinsta',
			'host'         => 'kinsta.cloud',
			'label_prefix' => '',
			'environment'  => 'staging',
		),
		// Source: https://feedback.cloudways.com/forums/203824-product-improvements-flexible/suggestions/6201044-ability-to-change-app-name-url-prior-to-launch (Cloudways staff: "our own URL (cloudwaysapps.com domain)").
		array(
			'name'         => 'cloudways',
			'host'         => 'cloudwaysapps.com',
			'label_prefix' => '',
			'environment'  => 'staging',
		),
		// Source: https://docs.pantheon.io/guides/domains/platform-domains (`dev-site-name.pantheonsite.io`).
		array(
			'name'         => 'pantheon',
			'host'         => 'pantheonsite.io',
			'label_prefix' => 'dev-',
			'environment'  => 'development',
		),
		// Source: https://docs.pantheon.io/guides/domains/platform-domains (`test-site-name.pantheonsite.io`).
		array(
			'name'         => 'pantheon',
			'host'         => 'pantheonsite.io',
			'label_prefix' => 'test-',
			'environment'  => 'staging',
		),
		// Source: https://docs.pantheon.io/guides/domains/platform-domains (`live-site-name.pantheonsite.io`).
		array(
			'name'         => 'pantheon',
			'host'         => 'pantheonsite.io',
			'label_prefix' => 'live-',
			'environment'  => 'production',
		),
		// Source: https://getflywheel.com/wordpress-support/what-is-a-temporary-url-and-how-do-i-change-it/ ("temporary domains reside at either flywheelsites.com or mysites.io").
		array(
			'name'         => 'flywheel',
			'host'         => 'flywheelsites.com',
			'label_prefix' => '',
			'environment'  => 'staging',
		),
		// Source: https://www.godaddy.com/help/why-use-a-temporary-domain-name-with-managed-hosting-for-wordpress-8996 (`XXX.myftpupload.com`).
		array(
			'name'         => 'godaddy',
			'host'         => 'myftpupload.com',
			'label_prefix' => '',
			'environment'  => 'staging',
		),
		// Source: https://docs.instawp.com/faqs/blocked-by-firewall-or-antivirus ("*.instawp.xyz", suffix "instawp.link or instawp.co").
		array(
			'name'         => 'instawp',
			'host'         => 'instawp.xyz',
			'label_prefix' => '',
			'environment'  => 'development',
		),
		// Source: https://docs.instawp.com/faqs/blocked-by-firewall-or-antivirus (suffix domain instawp.co).
		array(
			'name'         => 'instawp',
			'host'         => 'instawp.co',
			'label_prefix' => '',
			'environment'  => 'development',
		),
		// Source: https://docs.instawp.com/faqs/blocked-by-firewall-or-antivirus (suffix domain instawp.link).
		array(
			'name'         => 'instawp',
			'host'         => 'instawp.link',
			'label_prefix' => '',
			'environment'  => 'development',
		),
		// Source: https://developer.wordpress.org/playground/ ("the Playground instance you get at https://playground.wordpress.net/").
		array(
			'name'         => 'playground',
			'host'         => 'playground.wordpress.net',
			'label_prefix' => '',
			'environment'  => 'development',
		),
		// Source: https://ngrok.com/docs/gateway/domains/ (base domain `ngrok.io`).
		array(
			'name'         => 'tunnel',
			'host'         => 'ngrok.io',
			'label_prefix' => '',
			'environment'  => 'development',
		),
		// Source: https://ngrok.com/docs/gateway/domains/ (base domain `ngrok-free.app`, "Used by free accounts").
		array(
			'name'         => 'tunnel',
			'host'         => 'ngrok-free.app',
			'label_prefix' => '',
			'environment'  => 'development',
		),
		// Source: https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/ ("a temporary trycloudflare.com URL").
		array(
			'name'         => 'tunnel',
			'host'         => 'trycloudflare.com', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- a host name to match, nothing is loaded from it.
			'label_prefix' => '',
			'environment'  => 'development',
		),
	);

	/**
	 * First rule matching a host: the host equals the rule host or ends with '.' + it, and the first label has the prefix.
	 *
	 * @param string                $host  Lower-case host without port.
	 * @param array<int,array>|null $rules Rules to use (null = RULES).
	 * @return array{environment:string,reason:string}|null Match, or null.
	 */
	public static function match( $host, $rules = null ) {
		$host = strtolower( trim( (string) $host, " \t\n\r\0\x0B." ) );

		if ( '' === $host ) {
			return null;
		}

		$first = (string) strtok( $host, '.' );

		foreach ( null === $rules ? self::RULES : (array) $rules as $rule ) {
			$base = (string) $rule['host'];

			if ( $host !== $base && substr( $host, -strlen( '.' . $base ) ) !== '.' . $base ) {
				continue;
			}

			$prefix = isset( $rule['label_prefix'] ) ? (string) $rule['label_prefix'] : '';

			if ( '' !== $prefix && 0 !== strpos( $first, $prefix ) ) {
				continue;
			}

			return array(
				'environment' => (string) $rule['environment'],
				'reason'      => 'host_' . (string) $rule['name'],
			);
		}

		return null;
	}
}
