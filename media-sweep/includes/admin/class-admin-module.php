<?php
/**
 * Admin Module Class
 *
 * @package media-sweep
 */

namespace Media_Sweep\Admin;

use Media_Sweep\Utils\Path_Helper;
use Media_Sweep\Services\System_Monitor_Service;
use Media_Sweep\Models\Scan_Model;
use Media_Sweep\REST_API\V1\Promo_Controller;

/**
 * Admin Module Class
 */
class Admin_Module {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $menu_slug = 'mswp';

	/**
	 * System monitor service instance.
	 *
	 * @var System_Monitor_Service
	 */
	private $system_monitor;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param System_Monitor_Service $system_monitor System monitor instance.
	 */
	public function __construct( System_Monitor_Service $system_monitor ) {
		$this->system_monitor = $system_monitor;
		$this->init();
	}

	/**
	 * Initialize admin functionality.
	 */
	private function init() {
		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Remove notices.
		add_action( 'admin_notices', array( $this, 'remove_notices' ), 999 );
		add_action( 'admin_notices', array( $this, 'inject_before_notices' ), -9999 );
		add_action( 'admin_notices', array( $this, 'inject_after_notices' ), PHP_INT_MAX );

		// Change Admin footer text.
		add_filter( 'admin_footer_text', array( $this, 'change_admin_footer_text' ) );

		// Add class to body.
		add_filter(
			'admin_body_class',
			function ( $classes ) {
				if ( self::is_admin_page() ) {
					$classes .= ' mswp-admin mswp-page';
				}

				return $classes;
			}
		);

		// Add admin bar styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_admin_bar_styles' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . MEDIA_SWEEP_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );

		// Initialize review notice.
		new Review_Notice();
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Media Sweep', 'media-sweep' ),
			__( 'Media Sweep', 'media-sweep' ),
			'manage_options',
			$this->menu_slug,
			array( $this, 'render_admin_page' ),
			'data:image/svg+xml;base64,' . base64_encode(
				'<svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 256 256" height="200px" width="200px" xmlns="http://www.w3.org/2000/svg"><path d="M227.81,28.19a28,28,0,0,0-39.6,0l-.21.23L131.78,94.11,118.15,80.48a20,20,0,0,0-28.29,0L13.17,157.17a4,4,0,0,0,0,5.65l80,80a4,4,0,0,0,5.65,0l76.69-76.69a20,20,0,0,0,0-28.29l-13.63-13.63L227.58,68l.23-.21A28,28,0,0,0,227.81,28.19ZM96,234.34,73.66,212l25.17-25.18a4,4,0,0,0-5.65-5.65L68,206.34,49.66,188l25.17-25.18a4,4,0,0,0-5.65-5.65L44,182.34,21.66,160,72,109.65,146.35,184ZM222.26,62,153.41,121a4,4,0,0,0-.23,5.87l16.69,16.69a12,12,0,0,1,0,17L152,178.34,77.66,104,95.52,86.13a12,12,0,0,1,17,0l16.69,16.69a4,4,0,0,0,5.87-.23L194,33.74A20,20,0,0,1,222.26,62Z" fill="currentColor"></path></svg>'
			),
			20
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Scans', 'media-sweep' ),
			__( 'Scans', 'media-sweep' ),
			'manage_options',
			$this->menu_slug,
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Trash', 'media-sweep' ),
			__( 'Trash', 'media-sweep' ),
			'manage_options',
			"{$this->menu_slug}&path=trash",
			array( $this, 'render_admin_page' ),
		);

		add_submenu_page(
			$this->menu_slug,
			__( 'Settings', 'media-sweep' ),
			__( 'Settings', 'media-sweep' ),
			'manage_options',
			"{$this->menu_slug}&path=settings",
			array( $this, 'render_admin_page' ),
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		// Enqueue scripts.
		$this->register_admin_scripts();
		$this->enqueue_admin_scripts();
		?>
		<div id="mswp-admin"></div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 */
	public function enqueue_admin_scripts() {
		wp_enqueue_media();
		// Core's plugin AJAX installer (`wp.updates.installPlugin`). Lets the cross-promo cards
		// install a sibling plugin in place, from WordPress.org, through core's own mechanism.
		wp_enqueue_script( 'updates' );
		wp_enqueue_script( 'mswp-admin' );
		wp_enqueue_style( 'mswp-admin' );

		// Allow developers to add custom scripts/styles if needed.
		do_action( 'mswp_enqueue_additional_admin_assets' );
	}

	/**
	 * Register admin scripts.
	 */
	public function register_admin_scripts() {
		$assets_dir   = MEDIA_SWEEP_PLUGIN_DIR . 'build';
		$assets_file  = $assets_dir . '/index.asset.php';
		$assets       = file_exists( $assets_file ) ? require_once $assets_file : array();
		$dependencies = isset( $assets['dependencies'] ) ? $assets['dependencies'] : array();
		$version      = isset( $assets['version'] ) ? $assets['version'] : MEDIA_SWEEP_VERSION;

		// Register scripts.
		wp_register_script(
			'mswp-admin',
			MEDIA_SWEEP_PLUGIN_URL . 'build/index.js',
			$dependencies,
			$version,
		);

		// Localize scripts.
		wp_localize_script(
			'mswp-admin',
			'mswpAdmin',
			$this->get_localized_data()
		);

		// Translate scripts.
		wp_set_script_translations( 'mswp-admin', 'media-sweep', MEDIA_SWEEP_PLUGIN_DIR . 'languages' );

		// Register styles.
		wp_register_style(
			'mswp-admin',
			MEDIA_SWEEP_PLUGIN_URL . 'build/index.css',
			array(
				'wp-components',
			),
			$version,
		);

		// Rtl.
		wp_style_add_data( 'mswp-admin', 'rtl', 'replace' );
	}

	/**
	 * Get localized data.
	 */
	private function get_localized_data() {
		return array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'mswp-admin' ),
			'assets_url'            => MEDIA_SWEEP_PLUGIN_URL . 'assets',
			'uploads_dir_url'       => Path_Helper::get_uploads_dir_url(),
			'is_woocommerce_active' => class_exists( 'WooCommerce' ),
			'server_info'           => $this->system_monitor->get_server_info(),
			// Cross-promo of our sibling WPCreatix plugins — already filtered to what this site can
			// actually use (see get_siblings()). Empty array = nothing relevant, render nothing.
			'siblings'              => $this->get_siblings(),
			// Engagement gate shared by the dashboard promo card and the rating banner: true once the
			// user has some history with the plugin, so a brand-new install is never nudged.
			'promo_should_prompt'   => $this->get_promo_should_prompt(),
			// Whether the rating ask has been permanently dismissed (shared with the PHP review notice).
			'review_dismissed'      => (bool) get_user_meta( get_current_user_id(), Promo_Controller::REVIEW_DISMISSED_META, true ),
		);
	}

	/**
	 * Build the filtered cross-promo list for the current site and user.
	 *
	 * All filtering happens here, server-side: WooCommerce-dependent products are dropped when
	 * WooCommerce is inactive (so a non-WooCommerce site sees nothing), and any product already
	 * installed and active is dropped (nothing left to prompt). Install and activate links route
	 * through WordPress core's own screens — never our servers — and are only populated when the
	 * current user holds the matching capability; the card falls back to the wp.org listing
	 * otherwise. No wp.org rating or install counts are exposed — benefit-led copy only.
	 *
	 * The AI Sales Agent is listed first so the dashboard card, which shows a single top pick,
	 * prefers it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_siblings() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$woo_active   = class_exists( 'WooCommerce' );
		$can_install  = current_user_can( 'install_plugins' );
		$can_activate = current_user_can( 'activate_plugins' );
		$user_id      = get_current_user_id();

		$catalog = array(
			array(
				'slug'         => 'wpcreatix-ai-sales-agent',
				'name'         => __( 'WPCreatix AI Sales Agent', 'media-sweep' ),
				'tagline'      => __( 'An AI sales agent that answers product questions and walks shoppers to checkout.', 'media-sweep' ),
				'requires_woo' => true,
			),
			array(
				'slug'         => 'vidshop-for-woocommerce',
				'name'         => __( 'VidShop', 'media-sweep' ),
				'tagline'      => __( 'Turn shoppers into buyers with shoppable video feeds.', 'media-sweep' ),
				'requires_woo' => true,
			),
		);

		$siblings = array();

		foreach ( $catalog as $product ) {
			// Drop WooCommerce-dependent products on a store without WooCommerce.
			if ( $product['requires_woo'] && ! $woo_active ) {
				continue;
			}

			$slug      = $product['slug'];
			$file      = $slug . '/' . $slug . '.php';
			$installed = file_exists( WP_PLUGIN_DIR . '/' . $file );
			$active    = $installed && is_plugin_active( $file );

			// Drop products already installed and active — nothing left to promote.
			if ( $active ) {
				continue;
			}

			// Native install: core's plugin search, pre-filtered to the plugin, so the merchant reads
			// the full listing before installing. Only when the user can actually install.
			$install_url = ( ! $installed && $can_install )
				? self_admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( $product['name'] ) )
				: '';

			// Installed-but-inactive: a normal, nonce-protected core activation link. `wp_nonce_url()`
			// HTML-encodes the ampersands for direct HTML output; decode them so the URL survives being
			// handed to JS and used as a React href (otherwise `&amp;` reaches the server and 403s).
			$activate_url = ( $installed && $can_activate )
				? html_entity_decode( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ) )
				: '';

			$siblings[] = array(
				'slug'         => $slug,
				'name'         => $product['name'],
				'tagline'      => $product['tagline'],
				'installed'    => $installed,
				'active'       => $active,
				'can_install'  => $can_install,
				'wporg_url'    => 'https://wordpress.org/plugins/' . $slug . '/',
				'install_url'  => $install_url,
				'activate_url' => $activate_url,
				'dismissed'    => (bool) get_user_meta( $user_id, Promo_Controller::dismissed_meta_key( $slug ), true ),
			);
		}

		return $siblings;
	}

	/**
	 * Engagement gate for the dashboard promo card and the rating banner.
	 *
	 * Mirrors the review notice's "give it a week / show some value first" logic so a fresh install is
	 * never cross-sold or asked to rate: true once the user has completed at least one scan, or once
	 * seven days have passed since their first admin view (the review notice's `first_seen` latch).
	 *
	 * @return bool
	 */
	private function get_promo_should_prompt() {
		// Completed at least one scan (a finished scan has a `finished_at`).
		if ( Scan_Model::query()->where_not_null( 'finished_at' )->count() > 0 ) {
			return true;
		}

		// Or seven days since the first admin view. Reuse the review notice's first-seen latch; if it
		// has not been set yet, the user is brand new and we hold off.
		$first_seen = get_user_meta( get_current_user_id(), 'mswp_review_notice_first_seen', true );
		if ( empty( $first_seen ) ) {
			return false;
		}

		return ( ( time() - intval( $first_seen ) ) / DAY_IN_SECONDS ) >= 7;
	}

	/**
	 * Check if the current page is an admin page.
	 *
	 * @return bool
	 */
	public static function is_admin_page(): bool {
		// Check if the current page is an admin page.
		$current_screen = get_current_screen();
		if ( ! $current_screen ) {
			return false;
		}

		// Check if the current screen ID matches the admin page slug.
		return strpos( $current_screen->id, 'mswp' ) !== false;
	}

	/**
	 * Runs before admin notices action and hides them.
	 *
	 * @since 1.0.0
	 */
	public static function inject_before_notices() {
		if ( ! self::is_admin_page() ) {
			return;
		}

		// Wrap the notices in a hidden div to prevent flickering before
		// they are moved elsewhere in the page by WordPress Core.
		echo '<div class="mswp-layout__notice-list-hide" style="display: none;" id="wp__notice-list">';

		if ( self::is_admin_page() ) {
			// Capture all notices and hide them. WordPress Core looks for
			// `.wp-header-end` and appends notices after it if found.
			// https://github.com/WordPress/WordPress/blob/f6a37e7d39e2534d05b9e542045174498edfe536/wp-admin/js/common.js#L737 .
			echo '<div class="wp-header-end" id="mswp-layout__notice-catcher"></div>';
		}
	}

	/**
	 * Runs after admin notices and closes div.
	 *
	 * @since 1.0.0
	 */
	public static function inject_after_notices() {
		if ( ! self::is_admin_page() ) {
				return;
		}
		// Close the hidden div used to prevent notices from flickering before
		// they are inserted elsewhere in the page.
		echo '</div>';
	}

	/**
	 * Remove Notices.
	 *
	 * @since 1.0.0
	 */
	public function remove_notices() {

		if ( ! self::is_admin_page() ) {
			return;
		}

		// Hello Dolly.
		if ( function_exists( 'hello_dolly' ) ) {
			remove_action( 'admin_notices', 'hello_dolly' );
		}
	}

	/**
	 * Change Admin footer text.
	 *
	 * @param string $text The text to change.
	 * @return string
	 */
	public function change_admin_footer_text( $text ) {
		if ( ! self::is_admin_page() ) {
			return $text;
		}

		/* translators: 1: Thanks for using, 2: Media Sweep, 3: crafted with ❤️ by */
		return sprintf(
			'<span id="footer-thankyou">%1$s <strong>%2$s</strong> — %3$s <a href="https://wpcreatix.com" target="_blank" rel="noopener">WPCreatix</a></span>',
			esc_html__( 'Thanks for using', 'media-sweep' ),
			esc_html__( 'Media Sweep', 'media-sweep' ),
			esc_html__( 'crafted with ❤️ by', 'media-sweep' )
		);
	}

	/**
	 * Add plugin action links on plugins page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$custom_links = array(
			'dashboard' => sprintf(
				'<a href="%s" style="color: #4361ee; font-weight: 600;">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ),
				esc_html__( 'Dashboard', 'media-sweep' )
			),
			'settings'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '&path=settings' ) ),
				esc_html__( 'Settings', 'media-sweep' )
			),
		);

		// Merge custom links with existing links.
		return array_merge( $custom_links, $links );
	}
}
