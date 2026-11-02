<?php
/**
 * "See exactly what is sent" panel of the opt-in screen: the register body plus every plugin and theme list part.
 *
 * @package WPCreatix\BridgeClient
 */

namespace WPCreatix\BridgeClient\Consent;

use WPCreatix\BridgeClient\Client;
use WPCreatix\BridgeClient\Identity;
use WPCreatix\BridgeClient\Snapshot\Snapshot;
use WPCreatix\BridgeClient\Sync\Bodies;

/**
 * Builds the bodies with the same code that sends them, so the preview is exact.
 */
final class Preview {

	/**
	 * The register body Allow would send, and the list parts that follow it.
	 *
	 * @param Client              $client  Client.
	 * @param array<string,mixed> $product Product.
	 * @return array{body:array<string,mixed>,extensions:array<int,array<string,mixed>>} Bodies.
	 */
	public static function bodies( Client $client, array $product ) {
		$store = $client->store();
		$uid   = (string) $store->get( 'uid' );

		// The uid shown in the preview is the one Allow will register.
		if ( ! Identity::is_uid( $uid ) || $store->get( 'gone' ) ) {
			$uid = Identity::new_uid();
			$store->set( array( 'uid' => $uid ) );
		}

		$state          = $store->all();
		$proof          = Texts::proof( 'usage' );
		$state['usage'] = array(
			'granted'      => true,
			'text_version' => null === $proof ? '' : $proof['text_version'],
			'at'           => $client->now(),
		);

		return array(
			'body'       => Bodies::register( $state, $uid, null, 'new', $client->lib(), (string) $product['slug'], Identity::home(), Snapshot::build( $store, $client->products(), $client->now() ) ),
			'extensions' => $client->syncer()->preview( $uid ),
		);
	}

	/**
	 * Panel markup (a modal dialog, hidden until opened; the script fills it from the preview op).
	 *
	 * @param array<string,string> $vars Placeholders.
	 * @return string HTML.
	 */
	public static function panel( array $vars ) {
		ob_start();
		?>
		<div class="wcxbc-preview" hidden data-wcxbc-preview role="dialog" aria-modal="true" aria-labelledby="wcxbc-preview-title">
			<div class="wcxbc-preview-box">
				<h3 id="wcxbc-preview-title"><?php echo esc_html( Texts::get( 'what_we_send_panel.title' ) ); ?></h3>
				<p><?php echo esc_html( Texts::get( 'what_we_send_panel.intro_before_save', $vars ) ); ?></p>
				<pre class="wcxbc-json" data-wcxbc-preview-json></pre>
				<div data-wcxbc-preview-ext-wrap hidden>
					<h4><?php echo esc_html( Texts::get( 'what_we_send_panel.plugin_list_heading' ) ); ?></h4>
					<pre class="wcxbc-json" data-wcxbc-preview-ext></pre>
				</div>
				<p><button type="button" class="wcxbc-bt wcxbc-bt-out" data-wcxbc-close-preview><?php echo esc_html( Texts::get( 'what_we_send_panel.button_close' ) ); ?></button></p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
