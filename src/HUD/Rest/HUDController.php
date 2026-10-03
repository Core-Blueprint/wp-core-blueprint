<?php
declare(strict_types=1);
/**
 * HUDController - REST endpoints for HUD state mutation.
 *
 * Endpoints under `core-blueprint/v1/hud/`:
 *
 *   POST /position       Sets the dock position for the current user.
 *                        Body: { position: <one of Storage::POSITIONS> }
 *
 *   POST /ghost          Toggles ghost-mode for the current user.
 *                        Body: { ghost: bool }
 *
 *   POST /brand          Sets the active brand for the current user.
 *                        Body: { brand_id: <registered brand id> }
 *
 *   POST /theme          Sets the active theme for the current user.
 *                        Body: { theme: <theme slug> }
 *
 * Description-mode persistence (Plain / Technical / Sync) is handled
 * by the suite-wide AJAX endpoint cb_core_set_description_mode -
 * the HUD's mode-switcher is just another instance of the shared
 * cb-core-mode-switcher component.
 *
 * All endpoints require the cb_core_hud_use capability and the standard
 * X-WP-Nonce header. Permission failures return 401/403; validation
 * failures return 400 with a machine-readable error code (so the JS
 * layer can decide whether to retry or surface the error to the user).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD\Rest;

use CoreBlueprint\Core\HUD\Access;
use CoreBlueprint\Core\HUD\Brand\BrandRegistry;
use CoreBlueprint\Core\HUD\Storage;

defined( 'ABSPATH' ) || exit;

final class HUDController {

	public const REST_NAMESPACE = 'core-blueprint/v1';
	public const REST_ROUTE_POSITION = '/hud/position';
	public const REST_ROUTE_GHOST    = '/hud/ghost';
	public const REST_ROUTE_BRAND    = '/hud/brand';
	public const REST_ROUTE_THEME    = '/hud/theme';

	/**
	 * Register all HUD REST routes. Called from Bootstrap::boot()'s
	 * rest_api_init hook.
	 */
	public static function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE_POSITION, [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'handle_position' ],
			'permission_callback' => [ self::class, 'permission_check' ],
			'args'                => [
				'position' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE_GHOST, [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'handle_ghost' ],
			'permission_callback' => [ self::class, 'permission_check' ],
			'args'                => [
				'ghost' => [
					'required' => true,
					'type'     => 'boolean',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE_BRAND, [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'handle_brand' ],
			'permission_callback' => [ self::class, 'permission_check' ],
			'args'                => [
				'brand_id' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE_THEME, [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'handle_theme' ],
			'permission_callback' => [ self::class, 'permission_check' ],
			'args'                => [
				'theme' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		] );
	}

	/**
	 * Capability gate for all three endpoints. Logged-in user with
	 * cb_core_hud_use is required.
	 */
	public static function permission_check(): bool {
		return Access::can_use();
	}

	// ─── Handlers ──────────────────────────────────────────────────────────

	public static function handle_position( \WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$position = (string) $request->get_param( 'position' );

		if ( ! Storage::set_position( $user_id, $position ) ) {
			return new \WP_Error(
				'cb_core_hud_invalid_position',
				__( 'Position must be one of the eight allowed dock anchors.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		return new \WP_REST_Response( [
			'ok'       => true,
			'position' => $position,
		], 200 );
	}

	public static function handle_ghost( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$ghost   = (bool) $request->get_param( 'ghost' );

		if ( ! Storage::set_ghost( $user_id, $ghost ) ) {
			return new \WP_Error(
				'cb_core_hud_save_failed',
				__( 'Could not save ghost-mode state.', 'core-blueprint' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response( [
			'ok'    => true,
			'ghost' => $ghost,
		], 200 );
	}

	public static function handle_brand( \WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$brand_id = (string) $request->get_param( 'brand_id' );

		// Reject brand ids that aren't registered. Defensive: a sibling
		// plugin could deactivate between the picker showing and the
		// click landing here, leaving a stale id in the request.
		if ( ! BrandRegistry::has( $brand_id ) ) {
			return new \WP_Error(
				'cb_core_hud_unknown_brand',
				__( 'Unknown brand id.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		// Reject brands flagged coming-soon - selectable in the picker
		// only as preview, not for real activation. The JS layer should
		// already gate the click but defend server-side too.
		$brand = BrandRegistry::get( $brand_id );
		if ( $brand && 'coming-soon' === $brand->status() ) {
			return new \WP_Error(
				'cb_core_hud_brand_unavailable',
				__( 'This brand is not yet available.', 'core-blueprint' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! Storage::set_active_brand_id( $user_id, $brand_id ) ) {
			return new \WP_Error(
				'cb_core_hud_save_failed',
				__( 'Could not save brand selection.', 'core-blueprint' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response( [
			'ok'        => true,
			'brand_id'  => $brand_id,
		], 200 );
	}

	/**
	 * Theme switch - proxies to Themes::set_user() (which uses the
	 * ScopedPreference trait so the same persistence + audit-log path
	 * fires regardless of whether the change comes from HUD or from
	 * the Preferences › Appearance page). HUD doesn't own theme state;
	 * it's a quick-access UI on top of the existing Themes subsystem.
	 */
	public static function handle_theme( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$theme   = (string) $request->get_param( 'theme' );

		// Validate against the Themes registry. Allows 'auto' plus any
		// registered theme slug - same contract as Themes::is_valid().
		if ( ! \CoreBlueprint\Core\Themes::is_valid( $theme ) ) {
			return new \WP_Error(
				'cb_core_hud_unknown_theme',
				__( 'Unknown theme slug.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! \CoreBlueprint\Core\Themes::set_user( $user_id, $theme ) ) {
			return new \WP_Error(
				'cb_core_hud_save_failed',
				__( 'Could not save theme selection.', 'core-blueprint' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response( [
			'ok'    => true,
			'theme' => $theme,
		], 200 );
	}
}
