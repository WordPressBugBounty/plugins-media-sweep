<?php
/**
 * Applies replies to local state: next heartbeat, actions (02 section 5) and errors (section 6); a marketing key is ignored.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Sync;

use WPCreatix\BridgeClient\Backoff;
use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Extensions\State;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Response;
use WPCreatix\BridgeClient\Scheduler;

/**
 * Turns a Response into state changes and tells the caller what to do next.
 */
final class Replies {

	/**
	 * Longest slow_down honoured (30 days).
	 */
	const MAX_SLOW_DOWN = 2592000;

	/**
	 * Apply a 2xx reply.
	 *
	 * @param Client              $client    Client.
	 * @param array<string,mixed> $body      Reply body.
	 * @param bool                $heartbeat Whether this call was a heartbeat (moves the next one).
	 * @return string|null Reregister reason when the server asked for it.
	 */
	public static function ok( Client $client, array $body, $heartbeat ) {
		$store   = $client->store();
		$now     = $client->now();
		$changes = array(
			'failures'   => 0,
			'last_error' => '',
		);

		if ( (int) $store->get( 'backoff_until' ) <= $now ) {
			$changes['backoff_until'] = 0;
		}

		if ( $heartbeat ) {
			$after                        = isset( $body['next_heartbeat_after'] ) && (int) $body['next_heartbeat_after'] >= 3600 ? (int) $body['next_heartbeat_after'] : Config::WEEK;
			$changes['next_heartbeat_at'] = Scheduler::next_slot( (string) $store->get( 'uid' ), $now + (int) floor( $after / 2 ) );
			$changes['pending_report_at'] = 0;
		}

		$store->set( $changes );

		return self::actions( $client, isset( $body['actions'] ) && is_array( $body['actions'] ) ? $body['actions'] : array() );
	}

	/**
	 * Apply the actions list.
	 *
	 * @param Client                         $client  Client.
	 * @param array<int,array<string,mixed>> $actions Actions.
	 * @return string|null Reregister reason, if asked.
	 */
	public static function actions( Client $client, array $actions ) {
		$store      = $client->store();
		$reregister = null;

		foreach ( $actions as $action ) {
			$type = is_array( $action ) && isset( $action['type'] ) ? (string) $action['type'] : '';

			if ( 'slow_down' === $type ) {
				$seconds = isset( $action['seconds'] ) ? min( self::MAX_SLOW_DOWN, max( 0, (int) $action['seconds'] ) ) : 86400;
				$store->set( array( 'backoff_until' => $client->now() + $seconds ) );
			} elseif ( 'stop' === $type ) {
				$store->set(
					array(
						'stopped'  => true,
						'stop_sig' => $client->signature(),
					)
				);
			} elseif ( 'reregister' === $type ) {
				$reason     = isset( $action['reason'] ) && in_array( $action['reason'], array( 'clone', 'url_changed', 'token_lost' ), true ) ? (string) $action['reason'] : 'clone';
				$reregister = $reason;
			} elseif ( 'extensions' === $type ) {
				self::extensions( $client, $action );
			}
		}

		return $reregister;
	}

	/**
	 * The extensions action: resync starts a full list sync on the next tick, pause stops sending for a while.
	 *
	 * @param Client              $client Client.
	 * @param array<string,mixed> $action Action.
	 * @return void
	 */
	public static function extensions( Client $client, array $action ) {
		$mode = isset( $action['mode'] ) ? (string) $action['mode'] : '';

		// A site on the v1 usage text never sends the list, so it keeps no sync record.
		if ( ! $client->store()->usage_covers( 'extensions' ) ) {
			return;
		}

		if ( 'resync' === $mode ) {
			State::patch(
				array(
					'acked_hash'   => '',
					'full'         => null,
					'next_full_at' => 0,
				)
			);
		} elseif ( 'pause' === $mode ) {
			$seconds = isset( $action['seconds'] ) ? min( self::MAX_SLOW_DOWN, max( 0, (int) $action['seconds'] ) ) : 86400;

			State::patch( array( 'paused_until' => $client->now() + $seconds ) );
		}
	}

	/**
	 * Apply an error reply; returns what the caller should do.
	 *
	 * @param Client   $client Client.
	 * @param Response $reply  Reply.
	 * @return string drop, token_lost, uid_taken, gone, split, protocol, backoff.
	 */
	public static function error( Client $client, Response $reply ) {
		$store = $client->store();
		$now   = $client->now();

		$store->set( array( 'last_error' => self::describe( $reply ) ) );

		switch ( $reply->status ) {
			case 400:
				return 'drop';

			case 401:
				if ( $now - (int) $store->get( 'last_401_reregister_at' ) > 30 * 86400 ) {
					return 'token_lost';
				}

				self::backoff( $client, 0 );
				return 'backoff';

			case 409:
				return 'uid_taken';

			case 410:
				self::gone( $client );
				return 'gone';

			case 413:
				return 'split';

			case 426:
				$store->set( array( 'protocol_stop_sig' => $client->signature() ) );
				return 'protocol';

			default:
				self::backoff( $client, $reply->retry_after );
				return 'backoff';
		}
	}

	/**
	 * Count a failure and set backoff_until.
	 *
	 * @param Client $client      Client.
	 * @param int    $retry_after Server Retry-After seconds.
	 * @return void
	 */
	public static function backoff( Client $client, $retry_after ) {
		$store    = $client->store();
		$failures = (int) $store->get( 'failures' ) + 1;

		$store->set(
			array(
				'failures'      => $failures,
				'backoff_until' => $client->now() + Backoff::delay( $failures, (int) $retry_after ),
			)
		);
	}

	/**
	 * 410: this uid is gone forever; consent is off until the user acts again.
	 *
	 * @param Client $client Client.
	 * @return void
	 */
	public static function gone( Client $client ) {
		$store = $client->store();

		$store->set(
			array(
				'gone'             => true,
				'token'            => '',
				'pending_register' => null,
			)
		);
		$store->patch( 'usage', array( 'granted' => false ) );
		Queue::clear();
		State::forget();
	}

	/**
	 * Short local log line for a failed call.
	 *
	 * @param Response $reply Reply.
	 * @return string Description.
	 */
	private static function describe( Response $reply ) {
		if ( 0 === $reply->status ) {
			return 'network: ' . substr( $reply->error, 0, 200 );
		}

		return $reply->status . ' ' . ( '' !== $reply->code() ? $reply->code() : 'error' );
	}
}
