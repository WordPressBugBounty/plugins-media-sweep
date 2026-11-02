<?php
/**
 * One admin-ajax endpoint for the opt-in screen, notice, settings, erase, newsletter card and deactivation feedback.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Config;
use WPCreatix\BridgeClient\Feedback\Deactivation;
use WPCreatix\BridgeClient\Newsletter\Box;

/**
 * Nonce and capability on every op; answers JSON for the script, without the script it redirects back.
 */
final class Ajax {

	/**
	 * Ops that need a user who may decide for the site.
	 *
	 * @var array<int,string>
	 */
	const OPS = array( 'optin_allow', 'optin_skip', 'notice_allow', 'notice_dismiss', 'usage', 'preview', 'erase', 'newsletter', 'newsletter_hide' );

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Ajax router for a client.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Route one request.
	 *
	 * @return void
	 */
	public function handle() {
		check_ajax_referer( Config::AJAX, 'nonce' );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'feedback' === $op ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
			}

			( new Deactivation( $this->client ) )->send( self::fields( array( 'slug', 'reason', 'text', 'reply', 'reply_email' ) ) );
			wp_send_json_success( array( 'result' => 'sent' ) );
		}

		if ( ! in_array( $op, self::OPS, true ) ) {
			wp_send_json_error( array( 'message' => 'unknown op' ), 400 );
		}

		if ( ! Client::can_decide() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$fields = self::fields( array( 'usage', 'email', 'via' ) );
		$via    = null === $this->client->products()->get( $fields['via'] ) ? '' : $fields['via'];

		switch ( $op ) {
			case 'optin_allow':
				$this->op_optin_allow( $via );
				break;
			case 'optin_skip':
				$this->op_optin_skip();
				break;
			case 'notice_allow':
				$this->op_notice_allow( $via );
				break;
			case 'notice_dismiss':
				$this->op_notice_dismiss();
				break;
			case 'usage':
				$this->op_usage( '1' === $fields['usage'], $via );
				break;
			case 'preview':
				$this->op_preview( $via );
				break;
			case 'erase':
				$this->op_erase( $via );
				break;
			case 'newsletter':
				$this->op_newsletter( $fields['email'], $via );
				break;
			default:
				$this->op_newsletter_hide();
		}
	}

	/**
	 * Opt-in screen Allow & Continue.
	 *
	 * @param string $via Plugin slug.
	 * @return void
	 */
	private function op_optin_allow( $via ) {
		$result = $this->client->consents()->allow( $via );

		$this->reply( $result, Texts::get( 'usage' === $result ? 'card.saved_usage' : 'card.retry_notice' ), true );
	}

	/**
	 * Opt-in screen Skip.
	 *
	 * @return void
	 */
	private function op_optin_skip() {
		$this->reply( $this->client->consents()->skip(), Texts::get( 'card.saved_nothing' ), true );
	}

	/**
	 * Notice Allow.
	 *
	 * @param string $via Plugin slug.
	 * @return void
	 */
	private function op_notice_allow( $via ) {
		$result = $this->client->consents()->notice_allow( $via );

		$this->reply( $result, Texts::get( 'usage' === $result ? 'optin_notice.done_yes' : 'card.retry_notice' ), true );
	}

	/**
	 * Notice No thanks or X.
	 *
	 * @return void
	 */
	private function op_notice_dismiss() {
		$this->reply( $this->client->consents()->notice_dismiss(), '', true );
	}

	/**
	 * Settings usage toggle; returns the new panel.
	 *
	 * @param bool   $on  Usage wanted.
	 * @param string $via Plugin slug.
	 * @return void
	 */
	private function op_usage( $on, $via ) {
		$result  = $this->client->consents()->set_usage( (bool) $on, $via );
		$message = Settings::message( $result );

		$this->reply( $result, $message, false, ( new Settings( $this->client ) )->html( $via, $message ) );
	}

	/**
	 * The exact bodies Allow would send (for the "See exactly what is sent" panel).
	 *
	 * @param string $via Plugin slug.
	 * @return void
	 */
	private function op_preview( $via ) {
		$product = $this->client->products()->get( $via );

		if ( null === $product ) {
			wp_send_json_error( array( 'message' => 'unknown plugin' ), 400 );
		}

		wp_send_json_success( Preview::bodies( $this->client, $product ) );
	}

	/**
	 * Delete my data; returns the new panel.
	 *
	 * @param string $via Plugin slug.
	 * @return void
	 */
	private function op_erase( $via ) {
		$result  = $this->client->eraser()->start();
		$message = Texts::get( 'ok' === $result ? 'settings.delete_dialog.done' : 'settings.delete_dialog.failed' );

		$this->reply( $result, $message, false, ( new Settings( $this->client ) )->html( $via, $message ) );
	}

	/**
	 * Newsletter card Subscribe.
	 *
	 * @param string $email Email typed.
	 * @param string $via   Plugin slug.
	 * @return void
	 */
	private function op_newsletter( $email, $via ) {
		$result = '' === $via ? 'error' : ( new Box( $this->client ) )->subscribe( $email, $via );

		$this->reply( $result, Box::message( $result ), 'subscribed' === $result );
	}

	/**
	 * Newsletter card Hide.
	 *
	 * @return void
	 */
	private function op_newsletter_hide() {
		Box::remember( 'dismissed' );
		$this->reply( 'dismissed', '', true );
	}

	/**
	 * Send the result as JSON, or redirect back when the script did not run.
	 *
	 * @param string $result  Result code.
	 * @param string $message Message for the user.
	 * @param bool   $done    Whether the surface is finished.
	 * @param string $html    Replacement panel markup.
	 * @return void
	 */
	private function reply( $result, $message, $done, $html = '' ) {
		if ( empty( $_POST['js'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle().
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
			exit;
		}

		wp_send_json_success(
			array(
				'result'  => $result,
				'message' => $message,
				'done'    => $done,
				'html'    => $html,
			)
		);
	}

	/**
	 * Read and clean POST fields (nonce already checked).
	 *
	 * @param array<int,string> $keys Field names.
	 * @return array<string,string> Clean values ('' when missing).
	 */
	private static function fields( array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle().
				$out[ $key ] = '';
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle().
			$out[ $key ] = 'text' === $key ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}

		return $out;
	}
}
