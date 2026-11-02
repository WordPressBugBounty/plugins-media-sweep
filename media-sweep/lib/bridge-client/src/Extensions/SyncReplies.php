<?php
/**
 * Replies to /v1/extensions (11 section 3.4): ack, 409 full sync, 403 consent_scope stop, backoff.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Extensions;

use WPCreatix\BridgeClient\Backoff;
use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Response;
use WPCreatix\BridgeClient\Sync\Replies;

/**
 * Turns an extensions reply into sync state changes.
 */
final class SyncReplies {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Reply handling for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * A 2xx: clear the failure count and apply reply actions.
	 *
	 * @param Response $reply Reply.
	 * @return void
	 */
	public function ok( Response $reply ) {
		State::patch(
			array(
				'failures'      => 0,
				'backoff_until' => 0,
			)
		);

		Replies::actions( $this->client, isset( $reply->body['actions'] ) && is_array( $reply->body['actions'] ) ? $reply->body['actions'] : array() );
	}

	/**
	 * The server holds the list now: remember it as acked.
	 *
	 * @param array<string,string> $hashes Item hashes.
	 * @param string               $hash   Our list hash.
	 * @param Response             $reply  Reply.
	 * @return void
	 */
	public function acked( array $hashes, $hash, Response $reply ) {
		$server = isset( $reply->body['list_hash'] ) ? (string) $reply->body['list_hash'] : '';

		State::patch(
			array(
				'acked_hash'   => 1 === preg_match( '/^[0-9a-f]{40}$/', $server ) ? $server : $hash,
				'acked'        => $hashes,
				'full'         => null,
				'dirty'        => false,
				'next_full_at' => $this->client->now() + Config::EXT_FULL_DAYS * 86400,
			)
		);
	}

	/**
	 * Error reply: 409 starts a full sync, 403 consent_scope stops until a new grant, others back off.
	 *
	 * @param Response $reply Reply.
	 * @return string next, scope or failed.
	 */
	public function failed( Response $reply ) {
		$client = $this->client;

		if ( 409 === $reply->status && 'ext_base_mismatch' === $reply->code() ) {
			State::patch(
				array(
					'acked_hash' => '',
					'acked'      => array(),
					'full'       => null,
				)
			);

			return 'next';
		}

		if ( 403 === $reply->status && 'consent_scope' === $reply->code() ) {
			$usage = $client->store()->get( 'usage' );

			State::patch(
				array(
					'scope_stop' => max( 1, (int) $usage['at'] ),
					'full'       => null,
				)
			);
			$client->store()->set( array( 'last_error' => '403 consent_scope' ) );

			return 'scope';
		}

		// Site-wide truths (gone, protocol) are applied like any other call.
		if ( 410 === $reply->status || 426 === $reply->status ) {
			Replies::error( $client, $reply );

			return 'failed';
		}

		$state    = State::read();
		$failures = (int) $state['failures'] + 1;

		State::patch(
			array(
				'failures'      => $failures,
				'backoff_until' => $client->now() + Backoff::delay( $failures, $reply->retry_after ),
			)
		);
		$client->store()->set( array( 'last_error' => 0 === $reply->status ? 'network: ' . substr( $reply->error, 0, 200 ) : $reply->status . ' ' . ( '' !== $reply->code() ? $reply->code() : 'error' ) ) );

		return 'failed';
	}
}
