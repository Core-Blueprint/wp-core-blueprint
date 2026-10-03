<?php
declare(strict_types=1);
/**
 * Admin Theme adapter policy.
 *
 * WordPress Core is light-first, so Base only activates its WordPress admin
 * presentation adapters while the resolved admin mode is Dark. Core Blueprint
 * components remain token-driven in both modes; Light therefore stays as close
 * to native WordPress as possible instead of being re-skinned unnecessarily.
 *
 * This class also owns the small set of curated compatibility adapters that
 * Base deliberately supports. These adapters bridge third-party presentation
 * tokens or correct a handful of hardcoded surfaces; they never take ownership
 * of plugin layout or business UI.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

use CoreBlueprint\Core\Themes;

defined( 'ABSPATH' ) || exit;

final class AdminThemeAdapters {

	private const TYPOGRAPHY_HANDLE = 'cb-core-css-admin-theme-typography';
	private const CORE_HANDLE = 'cb-core-css-admin-theme-core-screens';
	private const CORE_DASHBOARD_HANDLE = 'cb-core-css-admin-theme-core-dashboard';
	private const CORE_PLUGINS_HANDLE = 'cb-core-css-admin-theme-core-plugins';
	private const DASHBOARD_COMPAT_HANDLE = 'cb-core-css-admin-theme-compat-dashboard-widgets';
	private const GUTENBERG_HANDLE = 'cb-core-css-admin-theme-gutenberg';
	private const GUTENBERG_TOKENS_HANDLE = 'cb-core-css-admin-theme-gutenberg-tokens';
	private const GUTENBERG_CANVAS_HANDLE = 'cb-core-css-admin-theme-gutenberg-canvas';
	private const HAPPYFILES_HANDLE = 'cb-core-css-admin-theme-integration-happyfiles';
	private const BRICKS_HANDLE = 'cb-core-css-admin-theme-integration-bricks';
	private const WOOCOMMERCE_TOKENS_HANDLE = 'cb-core-css-admin-theme-integration-woocommerce-tokens';
	private const WOOCOMMERCE_CLASSIC_HANDLE = 'cb-core-css-admin-theme-integration-woocommerce-classic';
	private const WOOCOMMERCE_ADMIN_HANDLE = 'cb-core-css-admin-theme-integration-woocommerce-admin';
	private const WOOCOMMERCE_COMPONENTS_HANDLE = 'cb-core-css-admin-theme-integration-woocommerce-components';
	private const WOOCOMMERCE_SETTINGS_HANDLE = 'cb-core-css-admin-theme-integration-woocommerce-settings';

	/** @var array<string, true> */
	private const DARK_ADAPTER_HANDLES = [
		'cb-core-css-admin-theme' => true,
		self::TYPOGRAPHY_HANDLE => true,
		self::CORE_HANDLE => true,
		self::CORE_DASHBOARD_HANDLE => true,
		self::CORE_PLUGINS_HANDLE => true,
		self::DASHBOARD_COMPAT_HANDLE => true,
		self::GUTENBERG_HANDLE => true,
		self::HAPPYFILES_HANDLE => true,
		self::BRICKS_HANDLE => true,
		self::WOOCOMMERCE_TOKENS_HANDLE => true,
		self::WOOCOMMERCE_CLASSIC_HANDLE => true,
		self::WOOCOMMERCE_ADMIN_HANDLE => true,
		self::WOOCOMMERCE_COMPONENTS_HANDLE => true,
		self::WOOCOMMERCE_SETTINGS_HANDLE => true,
	];

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		// Base enqueues the canonical adapter at priority 0. Presentation adapters
		// intentionally enqueue late so they can normalize Core/plugin hardcoded
		// light colors without requiring vendor-specific specificity wars.
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ], 99 );

		// Gutenberg has a separate UI shell and (WordPress 7.1+) an always-iframed
		// content canvas. Use the official editor hooks for both boundaries.
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue_block_editor_assets' ] );
		add_action( 'enqueue_block_assets', [ self::class, 'enqueue_block_content_assets' ] );

		// The canonical WordPress/curated adapter stylesheets are dark-only. Using
		// media attributes keeps Light close to native WordPress and remains
		// live-switchable through the browser API.
		add_filter( 'style_loader_tag', [ self::class, 'filter_style_loader_tag' ], 10, 4 );
	}

	public static function enqueue( string $hook_suffix = '' ): void {
		if ( ! AdminTheme::applies( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			self::TYPOGRAPHY_HANDLE,
			CB_CORE_URL . 'assets/css/admin-theme/typography.css',
			[ 'cb-core-css-admin-theme' ],
			CB_CORE_VERSION
		);

		wp_enqueue_style(
			self::CORE_HANDLE,
			CB_CORE_URL . 'assets/css/admin-theme/core-screens.css',
			[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE ],
			CB_CORE_VERSION
		);

		if ( 'index.php' === $hook_suffix ) {
			wp_enqueue_style(
				self::CORE_DASHBOARD_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/core/dashboard.css',
				[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE, self::CORE_HANDLE ],
				CB_CORE_VERSION
			);

			wp_enqueue_style(
				self::DASHBOARD_COMPAT_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/compat/dashboard-widgets.css',
				[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE, self::CORE_DASHBOARD_HANDLE ],
				CB_CORE_VERSION
			);
		}

		if ( 'plugins.php' === $hook_suffix ) {
			wp_enqueue_style(
				self::CORE_PLUGINS_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/core/plugins.css',
				[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE, self::CORE_HANDLE ],
				CB_CORE_VERSION
			);
		}

		// HappyFiles is a deliberate curated bridge. It exposes useful --hf-*
		// presentation variables but also ships a few hardcoded light surfaces.
		if ( class_exists( '\\HappyFiles\\Init' ) || taxonomy_exists( 'happyfiles_category' ) ) {
			wp_enqueue_style(
				self::HAPPYFILES_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/happyfiles.css',
				[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE ],
				CB_CORE_VERSION
			);
		}

		// Bricks is a strategic curated integration for the suite. The adapter is
		// scoped to Bricks-owned admin wrappers and preserves Bricks brand/layout.
		if ( defined( 'BRICKS_VERSION' ) && BRICKS_VERSION ) {
			wp_enqueue_style(
				self::BRICKS_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/bricks.css',
				[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE ],
				CB_CORE_VERSION
			);
		}

		// WooCommerce is a larger curated integration with classic admin, Woo
		// Admin React and the newer WPDS-based Settings UI. Load it only when
		// WooCommerce is active AND the current screen is WooCommerce-owned.
		if ( self::is_woocommerce_admin_screen() ) {
			wp_enqueue_style(
				self::WOOCOMMERCE_TOKENS_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/woocommerce/tokens.css',
				[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE ],
				CB_CORE_VERSION
			);

			wp_enqueue_style(
				self::WOOCOMMERCE_CLASSIC_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/woocommerce/classic.css',
				[ self::WOOCOMMERCE_TOKENS_HANDLE ],
				CB_CORE_VERSION
			);

			wp_enqueue_style(
				self::WOOCOMMERCE_ADMIN_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/woocommerce/admin.css',
				[ self::WOOCOMMERCE_TOKENS_HANDLE ],
				CB_CORE_VERSION
			);

			wp_enqueue_style(
				self::WOOCOMMERCE_COMPONENTS_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/woocommerce/components.css',
				[ self::WOOCOMMERCE_TOKENS_HANDLE, self::WOOCOMMERCE_ADMIN_HANDLE ],
				CB_CORE_VERSION
			);

			wp_enqueue_style(
				self::WOOCOMMERCE_SETTINGS_HANDLE,
				CB_CORE_URL . 'assets/css/admin-theme/integrations/woocommerce/settings.css',
				[ self::WOOCOMMERCE_TOKENS_HANDLE, self::WOOCOMMERCE_COMPONENTS_HANDLE ],
				CB_CORE_VERSION
			);
		}
	}

	/** Enqueue Gutenberg UI chrome through the official editor UI hook. */
	public static function enqueue_block_editor_assets(): void {
		if ( ! AdminTheme::applies() || ! self::is_block_editor_screen() ) {
			return;
		}

		wp_enqueue_style(
			self::GUTENBERG_HANDLE,
			CB_CORE_URL . 'assets/css/admin-theme/gutenberg.css',
			[ 'cb-core-css-admin-theme', self::TYPOGRAPHY_HANDLE ],
			CB_CORE_VERSION
		);
	}

	/**
	 * Enqueue editor-content assets through enqueue_block_assets so WordPress
	 * includes them in the Gutenberg iframe. The canvas stylesheet is always
	 * present in block-editor admin requests and self-scopes to data-cb-mode=dark;
	 * this enables live HUD switching without a reload.
	 */
	public static function enqueue_block_content_assets(): void {
		if ( ! is_admin() || ! AdminTheme::applies() || ! self::is_block_editor_screen() ) {
			return;
		}

		wp_enqueue_style(
			self::GUTENBERG_TOKENS_HANDLE,
			CB_CORE_URL . 'assets/css/tokens.css',
			[],
			CB_CORE_VERSION
		);

		wp_enqueue_style(
			self::GUTENBERG_CANVAS_HANDLE,
			CB_CORE_URL . 'assets/css/admin-theme/gutenberg-canvas.css',
			[ self::GUTENBERG_TOKENS_HANDLE ],
			CB_CORE_VERSION
		);
	}

	/**
	 * Give every WordPress/curated adapter the correct initial media boundary.
	 *
	 * Auto mode uses the browser media query before first paint. The browser API
	 * then normalises all adapter links to `all` / `not all` whenever the HUD or
	 * system preference changes the resolved data-cb-mode attribute.
	 */
	public static function filter_style_loader_tag( string $html, string $handle, string $href, string $media ): string {
		unset( $href, $media );

		if ( ! isset( self::DARK_ADAPTER_HANDLES[ $handle ] ) ) {
			return $html;
		}

		$target_media = self::initial_media();
		$escaped      = esc_attr( $target_media );

		if ( preg_match( '/\smedia=(["\']).*?\1/i', $html ) ) {
			return (string) preg_replace( '/\smedia=(["\']).*?\1/i', ' media="' . $escaped . '"', $html, 1 );
		}

		return str_replace( '<link ', '<link media="' . $escaped . '" ', $html );
	}

	private static function initial_media(): string {
		if ( Themes::is_auto_mode() ) {
			return '(prefers-color-scheme: dark)';
		}

		return 'dark' === AdminTheme::mode() ? 'all' : 'not all';
	}

	private static function is_block_editor_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		return $screen instanceof \WP_Screen && $screen->is_block_editor();
	}

	private static function is_woocommerce_admin_screen(): bool {
		if ( ! defined( 'WC_VERSION' ) || ! WC_VERSION || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		if ( function_exists( 'wc_get_screen_ids' ) && in_array( $screen->id, wc_get_screen_ids(), true ) ) {
			return true;
		}

		return in_array( $screen->id, [ 'woocommerce_page_wc-admin', 'admin_page_wc-admin' ], true );
	}
}
