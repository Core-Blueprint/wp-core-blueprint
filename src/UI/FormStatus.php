<?php
declare(strict_types=1);
/**
 * FormStatus - CB UI primitive for inline save-feedback.
 *
 * Renders the canonical save-status element next to a save button.
 * State (idle/pending/success/error) is driven by the `data-kind`
 * attribute, which JS sets after each save attempt. The element
 * always emits `role="status"` and `aria-live="polite"` so screen-
 * readers announce updates.
 *
 *   echo FormStatus::render();
 *   echo FormStatus::render( [ 'target' => 'operators' ] );
 *   echo FormStatus::render( [
 *       'data' => [ 'data-cb-core-alert-recipient-status' => '',
 *                   'data-cb-core-alert-group'           => 'permissions' ],
 *   ] );
 *
 * Element type:
 *
 *   - default <span>  inline next to a save-button
 *   - 'block'  <div>  block-level under a save-button (used on pages
 *                     where the status sits below the action row)
 *
 * Markup contract:
 *
 *   <span class="cb-core-form-status [--tight] [extra]"
 *         role="status"
 *         aria-live="polite"
 *         [id="..."]
 *         [data-target="..."]
 *         [data-* extras]
 *   ></span>
 *
 * JS sets `el.textContent` and `el.dataset.kind = 'pending|success|error'`
 * via the canonical setStatus helper. CSS lives in
 * components/form-status.css.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class FormStatus {

	/**
	 * Render a form-status element to inline HTML.
	 *
	 * Recognised args:
	 *   block  : bool   - render as <div> instead of <span>
	 *   target : string - emitted as data-target="..." (used by JS helpers
	 *                     like permissions.js to find the right status span
	 *                     within a form that has multiple save buttons)
	 *   id     : string - emitted as id="..." (used where templates need
	 *                     to address the element by id, e.g. maintenance-report)
	 *   tight  : bool   - adds --tight modifier (smaller margin-left, used
	 *                     when the status sits inside a tight inline context)
	 *   class  : string - extra class string appended to the wrapper
	 *   data   : array  - extra data-* attributes as key/value pairs.
	 *                     Keys must include the 'data-' prefix. Values that
	 *                     are empty strings render as bare attributes
	 *                     (e.g. data-cb-core-ls-save-status="").
	 */
	public static function render( array $args = [] ): string {
		$block  = (bool)   ( $args['block']  ?? false );
		$target = (string) ( $args['target'] ?? '' );
		$id     = (string) ( $args['id']     ?? '' );
		$tight  = (bool)   ( $args['tight']  ?? false );
		$extra  = (string) ( $args['class']  ?? '' );
		$data   = is_array( $args['data'] ?? null ) ? $args['data'] : [];

		$tag = $block ? 'div' : 'span';

		$classes = [ 'cb-core-form-status' ];
		if ( $tight ) {
			$classes[] = 'cb-core-form-status--tight';
		}
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$attrs  = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		$attrs .= ' role="status"';
		$attrs .= ' aria-live="polite"';

		if ( '' !== $id ) {
			$attrs .= ' id="' . esc_attr( $id ) . '"';
		}
		if ( '' !== $target ) {
			$attrs .= ' data-target="' . esc_attr( $target ) . '"';
		}

		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( 0 !== strncmp( $key, 'data-', 5 ) ) {
				continue; // Defensive: ignore non-data attributes.
			}
			$attrs .= ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $value ) . '"';
		}

		return '<' . $tag . $attrs . '></' . $tag . '>';
	}
}
