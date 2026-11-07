<?php
/**
 * The clicking admin's contact for an Allow: email, first name and language (owner decision 2026-11-03).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

/**
 * Built only on Allow (screen or notice); Skip, the settings toggle and background reports never build one.
 */
final class Contact {

	/**
	 * Where Allow was clicked: the opt-in screen or the notice after Skip.
	 *
	 * @var array<int,string>
	 */
	const METHODS = array( 'optin_allow', 'notice_allow' );

	/**
	 * Contact of the current user for an Allow click.
	 *
	 * @param string $method optin_allow or notice_allow.
	 * @return array<string,string>|null email, first_name (only when set), language, method; null without a valid email.
	 */
	public static function current( $method ) {
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return null;
		}

		$user  = wp_get_current_user();
		$email = Consents::clean_email( $user instanceof \WP_User ? (string) $user->user_email : '' );

		// No usable profile email: the site (network) admin email.
		if ( '' === $email ) {
			$email = Consents::clean_email( (string) ( is_multisite() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' ) ) );
		}

		if ( '' === $email ) {
			return null;
		}

		$contact = array( 'email' => $email );
		$name    = self::first_name( $user instanceof \WP_User ? (string) $user->first_name : '' );

		if ( '' !== $name ) {
			$contact['first_name'] = $name;
		}

		$contact['language'] = Consents::language();
		$contact['method']   = (string) $method;

		return $contact;
	}

	/**
	 * A first name the server accepts (letters, spaces, hyphen, apostrophe; up to 40 chars), else ''.
	 *
	 * @param string $raw Profile first name.
	 * @return string Name or ''.
	 */
	public static function first_name( $raw ) {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) );

		if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > 40 || 1 !== preg_match( '/^[\p{L}\p{M} \'\x{2019}-]+$/u', $name ) || 1 !== preg_match( '/\p{L}/u', $name ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * A stored contact in wire order, or null when it is not a valid one.
	 *
	 * @param mixed $value Stored value (state optin_contact).
	 * @return array<string,string>|null Contact.
	 */
	public static function clean( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['email'], $value['method'] ) || '' === Consents::clean_email( (string) $value['email'] ) || ! in_array( $value['method'], self::METHODS, true ) ) {
			return null;
		}

		$contact = array( 'email' => Consents::clean_email( (string) $value['email'] ) );

		if ( isset( $value['first_name'] ) && '' !== self::first_name( (string) $value['first_name'] ) ) {
			$contact['first_name'] = self::first_name( (string) $value['first_name'] );
		}

		$contact['language'] = isset( $value['language'] ) && 1 === preg_match( '/^[a-z]{2}$/', (string) $value['language'] ) ? (string) $value['language'] : 'en';
		$contact['method']   = (string) $value['method'];

		return $contact;
	}
}
