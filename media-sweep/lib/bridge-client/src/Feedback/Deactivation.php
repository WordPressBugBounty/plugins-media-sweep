<?php
/**
 * Optional deactivation feedback modal on the plugins screen; sends /v1/feedback without waiting.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Feedback;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Consent\Consents;
use WPCreatix\BridgeClient\Consent\Texts;
use WPCreatix\BridgeClient\Snapshot\Buckets;
use WPCreatix\BridgeClient\Snapshot\Env;
use WPCreatix\BridgeClient\Sync\Bodies;

/**
 * Skip sends nothing; the uid is sent only with usage consent.
 */
final class Deactivation {

	/**
	 * Max feedback text length.
	 */
	const MAX_TEXT = 500;

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Feedback for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Print the modal on the plugins screen (admin_footer).
	 *
	 * @return void
	 */
	public function render() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! in_array( (string) $screen->id, array( 'plugins', 'plugins-network' ), true ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$plugins = array();

		foreach ( $this->client->products()->all() as $product ) {
			$plugins[] = array(
				'basename' => $product['basename'],
				'slug'     => $product['slug'],
				'name'     => $product['name'],
			);
		}

		if ( ! $plugins ) {
			return;
		}

		echo $this->html( $plugins ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}

	/**
	 * Modal markup; {plugin_name} is filled by the script per plugin.
	 *
	 * @param array<int,array<string,string>> $plugins Our plugins on this screen.
	 * @return string HTML.
	 */
	public function html( array $plugins ) {
		$reasons = Texts::node( 'deactivation_feedback.reasons' );
		$data    = array(
			'plugins' => $plugins,
			'email'   => (string) wp_get_current_user()->user_email,
		);

		ob_start();
		?>
		<div class="wcxbc-modal" hidden data-wcxbc-feedback role="dialog" aria-modal="true" aria-labelledby="wcxbc-feedback-title">
			<form class="wcxbc-modal-box" data-wcxbc-feedback-form>
				<h2 id="wcxbc-feedback-title"><?php echo esc_html( Texts::get( 'deactivation_feedback.title' ) ); ?></h2>
				<p data-wcxbc-template="<?php echo esc_attr( Texts::get( 'deactivation_feedback.intro' ) ); ?>"></p>
				<fieldset class="wcxbc-reasons">
					<?php foreach ( is_array( $reasons ) ? $reasons : array() as $reason ) : ?>
						<label><input type="radio" name="reason" value="<?php echo esc_attr( (string) $reason['code'] ); ?>" data-placeholder="<?php echo esc_attr( (string) $reason['text_placeholder'] ); ?>" /> <?php echo esc_html( (string) $reason['label'] ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="wcxbc-fields" hidden data-wcxbc-text-row>
					<textarea name="text" rows="3" maxlength="<?php echo esc_attr( (string) self::MAX_TEXT ); ?>"></textarea>
					<span class="description"><?php echo esc_html( Texts::get( 'deactivation_feedback.text_hint' ) ); ?></span>
				</p>
				<p><label><input type="checkbox" name="reply" value="1" data-wcxbc-reply /> <?php echo esc_html( Texts::get( 'deactivation_feedback.reply_checkbox' ) ); ?></label></p>
				<p class="wcxbc-fields" hidden data-wcxbc-reply-row>
					<label><?php echo esc_html( Texts::get( 'deactivation_feedback.reply_email_label' ) ); ?> <input type="email" name="reply_email" maxlength="190" autocomplete="email" value="" /></label>
					<span class="description"><?php echo esc_html( Texts::get( 'deactivation_feedback.reply_email_note' ) ); ?></span>
				</p>
				<p class="description" data-wcxbc-template="<?php echo esc_attr( Texts::get( 'deactivation_feedback.disclosure' ) ); ?>"></p>
				<p class="wcxbc-actions">
					<button type="submit" class="button" data-wcxbc-submit><?php echo esc_html( Texts::get( 'deactivation_feedback.button_submit' ) ); ?></button>
					<button type="button" class="button" data-wcxbc-skip><?php echo esc_html( Texts::get( 'deactivation_feedback.button_skip' ) ); ?></button>
				</p>
			</form>
			<script type="application/json" data-wcxbc-feedback-data><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build and fire the feedback request.
	 *
	 * @param array<string,string> $fields slug, reason, text, reply, reply_email.
	 * @return array<string,mixed>|null Body sent, or null when the plugin is not ours.
	 */
	public function send( array $fields ) {
		$client  = $this->client;
		$store   = $client->store();
		$product = $client->products()->get( isset( $fields['slug'] ) ? (string) $fields['slug'] : '' );

		if ( null === $product ) {
			return null;
		}

		// Only a user who may decide for this install sends with the site token (a sub-site admin sends anonymously).
		$blocked = $client->blocked();
		$shared  = Client::can_decide() && $store->usage() && $store->registered() && '' === $blocked;
		$body    = Bodies::feedback( self::fields( $product, $fields, $client->lifecycle()->days_installed( $product['slug'] ) ), $shared ? (string) $store->get( 'uid' ) : '' );

		// A stop, protocol stop or slow_down means no calls at all; the body is still returned so the UI goes on.
		if ( in_array( $blocked, array( 'stopped', 'protocol' ), true ) || (int) $store->get( 'backoff_until' ) > $client->now() ) {
			return $body;
		}

		$client->remember_payload( $body );
		$client->transport()->fire( 'feedback', $body, $shared ? (string) $store->get( 'token' ) : '' );

		return $body;
	}

	/**
	 * Clean feedback fields in wire order.
	 *
	 * @param array<string,mixed>  $product Product.
	 * @param array<string,string> $fields  Raw fields.
	 * @param int                  $days    Days installed.
	 * @return array<string,mixed> Fields.
	 */
	public static function fields( array $product, array $fields, $days ) {
		$codes  = array();
		$reason = isset( $fields['reason'] ) ? (string) $fields['reason'] : '';
		$text   = isset( $fields['text'] ) ? trim( (string) $fields['text'] ) : '';

		foreach ( (array) Texts::node( 'deactivation_feedback.reasons' ) as $row ) {
			$codes[] = is_array( $row ) && isset( $row['code'] ) ? (string) $row['code'] : '';
		}

		$reply = ! empty( $fields['reply'] ) && '1' === (string) $fields['reply'] ? Consents::clean_email( isset( $fields['reply_email'] ) ? (string) $fields['reply_email'] : '' ) : '';

		return array(
			'plugin'         => (string) $product['slug'],
			'version'        => Env::version( (string) $product['version'] ),
			'reason'         => in_array( $reason, $codes, true ) ? $reason : 'other',
			'text'           => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT ),
			'days_installed' => Buckets::days( $days ),
			'reply_email'    => '' === $reply ? null : $reply,
			'wp'             => Env::version( (string) get_bloginfo( 'version' ) ),
			'locale'         => substr( (string) get_locale(), 0, 20 ),
		);
	}
}
