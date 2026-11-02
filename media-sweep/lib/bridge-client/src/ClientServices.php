<?php
/**
 * Lazily built services of the booted client (store, transport, flows).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

use WPCreatix\BridgeClient\Consent\Consents;
use WPCreatix\BridgeClient\Extensions\Syncer;
use WPCreatix\BridgeClient\Sync\Eraser;
use WPCreatix\BridgeClient\Sync\Registrar;
use WPCreatix\BridgeClient\Sync\Reporter;

/**
 * Used by Client only; each service is built once per request.
 */
trait ClientServices {

	/**
	 * State store.
	 *
	 * @return Store Store.
	 */
	public function store() {
		return $this->service( 'store', Store::class, false );
	}

	/**
	 * HTTP transport.
	 *
	 * @return Transport Transport.
	 */
	public function transport() {
		if ( ! isset( $this->services['transport'] ) ) {
			$this->services['transport'] = new Transport( $this->lib );
		}

		return $this->services['transport'];
	}

	/**
	 * Register flow.
	 *
	 * @return Registrar Registrar.
	 */
	public function registrar() {
		return $this->service( 'registrar', Registrar::class );
	}

	/**
	 * Report flow.
	 *
	 * @return Reporter Reporter.
	 */
	public function reporter() {
		return $this->service( 'reporter', Reporter::class );
	}

	/**
	 * Erase flow.
	 *
	 * @return Eraser Eraser.
	 */
	public function eraser() {
		return $this->service( 'eraser', Eraser::class );
	}

	/**
	 * Plugin lifecycle events.
	 *
	 * @return Lifecycle Lifecycle.
	 */
	public function lifecycle() {
		return $this->service( 'lifecycle', Lifecycle::class );
	}

	/**
	 * Timing.
	 *
	 * @return Scheduler Scheduler.
	 */
	public function scheduler() {
		return $this->service( 'scheduler', Scheduler::class );
	}

	/**
	 * Plugin and theme list sync.
	 *
	 * @return Syncer Syncer.
	 */
	public function syncer() {
		return $this->service( 'syncer', Syncer::class );
	}

	/**
	 * Consent changes.
	 *
	 * @return Consents Consents.
	 */
	public function consents() {
		return $this->service( 'consents', Consents::class );
	}

	/**
	 * Build a service once.
	 *
	 * @param string $key        Service key.
	 * @param string $class_name Class name.
	 * @param bool   $with_self  Pass this client to the constructor.
	 * @return mixed Service.
	 */
	private function service( $key, $class_name, $with_self = true ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $with_self ? new $class_name( $this ) : new $class_name();
		}

		return $this->services[ $key ];
	}
}
