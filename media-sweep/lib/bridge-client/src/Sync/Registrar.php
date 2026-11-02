<?php
/**
 * POST /v1/sites under the register mutex; one registration per site.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Sync;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Identity;
use WPCreatix\BridgeClient\Lock;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Snapshot\Snapshot;

/**
 * Register, retry a lost reply with the same uid, and re-register with a parent uid.
 */
final class Registrar {

	/**
	 * Valid register reasons.
	 *
	 * @var array<int,string>
	 */
	const REASONS = array( 'new', 'url_changed', 'token_lost', 'uid_taken', 'clone' );

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Registrar for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Register (or re-register) this site.
	 *
	 * @param string $reason  Register reason.
	 * @param string $via     Plugin slug that triggered it ('' = first product).
	 * @param int    $timeout HTTP timeout.
	 * @param bool   $user    User action (ignores backoff).
	 * @param int    $depth   Recursion guard for 409 and reregister.
	 * @return string ok, skipped, blocked, backoff, dropped, locked, failed.
	 */
	public function register( $reason = 'new', $via = '', $timeout = Config::TIMEOUT_CRON, $user = false, $depth = 0 ) {
		$client = $this->client;
		$store  = $client->store();

		if ( ! $store->consented() ) {
			$store->set( array( 'pending_register' => null ) );
			return 'skipped';
		}

		if ( '' !== $client->blocked() ) {
			return 'blocked';
		}

		if ( ! $user && (int) $store->get( 'backoff_until' ) > $client->now() ) {
			return 'backoff';
		}

		$dropped = (string) $store->get( 'register_dropped_sig' );

		if ( '' !== $dropped && ! $user && $dropped === $client->signature() ) {
			return 'dropped';
		}

		if ( '' !== $dropped ) {
			$store->set( array( 'register_dropped_sig' => '' ) );
		}

		if ( ! Lock::acquire( $client->now() ) ) {
			return 'locked';
		}

		try {
			$store->reload();

			// Another request registered while this one waited for the lock.
			if ( 'new' === $reason && $store->registered() && ! is_array( $store->get( 'pending_register' ) ) ) {
				return 'ok';
			}

			$pending = $this->pending( $reason, $via );
			$state   = $store->all();
			$usage   = $store->usage();
			$body    = Bodies::register( $state, $pending['uid'], $pending['parent_uid'], $pending['reason'], $client->lib(), $pending['via'], Identity::home(), $usage ? Snapshot::build( $store, $client->products(), $client->now() ) : null );

			$client->remember_payload( $body );
			$reply = $client->transport()->post( 'sites', $body, '', $timeout );
		} finally {
			Lock::release();
		}

		if ( $reply->ok() && isset( $reply->body['token'] ) && is_string( $reply->body['token'] ) && 1 === preg_match( '/^wcxb_[A-Za-z0-9_-]{43}$/', $reply->body['token'] ) ) {
			return $this->registered( $pending, $reply->body, $timeout, $depth );
		}

		if ( $reply->ok() ) {
			$store->set( array( 'last_error' => 'register reply without a valid token' ) );
			Replies::backoff( $client, 0 );
			return 'failed';
		}

		return $this->failed( $pending, Replies::error( $client, $reply ), $timeout, $user, $depth );
	}

	/**
	 * The pending registration to send, created once so a lost reply is retried with the same uid.
	 *
	 * @param string $reason Register reason.
	 * @param string $via    Plugin slug.
	 * @return array{uid:string,parent_uid:string|null,reason:string,via:string} Pending registration.
	 */
	private function pending( $reason, $via ) {
		$store   = $this->client->store();
		$pending = $store->get( 'pending_register' );

		if ( is_array( $pending ) && isset( $pending['uid'] ) && Identity::is_uid( $pending['uid'] ) ) {
			return array(
				'uid'        => (string) $pending['uid'],
				'parent_uid' => isset( $pending['parent_uid'] ) && Identity::is_uid( $pending['parent_uid'] ) ? (string) $pending['parent_uid'] : null,
				'reason'     => isset( $pending['reason'] ) && in_array( $pending['reason'], self::REASONS, true ) ? (string) $pending['reason'] : 'new',
				'via'        => isset( $pending['via'] ) ? (string) $pending['via'] : $this->via( $via ),
			);
		}

		$reason = in_array( $reason, self::REASONS, true ) ? $reason : 'new';
		$old    = (string) $store->get( 'uid' );

		// A never-registered site keeps the uid it showed in the preview.
		$uid    = 'new' === $reason && Identity::is_uid( $old ) && ! $store->registered() && ! $store->get( 'gone' ) ? $old : Identity::new_uid();
		$parent = 'new' !== $reason && Identity::is_uid( $old ) ? $old : null;

		$pending = array(
			'uid'        => $uid,
			'parent_uid' => $parent,
			'reason'     => $reason,
			'via'        => $this->via( $via ),
		);

		$store->set(
			array(
				'pending_register' => $pending,
				'gone'             => false,
			)
		);

		return $pending;
	}

	/**
	 * Store the new identity after a 201.
	 *
	 * @param array<string,mixed> $pending Pending registration.
	 * @param array<string,mixed> $body    Reply body.
	 * @param int                 $timeout HTTP timeout.
	 * @param int                 $depth   Recursion guard.
	 * @return string ok.
	 */
	private function registered( array $pending, array $body, $timeout, $depth ) {
		$client = $this->client;
		$store  = $client->store();

		$store->set(
			array(
				'uid'              => $pending['uid'],
				'token'            => (string) $body['token'],
				'registered_home'  => Identity::home(),
				'registered_at'    => $client->now(),
				'pending_register' => null,
				'gone'             => false,
			)
		);

		// The register body already carried consent and the snapshot.
		Queue::clear();
		$client->lifecycle()->record_versions();

		$reregister = Replies::ok( $client, $body, true );

		if ( null !== $reregister && $depth < 1 ) {
			$this->register( $reregister, $pending['via'], $timeout, false, $depth + 1 );
		}

		return 'ok';
	}

	/**
	 * Follow up on an error reply.
	 *
	 * @param array<string,mixed> $pending Pending registration.
	 * @param string              $what    Outcome from Replies::error().
	 * @param int                 $timeout HTTP timeout.
	 * @param bool                $user    User action.
	 * @param int                 $depth   Recursion guard.
	 * @return string Outcome.
	 */
	private function failed( array $pending, $what, $timeout, $user, $depth ) {
		$store = $this->client->store();

		if ( 'uid_taken' === $what && $depth < 1 ) {
			$store->set(
				array(
					'uid'              => $pending['uid'],
					'pending_register' => null,
				)
			);

			return $this->register( 'uid_taken', $pending['via'], $timeout, $user, $depth + 1 );
		}

		// A bad request is never retried; the tick waits for a user action or a version change.
		if ( 'drop' === $what || 'uid_taken' === $what ) {
			$store->set(
				array(
					'pending_register'     => null,
					'register_dropped_sig' => $this->client->signature(),
				)
			);
		}

		return 'failed';
	}

	/**
	 * Slug sent as client.via.
	 *
	 * @param string $via Requested slug.
	 * @return string Slug.
	 */
	private function via( $via ) {
		if ( '' !== (string) $via ) {
			return (string) $via;
		}

		$first = $this->client->products()->first();

		return null === $first ? '' : (string) $first['slug'];
	}
}
