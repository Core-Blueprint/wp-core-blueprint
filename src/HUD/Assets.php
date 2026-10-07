<?php
declare(strict_types=1);
/**
 * Assets - HUD CSS + JS module enqueue.
 *
 * Enqueues are split admin / frontend so each can short-circuit on
 * its own gate. Both routes load the HUD component plus every shared
 * Foundation dependency rendered inside it (currently Mode Switcher).
 *
 * The script module receives configuration via the
 * `script_module_data_@cb-core/hud` filter - current brand id, position,
 * ghost state, REST URLs + nonce, and i18n labels for the JS-rendered
 * micro-affordances (drag tooltip, dock-snap announcement). No
 * wp_localize_script - that's the legacy classic-script API and CB
 * Base's script-module convention is what we adopt here.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD;

use CoreBlueprint\Core\HUD\Brand\BrandRegistry;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/** Style handle. */
	public const STYLE_HANDLE = 'cb-core-css-hud';

	/** HUD script module id. */
	public const SCRIPT_MODULE_ID = '@cb-core/hud';

	/** Shared DOM helper module used by the Mode Switcher Foundation. */
	private const DOM_MODULE_ID = '@cb-core/dom';

	/** Shared Mode Switcher Foundation module rendered inside the HUD header. */
	private const MODE_SWITCHER_MODULE_ID = '@cb-core/mode-switcher';

	/** Prevent duplicate module-data filter registration on unusual requests. */
	private static bool $module_data_filters_registered = false;

	/** Prevent duplicate dynamic palette CSS on unusual double-enqueue requests. */
	private static bool $palette_css_added = false;

	/**
	 * Admin-context enqueue. Runs for every admin screen the current
	 * user can see HUD on; the rendering gate ({@see Access::can_render})
	 * still runs in the footer so unauthenticated screens emit nothing
	 * even if the assets loaded.
	 */
	public static function enqueue_admin( string $hook = '' ): void {
		// Phase-1: load assets for any logged-in user with HUD capability.
		// Per-screen exclusion can layer on top later if specific admin
		// pages need HUD silenced (none currently).
		if ( ! Access::can_use() ) {
			return;
		}
		self::do_enqueue();
	}

	/**
	 * Frontend-context enqueue. Same rules as admin but additionally
	 * skips when the current request matches an excluded post type
	 * (delegated to Access).
	 */
	public static function enqueue_frontend(): void {
		if ( ! Access::can_render() ) {
			return;
		}
		self::do_enqueue();
	}

	/**
	 * Common enqueue body. Idempotent - wp_enqueue_* dedupes on handle
	 * so calling this from both admin and frontend hooks on the same
	 * request (which can happen with REST or AJAX in admin context) is
	 * safe.
	 */
	private static function do_enqueue(): void {
		// HUD is a portable surface: unlike a regular Core Blueprint admin
		// screen it can render inside third-party wp-admin pages and on the
		// frontend. It therefore owns an explicit dependency list instead of
		// relying on Admin\Admin::enqueue_assets() having run earlier.
		if ( ! wp_style_is( 'cb-core-css-tokens', 'enqueued' ) ) {
			wp_enqueue_style(
				'cb-core-css-tokens',
				CB_CORE_URL . 'assets/css/tokens.css',
				[],
				CB_CORE_VERSION
			);
		}

		// Dashicons are native in wp-admin but must be requested explicitly on
		// the frontend for the WordPress navigation items rendered in the HUD.
		wp_enqueue_style( 'dashicons' );

		// Token fallback for contexts where Core Blueprint does not own the
		// document theme attribute (frontend / third-party admin screens).
		wp_enqueue_style(
			'cb-core-css-hud-fallback-tokens',
			CB_CORE_URL . 'assets/css/components/hud-fallback-tokens.css',
			[],
			CB_CORE_VERSION
		);

		// The HUD header renders UI::render_mode_switcher(), so the shared
		// Mode Switcher Foundation is a real runtime dependency, not merely a
		// Core-admin-page convenience. Omitting it is what caused raw P/T/S
		// browser buttons outside Core Blueprint pages.
		wp_enqueue_style(
			'cb-core-css-mode-switcher',
			CB_CORE_URL . 'assets/css/components/mode-switcher.css',
			[ 'cb-core-css-tokens', 'cb-core-css-hud-fallback-tokens' ],
			CB_CORE_VERSION
		);

		wp_enqueue_style(
			self::STYLE_HANDLE,
			CB_CORE_URL . 'assets/css/components/hud.css',
			[ 'cb-core-css-tokens', 'cb-core-css-hud-fallback-tokens', 'cb-core-css-mode-switcher', 'dashicons' ],
			CB_CORE_VERSION
		);

		if ( ! self::$palette_css_added ) {
			$palette_css = self::brand_palette_css();
			if ( '' !== $palette_css && wp_add_inline_style( self::STYLE_HANDLE, $palette_css ) ) {
				self::$palette_css_added = true;
			}
		}

		// Shared Foundation behaviour. mode-switcher.js imports dom.js and
		// persists through the existing cb_core_set_description_mode endpoint.
		// Enqueue both explicitly so the switcher works on frontend and
		// non-Core admin screens as well as looking correct there.
		wp_enqueue_script_module(
			self::DOM_MODULE_ID,
			CB_CORE_URL . 'assets/js/core/dom.js',
			[],
			CB_CORE_VERSION
		);
		wp_enqueue_script_module(
			self::MODE_SWITCHER_MODULE_ID,
			CB_CORE_URL . 'assets/js/core/mode-switcher.js',
			[ self::DOM_MODULE_ID ],
			CB_CORE_VERSION
		);
		wp_enqueue_script_module(
			self::SCRIPT_MODULE_ID,
			CB_CORE_URL . 'assets/js/features/hud.js',
			[ self::MODE_SWITCHER_MODULE_ID ],
			CB_CORE_VERSION
		);

		self::register_module_data_filters();
	}

	/**
	 * Build brand palette overrides for the already-enqueued HUD stylesheet.
	 *
	 * All registered palettes are emitted up front so client-side brand
	 * switching can activate another brand without a page reload. Values remain
	 * restricted to CSS custom properties and the same bounded character set as
	 * the former footer renderer.
	 */
	private static function brand_palette_css(): string {
		$css = '';

		foreach ( BrandRegistry::all() as $brand ) {
			$brand_id = sanitize_key( $brand->id() );
			if ( '' === $brand_id ) {
				continue;
			}

			$rules = '';
			foreach ( $brand->palette() as $token => $value ) {
				$token = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $token );
				$value = preg_replace( '/[^#a-zA-Z0-9\\(\\),\\s%\\.\\-]/', '', (string) $value );
				if ( '' === $token || '' === $value || ! str_starts_with( $token, '--' ) ) {
					continue;
				}
				$rules .= sprintf( '%s:%s;', $token, $value );
			}

			if ( '' !== $rules ) {
				$css .= sprintf( 'html[data-cb-brand="%s"]{%s}', $brand_id, $rules );
			}
		}

		return $css;
	}

	/**
	 * Register server-data providers once per request.
	 *
	 * Core admin pages may enqueue these shared modules through the main admin
	 * asset pipeline as well; additive filters are safe, but registering the HUD
	 * copies more than once on the same request is unnecessary.
	 */
	private static function register_module_data_filters(): void {
		if ( self::$module_data_filters_registered ) {
			return;
		}

		add_filter( 'script_module_data_' . self::DOM_MODULE_ID, [ self::class, 'dom_module_data' ] );
		add_filter( 'script_module_data_' . self::MODE_SWITCHER_MODULE_ID, [ self::class, 'mode_switcher_module_data' ] );
		add_filter( 'script_module_data_' . self::SCRIPT_MODULE_ID, [ self::class, 'script_module_data' ] );

		self::$module_data_filters_registered = true;
	}

	/** @param array<string, mixed> $existing @return array<string, mixed> */
	public static function dom_module_data( array $existing ): array {
		return array_merge( $existing, [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => [
				'copiedToClipboard' => __( 'Copied to clipboard', 'core-blueprint' ),
			],
		] );
	}

	/** @param array<string, mixed> $existing @return array<string, mixed> */
	public static function mode_switcher_module_data( array $existing ): array {
		return array_merge( $existing, [
			'nonce' => wp_create_nonce( 'cb_core_admin' ),
			'i18n'  => [
				'modeChangeFailed' => __( 'Could not change reading mode.', 'core-blueprint' ),
			],
		] );
	}

	/**
	 * Build the data payload passed to the @cb-core/hud script module.
	 * Merges with whatever's already in the filter chain (defensive
	 * against multiple consumers adding to the same filter).
	 *
	 * @param array<string, mixed> $existing
	 * @return array<string, mixed>
	 */
	public static function script_module_data( array $existing ): array {
		$brand = BrandRegistry::current();
		return array_merge( $existing, [
			'brandId'         => $brand->id(),
			'position'        => Storage::get_position(),
			'ghost'           => Storage::get_ghost(),
			'restRoot'        => esc_url_raw( rest_url( 'core-blueprint/v1/hud/' ) ),
			'restNonce'       => wp_create_nonce( 'wp_rest' ),
			'i18n' => [
				'open'      => __( 'Open HUD', 'core-blueprint' ),
				'close'     => __( 'Close HUD', 'core-blueprint' ),
				'ghostMode' => __( 'Ghost mode', 'core-blueprint' ),
				'dockHint'  => __( 'Drag to reposition', 'core-blueprint' ),
				'savedToast'    => __( 'HUD position saved.', 'core-blueprint' ),
				'saveErrorToast' => __( 'Could not save HUD state.', 'core-blueprint' ),
			],
		] );
	}
}