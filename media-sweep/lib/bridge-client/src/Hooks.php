<?php
/**
 * WordPress wiring of the booted client: cron, admin load, opt-in screen, notice, panels, assets, ajax and the public actions.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient;

use WPCreatix\BridgeClient\Consent\Ajax;
use WPCreatix\BridgeClient\Consent\OptinNotice;
use WPCreatix\BridgeClient\Consent\OptinScreen;
use WPCreatix\BridgeClient\Consent\Settings;
use WPCreatix\BridgeClient\Events\Custom;
use WPCreatix\BridgeClient\Feedback\Deactivation;
use WPCreatix\BridgeClient\Newsletter\Box;

/**
 * Every callback goes through Client::guard() so a client error never reaches the host site.
 */
final class Hooks {

	/**
	 * Hooks that change the plugin and theme list (they only mark it dirty).
	 *
	 * @var array<int,string>
	 */
	const EXT_HOOKS = array( 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'upgrader_process_complete', 'switch_theme', 'delete_theme' );

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Hooks for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Register every hook; heavy work only runs in admin, ajax and cron.
	 *
	 * @return void
	 */
	public function register() {
		$client = $this->client;

		add_action( Config::CRON_HOOK, $client->guard( array( $client->scheduler(), 'tick' ) ) );
		add_action( 'admin_init', $client->guard( array( $this, 'admin_init' ) ) );
		add_action( 'activated_plugin', $client->guard( array( $client->lifecycle(), 'activated' ) ) );
		add_action( 'deactivated_plugin', $client->guard( array( $client->lifecycle(), 'deactivated' ) ) );
		add_action( 'delete_plugin', $client->guard( array( $client->lifecycle(), 'deleted' ) ) );

		foreach ( self::EXT_HOOKS as $hook ) {
			add_action( $hook, $client->guard( array( $client->syncer(), 'mark_dirty' ) ), 20, 0 );
		}

		$screen = new OptinScreen( $client );
		add_action( 'current_screen', $client->guard( array( $screen, 'decide' ) ) );
		add_filter( 'admin_body_class', array( $screen, 'body_class' ) );
		add_action( 'all_admin_notices', $client->guard( array( $screen, 'auto' ) ), 1 );
		add_action( 'wpcreatix_bridge_client_card', $client->guard( array( $screen, 'render' ) ) );
		// After admin_notices: some hosts hide that whole hook on their own screens.
		add_action( 'all_admin_notices', $client->guard( array( new OptinNotice( $client ), 'render' ) ) );
		add_action( 'admin_enqueue_scripts', $client->guard( array( $this, 'enqueue' ) ) );

		add_action( 'wpcreatix_bridge_client_settings', $client->guard( array( new Settings( $client ), 'render' ) ) );
		add_action( 'wpcreatix_bridge_client_newsletter', $client->guard( array( new Box( $client, $screen ), 'render' ) ) );
		add_action( 'wpcreatix_bridge_client_event', $client->guard( array( new Custom( $client ), 'queue' ) ), 10, 3 );
		add_action( 'admin_footer', $client->guard( array( new Deactivation( $client ), 'render' ) ) );
		add_action( 'wp_ajax_' . Config::AJAX, $client->guard( array( new Ajax( $client ), 'handle' ) ) );
	}

	/**
	 * Admin load: lift stale stops, note the server name, catch version changes, keep the tick right.
	 *
	 * @return void
	 */
	public function admin_init() {
		$client = $this->client;

		if ( wp_doing_ajax() || ! Scheduler::is_sender_site() ) {
			return;
		}

		$server = Snapshot\Env::server();

		if ( '' !== $server && $server !== $client->store()->get( 'server' ) ) {
			$client->store()->set( array( 'server' => $server ) );
		}

		$client->blocked();
		$client->lifecycle()->sync();
		$client->scheduler()->ensure();
		$client->scheduler()->maybe_fallback();
	}

	/**
	 * Enqueue the client assets on our screens, and the feedback script on the plugins screen.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id      = null === $screen ? '' : (string) $screen->id;
		$plugins = 'plugins.php' === $hook_suffix;

		if ( $plugins ) {
			self::assets( $this->client, true, false );
			return;
		}

		if ( null !== $this->client->products()->by_screen( $id ) ) {
			self::assets( $this->client );
		}
	}

	/**
	 * Enqueue the assets (also called by render hooks when the host page enqueues late).
	 *
	 * @param Client $client   Client.
	 * @param bool   $feedback Include the deactivation feedback script.
	 * @param bool   $fonts    Include the bundled Geist fonts (our own screens only).
	 * @return void
	 */
	public static function assets( Client $client, $feedback = false, $fonts = true ) {
		$deps = array();

		if ( $fonts ) {
			wp_enqueue_style( 'wpcreatix-bridge-client-fonts', $client->asset_url( 'assets/fonts/geist.css' ), array(), $client->lib() );
			$deps[] = 'wpcreatix-bridge-client-fonts';
		}

		wp_enqueue_style( 'wpcreatix-bridge-client', $client->asset_url( 'assets/optin.css' ), $deps, $client->lib() );
		wp_enqueue_script( 'wpcreatix-bridge-client', $client->asset_url( 'assets/optin.js' ), array(), $client->lib(), true );
		wp_localize_script(
			'wpcreatix-bridge-client',
			'wpcreatixBridgeClient',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( Config::AJAX ),
			)
		);

		if ( $feedback ) {
			wp_enqueue_script( 'wpcreatix-bridge-client-feedback', $client->asset_url( 'assets/feedback.js' ), array( 'wpcreatix-bridge-client' ), $client->lib(), true );
		}
	}
}
