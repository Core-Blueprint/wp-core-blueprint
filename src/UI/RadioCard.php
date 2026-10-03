<?php
declare(strict_types=1);
/**
 * RadioCard - CB UI primitive for radio-card form options.
 *
 * Renders a `<label>` wrapping a radio input with title and optional
 * description, styled as a clickable card. Used wherever a radio group
 * needs more affordance than a default browser radio: protection-mode
 * choices in Login Shield, IP-mode options in Privacy, description-mode
 * picker in Language.
 *
 * Three variants:
 *
 *   - 'default'    standard card (login-shield mode radios, privacy)
 *   - 'compact'    smaller padding + smaller title font (login-shield
 *                  response-code radios)
 *   - 'checkable'  with animated checkmark + is-selected-user/site states
 *                  (language description-mode picker - supports a per-user
 *                  override AND a separate site-default selection rendered
 *                  side-by-side)
 *
 *   echo RadioCard::render( [
 *       'name'  => 'mode',
 *       'value' => 'standard',
 *       'label' => __( 'Standard', 'core-blueprint' ),
 *       'desc'  => __( 'Blocks /wp-login.php for guests…', 'core-blueprint' ),
 *       'checked' => true,
 *   ] );
 *
 * Two-level data attributes: page-specific JS hooks land on the input
 * (login-shield's `data-cb-core-ls-mode`) or on the label (language's
 * `data-mode` and `data-user-only`). Use `input_data` and `data` args
 * accordingly.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class RadioCard {

	public const VARIANT_DEFAULT   = 'default';
	public const VARIANT_COMPACT   = 'compact';
	public const VARIANT_CHECKABLE = 'checkable';

	/**
	 * Render a radio-card to inline HTML.
	 *
	 * Recognised args:
	 *   variant        : 'default' | 'compact' | 'checkable'
	 *   name           : input[name] - required
	 *   value          : input[value] - required
	 *   label          : title text (caller-localised, escaped here)
	 *   desc           : optional description text (caller-localised,
	 *                    escaped here). Omitted when empty.
	 *   checked        : bool - adds checked="checked" to input
 *   active         : optional bool - explicit visual active state for the
 *                    default/compact variants. When omitted, checked input
 *                    state continues to drive the visual selection. Use this
 *                    only when a staged radio choice can differ from the
 *                    currently effective configuration.
	 *   selected_user  : bool - adds is-selected-user state class. Only
	 *                    meaningful for 'checkable' variant.
	 *   selected_site  : bool - adds is-selected-site state class. Only
	 *                    meaningful for 'checkable' variant.
	 *   site_badge     : optional badge text for the site-default state.
	 *                    Defaults to the localised "Site default" label.
	 *   class          : extra class string appended to the wrapper label
	 *   data           : array of data-* attributes for the wrapper <label>
	 *                    (key/value pairs; keys must include 'data-' prefix;
	 *                    empty-string values render as bare attributes)
	 *   input_data     : array of data-* attributes for the inner <input>
	 *                    (same shape as 'data')
	 */
	public static function render( array $args ): string {
		$variant       = (string) ( $args['variant']       ?? self::VARIANT_DEFAULT );
		$name          = (string) ( $args['name']          ?? '' );
		$value         = (string) ( $args['value']         ?? '' );
		$label         = (string) ( $args['label']         ?? '' );
		$desc          = (string) ( $args['desc']          ?? '' );
		$checked       = (bool)   ( $args['checked']       ?? false );
		$has_active     = array_key_exists( 'active', $args );
		$active         = $has_active ? (bool) $args['active'] : false;
		$selected_user = (bool)   ( $args['selected_user'] ?? false );
		$selected_site = (bool)   ( $args['selected_site'] ?? false );
		$site_badge    = (string) ( $args['site_badge']    ?? __( 'Site default', 'core-blueprint' ) );
		$extra         = (string) ( $args['class']         ?? '' );
		$data          = is_array( $args['data']       ?? null ) ? $args['data']       : [];
		$input_data    = is_array( $args['input_data'] ?? null ) ? $args['input_data'] : [];

		$is_checkable = self::VARIANT_CHECKABLE === $variant;

		// Wrapper classes.
		$classes = [ 'cb-core-radio-card' ];
		if ( self::VARIANT_COMPACT === $variant ) {
			$classes[] = 'cb-core-radio-card--compact';
		}
		if ( ! $is_checkable && $has_active ) {
			$classes[] = 'has-explicit-active-state';
			if ( $active ) {
				$classes[] = 'is-active';
			}
		}
		if ( $is_checkable ) {
			$classes[] = 'cb-core-radio-card--checkable';
			$classes[] = 'cb-core-choice-card';
			if ( $selected_user ) {
				$classes[] = 'is-selected-user';
			}
			if ( $selected_site ) {
				$classes[] = 'is-selected-site';
			}
		}
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		// Wrapper attributes.
		$label_attrs  = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		$label_attrs .= self::format_data_attrs( $data );
		if ( $is_checkable ) {
			$label_attrs .= ' data-site-badge="' . esc_attr( $site_badge ) . '"';
		}

		// Input attributes.
		$input_attrs  = ' type="radio"';
		$input_attrs .= ' name="' . esc_attr( $name ) . '"';
		$input_attrs .= ' value="' . esc_attr( $value ) . '"';
		if ( $checked ) {
			$input_attrs .= ' checked="checked"';
		}
		$input_attrs .= self::format_data_attrs( $input_data );

		// Body slot.
		$body  = '<span class="cb-core-radio-card__body">';
		$body .= '<span class="cb-core-radio-card__label">' . esc_html( $label ) . '</span>';
		if ( '' !== $desc ) {
			$body .= '<span class="cb-core-radio-card__desc">' . esc_html( $desc ) . '</span>';
		}
		$body .= '</span>';

		// Checkmark indicator (checkable variant only).
		$check = '';
		if ( $is_checkable ) {
			$check = '<span class="cb-core-radio-card__check cb-core-choice-card__check" aria-hidden="true">&#10003;</span>';
		}

		return '<label' . $label_attrs . '><input' . $input_attrs . ' />' . $body . $check . '</label>';
	}

	/**
	 * Format a key/value array into `' key="value"'` attribute string.
	 * Defensive: silently drops keys that don't begin with 'data-'.
	 */
	private static function format_data_attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			$key = (string) $key;
			if ( 0 !== strncmp( $key, 'data-', 5 ) ) {
				continue;
			}
			$out .= ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}
}
