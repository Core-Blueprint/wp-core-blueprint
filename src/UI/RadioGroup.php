<?php
declare(strict_types=1);
namespace CB\Core\UI;

defined( 'ABSPATH' ) || exit;

/**
 * RadioGroup - CB UI primitive for radio-card groups.
 *
 * Renders N radio-cards from a structured options array, deriving each
 * card's checked state from a single current `value`. Used wherever a
 * radio group represents a single-select setting: Privacy IP mode,
 * Login Shield protection mode, Login Shield response-code picker.
 *
 * Delegates to RadioCard::render() per option.
 *
 *   echo RadioGroup::render( [
 *       'name'    => 'ip_mode',
 *       'value'   => $current_ip_mode,
 *       'options' => [
 *           [ 'value' => 'anonymized', 'label' => __( '…', 'core-blueprint' ),
 *             'desc' => __( '…', 'core-blueprint' ) ],
 *           [ 'value' => 'full',  'label' => __( '…', 'core-blueprint' ),
 *             'desc' => __( '…', 'core-blueprint' ) ],
 *           [ 'value' => 'none',  'label' => __( '…', 'core-blueprint' ),
 *             'desc' => __( '…', 'core-blueprint' ) ],
 *       ],
 *   ] );
 *
 * Scope:
 *
 *   This helper supports the 'default' and 'compact' RadioCard variants
 *   only - single-select cases where one current value determines which
 *   card is checked. The 'checkable' RadioCard variant is intentionally
 *   *not* supported here, because checkable cards have a dual-state
 *   selection (user-pref + site-default) that cannot be derived from a
 *   single value.
 *
 *   For checkable radio groups (Language description-mode picker), use
 *   a manual foreach + RadioCard::render() - see templates/language.php
 *   as the canonical example.
 *
 * Layout:
 *
 *   - 'stack'  (default) cards as direct siblings, no wrapper
 *   - 'grid'   cards wrapped in <div class="cb-core-radio-grid"> for
 *              grid layout. Optional `columns` (2, 3 or 4) selects an
 *              explicit equal-column Foundation modifier; omitted keeps
 *              the auto-fill grid.
 *
 * Per-option args (within 'options' array):
 *
 *   value       : input[value] - required
 *   label       : title text (caller-localised, escaped by RadioCard)
 *   desc        : optional description (caller-localised)
 *   data        : data-* attributes on the <label> wrapper
 *   input_data  : data-* attributes on the <input>
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


final class RadioGroup {

	public const LAYOUT_STACK = 'stack';
	public const LAYOUT_GRID  = 'grid';

	private const SUPPORTED_VARIANTS = [
		RadioCard::VARIANT_DEFAULT,
		RadioCard::VARIANT_COMPACT,
	];

	/**
	 * Render a radio-group to inline HTML.
	 *
	 * Recognised args:
	 *   variant  : 'default' | 'compact' - passed through to RadioCard.
	 *              'checkable' is REJECTED at runtime via _doing_it_wrong
	 *              because dual-state selection cannot be expressed via a
	 *              single 'value'.
	 *   name     : input[name] applied to every card - REQUIRED
	 *   value    : current selected value - REQUIRED. Card whose option
	 *              value matches gets `checked`.
	 *   options  : non-empty array of option-arrays - REQUIRED. Each
	 *              option must have 'value' and 'label'.
	 *   layout   : 'stack' (default) | 'grid'
	 *   columns  : optional 2 | 3 | 4 when layout=grid. Adds the matching
 *              cb-core-radio-grid--columns-N Foundation modifier.
 *   class    : extra class on the grid wrapper (ignored when layout=stack)
	 */
	public static function render( array $args ): string {
		$variant = (string) ( $args['variant'] ?? RadioCard::VARIANT_DEFAULT );
		$name    = (string) ( $args['name']    ?? '' );
		$value   = (string) ( $args['value']   ?? '' );
		$layout  = (string) ( $args['layout']  ?? self::LAYOUT_STACK );
		$columns = isset( $args['columns'] ) ? (int) $args['columns'] : 0;
		$extra   = (string) ( $args['class']   ?? '' );
		$options = is_array( $args['options'] ?? null ) ? $args['options'] : [];

		// ─── Runtime guards ─────────────────────────────────────────────
		// Reject the checkable variant explicitly. Dual-state selection
		// belongs in a manual foreach with RadioCard::render() - see the
		// docblock for rationale.
		if ( RadioCard::VARIANT_CHECKABLE === $variant ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__(
					'RadioGroup does not support the "checkable" variant. Checkable cards have a dual-state selection that cannot be derived from a single value - render them with a manual foreach + RadioCard::render() instead.',
					'core-blueprint'
				),
				'1.1.0'
			);
			return '';
		}

		if ( ! in_array( $variant, self::SUPPORTED_VARIANTS, true ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s: invalid variant name */
						__( 'RadioGroup variant "%s" is not supported. Use "default" or "compact".', 'core-blueprint' ),
						$variant
					)
				),
				'1.1.0'
			);
			return '';
		}

		if ( '' === $name ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'RadioGroup requires a non-empty "name" argument.', 'core-blueprint' ),
				'1.1.0'
			);
			return '';
		}

		if ( empty( $options ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'RadioGroup requires a non-empty "options" array.', 'core-blueprint' ),
				'1.1.0'
			);
			return '';
		}

		// ─── Render ────────────────────────────────────────────────────
		$out = '';
		if ( self::LAYOUT_GRID === $layout ) {
			$grid_classes = [ 'cb-core-radio-grid' ];
			if ( in_array( $columns, [ 2, 3, 4 ], true ) ) {
				$grid_classes[] = 'cb-core-radio-grid--columns-' . $columns;
			}
			if ( '' !== $extra ) {
				$grid_classes[] = $extra;
			}
			$out .= '<div class="' . esc_attr( implode( ' ', $grid_classes ) ) . '">';
		}

		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) || ! isset( $opt['value'], $opt['label'] ) ) {
				continue; // Defensive: skip malformed options silently.
			}
			$opt_value = (string) $opt['value'];
			$out .= RadioCard::render( [
				'variant'    => $variant,
				'name'       => $name,
				'value'      => $opt_value,
				'label'      => (string) $opt['label'],
				'desc'       => (string) ( $opt['desc'] ?? '' ),
				'checked'    => $value === $opt_value,
				'data'       => is_array( $opt['data']       ?? null ) ? $opt['data']       : [],
				'input_data' => is_array( $opt['input_data'] ?? null ) ? $opt['input_data'] : [],
			] );
		}

		if ( self::LAYOUT_GRID === $layout ) {
			$out .= '</div>';
		}

		return $out;
	}
}
