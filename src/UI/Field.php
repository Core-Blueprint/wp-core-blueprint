<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Field - CB UI primitive for form-field wrappers.
 *
 * Slot-component: callers fill the control slot with already-built HTML
 * (input, select, fieldset, RadioGroup output, multi-input combo, etc).
 * Helper handles the wrapper element, label rendering, optional hint
 * paragraph, and variant-specific spacing.
 *
 * Four variants:
 *
 *   - 'default'    vertical stack - label above control above hint
 *   - 'inline'     horizontal flex row - input alongside button + status
 *                  (notifications recipient inputs)
 *   - 'separated'  vertical stack with sibling-divider above (for stacked
 *                  config rows that need visual separation)
 *   - 'enable'     first-row toggle, no padding/margin/divider - anchors
 *                  the top of a separated form
 *
 *   echo Field::render( [
 *       'variant'   => 'separated',
 *       'label'     => __( 'Custom login URL', 'core-blueprint' ),
 *       'label_sub' => __( 'Letters, numbers, and hyphens only…', 'core-blueprint' ),
 *       'label_for' => 'cb-core-ls-slug',
 *       'control'   => $slug_input_html,
 *   ] );
 *
 * Label rendering:
 *
 *   - default                  <label class="cb-core-field__label">{label}</label>
 *   - with label_sub           <label …><strong>{label}</strong>
 *                                       <span class="cb-core-muted">{label_sub}</span></label>
 *   - with label_for           wraps in <label for="X"…>
 *   - without label_for        wraps in <span class="cb-core-field__label"…>
 *                              (no click-to-focus target - used when the
 *                              control is a fieldset, RadioGroup, or
 *                              multiple inputs without a single id)
 *   - empty label              omitted entirely (caller fully controls
 *                              the field interior via the control slot)
 *
 * Hint:
 *
 *   - hint arg renders <p class="description">…</p> below the control,
 *     inside the field wrapper. For descriptions that should sit
 *     OUTSIDE the field (e.g. notifications recipient field where the
 *     description is paragraph-level), put the <p> in the template
 *     instead and leave the hint arg empty.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


final class Field {

	public const VARIANT_DEFAULT   = 'default';
	public const VARIANT_INLINE    = 'inline';
	public const VARIANT_SEPARATED = 'separated';
	public const VARIANT_ENABLE    = 'enable';

	public const STATE_DEFAULT = 'default';
	public const STATE_ERROR   = 'error';

	private const SUPPORTED_STATES = [
		self::STATE_DEFAULT,
		self::STATE_ERROR,
	];

	private const SUPPORTED_VARIANTS = [
		self::VARIANT_DEFAULT,
		self::VARIANT_INLINE,
		self::VARIANT_SEPARATED,
		self::VARIANT_ENABLE,
	];

	/**
	 * Render a field-wrapper to inline HTML.
	 *
	 * Recognised args:
	 *   variant   : 'default' | 'inline' | 'separated' | 'enable'
	 *   label     : title text - caller-localised, escaped here. Empty
	 *               string omits the label entirely.
	 *   label_sub : optional muted secondary text rendered as
	 *               <span class="cb-core-muted"> next to/below the title.
	 *               When set, the title is wrapped in <strong>.
	 *   label_for : optional input id - when set, label renders as
	 *               <label for="X">. When empty, label renders as <span>
	 *               (used for fieldset/RadioGroup controls where there
	 *               isn't a single focusable element).
	 *   control   : HTML string for the control slot. NOT escaped -
	 *               caller is responsible for escaping content inside.
	 *               This is the primary slot where caller drops inputs,
	 *               selects, RadioGroup output, multi-input combos.
	 *   hint      : optional plain-text help rendered below the control.
	 *   hint_id   : optional id for the help text. Caller may reference it
	 *               from the control via aria-describedby.
	 *   error     : optional plain-text validation/error message.
	 *   error_id  : optional id for the error text. Caller should reference
	 *               it from aria-describedby and set aria-invalid="true"
	 *               on the actual invalid control.
	 *   meta      : optional muted secondary metadata below help/error.
	 *   meta_id   : optional id for the metadata.
	 *   state     : 'default' | 'error'. Supplying error automatically uses
	 *               the error state.
	 *   class     : extra class string appended to the wrapper.
	 */
	public static function render( array $args ): string {
		$variant   = (string) ( $args['variant']   ?? self::VARIANT_DEFAULT );
		$label     = (string) ( $args['label']     ?? '' );
		$label_sub = (string) ( $args['label_sub'] ?? '' );
		$label_for = (string) ( $args['label_for'] ?? '' );
		$control   = (string) ( $args['control']   ?? '' );
		$hint      = (string) ( $args['hint']      ?? '' );
		$hint_id   = (string) ( $args['hint_id']   ?? '' );
		$error     = (string) ( $args['error']     ?? '' );
		$error_id  = (string) ( $args['error_id']  ?? '' );
		$meta      = (string) ( $args['meta']      ?? '' );
		$meta_id   = (string) ( $args['meta_id']   ?? '' );
		$state     = (string) ( $args['state']     ?? self::STATE_DEFAULT );
		$extra     = (string) ( $args['class']     ?? '' );

		// ─── Runtime guard ─────────────────────────────────────────────
		if ( ! in_array( $state, self::SUPPORTED_STATES, true ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s: invalid state name */
						__( 'Field state "%s" is not supported. Use "default" or "error".', 'core-blueprint' ),
						$state
					)
				),
				'2.0.0'
			);
			return '';
		}

		if ( ! in_array( $variant, self::SUPPORTED_VARIANTS, true ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s: invalid variant name */
						__( 'Field variant "%s" is not supported. Use "default", "inline", "separated", or "enable".', 'core-blueprint' ),
						$variant
					)
				),
				'1.1.0'
			);
			return '';
		}

		// ─── Wrapper classes ───────────────────────────────────────────
		$classes = [ 'cb-core-field' ];
		if ( self::VARIANT_DEFAULT !== $variant ) {
			$classes[] = 'cb-core-field--' . $variant;
		}
		if ( '' !== $error || self::STATE_ERROR === $state ) {
			$classes[] = 'cb-core-field--error';
		}
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		// ─── Label slot ────────────────────────────────────────────────
		$label_html = '';
		if ( '' !== $label ) {
			$label_tag   = '' !== $label_for ? 'label' : 'span';
			$label_attrs = ' class="cb-core-field__label"';
			if ( '' !== $label_for ) {
				$label_attrs .= ' for="' . esc_attr( $label_for ) . '"';
			}

			$label_inner = '';
			if ( '' !== $label_sub ) {
				$label_inner  = '<strong>' . esc_html( $label ) . '</strong>';
				$label_inner .= '<span class="cb-core-muted">' . esc_html( $label_sub ) . '</span>';
			} else {
				$label_inner = esc_html( $label );
			}

			$label_html = '<' . $label_tag . $label_attrs . '>' . $label_inner . '</' . $label_tag . '>';
		}

		// ─── Hint slot ─────────────────────────────────────────────────
		$hint_html = '';
		if ( '' !== $hint ) {
			$hint_attrs = '' !== $hint_id ? ' id="' . esc_attr( $hint_id ) . '"' : '';
			$hint_html  = '<p class="description cb-core-field__hint"' . $hint_attrs . '>' . esc_html( $hint ) . '</p>';
		}

		$error_html = '';
		if ( '' !== $error ) {
			$error_attrs = '' !== $error_id ? ' id="' . esc_attr( $error_id ) . '"' : '';
			$error_html  = '<p class="cb-core-field__error"' . $error_attrs . '>' . esc_html( $error ) . '</p>';
		}

		$meta_html = '';
		if ( '' !== $meta ) {
			$meta_attrs = '' !== $meta_id ? ' id="' . esc_attr( $meta_id ) . '"' : '';
			$meta_html  = '<p class="cb-core-field__meta"' . $meta_attrs . '>' . esc_html( $meta ) . '</p>';
		}

		// ─── Compose ───────────────────────────────────────────────────
		return '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">'
			. $label_html
			. $control
			. $error_html
			. $hint_html
			. $meta_html
			. '</div>';
	}
}
