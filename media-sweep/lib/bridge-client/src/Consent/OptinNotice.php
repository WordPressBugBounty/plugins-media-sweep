<?php
/**
 * One-time notice after Skip (design 5-V2): seven days later, on our own screens only, dismissible.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;

/**
 * Allow and No thanks are the same size; any answer ends the notice for good on this site.
 */
final class OptinNotice {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Whether the notice was printed on this request.
	 *
	 * @var bool
	 */
	private $printed = false;

	/**
	 * Notice for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Whether the notice should show now (on any screen; render() also checks the screen).
	 *
	 * @return bool True when it should.
	 */
	public function should_show() {
		$store   = $this->client->store();
		$skipped = (int) $store->get( 'optin_skipped_at' );

		if ( ! Client::can_decide() || ! current_user_can( 'manage_options' ) || $store->usage() || $store->get( 'notice_done' ) || $store->get( 'erase_pending' ) ) {
			return false;
		}

		return $skipped > 0 && $this->client->now() >= $skipped + Config::NOTICE_DELAY && null !== Texts::consent( 'usage' );
	}

	/**
	 * Print the notice on one of our screens (all_admin_notices; "below-h2" keeps core from moving it into a hidden notice area).
	 *
	 * @return void
	 */
	public function render() {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$product = null === $screen ? null : $this->client->products()->by_screen( (string) $screen->id );

		if ( $this->printed || null === $product || ! OptinScreen::asks( (string) $product['slug'] ) || ! $this->should_show() ) {
			return;
		}

		$this->printed = true;
		// Shown once: an ignored notice does not come back (its buttons still answer).
		$this->client->store()->set( array( 'notice_done' => true ) );
		$this->client->enqueue_assets();

		echo $this->html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}

	/**
	 * Notice markup.
	 *
	 * @param array<string,mixed> $product Product.
	 * @return string HTML.
	 */
	public function html( array $product ) {
		$vars = array( 'plugin_name' => (string) $product['name'] );

		ob_start();
		?>
		<div class="notice below-h2 wcxbc-ntc wcxbc-ui" data-wcxbc-notice data-state="idle">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-wcxbc-form="notice">
				<input type="hidden" name="action" value="<?php echo esc_attr( Config::AJAX ); ?>" />
				<input type="hidden" name="via" value="<?php echo esc_attr( (string) $product['slug'] ); ?>" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( Config::AJAX ) ); ?>" />
				<div class="wcxbc-ntc-line">
					<?php echo OptinScreen::icon( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() escapes. ?>
					<p><strong><?php echo esc_html( Texts::get( 'optin_notice.lead', $vars ) ); ?></strong> <?php echo esc_html( Texts::usage_sentence() ); ?></p>
					<span class="wcxbc-ntc-acts">
						<button type="submit" class="wcxbc-obt wcxbc-obt-pri" name="op" value="notice_allow"><?php echo esc_html( Texts::get( 'optin_notice.button_allow' ) ); ?></button>
						<button type="submit" class="wcxbc-obt" name="op" value="notice_dismiss"><?php echo esc_html( Texts::get( 'optin_notice.button_no_thanks' ) ); ?></button>
						<button type="button" class="wcxbc-ntc-link" aria-expanded="false" aria-controls="wcxbc-ntc-det" data-wcxbc-details><?php echo esc_html( Texts::get( 'optin_notice.details_link' ) ); ?></button>
					</span>
				</div>
				<div class="wcxbc-ntc-det" id="wcxbc-ntc-det" hidden data-wcxbc-details-panel>
					<ul>
						<?php foreach ( Texts::usage_bullets() as $bullet ) : ?>
							<li><?php echo esc_html( $bullet ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<p class="wcxbc-ntc-done" role="status" aria-live="polite" data-wcxbc-message></p>
				<button type="submit" class="wcxbc-ntc-x" name="op" value="notice_dismiss" aria-label="<?php echo esc_attr( Texts::get( 'optin_notice.dismiss_aria' ) ); ?>"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="M3.5 3.5l9 9M12.5 3.5l-9 9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
