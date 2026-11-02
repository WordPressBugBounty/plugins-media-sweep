<?php
/**
 * One API reply: status, JSON body and Retry-After.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Value object; status 0 means a network error.
 */
final class Response {

	/**
	 * HTTP status (0 on network error).
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Decoded JSON body (empty when not JSON).
	 *
	 * @var array<string,mixed>
	 */
	public $body;

	/**
	 * Retry-After in seconds (0 when absent).
	 *
	 * @var int
	 */
	public $retry_after;

	/**
	 * Network error message, if any.
	 *
	 * @var string
	 */
	public $error;

	/**
	 * Build a response.
	 *
	 * @param int                 $status      HTTP status.
	 * @param array<string,mixed> $body        Body.
	 * @param int                 $retry_after Seconds.
	 * @param string              $error       Network error.
	 */
	public function __construct( $status, array $body = array(), $retry_after = 0, $error = '' ) {
		$this->status      = (int) $status;
		$this->body        = $body;
		$this->retry_after = max( 0, (int) $retry_after );
		$this->error       = (string) $error;
	}

	/**
	 * From a wp_remote_* result.
	 *
	 * @param array<string,mixed>|\WP_Error $raw HTTP API result.
	 * @return Response Reply.
	 */
	public static function from_wp( $raw ) {
		if ( is_wp_error( $raw ) ) {
			return new self( 0, array(), 0, $raw->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $raw ), true );

		return new self(
			(int) wp_remote_retrieve_response_code( $raw ),
			is_array( $body ) ? $body : array(),
			self::parse_retry_after( (string) wp_remote_retrieve_header( $raw, 'retry-after' ) )
		);
	}

	/**
	 * Retry-After as seconds (delta-seconds or an HTTP date), capped at 7 days.
	 *
	 * @param string   $value Header value.
	 * @param int|null $now   Unix time (tests).
	 * @return int Seconds.
	 */
	public static function parse_retry_after( $value, $now = null ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			$seconds = (int) $value;
		} else {
			$time    = strtotime( $value );
			$seconds = false === $time ? 0 : $time - ( null === $now ? time() : (int) $now );
		}

		return min( Config::WEEK, max( 0, $seconds ) );
	}

	/**
	 * Whether the call succeeded (2xx).
	 *
	 * @return bool True on 2xx.
	 */
	public function ok() {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Error code from the body, or ''.
	 *
	 * @return string Code.
	 */
	public function code() {
		return isset( $this->body['code'] ) ? (string) $this->body['code'] : '';
	}
}
