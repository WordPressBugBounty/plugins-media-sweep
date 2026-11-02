<?php
/**
 * Plugin lifecycle events of our plugins: installed, activated, deactivated, uninstalled, updated.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

use WPCreatix\BridgeClient\Snapshot\Plugins;

/**
 * Local records are kept without consent (never sent); events are queued only with usage consent.
 */
final class Lifecycle {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Basenames deactivated on this request (core saves active_plugins only after a bulk loop).
	 *
	 * @var array<int,string>
	 */
	private $leaving = array();

	/**
	 * Lifecycle for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Whether plugin.* events may be queued.
	 *
	 * @return bool True with usage consent on a registered (or registering) site.
	 */
	public function events_allowed() {
		$store = $this->client->store();

		return $store->usage() && ( $store->registered() || is_array( $store->get( 'pending_register' ) ) ) && '' === $this->client->blocked();
	}

	/**
	 * Record first-seen dates and queue plugin.updated when a version changed.
	 *
	 * @return void
	 */
	public function sync() {
		$client  = $this->client;
		$store   = $client->store();
		$records = $this->records();
		$changed = false;
		$queued  = false;

		foreach ( $client->products()->all() as $slug => $product ) {
			if ( ! isset( $records[ $slug ] ) ) {
				$records[ $slug ] = $this->record( $product['version'] );
				$changed          = true;
				continue;
			}

			$from = (string) $records[ $slug ]['version'];

			if ( '' !== $product['version'] && $from !== $product['version'] ) {
				if ( $this->events_allowed() ) {
					Queue::push(
						Queue::make(
							$store,
							'plugin.updated',
							$slug,
							$product['version'],
							array(
								'from' => $from,
								'to'   => $product['version'],
							),
							$client->now()
						)
					);
					$queued = true;
				}

				$records[ $slug ]['version'] = $product['version'];
				$changed                     = true;
			}
		}

		foreach ( Plugins::installed_known() as $slug => $info ) {
			if ( ! isset( $records[ $slug ] ) ) {
				$records[ $slug ] = $this->record( $info['version'] );
				$changed          = true;
			}
		}

		if ( $changed ) {
			$store->set( array( 'plugins' => $records ) );
		}

		if ( $queued ) {
			$this->report_later( (int) Identity::seeded_jitter( (string) $store->get( 'uid' ) ) );
		}
	}

	/**
	 * Mark every current version as known (after a register carried the snapshot).
	 *
	 * @return void
	 */
	public function record_versions() {
		$records = $this->records();

		foreach ( $this->client->products()->all() as $slug => $product ) {
			$records[ $slug ]            = isset( $records[ $slug ] ) ? $records[ $slug ] : $this->record( $product['version'] );
			$records[ $slug ]['version'] = $product['version'];
		}

		$this->client->store()->set( array( 'plugins' => $records ) );
	}

	/**
	 * One of our plugins was activated (activated_plugin action).
	 *
	 * @param string $basename Plugin basename.
	 * @return void
	 */
	public function activated( $basename ) {
		$product = $this->client->products()->by_basename( (string) $basename );

		if ( null === $product ) {
			return;
		}

		$records = $this->records();
		$first   = ! isset( $records[ $product['slug'] ] );

		if ( $first ) {
			$records[ $product['slug'] ] = $this->record( $product['version'] );
			$this->client->store()->set( array( 'plugins' => $records ) );
		}

		if ( ! $this->events_allowed() ) {
			return;
		}

		if ( $first ) {
			$this->push( 'plugin.installed', $product['slug'], $product['version'] );
		}

		$this->push( 'plugin.activated', $product['slug'], $product['version'] );
		$this->report_later( 0 );
		$this->client->scheduler()->ensure();
	}

	/**
	 * One of our plugins was deactivated (deactivated_plugin action): send without waiting.
	 *
	 * @param string $basename Plugin basename.
	 * @return void
	 */
	public function deactivated( $basename ) {
		$product = $this->client->products()->by_basename( (string) $basename );

		if ( null === $product ) {
			return;
		}

		if ( $this->events_allowed() ) {
			$this->push( 'plugin.deactivated', $product['slug'], $product['version'] );
			$this->client->reporter()->fire();
		}

		$this->leaving[] = $product['basename'];
		$this->clear_tick_if_last();
	}

	/**
	 * One of our plugins is being deleted (delete_plugin action): send without waiting.
	 *
	 * @param string $basename Plugin basename.
	 * @return void
	 */
	public function deleted( $basename ) {
		$slug    = $this->slug_for_basename( (string) $basename );
		$records = $this->records();

		if ( '' === $slug || ! isset( $records[ $slug ] ) ) {
			return;
		}

		if ( $this->events_allowed() ) {
			$this->push( 'plugin.uninstalled', $slug, (string) $records[ $slug ]['version'] );
			$this->client->reporter()->fire();
		}

		unset( $records[ $slug ] );
		$this->client->store()->set( array( 'plugins' => $records ) );
	}

	/**
	 * Days since a plugin was first seen here.
	 *
	 * @param string $slug Slug.
	 * @return int Days (0 when unknown).
	 */
	public function days_installed( $slug ) {
		$records = $this->records();
		$date    = isset( $records[ $slug ]['installed_at'] ) ? strtotime( (string) $records[ $slug ]['installed_at'] . ' 00:00:00 UTC' ) : false;

		return false === $date ? 0 : (int) floor( max( 0, $this->client->now() - $date ) / 86400 );
	}

	/**
	 * Local plugin records.
	 *
	 * @return array<string,array<string,mixed>> Records.
	 */
	private function records() {
		$records = $this->client->store()->get( 'plugins' );

		return is_array( $records ) ? $records : array();
	}

	/**
	 * A new local record.
	 *
	 * @param string $version Version.
	 * @return array<string,string> Record.
	 */
	private function record( $version ) {
		return array(
			'version'      => (string) $version,
			'installed_at' => gmdate( 'Y-m-d', $this->client->now() ),
		);
	}

	/**
	 * Queue one plugin event.
	 *
	 * @param string $type    Event type.
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return void
	 */
	private function push( $type, $slug, $version ) {
		Queue::push( Queue::make( $this->client->store(), $type, $slug, $version, array(), $this->client->now() ) );
	}

	/**
	 * Ask for a report after a delay (keeps an earlier one).
	 *
	 * @param int $delay Seconds.
	 * @return void
	 */
	private function report_later( $delay ) {
		$store   = $this->client->store();
		$current = (int) $store->get( 'pending_report_at' );
		$at      = $this->client->now() + max( 0, (int) $delay );

		if ( 0 === $current || $at < $current ) {
			$store->set( array( 'pending_report_at' => $at ) );
		}
	}

	/**
	 * Clear the tick when no product with the client stays active.
	 *
	 * @return void
	 */
	private function clear_tick_if_last() {
		foreach ( $this->client->products()->all() as $product ) {
			if ( ! in_array( $product['basename'], $this->leaving, true ) && Plugins::is_active( $product['basename'] ) ) {
				return;
			}
		}

		wp_clear_scheduled_hook( Config::CRON_HOOK );
	}

	/**
	 * Our slug for a basename (registered product or known folder).
	 *
	 * @param string $basename Plugin basename.
	 * @return string Slug or ''.
	 */
	private function slug_for_basename( $basename ) {
		$product = $this->client->products()->by_basename( $basename );

		if ( null !== $product ) {
			return (string) $product['slug'];
		}

		$folder = dirname( $basename );
		$slug   = array_search( $folder, Config::KNOWN_PLUGINS, true );

		if ( false !== $slug ) {
			return (string) $slug;
		}

		// Registered products use their folder name as slug by convention.
		return array_key_exists( $folder, $this->records() ) ? $folder : '';
	}
}
