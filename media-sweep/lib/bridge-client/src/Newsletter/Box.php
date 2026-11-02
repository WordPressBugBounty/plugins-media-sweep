<?php
/**
 * Newsletter card (design 6-V3) on our plugins' own screens: do_action( 'wpcreatix_bridge_client_newsletter', '<slug>' ).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Newsletter;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Consent\OptinScreen;
use WPCreatix\BridgeClient\Consent\Texts;
use WPCreatix\BridgeClient\Store;
use WPCreatix\BridgeClient\Sync\Subscriber;

/**
 * No checkbox and no status: keeping or typing the email and pressing Subscribe is the consent.
 */
final class Box {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * The opt-in screen of this request (the card never shows while it is open).
	 *
	 * @var OptinScreen|null
	 */
	private $screen;

	/**
	 * Whether a card was printed on this request.
	 *
	 * @var bool
	 */
	private $printed = false;

	/**
	 * Card for a client.
	 *
	 * @param Client           $client Client.
	 * @param OptinScreen|null $screen Opt-in screen of this request.
	 */
	public function __construct( Client $client, $screen = null ) {
		$this->client = $client;
		$this->screen = $screen instanceof OptinScreen ? $screen : null;
	}

	/**
	 * Print the card for a product (action callback).
	 *
	 * @param mixed $slug Product slug.
	 * @return void
	 */
	public function render( $slug = '' ) {
		$product = $this->client->products()->get( (string) $slug );

		if ( $this->printed || null === $product || ! $this->should_show( $product ) ) {
			return;
		}

		$this->printed = true;
		$this->client->enqueue_assets();
		$this->enqueue();

		echo $this->html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}

	/**
	 * Whether the card may show now for a product.
	 *
	 * @param array<string,mixed> $product Product.
	 * @return bool True when it may.
	 */
	public function should_show( array $product ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! Client::can_decide() || ! current_user_can( 'manage_options' ) || null === $screen || ! in_array( (string) $screen->id, (array) $product['screens'], true ) ) {
			return false;
		}

		if ( '' !== self::choice() || in_array( $this->client->blocked(), array( 'stopped', 'protocol' ), true ) ) {
			return false;
		}

		return null === $this->screen || ! $this->screen->is_open();
	}

	/**
	 * Stored choice: dismissed, subscribed or ''.
	 *
	 * @return string Choice.
	 */
	public static function choice() {
		$value = Store::read_option( Config::NEWSLETTER_OPTION, '' );

		return in_array( $value, array( 'dismissed', 'subscribed' ), true ) ? (string) $value : '';
	}

	/**
	 * Save the choice for the whole site.
	 *
	 * @param string $choice dismissed or subscribed.
	 * @return void
	 */
	public static function remember( $choice ) {
		Store::write_option( Config::NEWSLETTER_OPTION, 'subscribed' === $choice ? 'subscribed' : 'dismissed' );
	}

	/**
	 * The site admin email (network admin email on multisite).
	 *
	 * @return string Email.
	 */
	public static function admin_email() {
		return strtolower( (string) ( is_multisite() ? get_site_option( 'admin_email', '' ) : get_option( 'admin_email', '' ) ) );
	}

	/**
	 * Subscribe (Ajax op newsletter): send, remember on success.
	 *
	 * @param string $email Email.
	 * @param string $slug  Product slug.
	 * @return string subscribed, email_invalid, rate_limited, error.
	 */
	public function subscribe( $email, $slug ) {
		$result = ( new Subscriber( $this->client ) )->send( $email, $slug );

		if ( 'subscribed' === $result ) {
			self::remember( 'subscribed' );
		}

		return 'invalid_email' === $result ? 'email_invalid' : $result;
	}

	/**
	 * Message for a subscribe result.
	 *
	 * @param string $result Result.
	 * @return string Message.
	 */
	public static function message( $result ) {
		$map = array(
			'subscribed'    => 'newsletter_box.success',
			'email_invalid' => 'newsletter_box.email_invalid',
			'rate_limited'  => 'newsletter_box.error_rate_limited',
			'error'         => 'newsletter_box.error',
		);

		return Texts::get( isset( $map[ $result ] ) ? $map[ $result ] : 'newsletter_box.error' );
	}

	/**
	 * Card markup.
	 *
	 * @param array<string,mixed> $product Product.
	 * @return string HTML.
	 */
	public function html( array $product ) {
		$hidden = array(
			'action' => Config::AJAX,
			'via'    => (string) $product['slug'],
			'nonce'  => wp_create_nonce( Config::AJAX ),
		);

		ob_start();
		?>
		<section class="wcxbn-box wcxbc-ui" data-wcxbn-box data-state="idle" aria-labelledby="wcxbn-title">
			<span class="wcxbn-pill"><?php echo esc_html( Texts::get( 'newsletter_box.pill_label' ) ); ?></span>
			<div class="wcxbn-tx">
				<h2 class="wcxbn-title" id="wcxbn-title"><?php echo esc_html( Texts::get( 'newsletter_box.title' ) ); ?></h2>
				<p class="wcxbn-desc"><?php echo esc_html( Texts::marketing_description() ); ?></p>
			</div>
			<form class="wcxbn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-wcxbn-form data-wcxbn-invalid="<?php echo esc_attr( Texts::get( 'newsletter_box.email_invalid' ) ); ?>" data-wcxbn-busy="<?php echo esc_attr( Texts::get( 'newsletter_box.button_busy' ) ); ?>" data-wcxbn-failed="<?php echo esc_attr( Texts::get( 'newsletter_box.error' ) ); ?>" novalidate>
				<?php self::hidden( $hidden + array( 'op' => 'newsletter' ) ); ?>
				<div class="wcxbn-row">
					<label class="wcxbn-sr" for="wcxbn-email"><?php echo esc_html( Texts::get( 'newsletter_box.email_label' ) ); ?></label>
					<input class="wcxbn-in" type="email" id="wcxbn-email" name="email" maxlength="190" autocomplete="email" required value="<?php echo esc_attr( self::admin_email() ); ?>" placeholder="<?php echo esc_attr( Texts::get( 'newsletter_box.email_placeholder' ) ); ?>" aria-describedby="wcxbn-msg" data-wcxbn-email />
					<button type="submit" class="wcxbn-sb" data-wcxbn-subscribe><span class="wcxbn-sb-t" aria-hidden="true"><svg viewBox="0 0 16 12" width="16" height="12" focusable="false"><path d="M1 6h14m0 0L10 1m5 5-5 5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="wcxbn-sb-l" data-wcxbn-label><?php echo esc_html( Texts::get( 'newsletter_box.button_subscribe' ) ); ?></span></button>
				</div>
				<div class="wcxbn-msg" id="wcxbn-msg" aria-live="polite">
					<p class="wcxbn-err" data-wcxbn-error></p>
					<p class="wcxbn-ok"><span class="wcxbn-tk" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" focusable="false"><path d="M2.5 8.5 6.5 12.5 13.5 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span data-wcxbn-success><?php echo esc_html( Texts::get( 'newsletter_box.success' ) ); ?></span></p>
				</div>
			</form>
			<div class="wcxbn-foot">
				<p class="wcxbn-fine"><?php echo esc_html( Texts::small_print() ); ?> <a href="<?php echo esc_url( Config::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( Texts::get( 'small_print.link_text' ) ); ?></a></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-wcxbn-dismiss-form>
					<?php self::hidden( $hidden + array( 'op' => 'newsletter_hide' ) ); ?>
					<button type="submit" class="wcxbn-hide" aria-label="<?php echo esc_attr( Texts::get( 'newsletter_box.dismiss_aria' ) ); ?>"><?php echo esc_html( Texts::get( 'newsletter_box.dismiss' ) ); ?></button>
				</form>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Print hidden inputs.
	 *
	 * @param array<string,string> $fields Name => value.
	 * @return void
	 */
	private static function hidden( array $fields ) {
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
	}

	/**
	 * Enqueue the card script and style.
	 *
	 * @return void
	 */
	private function enqueue() {
		wp_enqueue_style( 'wpcreatix-bridge-client-newsletter', $this->client->asset_url( 'assets/newsletter.css' ), array( 'wpcreatix-bridge-client' ), $this->client->lib() );
		wp_enqueue_script( 'wpcreatix-bridge-client-newsletter', $this->client->asset_url( 'assets/newsletter.js' ), array(), $this->client->lib(), true );
	}
}
