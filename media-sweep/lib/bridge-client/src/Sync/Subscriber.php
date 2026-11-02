<?php
/**
 * Newsletter card send: POST /v1/subscribe server-to-server (13 section 3.3).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Sync;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Consent\Consents;

/**
 * Sends the site uid and token only when this site shares usage data; never the site address or a snapshot.
 */
final class Subscriber {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Subscriber for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Send one subscribe request.
	 *
	 * @param string $email Email the person typed.
	 * @param string $slug  Plugin slug (the source).
	 * @return string subscribed, invalid_email, rate_limited, error.
	 */
	public function send( $email, $slug ) {
		$email = Consents::clean_email( $email );

		if ( '' === $email ) {
			return 'invalid_email';
		}

		$store = $this->client->store();
		$link  = $store->usage() && $store->registered() && '' === $this->client->blocked();
		$body  = Bodies::subscribe( (string) $slug, $email, Consents::language(), $this->client->lib(), $link ? (string) $store->get( 'uid' ) : '' );

		if ( '' === $body['text_version'] ) {
			return 'error';
		}

		$reply = $this->client->transport()->post( 'subscribe', $body, $link ? (string) $store->get( 'token' ) : '', Config::TIMEOUT_USER );

		// Only a 202 counts; the reply never says anything about this email.
		if ( 202 === $reply->status ) {
			return 'subscribed';
		}

		return 429 === $reply->status ? 'rate_limited' : 'error';
	}
}
