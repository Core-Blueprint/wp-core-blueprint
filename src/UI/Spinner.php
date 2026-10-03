<?php
declare(strict_types=1);
/**
 * Spinner - CB UI primitive for inline loading indicators.
 *
 * Two emission patterns:
 *
 *   - Spinner::render()  - small inline spinner element. Drop next to
 *                          a disabled button or in-line in a status
 *                          message. Already includes aria-label for
 *                          accessibility.
 *
 *   - Spinner::busy()    - emits the `aria-busy="true"` attribute pair
 *                          for use on a containing form/section. Pair
 *                          with the spinner element for a visible cue.
 *
 * Visual states are CSS-only; toggle visibility/state from JS by adding
 * or removing the `is-active` class on the wrapper, or by toggling
 * aria-busy on the section.
 *
 *   <button id="generate" disabled><?php esc_html_e( 'Generating…', 'core-blueprint' ); ?></button>
 *   <?php echo Spinner::render(); ?>
 *
 *   <section <?php echo Spinner::busy( $is_running ); ?>>…
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Spinner {

	/**
	 * Render a small inline spinner element.
	 *
	 * Recognised args:
	 *   label  : aria-label override (default: "Loading")
	 *   active : bool - initial state. Defaults to true (visible).
	 *            Set false and toggle via JS for click-driven states.
	 *   class  : extra class string appended to the wrapper
	 */
	public static function render( array $args = [] ): string {
		$label  = (string) ( $args['label']  ?? __( 'Loading', 'core-blueprint' ) );
		$active = (bool)   ( $args['active'] ?? true );
		$extra  = (string) ( $args['class']  ?? '' );

		$classes = [ 'cb-core-spinner' ];
		if ( $active ) {
			$classes[] = 'is-active';
		}
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		return sprintf(
			'<span class="%s" role="status" aria-label="%s"><span class="cb-core-spinner__ring" aria-hidden="true"></span></span>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $label )
		);
	}

	/**
	 * Emit the `aria-busy="…"` attribute pair for a containing element.
	 * Pair with a visible spinner inside the busy region.
	 *
	 * @param bool $is_busy True when the region is loading.
	 * @return string Busy-region attributes or an empty string.
	 *                directly inside an opening tag.
	 */
	public static function busy( bool $is_busy ): string {
		return $is_busy ? ' data-cb-core-busy-region="true" aria-busy="true"' : '';
	}
}
