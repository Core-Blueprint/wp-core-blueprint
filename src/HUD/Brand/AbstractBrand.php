<?php
declare(strict_types=1);
/**
 * AbstractBrand - convenience base class for HUD brand implementations.
 *
 * Provides default implementations of {@see BrandInterface::themes()} and
 * {@see BrandInterface::render_trigger()} so concrete Brand classes only
 * need to override what they actually want to change. The interface still
 * defines the contract - extending AbstractBrand is optional but
 * recommended for any white-label plugin that just wants to ship a logo
 * and a palette without re-implementing the boilerplate.
 *
 * The built-in CoreBlueprint brand extends this class. Third-party Brand classes registered through
 * `core_blueprint_register_brands` may extend it too, or implement
 * BrandInterface directly when they need full control over every method.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD\Brand;

use CoreBlueprint\Core\Themes;

defined( 'ABSPATH' ) || exit;

abstract class AbstractBrand implements BrandInterface {

	/**
	 * Default theme list - one light + one dark variant. Subclasses can
	 * override to provide their own slugs (must be registered through
	 * `core_blueprint_admin_themes` so the global theme system recognises them).
	 *
	 * The returned array is run through the
	 * `core_blueprint_brand_themes_{brand_id}` filter so white-label plugins
	 * can replace a brand's theme list without subclassing - useful for
	 * shipping a "Theme Pack" extension that adds custom themes to an
	 * existing brand.
	 *
	 * @return array<int, array{slug: string, label: string, mode: string}>
	 */
	public function themes(): array {
		$default = [
			[
				'slug'  => Themes::SLUG_CB_LIGHT,
				'label' => __( 'Light', 'core-blueprint' ),
				'mode'  => 'light',
			],
			[
				'slug'  => Themes::SLUG_CB_DARK,
				'label' => __( 'Dark', 'core-blueprint' ),
				'mode'  => 'dark',
			],
		];

		/**
		 * Filter: core_blueprint_brand_themes_{brand_id}
		 *
		 * Replace or extend the theme list for this brand without
		 * subclassing it. Useful for white-label theme packs.
		 *
		 * Example - replacing CB's themes with two custom ones:
		 *
		 *     add_filter( 'core_blueprint_brand_themes_core-blueprint', function (): array {
		 *         return [
		 *             [ 'slug' => 'vendor_light', 'label' => 'Cream', 'mode' => 'light' ],
		 *             [ 'slug' => 'vendor_dark', 'label' => 'Slate', 'mode' => 'dark' ],
		 *         ];
		 *     } );
		 *
		 * @param array<int, array{slug: string, label: string, mode: string}> $default
		 */
		return (array) apply_filters( 'core_blueprint_brand_themes_' . $this->id(), $default );
	}

	/**
	 * Default trigger renderer - logo on the left, label on the right.
	 * Sanitises the brand's SVG through HUD::sanitize_logo_svg() before
	 * emitting; all text is escaped via esc_html().
	 *
	 * White-label brands can override to return custom markup -
	 * wordmark-only, logo-only, or any combination. The HUD CSS expects
	 * the outer container class `cb-hud__brand-trigger`; subclasses
	 * that override should keep that class so the row layout remains
	 * consistent across brands.
	 */
	public function render_trigger(): string {
		$logo  = \CoreBlueprint\Core\HUD\HUD::sanitize_logo_svg( $this->logo_svg() );
		$label = esc_html( $this->label() );

		return sprintf(
			'<span class="cb-hud__brand-trigger">'
			. '<span class="cb-hud__brand-logo" aria-hidden="true">%s</span>'
			. '<span class="cb-hud__brand-label">%s</span>'
			. '</span>',
			$logo,
			$label
		);
	}
}
