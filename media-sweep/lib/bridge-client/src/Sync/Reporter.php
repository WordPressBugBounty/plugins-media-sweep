<?php
/**
 * POST /v1/report: heartbeat snapshot and/or queued events.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Sync;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Scheduler;
use WPCreatix\BridgeClient\Snapshot\Snapshot;
use WPCreatix\BridgeClient\Transport;

/**
 * Sends reports, splits on 413, and re-registers on 401 or a reregister action.
 */
final class Reporter {

	/**
	 * Most requests one report() call may make.
	 */
	const MAX_ROUNDS = 4;

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Reporter for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Send a report now.
	 *
	 * @param bool $heartbeat Whether to include the snapshot (usage) and move the next heartbeat.
	 * @param int  $timeout   HTTP timeout.
	 * @param bool $user      User action: a queued consent.withdrawn may pass a slow_down.
	 * @return string ok, nothing, unregistered, blocked, backoff, failed.
	 */
	public function report( $heartbeat, $timeout = Config::TIMEOUT_CRON, $user = false ) {
		$client = $this->client;
		$store  = $client->store();

		if ( ! $store->registered() ) {
			return 'unregistered';
		}

		if ( '' !== $client->blocked() ) {
			return 'blocked';
		}

		Queue::prune( $store->usage(), $client->now() );

		if ( (int) $store->get( 'backoff_until' ) > $client->now() && ! ( $user && $this->has_withdraw() ) ) {
			return 'backoff';
		}

		$heartbeat  = $heartbeat && $store->consented();
		$limit      = Config::MAX_EVENTS;
		$reregister = null;

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			$events = array_slice( Queue::all(), 0, $limit );

			if ( ! $events && ! $heartbeat ) {
				return $this->reregister( $reregister, $timeout );
			}

			$body   = $this->fit( $heartbeat, $events );
			$events = $body['events'];
			$limit  = $events ? count( $events ) : $limit;

			$client->remember_payload( $body );
			$reply = $client->transport()->post( 'report', $body, (string) $store->get( 'token' ), $timeout );

			if ( $reply->ok() ) {
				Queue::remove( self::ids( $events ) );
				$asked = Replies::ok( $client, $reply->body, $heartbeat );

				// Queued consent changes go out on this uid first; the new uid would never carry them.
				if ( null !== $asked ) {
					$reregister = $asked;
				}

				$heartbeat = false;
				continue;
			}

			$what = Replies::error( $client, $reply );

			if ( 'drop' === $what ) {
				Queue::remove( self::ids( $events ) );

				// A refused heartbeat is not retried before the next weekly slot (02 section 6).
				if ( $heartbeat ) {
					$store->set(
						array(
							'next_heartbeat_at' => Scheduler::next_slot( (string) $store->get( 'uid' ), $client->now() + (int) floor( Config::WEEK / 2 ) ),
							'pending_report_at' => 0,
						)
					);
				}

				return 'failed';
			}

			if ( 'split' === $what && count( $events ) > 1 ) {
				$limit = max( 1, (int) floor( count( $events ) / 2 ) );
				continue;
			}

			if ( 'split' === $what ) {
				Queue::remove( self::ids( $events ) );
				return 'failed';
			}

			if ( 'token_lost' === $what ) {
				$result = $client->registrar()->register( 'token_lost', '', $timeout );

				// Only a register that worked (or was refused for good) uses up the once-per-30-days try.
				if ( 'ok' === $result || '' !== (string) $store->get( 'register_dropped_sig' ) ) {
					$store->set( array( 'last_401_reregister_at' => $client->now() ) );
				}
			}

			return 'failed';
		}

		return $this->reregister( $reregister, $timeout );
	}

	/**
	 * Run a re-register the server asked for, once no consent event waits on the current uid.
	 *
	 * @param string|null $reason  Reregister reason, or null.
	 * @param int         $timeout HTTP timeout.
	 * @return string ok.
	 */
	private function reregister( $reason, $timeout ) {
		if ( null !== $reason && ! Queue::has_consent() ) {
			$this->client->registrar()->register( $reason, '', $timeout );
		}

		return 'ok';
	}

	/**
	 * Fire queued events without waiting (deactivate, uninstall); they stay queued and the server dedupes by id.
	 *
	 * @return void
	 */
	public function fire() {
		$client = $this->client;
		$store  = $client->store();

		if ( ! $store->registered() || '' !== $client->blocked() || (int) $store->get( 'backoff_until' ) > $client->now() ) {
			return;
		}

		$events = array_slice( Queue::all(), 0, Config::MAX_EVENTS );

		if ( ! $events ) {
			return;
		}

		$body = $this->body( false, $events );
		$client->remember_payload( $body );
		$client->transport()->fire( 'report', $body, (string) $store->get( 'token' ) );
	}

	/**
	 * A body under 64 KB: halve the events until it fits (one event always goes).
	 *
	 * @param bool                           $heartbeat Whether this is a heartbeat.
	 * @param array<int,array<string,mixed>> $events    Events.
	 * @return array<string,mixed> Body.
	 */
	private function fit( $heartbeat, array $events ) {
		$body  = $this->body( $heartbeat, $events );
		$count = count( $events );
		$size  = strlen( Transport::encode( $body ) );

		while ( $count > 1 && $size > Config::MAX_BODY ) {
			$count  = (int) floor( $count / 2 );
			$events = array_slice( $events, 0, $count );
			$body   = $this->body( $heartbeat, $events );
			$size   = strlen( Transport::encode( $body ) );
		}

		return $body;
	}

	/**
	 * Build a report body; the snapshot only with usage consent.
	 *
	 * @param bool                           $heartbeat Whether this is a heartbeat.
	 * @param array<int,array<string,mixed>> $events    Events.
	 * @return array<string,mixed> Body.
	 */
	private function body( $heartbeat, array $events ) {
		$client   = $this->client;
		$store    = $client->store();
		$snapshot = $heartbeat && $store->usage() ? Snapshot::build( $store, $client->products(), $client->now() ) : null;

		return Bodies::report( (string) $store->get( 'uid' ), gmdate( 'Y-m-d\TH:i:s\Z', $client->now() ), $snapshot, $events );
	}

	/**
	 * Whether a consent.withdrawn event is waiting.
	 *
	 * @return bool True when queued.
	 */
	private function has_withdraw() {
		foreach ( Queue::all() as $event ) {
			if ( isset( $event['type'] ) && 'consent.withdrawn' === $event['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Ids of events.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return array<int,string> Ids.
	 */
	private static function ids( array $events ) {
		$ids = array();

		foreach ( $events as $event ) {
			$ids[] = isset( $event['id'] ) ? (string) $event['id'] : '';
		}

		return $ids;
	}
}
