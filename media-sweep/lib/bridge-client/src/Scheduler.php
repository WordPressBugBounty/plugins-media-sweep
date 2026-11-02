<?php
/**
 * When to send: hourly WP-Cron tick, weekly heartbeat at a seeded hour, admin-load fallback without cron.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Timing rules from 02 section 7.
 */
final class Scheduler {

	/**
	 * Without WP-Cron, send on an admin load once the last heartbeat is this old (9 days).
	 */
	const FALLBACK_AGE = 777600;

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Scheduler for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * First weekly slot (Monday 00:00 UTC + seeded hours) at or after a time.
	 *
	 * @param string $uid   Site uid.
	 * @param int    $after Unix time.
	 * @return int Unix time of the slot.
	 */
	public static function next_slot( $uid, $after ) {
		$after    = (int) $after;
		$midnight = $after - ( $after % 86400 );
		$monday   = $midnight - ( (int) gmdate( 'N', $after ) - 1 ) * 86400;
		$slot     = $monday + Identity::seeded_hour( $uid ) * 3600;

		while ( $slot < $after ) {
			$slot += Config::WEEK;
		}

		return $slot;
	}

	/**
	 * Whether this request may run the shared sender (main site only on multisite).
	 *
	 * @return bool True when allowed.
	 */
	public static function is_sender_site() {
		return ! is_multisite() || is_main_site();
	}

	/**
	 * Whether there is anything for the tick to do.
	 *
	 * @return bool True when the tick should stay scheduled.
	 */
	public function needed() {
		$store = $this->client->store();

		return $store->consented() || (bool) $store->get( 'erase_pending' ) || ( $store->registered() && Queue::all() );
	}

	/**
	 * Schedule or clear the hourly tick to match the state.
	 *
	 * @return void
	 */
	public function ensure() {
		if ( ! self::is_sender_site() ) {
			return;
		}

		$next = wp_next_scheduled( Config::CRON_HOOK );

		if ( $this->needed() && $this->client->products()->all() ) {
			if ( false === $next ) {
				wp_schedule_event( $this->client->now() + wp_rand( 60, 3600 ), 'hourly', Config::CRON_HOOK );
			}

			return;
		}

		if ( false !== $next ) {
			wp_clear_scheduled_hook( Config::CRON_HOOK );
		}
	}

	/**
	 * The hourly tick: retry, re-register on URL change, version reports, heartbeat, queued events.
	 *
	 * @return string What happened (for tests and logs).
	 */
	public function tick() {
		$client = $this->client;
		$store  = $client->store();

		if ( ! self::is_sender_site() ) {
			return 'not_sender';
		}

		$store->reload();

		if ( $store->get( 'erase_pending' ) ) {
			return 'erase:' . $client->eraser()->run();
		}

		if ( '' !== $client->blocked() ) {
			return 'blocked';
		}

		if ( ! $store->registered() ) {
			if ( ! $store->consented() ) {
				return 'idle';
			}

			$pending = $store->get( 'pending_register' );

			return 'register:' . $client->registrar()->register( is_array( $pending ) && isset( $pending['reason'] ) ? (string) $pending['reason'] : 'new' );
		}

		if ( Identity::url_changed( (string) $store->get( 'registered_home' ), Identity::home() ) ) {
			// Queued consent changes go out on the old uid first; the new uid would never carry them.
			if ( Queue::has_consent() ) {
				$flushed = $client->reporter()->report( false );

				if ( Queue::has_consent() ) {
					return 'report:' . $flushed;
				}
			}

			return 'register:' . $client->registrar()->register( 'url_changed' );
		}

		$client->lifecycle()->sync();

		$now       = $client->now();
		$heartbeat = (int) $store->get( 'next_heartbeat_at' ) <= $now;
		$done      = '';

		// The list goes after the consent events (a new grant must land first) and before the heartbeat (fresh ext_hash).
		if ( $store->usage_covers( 'extensions' ) ) {
			if ( Queue::has_consent() ) {
				$done = 'report:' . $client->reporter()->report( false );

				if ( Queue::has_consent() ) {
					return $done;
				}
			}

			$ext  = $client->syncer()->tick( $heartbeat );
			$done = '' === $done && in_array( $ext, array( 'sent', 'failed', 'scope' ), true ) ? 'extensions:' . $ext : $done;
		}

		$pending = (int) $store->get( 'pending_report_at' );
		$due     = $heartbeat || ( $pending > 0 && $pending <= $now );

		// Plugin events wait for a pending seeded delay (02 section 7); consent events never wait.
		if ( $due || Queue::has_consent() || ( 0 === $pending && Queue::all() ) ) {
			return 'report:' . $client->reporter()->report( $due );
		}

		return '' === $done ? 'idle' : $done;
	}

	/**
	 * Admin-load fallback when WP-Cron is disabled: run the tick after the response, at most once an hour.
	 *
	 * @return void
	 */
	public function maybe_fallback() {
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! self::is_sender_site() ) {
			return;
		}

		$store = $this->client->store();
		$last  = (int) $store->get( 'next_heartbeat_at' ) - Config::WEEK;

		if ( ! $store->consented() || $this->client->now() - $last < self::FALLBACK_AGE || get_transient( 'wpcreatix_bridge_client_fallback' ) ) {
			return;
		}

		set_transient( 'wpcreatix_bridge_client_fallback', 1, HOUR_IN_SECONDS );

		add_action(
			'shutdown',
			/**
			 * Run the tick after the page was sent.
			 *
			 * @return void
			 */
			function () {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}

				$this->client->safe(
					function () {
						$this->tick();
					}
				);
			}
		);
	}
}
