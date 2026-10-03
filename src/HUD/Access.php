<?php
declare(strict_types=1);
/**
 * Access - HUD capability gating and render-allowed checks.
 *
 * Single source of truth for "should this user see HUD on this page".
 * Combines three layers:
 *
 *   1. User session - must be logged in (frontend + admin)
 *   2. Capability - must hold cb_core_hud_use (explicitly granted by the
 *      canonical Role Policy; the required cap remains filterable)
 *   3. Context - admin pages always allowed for capable users; frontend
 *      pages allowed unless the current post type is on the exclusion
 *      list (filterable, empty by default - operators tend to want HUD
 *      EVERYWHERE, restricting frontend visibility is the rarer case)
 *
 * Embedded builder previews are presentation canvases, not independent
 * operator workspaces. Known preview requests therefore suppress the HUD so a
 * builder can keep one canonical launcher in its outer application chrome.
 *
 * The canonical Role Policy grants cb_core_hud_use explicitly to the
 * Administrator and CB Operator roles. Custom roles may receive it normally.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD;

defined( 'ABSPATH' ) || exit;

final class Access {

	/** Capability that controls HUD visibility for the current user. */
	public const CAPABILITY = 'cb_core_hud_use';

	/**
	 * Resolve the effective capability required to use HUD. White-label
	 * plugins can lower this to a less-privileged cap (e.g. 'edit_posts')
	 * to expose HUD to broader teams without granting full admin access.
	 */
	public static function capability(): string {
		/**
		 * Filter: core_blueprint_hud_capability
		 *
		 * Override the cap required to see and use HUD. Default
		 * cb_core_hud_use. The canonical Role Policy grants it explicitly to
		 * Administrator and CB Operator.
		 *
		 * @param string $capability Default cb_core_hud_use.
		 */
		return (string) apply_filters( 'core_blueprint_hud_capability', self::CAPABILITY );
	}

	/**
	 * Whether the current user passes the capability + session gate.
	 * Ignores context-level rules (admin vs frontend, post-type
	 * exclusions) - those are the caller's responsibility via
	 * {@see can_render()}.
	 */
	public static function can_use(): bool {
		return is_user_logged_in() && current_user_can( self::capability() );
	}

	/**
	 * Whether HUD should render on the current request. Combines the
	 * capability gate with the context rules (admin always, frontend
	 * unless the post type is excluded). Called from
	 * {@see Bootstrap::render()} as the single render-time gate.
	 */
	public static function can_render(): bool {
		if ( ! self::can_use() ) {
			return false;
		}

		// Embedded builder preview documents are visual canvases inside a parent
		// operator workspace. Rendering HUD in both documents creates duplicate
		// launchers and contaminates the preview itself.
		if ( self::is_embedded_builder_preview() ) {
			return false;
		}

		// Admin context - always allowed for capable users. The "front
		// door" philosophy applies most strongly inside /wp-admin.
		if ( is_admin() ) {
			return true;
		}

		// Frontend context - allowed by default, with an exclusion list
		// for sites that don't want HUD on certain singular post types
		// (e.g. landing pages where pixel-perfect visual review matters).
		return ! self::is_excluded_frontend_context();
	}

	/**
	 * Detect confirmed embedded builder-preview requests.
	 *
	 * Bricks renders its work-area preview as a frontend iframe whose URL uses
	 * both `bricks=run` and `brickspreview=true`. Requiring both signals keeps
	 * normal frontend visits and the outer Bricks Builder application eligible
	 * for HUD while suppressing only the nested preview document.
	 */
	private static function is_embedded_builder_preview(): bool {
		$bricks = isset( $_GET['bricks'] )
			? sanitize_key( wp_unslash( (string) $_GET['bricks'] ) )
			: '';

		$preview = isset( $_GET['brickspreview'] )
			? sanitize_key( wp_unslash( (string) $_GET['brickspreview'] ) )
			: '';

		return 'run' === $bricks && 'true' === $preview;
	}

	/**
	 * Frontend context exclusion check. Empty by default - most
	 * operators want HUD reachable everywhere, including the public
	 * site. Filter `core_blueprint_hud_excluded_post_types` accepts an array
	 * of post-type slugs to suppress HUD for.
	 */
	private static function is_excluded_frontend_context(): bool {
		/**
		 * Filter: core_blueprint_hud_excluded_post_types
		 *
		 * Array of post-type slugs that suppress HUD rendering on
		 * frontend singular views. Default empty - HUD is reachable
		 * everywhere unless explicitly opted out.
		 *
		 * @param array<int, string> $excluded Post-type slugs.
		 */
		$excluded = (array) apply_filters( 'core_blueprint_hud_excluded_post_types', [] );
		$excluded = array_map( 'sanitize_key', $excluded );

		if ( empty( $excluded ) || ! is_singular() ) {
			return false;
		}

		$post_type = get_post_type();
		return is_string( $post_type ) && in_array( $post_type, $excluded, true );
	}

}
