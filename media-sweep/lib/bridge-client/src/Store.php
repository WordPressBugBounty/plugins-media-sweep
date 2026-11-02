<?php
/**
 * The shared wpcreatix_site record (one per site, one per network on multisite).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Plain-array state with a schema number, so any client copy can read it.
 */
final class Store {

	/**
	 * Current state schema.
	 */
	const SCHEMA = 1;

	/**
	 * Keys older copies stored that this copy drops on load.
	 *
	 * @var array<int,string>
	 */
	const LEGACY = array( 'marketing', 'asked_at', 'reconsent_answered' );

	/**
	 * Loaded state, or null before the first read.
	 *
	 * @var array<string,mixed>|null
	 */
	private $data = null;

	/**
	 * Default state for a site that never saved anything.
	 *
	 * @return array<string,mixed> Defaults.
	 */
	public static function defaults() {
		return array(
			'schema'                 => self::SCHEMA,
			'uid'                    => '',
			'token'                  => '',
			'registered_home'        => '',
			'registered_at'          => 0,
			'optin_answered_at'      => 0,
			'optin_skipped_at'       => 0,
			'notice_done'            => false,
			'usage'                  => array(
				'granted'      => false,
				'text_version' => '',
				'at'           => 0,
			),
			'seq'                    => 0,
			'next_heartbeat_at'      => 0,
			'pending_report_at'      => 0,
			'backoff_until'          => 0,
			'failures'               => 0,
			'last_401_reregister_at' => 0,
			'stopped'                => false,
			'stop_sig'               => '',
			'protocol_stop_sig'      => '',
			'gone'                   => false,
			'pending_register'       => null,
			'register_dropped_sig'   => '',
			'erase_pending'          => false,
			'last_payload'           => null,
			'last_payload_at'        => 0,
			'last_error'             => '',
			'server'                 => '',
			'plugins'                => array(),
		);
	}

	/**
	 * Whether the record lives in a network option.
	 *
	 * @return bool True on multisite.
	 */
	public static function network() {
		return is_multisite();
	}

	/**
	 * Read a raw option (site option on multisite).
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed Stored value.
	 */
	public static function read_option( $name, $fallback = false ) {
		return self::network() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	/**
	 * Write a raw option without autoload (site option on multisite).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function write_option( $name, $value ) {
		if ( self::network() ) {
			update_site_option( $name, $value );
			return;
		}

		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', 'no' );
			return;
		}

		update_option( $name, $value, false );
	}

	/**
	 * Delete a raw option (site option on multisite).
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function delete_option( $name ) {
		if ( self::network() ) {
			delete_site_option( $name );
			return;
		}

		delete_option( $name );
	}

	/**
	 * Full state, merged over the defaults.
	 *
	 * @return array<string,mixed> State.
	 */
	public function all() {
		if ( null === $this->data ) {
			$this->data = self::normalize( self::read_option( Config::OPTION, array() ) );
		}

		return $this->data;
	}

	/**
	 * Drop the cached copy so the next read hits the database.
	 *
	 * @return void
	 */
	public function reload() {
		$this->data = null;
		wp_cache_delete( Config::OPTION, 'options' );
	}

	/**
	 * One top-level value.
	 *
	 * @param string $key Key.
	 * @return mixed Value or null.
	 */
	public function get( $key ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Merge top-level values and save.
	 *
	 * @param array<string,mixed> $values Values to set.
	 * @return void
	 */
	public function set( array $values ) {
		$this->data = array_merge( $this->all(), $values );
		self::write_option( Config::OPTION, $this->data );
	}

	/**
	 * Merge values into a nested array key (usage) and save.
	 *
	 * @param string              $key    Top-level key.
	 * @param array<string,mixed> $values Nested values.
	 * @return void
	 */
	public function patch( $key, array $values ) {
		$current = $this->get( $key );

		$this->set( array( $key => array_merge( is_array( $current ) ? $current : array(), $values ) ) );
	}

	/**
	 * Remove the record (and nothing else).
	 *
	 * @return void
	 */
	public function forget() {
		$this->data = null;
		self::delete_option( Config::OPTION );
	}

	/**
	 * Next event sequence number (stored).
	 *
	 * @return int Sequence.
	 */
	public function next_seq() {
		$seq = (int) $this->get( 'seq' ) + 1;
		$this->set( array( 'seq' => $seq ) );

		return $seq;
	}

	/**
	 * Whether the site agreed to send anything (usage is the only site consent since 1.2.0).
	 *
	 * @return bool True when usage is on.
	 */
	public function consented() {
		return $this->usage();
	}

	/**
	 * Whether usage sharing is on.
	 *
	 * @return bool True when granted.
	 */
	public function usage() {
		$usage = $this->get( 'usage' );

		return ! empty( $usage['granted'] );
	}

	/**
	 * Whether usage is on and its agreed text covers a scope (extensions needs the v2 text, D33).
	 *
	 * @param string $scope Scope such as extensions.
	 * @return bool True when covered.
	 */
	public function usage_covers( $scope ) {
		$usage = $this->get( 'usage' );

		return $this->usage() && in_array( (string) $scope, Consent\Texts::covers( isset( $usage['text_version'] ) ? (string) $usage['text_version'] : '' ), true );
	}

	/**
	 * Whether the site holds a token.
	 *
	 * @return bool True when registered.
	 */
	public function registered() {
		return '' !== (string) $this->get( 'token' ) && '' !== (string) $this->get( 'uid' );
	}

	/**
	 * Merge a stored value over the defaults, tolerating junk from other versions.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string,mixed> State.
	 */
	public static function normalize( $raw ) {
		$defaults = self::defaults();
		$raw      = is_array( $raw ) ? $raw : array();
		$data     = array_merge( $defaults, $raw );

		// Keys of older copies: the marketing record and the old card answers are gone since 1.2.0.
		foreach ( self::LEGACY as $key ) {
			unset( $data[ $key ] );
		}

		$data['usage'] = array_merge( $defaults['usage'], is_array( $data['usage'] ) ? $data['usage'] : array() );

		if ( ! is_array( $data['plugins'] ) ) {
			$data['plugins'] = array();
		}

		return $data;
	}
}
