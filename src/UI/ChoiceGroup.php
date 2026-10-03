<?php
declare(strict_types=1);
/**
 * ChoiceGroup - shared Core Blueprint Foundation for grouped checkbox/radio options.
 *
 * The component owns presentation and accessible grouping only. Consumers retain
 * ownership of field names, values, persistence and validation. This keeps the
 * primitive reusable across Base modules and sibling extensions without leaking
 * domain semantics into Foundation.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class ChoiceGroup {

	public const TYPE_CHECKBOX = 'checkbox';
	public const TYPE_RADIO    = 'radio';

	/**
	 * Render a grouped set of native checkbox or radio inputs.
	 *
	 * Recognised args:
	 * - type: `checkbox` (default) or `radio`.
	 * - options: list of arrays containing `name`, `label`, `value`, `checked`,
	 *   and optional `disabled`, `id`, `class`.
	 * - scrollable: constrains tall collections and enables internal scrolling.
	 * - compact: denser presentation for embedded workspaces.
	 * - class: optional extra wrapper class(es).
	 * - aria_label: optional accessible group name when no external label exists.
	 *
	 * @param array<string,mixed> $args Component arguments.
	 */
	public static function render( array $args ): string {
		$type = (string) ( $args['type'] ?? self::TYPE_CHECKBOX );
		if ( ! in_array( $type, [ self::TYPE_CHECKBOX, self::TYPE_RADIO ], true ) ) {
			$type = self::TYPE_CHECKBOX;
		}

		$options    = is_array( $args['options'] ?? null ) ? $args['options'] : [];
		$scrollable = ! empty( $args['scrollable'] );
		$compact    = ! empty( $args['compact'] );
		$extra      = trim( (string) ( $args['class'] ?? '' ) );
		$aria_label = trim( (string) ( $args['aria_label'] ?? '' ) );

		$classes = [ 'cb-core-choice-group' ];
		if ( $scrollable ) {
			$classes[] = 'cb-core-choice-group--scrollable';
		}
		if ( $compact ) {
			$classes[] = 'cb-core-choice-group--compact';
		}
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$attrs = ' class="' . esc_attr( implode( ' ', $classes ) ) . '" role="group"';
		if ( '' !== $aria_label ) {
			$attrs .= ' aria-label="' . esc_attr( $aria_label ) . '"';
		}

		$html = '<div' . $attrs . '>';
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$name  = (string) ( $option['name'] ?? '' );
			$label = (string) ( $option['label'] ?? '' );
			if ( '' === $name || '' === $label ) {
				continue;
			}

			$value     = (string) ( $option['value'] ?? '1' );
			$id        = trim( (string) ( $option['id'] ?? '' ) );
			$disabled  = ! empty( $option['disabled'] );
			$checked   = ! empty( $option['checked'] );
			$option_css = trim( (string) ( $option['class'] ?? '' ) );
			$option_classes = [ 'cb-core-choice-group__option' ];
			if ( '' !== $option_css ) {
				$option_classes[] = $option_css;
			}

			$input_attrs = ' type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"';
			if ( '' !== $id ) {
				$input_attrs .= ' id="' . esc_attr( $id ) . '"';
			}
			if ( $checked ) {
				$input_attrs .= ' checked';
			}
			if ( $disabled ) {
				$input_attrs .= ' disabled';
			}

			$html .= '<label class="' . esc_attr( implode( ' ', $option_classes ) ) . '">';
			$html .= '<input' . $input_attrs . ' />';
			$html .= '<span class="cb-core-choice-group__label">' . esc_html( $label ) . '</span>';
			$html .= '</label>';
		}
		$html .= '</div>';

		return $html;
	}
}
