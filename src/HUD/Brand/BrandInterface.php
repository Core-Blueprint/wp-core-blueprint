<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\HUD\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * BrandInterface - contract for HUD brand implementations.
 *
 * A Brand defines the visual identity that HUD presents on a given site:
 * the launcher button's logo, the panel's labels, the colour palette
 * tokens that override CB Base's defaults, and the human-readable
 * descriptions shown in the brand picker.
 *
 * The built-in CoreBlueprint brand lives under
 * {@see \CoreBlueprint\Core\HUD\Brand} and are registered automatically by
 * {@see \CoreBlueprint\Core\HUD\Bootstrap::register_brands()}. White-label and
 * sibling plugins implement this interface and register their brand on
 * the `cb_core_register_brands` action - see Bootstrap docblock for the
 * canonical usage example.
 *
 * Status states:
 *
 *   - 'available'   - fully implemented, selectable, applied immediately
 *                     when chosen from the brand picker
 *   - 'coming-soon' - visible in the picker with a "Coming Soon" badge,
 *                     the picker entry is disabled (click does nothing).
 *                     Use this when a brand's design is locked but the
 *                     supporting palette / theme variants aren't shipped
 *                     yet - the slot is reserved so users see the brand
 *                     is on the roadmap.
 *   - 'beta'        - selectable but flagged as not-yet-stable. Used for
 *                     brands with finished design but pending real-world
 *                     validation.
 *
 * Palette contract:
 *
 *   The {@see palette()} method returns an array of CSS custom property
 *   overrides keyed by token name. When a brand becomes active, those
 *   tokens are emitted on the `<html data-cb-brand="...">` element via
 *   inline style block, cascading to override CB Base's default tokens
 *   site-wide. Brand palettes should re-define core tokens (--cb-accent,
 *   --cb-success, --cb-surface-1, etc.) - anything not redefined falls
 *   back to CB Base's default value.
 *
 * Logo contract:
 *
 *   {@see logo_svg()} returns inline SVG markup (without surrounding
 *   <picture> or <img> tags) sized for the HUD launcher button - target
 *   intrinsic size is 24x24 to 32x32 viewBox, scales via CSS to the
 *   actual button dimensions. The SVG should be self-contained: no
 *   external references, no scripts, no embedded base64 (defeats
 *   caching). Use currentColor where possible so the brand palette's
 *   accent colour applies.
 *
 *   {@see logo_animated_svg()} provides an animated variant for the
 *   button hover/active state - same intrinsic size, may include CSS
 *   animations or SMIL transforms within the SVG. Returns the same as
 *   logo_svg() if the brand has no animated variant.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


interface BrandInterface {

	/** Brand id - kebab-case, must be unique across all registered brands. */
	public function id(): string;

	/** Human-readable brand label, shown in the brand picker. */
	public function label(): string;

	/**
	 * Implementation status - controls whether the brand is selectable.
	 *
	 * @return 'available' | 'coming-soon' | 'beta'
	 */
	public function status(): string;

	/** Inline SVG markup for the HUD launcher button (static state). */
	public function logo_svg(): string;

	/**
	 * Inline SVG markup for the launcher's hover/active state. Brands
	 * without a separate animated variant return logo_svg() here.
	 */
	public function logo_animated_svg(): string;

	/**
	 * CSS custom property overrides applied when this brand is active.
	 * Keys are full token names ('--cb-accent', '--cb-surface-1', etc.);
	 * values are CSS-valid property values. Tokens not present here
	 * inherit from CB Base's defaults.
	 *
	 * @return array<string, string>
	 */
	public function palette(): array;

	/**
	 * Plain/Technical description pair for the brand picker tooltip.
	 * Keys 'plain' and 'technical' both required - the active mode
	 * decides which one shows when the user hovers the brand entry.
	 *
	 * @return array{plain: string, technical: string}
	 */
	public function description(): array;

	/**
	 * Theme variants associated with this brand. This remains brand/theme
	 * metadata and is not a HUD section-renderer contract.
	 *
	 * Default contract: each brand provides exactly two themes, one
	 * with mode 'light' and one with mode 'dark'. White-label brands
	 * override this to register their own theme slugs (which must also
	 * be registered through the global `cb_admin_themes` filter so the
	 * theme system itself recognises them).
	 *
	 * Filter `cb_core_brand_themes_{$brand_id}` wraps the return value
	 * so a white-label plugin can replace a brand's theme list without
	 * subclassing the Brand class.
	 *
	 * Each entry shape:
	 *   slug  string  Theme slug - must match a key in Themes::all()
	 *   label string  Human-readable label, shown in tooltip
	 *   mode  string  'light' | 'dark' - drives the segment glyph
	 *
	 * @return array<int, array{slug: string, label: string, mode: string}>
	 */
	public function themes(): array;

	/**
	 * Optional HTML representation for brand-aware presentation surfaces -
	 * typically logo + label, while white-label brands can return
	 * any markup they want here (logo only, wordmark only, custom
	 * compound shape).
	 *
	 * Output is rendered server-side and inserted with no further
	 * escaping - implementations are responsible for their own escaping
	 * via esc_html() / esc_attr() / wp_kses() as appropriate. SVG
	 * markup MUST be sanitised through {@see \CoreBlueprint\Core\HUD\HUD::sanitize_logo_svg()}
	 * or an equivalent allowlist, never echoed raw.
	 *
	 * Default implementation in {@see AbstractBrand} renders a
	 * standard logo+label block; brands extending AbstractBrand inherit
	 * that default and only override when they need a different
	 * presentation.
	 */
	public function render_trigger(): string;
}
