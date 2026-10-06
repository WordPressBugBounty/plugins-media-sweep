<?php
/**
 * Abilities bootstrap for Media Sweep.
 *
 * Registers the plugin's two ability categories and every ability declared by
 * the provider classes in this directory. This layer owns no business logic:
 * each callback either calls an existing service method or dispatches to one
 * of the plugin's own REST routes.
 *
 * Nothing in includes/abilities/ names a core Abilities class. Every call goes
 * through the bare wp_* functions behind function_exists(), so on a WordPress
 * without the Abilities API these files are inert class definitions and the
 * plugin's "Requires at least: 5.8" header stays honest.
 *
 * @package media-sweep
 */

namespace Media_Sweep\Abilities;

/**
 * Registers Media Sweep's ability categories and abilities.
 */
class Abilities_Module {

	/**
	 * Category for read-only abilities. Nothing in it changes anything.
	 */
	const CATEGORY_AUDIT = 'media-sweep-audit';

	/**
	 * Category for abilities that write, move or delete.
	 *
	 * The split is deliberate: a reviewer can fetch this one category over
	 * REST and see the exact list of everything that can destroy a file.
	 */
	const CATEGORY_ACTIONS = 'media-sweep-actions';

	/**
	 * Provider classes, each exposing a static definitions( $module ) method.
	 *
	 * A provider whose class is missing is skipped, so this list may name a
	 * class before its file ships.
	 */
	const PROVIDERS = array(
		Scan_Abilities::class,
		Audit_Abilities::class,
		Sweep_Abilities::class,
	);

	/**
	 * Constructor. Takes no dependencies; services are resolved lazily.
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Hooked at plugin load time, which is what makes registration safe.
		// Media Sweep boots at file scope (media-sweep.php), long before init,
		// and both core registries refuse to instantiate until init has fired.
		// So neither registry can already exist when these listeners are added,
		// and the lazy-singleton race (hooking a one-shot action that has
		// already fired) is structurally impossible rather than merely unlikely.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register both ability categories.
	 *
	 * @return void
	 */
	public function register_categories() {
		wp_register_ability_category(
			self::CATEGORY_AUDIT,
			array(
				'label'       => __( 'Media Sweep (Audit)', 'media-sweep' ),
				'description' => __( 'Read-only inspection of scans, findings, evidence, trash contents and server capacity. Nothing in this category changes or deletes anything.', 'media-sweep' ),
			)
		);

		wp_register_ability_category(
			self::CATEGORY_ACTIONS,
			array(
				'label'       => __( 'Media Sweep (Actions)', 'media-sweep' ),
				'description' => __( 'Operations that run scans or move and delete media files. Everything here writes.', 'media-sweep' ),
			)
		);
	}

	/**
	 * Register every ability declared by the available providers.
	 *
	 * @return void
	 */
	public function register_abilities() {
		// An unregistered category silently kills every ability assigned to
		// it, so bail loudly rather than register a half-empty surface.
		if ( ! wp_has_ability_category( self::CATEGORY_AUDIT ) || ! wp_has_ability_category( self::CATEGORY_ACTIONS ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: audit category slug, 2: actions category slug */
					esc_html__( 'Media Sweep abilities were not registered because the "%1$s" or "%2$s" category is missing.', 'media-sweep' ),
					esc_html( self::CATEGORY_AUDIT ),
					esc_html( self::CATEGORY_ACTIONS )
				),
				MEDIA_SWEEP_VERSION
			);
			return;
		}

		$expected = array();

		foreach ( self::PROVIDERS as $provider ) {
			if ( ! class_exists( $provider ) ) {
				continue;
			}

			$definitions = $provider::definitions( $this );
			if ( ! is_array( $definitions ) ) {
				continue;
			}

			foreach ( $definitions as $name => $args ) {
				$expected[] = $name;
				wp_register_ability( $name, $args );
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->assert_invariants( $expected );
		}
	}

	/**
	 * Build the permission callback for one ability.
	 *
	 * Always a bare bool, never a WP_Error and never a side effect: a REST
	 * /run request runs the permission check twice. The filter is the site
	 * owner's lever for a locked-down MCP user - it can only raise the bar.
	 *
	 * @param string $ability_name Ability name, passed to the filter.
	 * @return callable Permission callback.
	 */
	public function permission_callback( $ability_name ) {
		return static function ( $input = null ) use ( $ability_name ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- core invokes the callback with the normalized input.
			// manage_options is a floor, not a default. Only the three
			// dispatch-backed abilities also pass through the REST controller's
			// hard-coded manage_options check; the thirteen service-backed ones -
			// delete-scanned-files included - are gated by this filter alone.
			if ( ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			/**
			 * Filters the capability required to run a Media Sweep ability.
			 *
			 * Applied on top of 'manage_options', never instead of it, so the
			 * filter can only narrow access further.
			 *
			 * @param string $capability   Capability name. Default 'manage_options'.
			 * @param string $ability_name Ability being checked.
			 */
			return current_user_can( apply_filters( 'media_sweep_ability_capability', 'manage_options', $ability_name ) );
		};
	}

	/**
	 * Build the meta block for one ability.
	 *
	 * Core rejects a non-boolean flag, so every value here is a real bool.
	 * show_in_rest is explicit because public -> show_in_rest is 7.1 behaviour,
	 * and meta.mcp.public is explicit because inheriting it from meta.public is
	 * only adapter 0.6.0+.
	 *
	 * @param array $annotations Annotation flags: readonly, destructive, idempotent.
	 * @param bool  $metered     Whether the ability spends a metered resource.
	 * @return array Meta array for wp_register_ability().
	 */
	public function meta( array $annotations, $metered = false ) {
		$readonly    = ! empty( $annotations['readonly'] );
		$destructive = ! empty( $annotations['destructive'] );
		$idempotent  = ! empty( $annotations['idempotent'] );

		return array(
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => ( ! $destructive && ! $metered ),
			),
		);
	}

	/**
	 * Run one of the plugin's own REST routes internally.
	 *
	 * Dispatching instead of calling a controller directly keeps the route's
	 * arg schema, its sanitize callbacks, the CVE-2026-77824 `fields` allowlist
	 * and the prepared search subquery in the path for free.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path, e.g. '/media-sweep/v1/scans'.
	 * @param array  $params Request parameters.
	 * @return mixed Response data, or WP_Error on failure.
	 */
	public function dispatch( $method, $route, array $params = array() ) {
		$request = new \WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		return $response->get_data();
	}

	/**
	 * Merge an ability input over its defaults.
	 *
	 * Core applies ONLY the top-level `default` while normalizing input; the
	 * per-property defaults inside `properties` are never applied. This is the
	 * single place that quirk is handled, so providers never repeat it.
	 *
	 * @param mixed $input    Raw ability input.
	 * @param array $defaults Default values.
	 * @return array Merged input.
	 */
	public function apply_defaults( $input, array $defaults ) {
		return wp_parse_args( is_array( $input ) ? $input : array(), $defaults );
	}

	/**
	 * Resolve a plugin service lazily.
	 *
	 * @param string $name Registered service name.
	 * @return mixed Service instance.
	 */
	public function service( $name ) {
		return media_sweep()->services()->get( $name );
	}

	/**
	 * Build an agent-friendly error.
	 *
	 * Execute-side only. Permission errors stay bare and generic, because the
	 * run controller returns them verbatim to the HTTP caller. A call site that
	 * omits `status` gets 400, because an agent error is a refusal, not a crash.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional data, may carry `agent_hint` and
	 *                        `recovery` => array( 'ability' => ..., 'input' => ... ).
	 * @return \WP_Error
	 */
	public function agent_error( $code, $message, array $data = array() ) {
		// Core's run route reads data['status'] for the HTTP code. Without one
		// every expected refusal answered 500, and MCP and HTTP clients treat a
		// 5xx as a server crash - they blind-retry or abort instead of reading
		// the agent_hint. An agent error is a refusal, so 400 is the floor.
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 400;
		}

		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Check the registration invariants this design depends on.
	 *
	 * Debug-only. Registration failures are otherwise invisible: core reports
	 * them through _doing_it_wrong and returns null, so a typo produces a
	 * silently missing ability rather than an error.
	 *
	 * @param array $expected Ability names that should now be registered.
	 * @return void
	 */
	private function assert_invariants( array $expected ) {
		$categories = array( self::CATEGORY_AUDIT, self::CATEGORY_ACTIONS );

		foreach ( $expected as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				$this->report_invariant( $name, __( 'was not registered.', 'media-sweep' ) );
				continue;
			}

			$ability = wp_get_ability( $name );
			if ( ! $ability ) {
				continue;
			}

			$meta        = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$readonly    = ! empty( $annotations['readonly'] );
			$destructive = ! empty( $annotations['destructive'] );
			$idempotent  = ! empty( $annotations['idempotent'] );

			$schema     = $ability->get_input_schema();
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

			// destructive + idempotent routes /run to DELETE, whose input is
			// never JSON-decoded, so an object-shaped input cannot survive.
			if ( $destructive && $idempotent ) {
				$this->report_invariant( $name, __( 'is annotated both destructive and idempotent.', 'media-sweep' ) );
			}

			if ( $readonly && $destructive ) {
				$this->report_invariant( $name, __( 'is annotated both readonly and destructive.', 'media-sweep' ) );
			}

			if ( $destructive ) {
				$mcp_public = isset( $meta['mcp']['public'] ) ? $meta['mcp']['public'] : null;
				if ( false !== $mcp_public ) {
					$this->report_invariant( $name, __( 'is destructive but is not opted out of MCP.', 'media-sweep' ) );
				}

				if ( ! $this->has_confirmation_field( $required ) ) {
					$this->report_invariant( $name, __( 'is destructive but requires no confirmation field.', 'media-sweep' ) );
				}
			}

			if ( ! in_array( $ability->get_category(), $categories, true ) ) {
				$this->report_invariant( $name, __( 'is not in a Media Sweep category.', 'media-sweep' ) );
			}

			// `force` bypasses the in-use status guard in Action_Service and is
			// never exposed to an agent, at any layer.
			if ( isset( $properties['force'] ) ) {
				$this->report_invariant( $name, __( 'declares a "force" input property.', 'media-sweep' ) );
			}
		}
	}

	/**
	 * Whether a required-field list contains a confirmation field.
	 *
	 * @param array $required Required field names.
	 * @return bool
	 */
	private function has_confirmation_field( array $required ) {
		foreach ( $required as $field ) {
			if ( is_string( $field ) && strpos( $field, 'confirm' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Report one broken invariant.
	 *
	 * @param string $name    Ability name.
	 * @param string $problem What is wrong with it.
	 * @return void
	 */
	private function report_invariant( $name, $problem ) {
		_doing_it_wrong(
			'Media_Sweep\Abilities\Abilities_Module::assert_invariants',
			sprintf(
				/* translators: 1: ability name, 2: description of the problem */
				esc_html__( 'Ability "%1$s" %2$s', 'media-sweep' ),
				esc_html( $name ),
				esc_html( $problem )
			),
			MEDIA_SWEEP_VERSION
		);
	}
}
