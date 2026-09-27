<?php
declare(strict_types=1);
/**
 * Canonical Core Blueprint brand lockup.
 *
 * Owns the fixed relationship between the Base brand mark and textual
 * wordmark. Visual brand rules live in the matching brand-lockup stylesheet.
 *
 * @internal
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Brand;

defined( 'ABSPATH' ) || exit;

final class CoreBlueprintLockup {

	public static function enqueue_assets(): void {
		wp_enqueue_style(
			'cb-core-css-brand-lockup',
			CB_CORE_URL . 'assets/css/components/brand-lockup.css',
			[ 'cb-core-css-tokens' ],
			CB_CORE_VERSION
		);
	}

	public static function html( string $class = '' ): string {
		$classes = trim( 'cb-core-brand-lockup ' . $class );

		return '<span class="' . esc_attr( $classes ) . '">'
			. '<img class="cb-core-brand-lockup__mark" src="' . esc_attr( CoreBlueprintMark::data_uri() ) . '" alt="" aria-hidden="true">'
			. '<span class="cb-core-brand-lockup__wordmark" aria-label="Core Blueprint">'
			. '<span aria-hidden="true">core<span class="cb-core-brand-lockup__dot">.</span>blueprint</span>'
			. '</span>'
			. '</span>';
	}
}
