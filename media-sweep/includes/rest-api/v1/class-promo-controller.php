<?php
/**
 * Promo Controller
 *
 * Persists per-user dismissals for the in-app cross-promo of our sibling WPCreatix plugins and for
 * the rating ask. Stored in user meta (never a cookie or transient) so the choice follows the user
 * across devices and survives cache clears.
 *
 * @package media-sweep
 */

namespace Media_Sweep\REST_API\V1;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Promo Controller
 */
class Promo_Controller extends REST_Controller {

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'promo';

	/**
	 * Prefix for the per-sibling dismissal user-meta key. The slug is appended, e.g.
	 * `mswp_promo_dismissed_vidshop-for-woocommerce`.
	 *
	 * @var string
	 */
	const DISMISS_META_PREFIX = 'mswp_promo_dismissed_';

	/**
	 * User-meta key holding the permanent rating-ask dismissal. Shared with the PHP review notice
	 * (see Review_Notice) so dismissing either surface silences both.
	 *
	 * @var string
	 */
	const REVIEW_DISMISSED_META = 'mswp_review_notice_permanent_dismissed';

	/**
	 * Slugs of the sibling plugins Media Sweep is allowed to cross-promote. Any other value handed to
	 * the dismiss route is ignored rather than written, so the endpoint can never be used to set
	 * arbitrary user meta.
	 *
	 * @var array<int, string>
	 */
	const SIBLING_SLUGS = array(
		'vidshop-for-woocommerce',
		'wpcreatix-ai-sales-agent',
	);

	/**
	 * Build the dismissal user-meta key for a sibling slug.
	 *
	 * @param string $slug Sibling plugin slug.
	 * @return string
	 */
	public static function dismissed_meta_key( $slug ) {
		return self::DISMISS_META_PREFIX . $slug;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Per-sibling cross-promo dismissal (dashboard card).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'dismiss_sibling' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
					'args'                => array(
						'slug' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => self::SIBLING_SLUGS,
						),
					),
				),
			)
		);

		// Permanent dismissal of the rating ask (in-app rating banner).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dismiss-review',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'dismiss_review' ),
					'permission_callback' => array( $this, 'check_private_permission' ),
				),
			)
		);
	}

	/**
	 * Marks a sibling cross-promo as dismissed for the current user.
	 *
	 * An unknown slug is a no-op rather than an error, so the client can never write arbitrary meta.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function dismiss_sibling( $request ) {
		$slug = (string) $request->get_param( 'slug' );

		if ( in_array( $slug, self::SIBLING_SLUGS, true ) ) {
			update_user_meta( get_current_user_id(), self::dismissed_meta_key( $slug ), 1 );
		}

		return new WP_REST_Response( array( 'dismissed' => true ) );
	}

	/**
	 * Permanently dismisses the rating ask for the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function dismiss_review( $request ) {
		update_user_meta( get_current_user_id(), self::REVIEW_DISMISSED_META, 1 );

		return new WP_REST_Response( array( 'dismissed' => true ) );
	}
}
