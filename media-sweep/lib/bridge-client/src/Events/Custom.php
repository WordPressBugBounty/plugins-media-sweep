<?php
/**
 * Custom events from our plugins: do_action( 'wpcreatix_bridge_client_event', $slug, $type, array $data ).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Events;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Queue;
use WPCreatix\BridgeClient\Transport;

/**
 * Queued only with usage consent; sent in the normal /v1/report envelope; the server checks the type schema.
 */
final class Custom {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Custom events for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Queue one custom event (action callback).
	 *
	 * @param mixed $slug Plugin slug (a registered product).
	 * @param mixed $type Event type, e.g. vidshop.storefront_created.
	 * @param mixed $data Event data (encoded size max 2 KB).
	 * @return string queued, or why it was not queued.
	 */
	public function queue( $slug = '', $type = '', $data = array() ) {
		$product = $this->client->products()->get( (string) $slug );

		if ( null === $product ) {
			return 'unknown_plugin';
		}

		if ( ! is_string( $type ) || ! Queue::allowed_type( $type ) || in_array( $type, Queue::TYPES, true ) ) {
			return 'invalid_type';
		}

		// Data is an object of named fields, never a list.
		if ( ! is_array( $data ) || ( $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) || strlen( Transport::encode( $data ) ) > Config::CUSTOM_DATA_MAX ) {
			return 'invalid_data';
		}

		if ( ! $this->client->lifecycle()->events_allowed() ) {
			return 'no_consent';
		}

		Queue::push( Queue::make( $this->client->store(), $type, (string) $product['slug'], (string) $product['version'], $data, $this->client->now() ) );

		return 'queued';
	}
}
