<?php
/**
 * Fixed client settings: endpoint, protocol, timeouts, names and allowlists.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Protocol 1 constants (docs/architecture/02-client-protocol.md).
 */
final class Config {

	/**
	 * Protocol number sent in every body.
	 */
	const PROTOCOL = 1;

	/**
	 * Protocol minor sent in every body (13 section 3): replies carry no marketing status.
	 */
	const PROTOCOL_MINOR = 2;

	/**
	 * Production base URL (D2); override with the WPCREATIX_BRIDGE_ENDPOINT constant.
	 */
	const ENDPOINT = 'https://connect.wpcreatix.com/v1/';

	/**
	 * Main state option (site option on multisite).
	 */
	const OPTION = 'wpcreatix_site';

	/**
	 * Event queue option.
	 */
	const QUEUE_OPTION = 'wpcreatix_site_queue';

	/**
	 * Register mutex option.
	 */
	const LOCK_OPTION = 'wpcreatix_site_lock';

	/**
	 * Plugin and theme list sync state option (site option on multisite).
	 */
	const EXT_OPTION = 'wpcreatix_site_ext';

	/**
	 * Max items or changes per /v1/extensions request.
	 */
	const EXT_BATCH = 100;

	/**
	 * Max plugins and themes sent per site.
	 */
	const EXT_MAX_ITEMS = 1000;

	/**
	 * Max encoded item bytes per /v1/extensions request (MAX_BODY less room for the envelope).
	 */
	const EXT_PART_BYTES = 61440;

	/**
	 * Days between full list syncs.
	 */
	const EXT_FULL_DAYS = 30;

	/**
	 * Most /v1/extensions requests one tick may make.
	 */
	const EXT_PER_TICK = 3;

	/**
	 * Seconds after Skip before the one opt-in notice may show.
	 */
	const NOTICE_DELAY = 604800;

	/**
	 * Newsletter box option: dismissed or subscribed (site-wide).
	 */
	const NEWSLETTER_OPTION = 'wpcreatix_newsletter_box';

	/**
	 * Max encoded bytes of a custom event's data.
	 */
	const CUSTOM_DATA_MAX = 2048;

	/**
	 * WP-Cron hook for the hourly tick.
	 */
	const CRON_HOOK = 'wpcreatix_bridge_client_tick';

	/**
	 * Ajax action and nonce name.
	 */
	const AJAX = 'wpcreatix_bridge_client';

	/**
	 * HTTP timeout for a user action (Allow, Subscribe, delete); room for one slow DNS retry on the server.
	 */
	const TIMEOUT_USER = 10;

	/**
	 * HTTP timeout for cron sends.
	 */
	const TIMEOUT_CRON = 3;

	/**
	 * HTTP timeout for non-blocking sends (deactivate, uninstall, feedback).
	 */
	const TIMEOUT_FIRE = 2;

	/**
	 * Max body bytes (D6).
	 */
	const MAX_BODY = 65536;

	/**
	 * Max events per request and in the queue (D6).
	 */
	const MAX_EVENTS = 50;

	/**
	 * Seconds in a week.
	 */
	const WEEK = 604800;

	/**
	 * Privacy policy URL shown on the card and settings.
	 */
	const PRIVACY_URL = 'https://wpcreatix.com/privacy-policy/';

	/**
	 * Privacy contact email shown on the card and settings.
	 */
	const PRIVACY_EMAIL = 'privacy@wpcreatix.com';

	/**
	 * Our plugins: slug => plugin folder, reported even when their own copy does not load.
	 *
	 * @var array<string,string>
	 */
	const KNOWN_PLUGINS = array(
		'media-sweep'              => 'media-sweep',
		'vidshop-for-woocommerce'  => 'vidshop-for-woocommerce',
		'wpcreatix-ai-sales-agent' => 'wpcreatix-ai-sales-agent',
		'viewmeter'                => 'viewmeter',
	);

	/**
	 * The API base URL with a trailing slash.
	 *
	 * @return string Base URL.
	 */
	public static function endpoint() {
		$base = defined( 'WPCREATIX_BRIDGE_ENDPOINT' ) && is_string( WPCREATIX_BRIDGE_ENDPOINT ) && '' !== WPCREATIX_BRIDGE_ENDPOINT ? WPCREATIX_BRIDGE_ENDPOINT : self::ENDPOINT;

		return rtrim( $base, '/' ) . '/';
	}
}
