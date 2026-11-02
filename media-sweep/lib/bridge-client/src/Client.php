<?php
/**
 * The booted client: holds the services and wires the WordPress hooks.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

/**
 * One instance per request, created by the newest copy only.
 */
final class Client {

	use ClientServices;

	/**
	 * The booted instance.
	 *
	 * @var Client|null
	 */
	private static $instance = null;

	/**
	 * Client lib version.
	 *
	 * @var string
	 */
	private $lib;

	/**
	 * Folder of this copy (holds load.php, assets, data).
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Fixed time for tests (null = real time).
	 *
	 * @var int|null
	 */
	public $clock = null;

	/**
	 * Lazily built services.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Products source.
	 *
	 * @var Products
	 */
	private $products;

	/**
	 * Build a client.
	 *
	 * @param string        $lib      Lib version.
	 * @param string        $dir      Copy folder.
	 * @param Products|null $products Products (tests pass their own).
	 */
	public function __construct( $lib, $dir, $products = null ) {
		$this->lib      = (string) $lib;
		$this->dir      = (string) $dir;
		$this->products = $products instanceof Products ? $products : new Products();
	}

	/**
	 * Boot the newest copy once per request.
	 *
	 * @param string $lib Lib version.
	 * @param string $dir Copy folder.
	 * @return Client Instance.
	 */
	public static function boot( $lib, $dir ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $lib, $dir );
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * The booted instance, if any.
	 *
	 * @return Client|null Instance.
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * Register WordPress hooks (see Hooks).
	 *
	 * @return void
	 */
	public function hooks() {
		( new Hooks( $this ) )->register();
	}

	/**
	 * Enqueue the client assets (render hooks call this when the host page enqueues late).
	 *
	 * @param bool $feedback Include the deactivation feedback script.
	 * @return void
	 */
	public function enqueue_assets( $feedback = false ) {
		Hooks::assets( $this, $feedback );
	}

	/**
	 * Public URL of a file in this copy.
	 *
	 * @param string $path Path relative to the copy folder, e.g. assets/optin.css.
	 * @return string URL.
	 */
	public function asset_url( $path ) {
		return plugins_url( ltrim( (string) $path, '/' ), $this->dir . '/load.php' );
	}

	/**
	 * Why sending is blocked now ('' when not); lifts a stop once any plugin version changed.
	 *
	 * @return string gone, stopped, protocol or ''.
	 */
	public function blocked() {
		$store = $this->store();
		$sig   = $this->signature();

		if ( $store->get( 'stopped' ) && Products::stop_lifts( (string) $store->get( 'stop_sig' ), $sig ) ) {
			$store->set(
				array(
					'stopped'  => false,
					'stop_sig' => '',
				)
			);
		}

		if ( '' !== (string) $store->get( 'protocol_stop_sig' ) && Products::stop_lifts( (string) $store->get( 'protocol_stop_sig' ), $sig ) ) {
			$store->set( array( 'protocol_stop_sig' => '' ) );
		}

		if ( $store->get( 'gone' ) ) {
			return 'gone';
		}

		if ( $store->get( 'stopped' ) ) {
			return 'stopped';
		}

		return '' !== (string) $store->get( 'protocol_stop_sig' ) ? 'protocol' : '';
	}

	/**
	 * Keep the exact JSON of the last request for "Show last data sent".
	 *
	 * @param array<string,mixed> $body Body.
	 * @return void
	 */
	public function remember_payload( array $body ) {
		$this->store()->set(
			array(
				'last_payload'    => Transport::encode( $body ),
				'last_payload_at' => $this->now(),
			)
		);
	}

	/**
	 * Wrap a callback so a client error never reaches the host site.
	 *
	 * @param callable $callback Callback.
	 * @return \Closure Safe callback.
	 */
	public function guard( $callback ) {
		return function () use ( $callback ) {
			$args = func_get_args();

			return $this->safe(
				function () use ( $callback, $args ) {
					return call_user_func_array( $callback, $args );
				}
			);
		};
	}

	/**
	 * Run a callback and swallow any error into last_error.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Callback result or null.
	 */
	public function safe( $callback ) {
		try {
			return call_user_func( $callback );
		} catch ( \Throwable $e ) {
			try {
				$this->store()->set( array( 'last_error' => 'php: ' . substr( $e->getMessage(), 0, 200 ) ) );
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}

			return null;
		}
	}

	/**
	 * Current Unix time (fixed in tests).
	 *
	 * @return int Time.
	 */
	public function now() {
		return null === $this->clock ? time() : (int) $this->clock;
	}

	/**
	 * Lib version.
	 *
	 * @return string Version.
	 */
	public function lib() {
		return $this->lib;
	}

	/**
	 * Copy folder.
	 *
	 * @return string Path.
	 */
	public function dir() {
		return $this->dir;
	}

	/**
	 * Fingerprint of lib + plugin versions (a stop lifts when it changes).
	 *
	 * @return string Signature.
	 */
	public function signature() {
		return $this->products->signature( $this->lib );
	}

	/**
	 * Registered products.
	 *
	 * @return Products Products.
	 */
	public function products() {
		return $this->products;
	}

	/**
	 * Whether the current user may decide for this site (super admin on multisite, D7).
	 *
	 * @return bool True when allowed.
	 */
	public static function can_decide() {
		return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
	}
}
