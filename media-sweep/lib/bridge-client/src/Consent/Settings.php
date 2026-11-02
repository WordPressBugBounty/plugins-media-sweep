<?php
/**
 * "Data sharing" panel for a host plugin's settings page (wpcreatix_bridge_client_settings action).
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;

/**
 * Three things only: the usage toggle, "See exactly what is sent" and "Delete my data".
 */
final class Settings {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Settings panel for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Print the panel (users who may decide only).
	 *
	 * @param string $slug Product slug.
	 * @return void
	 */
	public function render( $slug = '' ) {
		if ( ! Client::can_decide() ) {
			return;
		}

		$this->client->enqueue_assets();

		echo $this->html( (string) $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}

	/**
	 * Panel markup.
	 *
	 * @param string $slug    Product slug.
	 * @param string $message Message to show (after a change).
	 * @return string HTML.
	 */
	public function html( $slug, $message = '' ) {
		$store   = $this->client->store();
		$usage   = $store->usage();
		$pending = (bool) $store->get( 'erase_pending' );

		ob_start();
		?>
		<div class="wcxbc-settings" data-wcxbc-settings>
			<h2><?php echo esc_html( Texts::get( 'settings.section_title' ) ); ?></h2>
			<p class="description"><?php echo esc_html( Texts::get( 'settings.shared_note' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-wcxbc-form="usage">
				<input type="hidden" name="action" value="<?php echo esc_attr( Config::AJAX ); ?>" />
				<input type="hidden" name="op" value="usage" />
				<input type="hidden" name="via" value="<?php echo esc_attr( $slug ); ?>" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( Config::AJAX ) ); ?>" />
				<input type="hidden" name="usage" value="0" />
				<p class="wcxbc-toggle">
					<label><input type="checkbox" role="switch" name="usage" value="1" <?php checked( $usage ); ?> data-wcxbc-autosave /> <?php echo esc_html( Texts::get( 'settings.toggle_usage' ) ); ?></label>
					<span class="wcxbc-status"><?php echo esc_html( Texts::get( $usage ? 'settings.usage_status_on' : 'settings.usage_status_off' ) ); ?></span>
				</p>
				<?php if ( ! $usage && $store->registered() ) : ?>
					<p class="wcxbc-note"><?php echo esc_html( Texts::get( 'settings.usage_off_notice' ) ); ?></p>
				<?php endif; ?>
				<p class="wcxbc-message" role="status" aria-live="polite" data-wcxbc-message><?php echo esc_html( $message ); ?></p>
			</form>
			<?php echo $this->data_tools( $pending ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data_tools() escapes every value. ?>
			<p class="description"><a href="<?php echo esc_url( Config::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( Texts::get( 'card.privacy_link' ) ); ?></a></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "See exactly what is sent" and "Delete my data".
	 *
	 * @param bool $pending Whether an erase is waiting to be retried.
	 * @return string HTML.
	 */
	private function data_tools( $pending ) {
		$store = $this->client->store();
		$last  = (string) $store->get( 'last_payload' );
		$at    = (int) $store->get( 'last_payload_at' );
		$date  = $at ? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $at ) : '';

		ob_start();
		?>
		<details class="wcxbc-last">
			<summary><?php echo esc_html( Texts::get( 'settings.button_show_last_data' ) ); ?></summary>
			<?php if ( '' === $last ) : ?>
				<p><?php echo esc_html( Texts::get( 'what_we_send_panel.empty' ) ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( Texts::get( 'what_we_send_panel.intro_after_save', array( 'date' => (string) $date ) ) ); ?></p>
				<pre class="wcxbc-json"><?php echo esc_html( self::pretty( $last ) ); ?></pre>
			<?php endif; ?>
		</details>
		<?php if ( $pending ) : ?>
			<p class="wcxbc-note"><?php echo esc_html( Texts::get( 'settings.delete_dialog.failed' ) ); ?></p>
		<?php endif; ?>
		<?php if ( $store->registered() && ! $pending ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-wcxbc-form="erase" data-wcxbc-confirm="<?php echo esc_attr( Texts::get( 'settings.delete_dialog.title' ) . "\n\n" . Texts::get( 'settings.delete_dialog.body' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Config::AJAX ); ?>" />
				<input type="hidden" name="op" value="erase" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( Config::AJAX ) ); ?>" />
				<p><button type="submit" class="button button-link-delete"><?php echo esc_html( Texts::get( 'settings.button_delete_data' ) ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Message for a usage change result.
	 *
	 * @param string $result Result from Consents::set_usage().
	 * @return string Message.
	 */
	public static function message( $result ) {
		return 'retry' === $result ? Texts::get( 'card.retry_notice' ) : '';
	}

	/**
	 * Pretty JSON of an encoded body for display.
	 *
	 * @param string $json Encoded body.
	 * @return string Pretty JSON.
	 */
	public static function pretty( $json ) {
		$data = json_decode( (string) $json );

		return null === $data ? '' : (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
