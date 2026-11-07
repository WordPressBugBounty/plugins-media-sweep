<?php
/**
 * Usage consent changes from the opt-in screen, the notice and the settings toggle (13 section 2.4).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Extensions\State;
use WPCreatix\BridgeClient\Newsletter\Box;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Sync\Bodies;

/**
 * Turns a user's choice into local state plus a register call or consent events; Skip sends nothing.
 */
final class Consents {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Consents for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Opt-in screen Allow & Continue: grant the current usage text and share the admin's contact.
	 *
	 * @param string $via Plugin slug of the screen.
	 * @return string usage or retry.
	 */
	public function allow( $via ) {
		$this->client->store()->set( array( 'optin_answered_at' => $this->client->now() ) );

		return 'saved' === $this->set_usage( true, $via, 'optin_allow' ) ? 'usage' : 'retry';
	}

	/**
	 * Opt-in screen Skip: local flag only, nothing is sent.
	 *
	 * @return string nothing.
	 */
	public function skip() {
		$now = $this->client->now();

		$this->client->store()->set(
			array(
				'optin_answered_at' => $now,
				'optin_skipped_at'  => $now,
			)
		);

		return 'nothing';
	}

	/**
	 * Notice Allow: grant usage, share the admin's contact and end the notice.
	 *
	 * @param string $via Plugin slug of the screen.
	 * @return string usage or retry.
	 */
	public function notice_allow( $via ) {
		$this->client->store()->set( array( 'notice_done' => true ) );

		return 'saved' === $this->set_usage( true, $via, 'notice_allow' ) ? 'usage' : 'retry';
	}

	/**
	 * Notice No thanks or X: never ask again on this site.
	 *
	 * @return string nothing.
	 */
	public function notice_dismiss() {
		$this->client->store()->set( array( 'notice_done' => true ) );

		return 'nothing';
	}

	/**
	 * Turn usage sharing on or off (settings toggle and the two asks).
	 *
	 * @param bool   $on     Usage wanted.
	 * @param string $via    Plugin slug of the screen.
	 * @param string $method optin_allow or notice_allow for an Allow click (shares the contact); '' for the settings toggle.
	 * @return string saved or retry.
	 */
	public function set_usage( $on, $via, $method = '' ) {
		$client = $this->client;
		$store  = $client->store();
		$was    = $store->usage();

		// Any answer in the settings counts as the opt-in answer, so the screen never shows after it.
		if ( ! (int) $store->get( 'optin_answered_at' ) ) {
			$store->set( array( 'optin_answered_at' => $client->now() ) );
		}

		if ( (bool) $on === $was ) {
			return 'saved';
		}

		if ( ! $on ) {
			$store->set( array( 'optin_contact' => null ) );
			$store->patch( 'usage', array( 'granted' => false ) );
			Queue::prune( false, $client->now() );
			Queue::drop_grants( 'usage' );
			State::forget();

			return $this->send( array( 'consent.withdrawn', array( 'purpose' => 'usage' ) ), $via, false );
		}

		// Allow grants the text it showed (with the contact line); the toggle shows none, so it grants the plain text.
		$proof   = '' === (string) $method ? Texts::plain_usage_proof() : Texts::proof( 'usage' );
		$contact = '' === (string) $method ? null : Contact::current( (string) $method );

		if ( null === $proof ) {
			return 'retry';
		}

		$contact = null !== $contact && Texts::shows_contact( $proof['text_version'] ) ? $contact : null;

		$store->patch(
			'usage',
			array(
				'granted'      => true,
				'text_version' => $proof['text_version'],
				'at'           => $client->now(),
			)
		);

		// Not registered: the register body carries the contact; registered: the consent event does.
		if ( ! $store->registered() ) {
			$store->set( array( 'optin_contact' => $contact ) );
			$contact = null;
		}

		$result = $this->send( array( 'consent.granted', Bodies::granted_data( $store->all(), null, $contact ) ), $via, true );

		// The event carried the Allow contact: the newsletter card has nothing left to ask.
		if ( 'saved' === $result && null !== $contact ) {
			Box::remember( 'subscribed' );
		}

		return $result;
	}

	/**
	 * Send a usage change: register when not registered yet, else queue the event and report now.
	 *
	 * @param array{0:string,1:array<string,mixed>} $event Type and data.
	 * @param string                                $via   Plugin slug.
	 * @param bool                                  $added Whether usage was just granted (send the snapshot).
	 * @return string saved or retry.
	 */
	private function send( array $event, $via, $added ) {
		$client = $this->client;
		$store  = $client->store();

		if ( ! $store->registered() ) {
			$result = $added ? $client->registrar()->register( 'new', $via, Config::TIMEOUT_USER, true ) : 'ok';

			if ( ! $added ) {
				$store->set( array( 'pending_register' => null ) );
			}

			$client->scheduler()->ensure();

			return 'ok' === $result ? 'saved' : 'retry';
		}

		$product = $client->products()->get( $via );
		$version = null === $product ? '' : (string) $product['version'];

		Queue::push( Queue::make( $store, $event[0], $via, $version, $event[1], $client->now() ) );

		$result = $client->reporter()->report( $added, Config::TIMEOUT_USER, true );
		$client->scheduler()->ensure();

		return 'ok' === $result && ! Queue::all() ? 'saved' : 'retry';
	}

	/**
	 * Lower-case valid email or ''.
	 *
	 * @param string $email Raw email.
	 * @return string Clean email.
	 */
	public static function clean_email( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );

		return '' !== $email && strlen( $email ) <= 190 && is_email( $email ) ? $email : '';
	}

	/**
	 * Two-letter email language from the user's locale.
	 *
	 * @return string Language code.
	 */
	public static function language() {
		$locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
		$lang   = strtolower( substr( $locale, 0, 2 ) );

		return 1 === preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
	}
}
