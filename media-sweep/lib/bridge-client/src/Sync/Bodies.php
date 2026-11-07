<?php
/**
 * Request bodies exactly as 02 and tests/contract/fixtures define them (field order included).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Sync;

use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Consent\Contact;
use WPCreatix\BridgeClient\Consent\Texts;

/**
 * Pure builders: no I/O, so the contract tests can compare them with the fixtures.
 */
final class Bodies {

	/**
	 * POST /v1/sites body; home_url and snapshot only with usage consent.
	 *
	 * @param array<string,mixed>      $state      Store state (usage).
	 * @param string                   $uid        New uid.
	 * @param string|null              $parent_uid Parent uid.
	 * @param string                   $reason     new, url_changed, token_lost, uid_taken, clone.
	 * @param string                   $lib        Client lib version.
	 * @param string                   $via        Plugin slug that triggered the call.
	 * @param string                   $home       Home URL.
	 * @param array<string,mixed>|null $snapshot   Snapshot (usage only).
	 * @return array<string,mixed> Body.
	 */
	public static function register( array $state, $uid, $parent_uid, $reason, $lib, $via, $home, $snapshot ) {
		$usage = ! empty( $state['usage']['granted'] );
		$body  = array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
			'uid'            => (string) $uid,
			'parent_uid'     => null === $parent_uid || '' === $parent_uid ? null : (string) $parent_uid,
			'reason'         => (string) $reason,
		);

		if ( $usage ) {
			$body['home_url'] = (string) $home;
		}

		$body['client']  = array(
			'lib' => (string) $lib,
			'via' => (string) $via,
		);
		$body['consent'] = array( 'usage' => self::usage_block( $state ) );
		$contact         = self::contact_block( $state );

		if ( null !== $contact ) {
			$body['consent']['contact'] = $contact;
		}

		if ( $usage && is_array( $snapshot ) ) {
			$body['snapshot'] = $snapshot;
		}

		return $body;
	}

	/**
	 * POST /v1/report body.
	 *
	 * @param string                         $uid      Site uid.
	 * @param string                         $sent_at  ISO 8601 UTC.
	 * @param array<string,mixed>|null       $snapshot Snapshot or null.
	 * @param array<int,array<string,mixed>> $events   Events.
	 * @return array<string,mixed> Body.
	 */
	public static function report( $uid, $sent_at, $snapshot, array $events ) {
		$body = array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
			'uid'            => (string) $uid,
			'sent_at'        => (string) $sent_at,
		);

		if ( is_array( $snapshot ) ) {
			$body['snapshot'] = $snapshot;
		}

		$body['events'] = array_values( $events );

		return $body;
	}

	/**
	 * POST /v1/feedback body; uid only with usage consent.
	 *
	 * @param array<string,mixed> $fields plugin, version, reason, text, days_installed, reply_email, wp, locale.
	 * @param string              $uid    Site uid or '' when not shared.
	 * @return array<string,mixed> Body.
	 */
	public static function feedback( array $fields, $uid = '' ) {
		$body = array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
		);

		if ( '' !== (string) $uid ) {
			$body['uid'] = (string) $uid;
		}

		foreach ( array( 'plugin', 'version', 'reason', 'text', 'days_installed', 'reply_email', 'wp', 'locale' ) as $key ) {
			$body[ $key ] = isset( $fields[ $key ] ) && '' !== $fields[ $key ] ? $fields[ $key ] : null;
		}

		return $body;
	}

	/**
	 * POST /v1/erase body.
	 *
	 * @param string $uid Site uid.
	 * @return array<string,mixed> Body.
	 */
	public static function erase( $uid ) {
		return array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
			'uid'            => (string) $uid,
			'scope'          => 'site',
		);
	}

	/**
	 * POST /v1/extensions full sync part (11 section 3.4).
	 *
	 * @param string                         $uid        Site uid.
	 * @param string                         $sync_id    Sync id.
	 * @param string                         $started_at ISO 8601 UTC start of this sync.
	 * @param int                            $part       Part number (1-based).
	 * @param int                            $parts      Part count.
	 * @param string                         $list_hash  Hash of the whole list.
	 * @param array<int,array<string,mixed>> $items      Items of this part.
	 * @param bool                           $truncated  The list was cut at the item cap (sent on part 1 only).
	 * @return array<string,mixed> Body.
	 */
	public static function extensions_full( $uid, $sync_id, $started_at, $part, $parts, $list_hash, array $items, $truncated = false ) {
		$body = array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
			'uid'            => (string) $uid,
			'mode'           => 'full',
			'sync_id'        => (string) $sync_id,
			'started_at'     => (string) $started_at,
			'part'           => (int) $part,
			'parts'          => (int) $parts,
			'list_hash'      => (string) $list_hash,
			'items'          => array_values( $items ),
		);

		if ( $truncated && 1 === (int) $part ) {
			$body['truncated'] = true;
		}

		return $body;
	}

	/**
	 * POST /v1/extensions diff (11 section 3.4).
	 *
	 * @param string                         $uid       Site uid.
	 * @param string                         $base_hash Hash the server acked last.
	 * @param string                         $list_hash Hash after the changes.
	 * @param array<int,array<string,mixed>> $changes   Changes from Differ::diff().
	 * @return array<string,mixed> Body.
	 */
	public static function extensions_diff( $uid, $base_hash, $list_hash, array $changes ) {
		return array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
			'uid'            => (string) $uid,
			'mode'           => 'diff',
			'base_hash'      => (string) $base_hash,
			'list_hash'      => (string) $list_hash,
			'changes'        => array_values( $changes ),
		);
	}

	/**
	 * POST /v1/subscribe body (13 section 3.3): the site uid only when the caller also sends the site token.
	 *
	 * @param string $source   Plugin slug.
	 * @param string $email    Clean email.
	 * @param string $language Two-letter language.
	 * @param string $lib      Client lib version.
	 * @param string $uid      Site uid ('' = not linked).
	 * @return array<string,mixed> Body.
	 */
	public static function subscribe( $source, $email, $language, $lib, $uid = '' ) {
		$proof = self::proof( 'marketing' );
		$body  = array(
			'protocol'       => Config::PROTOCOL,
			'protocol_minor' => Config::PROTOCOL_MINOR,
			'source'         => (string) $source,
			'email'          => (string) $email,
			'language'       => (string) $language,
			'text_version'   => $proof['text_version'],
			'text_sha256'    => $proof['text_sha256'],
			'consent_method' => 'form_submit',
		);

		if ( '' !== (string) $uid ) {
			$body['uid'] = (string) $uid;
		}

		$body['client'] = array(
			'lib' => (string) $lib,
			'via' => (string) $source,
		);

		return $body;
	}

	/**
	 * Data of a usage consent.granted event; the notice Allow on a registered site adds the contact.
	 *
	 * @param array<string,mixed>       $state   Store state.
	 * @param string|null               $version Text version to grant (default: the one in the state, else the current one).
	 * @param array<string,string>|null $contact Contact of an Allow click, only on a text with the contact line.
	 * @return array<string,mixed> Event data.
	 */
	public static function granted_data( array $state, $version = null, $contact = null ) {
		$data    = array_merge( array( 'purpose' => 'usage' ), self::proof( 'usage', null === $version ? self::version_in( $state, 'usage' ) : $version ) );
		$contact = Contact::clean( $contact );

		if ( null !== $contact && Texts::shows_contact( $data['text_version'] ) ) {
			$data['contact'] = $contact;
		}

		return $data;
	}

	/**
	 * Contact block of a register body: only with usage on a text that shows the contact line, and an Allow contact waiting.
	 *
	 * @param array<string,mixed> $state Store state.
	 * @return array<string,string>|null Block.
	 */
	private static function contact_block( array $state ) {
		if ( empty( $state['usage']['granted'] ) || ! Texts::shows_contact( self::version_in( $state, 'usage' ) ) ) {
			return null;
		}

		return Contact::clean( isset( $state['optin_contact'] ) ? $state['optin_contact'] : null );
	}

	/**
	 * Usage consent block of a register body.
	 *
	 * @param array<string,mixed> $state Store state.
	 * @return array<string,mixed> Block.
	 */
	private static function usage_block( array $state ) {
		if ( empty( $state['usage']['granted'] ) ) {
			return array( 'granted' => false );
		}

		return array_merge( array( 'granted' => true ), self::proof( 'usage', self::version_in( $state, 'usage' ) ) );
	}

	/**
	 * Text version stored for a purpose in the state ('' when none).
	 *
	 * @param array<string,mixed> $state   Store state.
	 * @param string              $purpose usage or marketing.
	 * @return string Version.
	 */
	private static function version_in( array $state, $purpose ) {
		return isset( $state[ $purpose ]['text_version'] ) ? (string) $state[ $purpose ]['text_version'] : '';
	}

	/**
	 * Text version and hash for a purpose (empty strings if the bundled text is broken).
	 *
	 * @param string $purpose usage or marketing.
	 * @param string $version Agreed version; the current text when '' or unknown.
	 * @return array{text_version:string,text_sha256:string} Proof.
	 */
	private static function proof( $purpose, $version = '' ) {
		$proof = Texts::proof( $purpose, $version );

		return null === $proof ? array(
			'text_version' => '',
			'text_sha256'  => '',
		) : $proof;
	}
}
