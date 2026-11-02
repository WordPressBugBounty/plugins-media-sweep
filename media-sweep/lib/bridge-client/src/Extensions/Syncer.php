<?php
/**
 * Sends the plugin and theme list: full sync in parts, then diffs (11 sections 3.3 and 3.4).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Extensions;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Response;
use WPCreatix\BridgeClient\Sync\Bodies;

/**
 * Only runs under a usage text that covers extensions (D33); hooks only mark the list dirty.
 */
final class Syncer {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * List source for tests (callable returning Collector::collect() shape), null = real site.
	 *
	 * @var callable|null
	 */
	public $source = null;

	/**
	 * Reply handling.
	 *
	 * @var SyncReplies
	 */
	private $replies;

	/**
	 * Syncer for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client  = $client;
		$this->replies = new SyncReplies( $client );
	}

	/**
	 * Whether this site may send the list now.
	 *
	 * @return bool True when registered, usage covers extensions, not blocked and no 403 for this grant.
	 */
	public function active() {
		$store = $this->client->store();
		$usage = $store->get( 'usage' );

		if ( ! $store->registered() || ! $store->usage_covers( 'extensions' ) || '' !== $this->client->blocked() ) {
			return false;
		}

		$stop = (int) State::read()['scope_stop'];

		return 0 === $stop || $stop !== (int) $usage['at'];
	}

	/**
	 * A plugin or theme changed (hook callback): mark dirty, send nothing.
	 *
	 * @return void
	 */
	public function mark_dirty() {
		if ( $this->active() ) {
			State::patch( array( 'dirty' => true ) );
		}
	}

	/**
	 * Hourly tick: send a diff or the next missing full parts (max EXT_PER_TICK requests).
	 *
	 * @param bool $heartbeat Whether the weekly heartbeat is due.
	 * @return string off, paused, backoff, idle, same, sent, failed.
	 */
	public function tick( $heartbeat = false ) {
		if ( ! $this->active() ) {
			return 'off';
		}

		$now   = $this->client->now();
		$state = State::read();

		if ( (int) $state['paused_until'] > $now ) {
			return 'paused';
		}

		if ( (int) $state['backoff_until'] > $now ) {
			return 'backoff';
		}

		$list   = $this->collect();
		$hashes = Differ::hashes( $list['items'] );
		$hash   = Differ::list_hash( $hashes );
		$result = 'idle';

		for ( $requests = 0; $requests < Config::EXT_PER_TICK; $requests++ ) {
			$state = State::read();
			$full  = is_array( $state['full'] ) || '' === State::acked_hash() || (int) $state['next_full_at'] <= $now;

			if ( ! $full ) {
				if ( ! $state['dirty'] && ! $heartbeat ) {
					return $result;
				}

				if ( $hash === $state['acked_hash'] ) {
					State::patch( array( 'dirty' => false ) );
					return 'idle' === $result ? 'same' : $result;
				}

				$changes = Differ::diff( $list['items'], $state['acked'] );

				// A diff never spans requests; too many changes (or bytes) means a fresh full sync.
				if ( 1 === count( Differ::parts( $changes, Config::EXT_BATCH, Config::EXT_PART_BYTES ) ) ) {
					$result = $this->send_diff( $state, $hash, $hashes, $changes );

					if ( 'next' !== $result ) {
						return $result;
					}

					continue;
				}
			}

			$result = $this->send_part( $list, $hash, $hashes );

			if ( 'next' !== $result ) {
				return $result;
			}
		}

		return 'sent';
	}

	/**
	 * Every full sync body as it would be sent now (the card's "what is sent" preview).
	 *
	 * @param string $uid Site uid.
	 * @return array<int,array<string,mixed>> Bodies.
	 */
	public function preview( $uid ) {
		$list   = $this->collect();
		$hash   = Differ::list_hash( Differ::hashes( $list['items'] ) );
		$parts  = Differ::parts( $list['items'], Config::EXT_BATCH, Config::EXT_PART_BYTES );
		$bodies = array();

		foreach ( $parts as $i => $items ) {
			$bodies[] = Bodies::extensions_full( $uid, 'preview', gmdate( 'Y-m-d\TH:i:s\Z', $this->client->now() ), $i + 1, count( $parts ), $hash, $items, $list['truncated'] );
		}

		return $bodies;
	}

	/**
	 * The current list.
	 *
	 * @return array{items:array<int,array<string,mixed>>,truncated:bool} List.
	 */
	public function collect() {
		$list = null === $this->source ? Collector::collect() : call_user_func( $this->source );

		return array(
			'items'     => isset( $list['items'] ) ? array_values( (array) $list['items'] ) : array(),
			'truncated' => ! empty( $list['truncated'] ),
		);
	}

	/**
	 * Send the next missing part of the running full sync (starts one when needed).
	 *
	 * @param array{items:array<int,array<string,mixed>>,truncated:bool} $current Current list.
	 * @param string                                                     $hash   List hash.
	 * @param array<string,string>                                       $hashes Item hashes.
	 * @return string next (keep going), sent (done), or an error outcome.
	 */
	private function send_part( array $current, $hash, array $hashes ) {
		$state = State::read();
		$full  = $state['full'];
		$parts = Differ::parts( $current['items'], Config::EXT_BATCH, Config::EXT_PART_BYTES );

		// The list changed since the sync started: begin again with a new id.
		if ( ! is_array( $full ) || ! isset( $full['list_hash'] ) || $hash !== $full['list_hash'] || count( $parts ) !== (int) $full['parts'] ) {
			$full  = array(
				'sync_id'    => Queue::new_id(),
				'started_at' => $this->client->now(),
				'parts'      => count( $parts ),
				'sent'       => array(),
				'list_hash'  => $hash,
			);
			$state = State::patch( array( 'full' => $full ) );
		}

		$part = 0;

		for ( $n = 1; $n <= (int) $full['parts']; $n++ ) {
			if ( ! in_array( $n, array_map( 'intval', (array) $full['sent'] ), true ) ) {
				$part = $n;
				break;
			}
		}

		// Every part went out but the server did not complete it: start over.
		if ( 0 === $part ) {
			State::patch( array( 'full' => null ) );
			return 'next';
		}

		$body  = Bodies::extensions_full( (string) $this->client->store()->get( 'uid' ), (string) $full['sync_id'], gmdate( 'Y-m-d\TH:i:s\Z', (int) $full['started_at'] ), $part, (int) $full['parts'], $hash, $parts[ $part - 1 ], $current['truncated'] );
		$reply = $this->post( $body );

		if ( ! $reply->ok() ) {
			return $this->replies->failed( $reply );
		}

		$this->replies->ok( $reply );

		if ( ! empty( $reply->body['complete'] ) ) {
			$this->replies->acked( $hashes, $hash, $reply );
			return 'sent';
		}

		$sent = array_map( 'intval', (array) $full['sent'] );

		// A part the server lost shows up as "all sent, not complete", which starts a new sync.
		if ( isset( $reply->body['parts_seen'] ) && is_array( $reply->body['parts_seen'] ) ) {
			$sent = array_merge( $sent, array_map( 'intval', $reply->body['parts_seen'] ) );
		}

		$halt = $this->halted();

		// A stop or pause in this reply ends the tick; the part counts only when the server listed it.
		if ( '' === $halt ) {
			$sent[] = $part;
		}

		$full['sent'] = array_values( array_unique( array_filter( $sent ) ) );
		sort( $full['sent'] );
		State::patch( array( 'full' => $full ) );

		return '' === $halt ? 'next' : $halt;
	}

	/**
	 * Why sending must stop now (a stop or pause action in the last reply).
	 *
	 * @return string off, paused, or '' to keep going.
	 */
	private function halted() {
		if ( ! $this->active() ) {
			return 'off';
		}

		return (int) State::read()['paused_until'] > $this->client->now() ? 'paused' : '';
	}

	/**
	 * Send one diff.
	 *
	 * @param array<string,mixed>            $state   State.
	 * @param string                         $hash    New list hash.
	 * @param array<string,string>           $hashes  Item hashes.
	 * @param array<int,array<string,mixed>> $changes Changes.
	 * @return string sent, next (409: go full) or an error outcome.
	 */
	private function send_diff( array $state, $hash, array $hashes, array $changes ) {
		$body  = Bodies::extensions_diff( (string) $this->client->store()->get( 'uid' ), (string) $state['acked_hash'], $hash, $changes );
		$reply = $this->post( $body );

		if ( ! $reply->ok() ) {
			return $this->replies->failed( $reply );
		}

		$this->replies->ok( $reply );
		$this->replies->acked( $hashes, $hash, $reply );

		return 'sent';
	}

	/**
	 * POST one body and keep it for "Show last data sent".
	 *
	 * @param array<string,mixed> $body Body.
	 * @return Response Reply.
	 */
	private function post( array $body ) {
		$client = $this->client;

		$client->remember_payload( $body );

		return $client->transport()->post( 'extensions', $body, (string) $client->store()->get( 'token' ), Config::TIMEOUT_CRON );
	}
}
