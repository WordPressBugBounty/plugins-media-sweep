<?php
/**
 * HTTP to the Bridge API: JSON bodies, both auth headers, short timeouts, sslverify on.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * Thin wrapper over the WordPress HTTP API.
 */
final class Transport {

	/**
	 * Client lib version (User-Agent and client.lib).
	 *
	 * @var string
	 */
	private $lib;

	/**
	 * Transport for one lib version.
	 *
	 * @param string $lib Client lib version.
	 */
	public function __construct( $lib ) {
		$this->lib = (string) $lib;
	}

	/**
	 * Encode a body exactly as it goes on the wire.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return string JSON.
	 */
	public static function encode( array $body ) {
		return (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Request headers (02 section 4: both auth headers when a token is given).
	 *
	 * @param string $token Bearer token or ''.
	 * @param bool   $json  Whether a JSON body is sent.
	 * @return array<string,string> Headers.
	 */
	public function headers( $token, $json = true ) {
		$headers = array();

		if ( '' !== (string) $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
			$headers['X-WCXB-Token']  = (string) $token;
		}

		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}

		$headers['User-Agent'] = 'WPCreatixBridge/' . $this->lib;

		return $headers;
	}

	/**
	 * POST a JSON body and read the reply.
	 *
	 * @param string              $path    Endpoint path (sites, report, feedback, erase).
	 * @param array<string,mixed> $body    Body.
	 * @param string              $token   Bearer token or ''.
	 * @param int                 $timeout Seconds.
	 * @return Response Reply.
	 */
	public function post( $path, array $body, $token = '', $timeout = Config::TIMEOUT_CRON ) {
		$raw = wp_remote_post(
			Config::endpoint() . $path,
			array(
				'timeout'     => (int) $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'WPCreatixBridge/' . $this->lib,
				'headers'     => $this->headers( $token ),
				'body'        => self::encode( $body ),
			)
		);

		return Response::from_wp( $raw );
	}

	/**
	 * POST without waiting for the reply (deactivate, uninstall, feedback).
	 *
	 * @param string              $path  Endpoint path.
	 * @param array<string,mixed> $body  Body.
	 * @param string              $token Bearer token or ''.
	 * @return void
	 */
	public function fire( $path, array $body, $token = '' ) {
		wp_remote_post(
			Config::endpoint() . $path,
			array(
				'timeout'     => Config::TIMEOUT_FIRE,
				'blocking'    => false,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'WPCreatixBridge/' . $this->lib,
				'headers'     => $this->headers( $token ),
				'body'        => self::encode( $body ),
			)
		);
	}

	/**
	 * GET /v1/ping (health and protocol range).
	 *
	 * @return Response Reply.
	 */
	public function ping() {
		$raw = wp_remote_get(
			Config::endpoint() . 'ping',
			array(
				'timeout'     => Config::TIMEOUT_CRON,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'WPCreatixBridge/' . $this->lib,
				'headers'     => $this->headers( '', false ),
			)
		);

		return Response::from_wp( $raw );
	}
}
