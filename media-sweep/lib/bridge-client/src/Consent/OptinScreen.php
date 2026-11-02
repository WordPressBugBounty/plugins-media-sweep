<?php
/**
 * First-visit opt-in screen (design 4-V2): shown instead of the plugin UI on our own screens, once per site.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;

/**
 * Usage only; Allow & Continue and Skip are the same size; Skip sends nothing.
 */
final class OptinScreen {

	/**
	 * Body class that hides the plugin UI while the screen is open.
	 */
	const BODY_CLASS = 'wcxbc-optin-open';

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Product whose screen is open, when the screen shows on this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $product = null;

	/**
	 * Whether the screen was printed on this request.
	 *
	 * @var bool
	 */
	private $printed = false;

	/**
	 * Opt-in screen for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Decide on current_screen whether this request shows the screen.
	 *
	 * @param \WP_Screen|null $screen Current screen.
	 * @return void
	 */
	public function decide( $screen = null ) {
		$id      = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		$product = '' === $id ? null : $this->client->products()->by_screen( $id );

		$this->product = null !== $product && self::asks( (string) $product['slug'] ) && $this->should_show() ? $product : null;
	}

	/**
	 * Whether a product wants the opt-in asked on its screens (false when the user already answered elsewhere, such as a Pro licence opt-in).
	 *
	 * @param string $slug Product slug.
	 * @return bool True to ask.
	 */
	public static function asks( $slug ) {
		return (bool) apply_filters( 'wpcreatix_bridge_client_ask', true, (string) $slug );
	}

	/**
	 * Whether the site still has to answer: no answer yet, usage off, a user who may decide.
	 *
	 * @return bool True when the screen should show.
	 */
	public function should_show() {
		$store = $this->client->store();

		return Client::can_decide() && ! (int) $store->get( 'optin_answered_at' ) && ! $store->usage() && ! $store->get( 'erase_pending' ) && null !== Texts::consent( 'usage' );
	}

	/**
	 * Whether the screen is open on this request.
	 *
	 * @return bool True when open.
	 */
	public function is_open() {
		return null !== $this->product;
	}

	/**
	 * Add the body class that hides the plugin UI (admin_body_class filter).
	 *
	 * @param string $classes Body classes.
	 * @return string Classes.
	 */
	public function body_class( $classes ) {
		return $this->is_open() ? rtrim( (string) $classes ) . ' ' . self::BODY_CLASS . ' ' : (string) $classes;
	}

	/**
	 * Print the screen first inside #wpbody-content (all_admin_notices).
	 *
	 * @return void
	 */
	public function auto() {
		if ( $this->is_open() ) {
			$this->render( (string) $this->product['slug'] );
		}
	}

	/**
	 * Print the screen for a product (also the wpcreatix_bridge_client_card action; prints once per request).
	 *
	 * @param string $slug Product slug.
	 * @return void
	 */
	public function render( $slug = '' ) {
		if ( $this->printed || ! $this->is_open() || ( '' !== (string) $slug && (string) $slug !== (string) $this->product['slug'] ) ) {
			return;
		}

		$this->printed = true;
		$this->client->enqueue_assets();

		echo $this->html( $this->product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}

	/**
	 * Whether this site had the plugin before today (an update, not a new install).
	 *
	 * @param string $slug Product slug.
	 * @return bool True for an existing install.
	 */
	private function existing_install( $slug ) {
		$records = $this->client->store()->get( 'plugins' );
		$date    = is_array( $records ) && isset( $records[ $slug ]['installed_at'] ) ? (string) $records[ $slug ]['installed_at'] : '';

		return '' !== $date && $date < gmdate( 'Y-m-d', $this->client->now() );
	}

	/**
	 * Screen markup.
	 *
	 * @param array<string,mixed> $product Product.
	 * @return string HTML.
	 */
	public function html( array $product ) {
		$vars    = array(
			'plugin_name'    => (string) $product['name'],
			'plugin_version' => (string) $product['version'],
		);
		$update  = $this->existing_install( (string) $product['slug'] ) && '' !== (string) $product['version'];
		$bullets = Texts::usage_bullets();

		ob_start();
		?>
		<div class="wcxbc-optin wcxbc-ui" data-wcxbc-optin>
			<section class="wcxbc-oi" aria-labelledby="wcxbc-oi-title">
				<div class="wcxbc-oi-stub">
					<span><?php echo esc_html( Texts::get( 'card.stub_label' ) ); ?></span>
					<img src="<?php echo esc_url( $this->client->asset_url( 'assets/logo.svg' ) ); ?>" width="52" height="18" alt="WPCreatix" />
				</div>
				<div class="wcxbc-oi-pad">
					<?php if ( $update ) : ?>
						<p class="wcxbc-oi-upd"><b><?php echo esc_html( Texts::get( 'card.update_badge', $vars ) ); ?></b><span><?php echo esc_html( Texts::get( 'card.update_line' ) ); ?></span></p>
					<?php endif; ?>
					<div class="wcxbc-oi-who"><?php echo self::icon( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() escapes. ?><?php echo esc_html( (string) $product['name'] ); ?></div>
					<h2 id="wcxbc-oi-title"><?php echo esc_html( Texts::get( 'card.title', $vars ) ); ?></h2>
					<p class="wcxbc-oi-lead"><?php echo esc_html( Texts::usage_sentence() ); ?><?php echo is_multisite() ? ' ' . esc_html( Texts::get( 'card.multisite_note' ) ) : ''; ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-wcxbc-form="optin">
						<input type="hidden" name="action" value="<?php echo esc_attr( Config::AJAX ); ?>" />
						<input type="hidden" name="via" value="<?php echo esc_attr( (string) $product['slug'] ); ?>" />
						<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( Config::AJAX ) ); ?>" />
						<div class="wcxbc-oi-btns">
							<button type="submit" class="wcxbc-bt wcxbc-bt-pri" name="op" value="optin_allow"><?php echo esc_html( Texts::get( 'card.button_allow' ) ); ?></button>
							<button type="submit" class="wcxbc-bt wcxbc-bt-out" name="op" value="optin_skip"><?php echo esc_html( Texts::get( 'card.button_no_thanks' ) ); ?></button>
						</div>
						<p class="wcxbc-msg" role="status" aria-live="polite" data-wcxbc-message></p>
					</form>
					<div class="wcxbc-oi-more">
						<details class="wcxbc-oi-wwc" data-wcxbc-wwc>
							<summary><span data-wcxbc-closed><?php echo esc_html( Texts::get( 'card.expander_label' ) ); ?></span><span data-wcxbc-opened><?php echo esc_html( Texts::get( 'card.expander_label_open' ) ); ?></span><?php echo self::chevron(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></summary>
							<ul>
								<?php foreach ( $bullets as $bullet ) : ?>
									<li><?php echo esc_html( $bullet ); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="wcxbc-oi-lk"><button type="button" class="wcxbc-linkbtn" data-wcxbc-open-preview><?php echo esc_html( Texts::get( 'card.preview_link' ) ); ?></button></p>
						</details>
						<a class="wcxbc-oi-pp" href="<?php echo esc_url( Config::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( Texts::get( 'card.privacy_link' ) ); ?></a>
					</div>
				</div>
			</section>
			<?php echo Preview::panel( $vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Preview escapes every value. ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Plugin icon, or a letter tile when the product has none.
	 *
	 * @param array<string,mixed> $product Product.
	 * @return string HTML.
	 */
	public static function icon( array $product ) {
		if ( '' !== (string) $product['icon'] ) {
			return '<img class="wcxbc-icon" src="' . esc_url( (string) $product['icon'] ) . '" width="36" height="36" alt="" />';
		}

		$letter = strtoupper( substr( (string) $product['name'], 0, 1 ) );

		return '<span class="wcxbc-icon wcxbc-icon-letter" aria-hidden="true">' . esc_html( $letter ) . '</span>';
	}

	/**
	 * Small chevron for the "What we collect" toggle.
	 *
	 * @return string SVG.
	 */
	public static function chevron() {
		return '<svg class="wcxbc-chev" viewBox="0 0 12 12" width="11" height="11" aria-hidden="true" focusable="false"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}
}
