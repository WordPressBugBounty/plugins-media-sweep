<?php
/**
 * "Delete my data": POST /v1/erase, then clear local state; the newsletter subscription is never touched.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Sync;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Extensions\State;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Store;

/**
 * Sending stops locally at once; the server call is retried by the tick until it lands.
 */
final class Eraser {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Eraser for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Start an erase (user action).
	 *
	 * @return string ok or failed.
	 */
	public function start() {
		$client = $this->client;
		$store  = $client->store();

		if ( ! $store->registered() ) {
			$this->wipe();
			return 'ok';
		}

		// Nothing queued is worth sending right before the erase.
		Queue::clear();
		$store->patch( 'usage', array( 'granted' => false ) );
		$store->set( array( 'erase_pending' => true ) );
		State::forget();

		return $this->run( Config::TIMEOUT_USER, true );
	}

	/**
	 * Send the pending erase (user action or tick).
	 *
	 * @param int  $timeout HTTP timeout.
	 * @param bool $user    User action.
	 * @return string ok or failed.
	 */
	public function run( $timeout = Config::TIMEOUT_CRON, $user = false ) {
		$client = $this->client;
		$store  = $client->store();

		if ( ! $store->registered() ) {
			$this->wipe();
			return 'ok';
		}

		if ( ! $user && (int) $store->get( 'backoff_until' ) > $client->now() ) {
			return 'failed';
		}

		$body = Bodies::erase( (string) $store->get( 'uid' ) );
		$client->remember_payload( $body );
		$reply = $client->transport()->post( 'erase', $body, (string) $store->get( 'token' ), $timeout );

		if ( $reply->ok() || 410 === $reply->status ) {
			$this->wipe();
			return 'ok';
		}

		if ( 400 === $reply->status || 401 === $reply->status ) {
			$store->set( array( 'last_error' => $reply->status . ' ' . $reply->code() ) );
			Replies::backoff( $client, 0 );
			return 'failed';
		}

		Replies::error( $client, $reply );

		return 'failed';
	}

	/**
	 * Clear local state but keep the opt-in answers and the local plugin records.
	 *
	 * @return void
	 */
	public function wipe() {
		$store = $this->client->store();
		$keep  = array(
			'optin_answered_at' => (int) $store->get( 'optin_answered_at' ),
			'optin_skipped_at'  => (int) $store->get( 'optin_skipped_at' ),
			'notice_done'       => (bool) $store->get( 'notice_done' ),
			'plugins'           => $store->get( 'plugins' ),
			'server'            => (string) $store->get( 'server' ),
		);

		Queue::clear();
		State::forget();
		$store->forget();
		$store->set( array_merge( Store::defaults(), $keep ) );
	}
}
