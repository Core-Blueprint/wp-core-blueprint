<?php
declare(strict_types=1);
namespace CB\Core\HUD;

use CB\Core\HUD\Brand\BrandRegistry;
use CB\Core\HUD\Brand\CoreBlueprint;
use CB\Core\HUD\Rest\HUDController;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap - wires the HUD subsystem into Core Blueprint.
 *
 * Called from {@see \CB\Core\Core::init()} alongside the Reports,
 * Permissions, Integrity, and Notes bootstraps. Registers:
 *
 *   - Brand registry (built-in CoreBlueprint brand, plus the action hook
 *     `cb_core_register_brands` that lets sibling/white-label plugins
 *     register their own brand implementations)
 *   - Default item registry (sections + items hook for siblings via the
 *     `cb_hud_register_items` action)
 *   - REST routes for state mutation (brand/position/ghost/visibility
 *     under `core-blueprint/v1/hud/*`)
 *   - Render hooks on `admin_footer` and `wp_footer` so the HUD chrome
 *     appears on every admin and frontend page where the user is allowed
 *     to see it
 *   - Asset enqueue on `admin_enqueue_scripts` and `wp_enqueue_scripts`
 *
 * Kill-switch:
 *
 *   The entire bootstrap honours an `cb_core_hud_enabled` filter - if it
 *   returns false the bootstrap returns silently before registering any
 *   hooks, REST routes, or render handlers. Equivalent to the subsystem
 *   not being loaded at all. Surfaces as a checkbox in Preferences ›
 *   Appearance via {@see Settings::is_enabled()}, which reads the
 *   `cb_core_hud_disabled` option and short-circuits the filter.
 *
 *   This is the operator's safety valve: if HUD ever conflicts with
 *   another plugin, breaks on a specific WordPress version, or is just
 *   not wanted on a particular site, one toggle in Preferences disables
 *   it without touching code, without deactivating the plugin, and
 *   without losing state - the user_meta keys (cb_core_hud_position,
 *   cb_core_hud_ghost, cb_core_active_brand) stay intact for next time.
 *
 * Frontend rendering:
 *
 *   HUD renders for logged-in users with the cb_core_hud_use capability
 *   on both /wp-admin/* and the public-facing site. The "front door"
 *   philosophy says the operator's launcher should be reachable wherever
 *   they're working - page builders run on the frontend, theme tweaks
 *   need to be checked in context, etc. Visitors without a session see
 *   nothing rendered (the render handler short-circuits before emitting
 *   any HTML).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


final class Bootstrap {

	/**
	 * Register every HUD hook. Idempotent - safe to call multiple times,
	 * but only does work the first time (subsequent calls return early
	 * via the static guard).
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}

		// Site-wide HUD menu preferences remain manageable even when the HUD
		// display kill-switch is off. HUD access itself is a real stored role
		// capability owned by the canonical Role Policy.
		MenuPreferences::boot();

		// Kill-switch - the universal escape hatch. Filter returns false
		// → no init, no enqueue, no rendering. The user-facing toggle in
		// Preferences › Appearance flips the underlying option which the
		// filter reads.
		if ( ! Settings::is_enabled() ) {
			$booted = true; // mark booted-but-disabled so subsequent boot calls are also fast
			return;
		}

		$booted = true;

		// Brand registry - built-in brands first, then sibling/white-
		// label plugins via the action. Brands are registered eagerly so
		// the brand picker UI has a complete list at render time.
		add_action( 'init', [ self::class, 'register_brands' ], 5 );

		// WordPress update state is consumed by the built-in HUD Updates stat
		// during item registration below. Without a persistent object cache,
		// bulk-prime the three canonical update site-transient options first so
		// wp_get_update_data() can reuse one request-local cache fill instead of
		// issuing three independent option queries.
		add_action( 'init', [ self::class, 'prime_update_cache' ], 9 );

		// Item registry - built-in sections live here; siblings register
		// their items via cb_hud_register_items when they boot.
		add_action( 'init', [ self::class, 'register_items' ], 10 );

		// REST routes - HUDController owns brand/position/ghost mutation
		// endpoints under core-blueprint/v1/hud/*.
		add_action( 'rest_api_init', [ HUDController::class, 'register_routes' ] );

		// Asset enqueue - both admin and frontend contexts load the same
		// stylesheet + script module. Visibility is gated at render-time
		// so unauthenticated users on the frontend never see the chrome,
		// but the assets themselves are enqueued unconditionally to keep
		// caching predictable.
		// The HUD is a portable overlay and should win over broad button/link
		// rules from third-party admin pages and frontend themes. Enqueue after
		// the normal page/theme asset pass while keeping all selectors scoped.
		add_action( 'admin_enqueue_scripts', [ Assets::class, 'enqueue_admin' ], 100 );
		add_action( 'wp_enqueue_scripts',    [ Assets::class, 'enqueue_frontend' ], 100 );

		// Render hooks - the chrome is appended to the document end so
		// it can position-fixed itself without parent-stacking-context
		// interference from page-specific layouts.
		add_action( 'admin_footer', [ self::class, 'render' ] );
		add_action( 'wp_footer',    [ self::class, 'render' ] );

	}

	/**
	 * Populate the brand registry with built-in brands. White-label and
	 * sibling plugins extend via the cb_core_register_brands action.
	 */
	public static function register_brands(): void {
		BrandRegistry::register( new CoreBlueprint() );

		/**
		 * Action: cb_core_register_brands
		 *
		 * Fired after built-in brands register. Receives the BrandRegistry
		 * class name as the action arg so handlers can call
		 * BrandRegistry::register() directly. White-label plugins that
		 * provide their own brand implementation should hook here.
		 */
		do_action( 'cb_core_register_brands', BrandRegistry::class );
	}

	/**
	 * Prime WordPress-owned update state before the HUD asks WordPress for its
	 * aggregate Updates count.
	 *
	 * The three update transients are stored as site options when no persistent
	 * object cache is active. WordPress can prime those option keys in one query,
	 * after which wp_get_update_data() and the native admin bar both reuse the
	 * request cache. With an external object cache, get_site_transient() already
	 * reads the dedicated `site-transient` cache group, so a database prime would
	 * be unnecessary and potentially slower.
	 */
	public static function prime_update_cache(): void {
		if (
			! current_user_can( 'update_core' )
			|| wp_using_ext_object_cache()
			|| ! function_exists( 'wp_prime_site_option_caches' )
		) {
			return;
		}

		wp_prime_site_option_caches( [
			'_site_transient_update_plugins',
			'_site_transient_update_themes',
			'_site_transient_update_core',
		] );
	}

	/**
	 * Populate the canonical HUD registries, then fire the ordered public
	 * extension actions so siblings can register controlled section types,
	 * sections and items.
	 *
	 * Three-phase: section types first, sections second, items third. Partners that need a custom section type register it first; custom
	 * sections must then exist before items target them, otherwise the
	 * fail-closed item registry rejects the orphan.
	 */
	public static function register_items(): void {
		SectionTypeRegistry::register_builtins();

		/**
		 * Action: cb_hud_register_section_types
		 *
		 * Public extension point for controlled custom HUD section types. Custom
		 * types are declarative and may only use Base-owned presentation primitives;
		 * arbitrary render callbacks/markup are not accepted. Receives the
		 * SectionTypeRegistry class name.
		 */
		do_action( 'cb_hud_register_section_types', SectionTypeRegistry::class );

		Registry::register_default_sections();

		/**
		 * Action: cb_hud_register_sections
		 *
		 * Fires before built-in items are registered. Partners that need
		 * to introduce their own section hook here and call the declarative
		 * Registry::register_section() contract. Most partners don't need this - slotting items into the
		 * canonical sections (cb-content, cb-site, cb-core) keeps the
		 * panel coherent and is the recommended path.
		 *
		 * Receives the Registry class name as the action arg so handlers
		 * can call Registry::register_section() directly without
		 * importing the class.
		 */
		do_action( 'cb_hud_register_sections', Registry::class );

		Registry::register_builtin_items();

		/**
		 * Action: cb_hud_register_items
		 *
		 * Public extension point for sibling plugins to register their
		 * own HUD items. Receives the Registry class name as the action
		 * arg so handlers can call Registry::add_item() directly.
		 *
		 * Example sibling usage:
		 *
		 *     add_action( 'cb_hud_register_items', function ( string $registry ): void {
		 *         $registry::add_item( [
		 *             'id'       => 'cb-hub-add-site',
		 *             'label'    => __( 'Add Website', 'core-blueprint-hub' ),
		 *             'section'  => 'cb-core',
		 *             'url'      => admin_url( 'admin.php?page=core-blueprint-hub#add' ),
		 *             'capability' => 'manage_options',
		 *         ] );
		 *     } );
		 */
		do_action( 'cb_hud_register_items', Registry::class );

		// Administrator-defined links are data, not a competing registry. Layer
		// them in after built-ins/extensions so they pass through the same
		// capability, sanitization and rendering pipeline as every other item.
		MenuPreferences::register_custom_items();
	}

	/**
	 * Render the HUD chrome at document end. Short-circuits when the
	 * current request shouldn't show HUD (anonymous user, missing
	 * capability, excluded post type, etc.).
	 */
	public static function render(): void {
		if ( ! Access::can_render() ) {
			return;
		}
		HUD::render();
	}
}
