<?php
declare(strict_types=1);
/**
 * Status - CB UI primitive for inline status indicators.
 *
 * Renders a colour-coded dot with a label, used everywhere a piece of
 * config or service has a state worth showing at a glance: "Active",
 * "Ready", "Connection faltering", "Deactivated", and so on.
 *
 *   echo Status::render( 'active', __( 'Active', 'core-blueprint' ) );
 *
 * Five variants - the API uses statement-flavoured names ("is this
 * thing active?") rather than colour names. Implementations across
 * the suite read more naturally with this shape:
 *
 *   - 'active'   → green dot   (good, no attention needed)
 *   - 'ready'    → amber dot   (waiting for operator action)
 *   - 'warning'  → amber dot   (attention soon, not urgent)
 *   - 'error'    → red dot     (something is wrong)
 *   - 'idle'     → grey dot    (deactivated / not relevant)
 *
 * The mapping from semantic variant to dot colour is encoded in the
 * markup this method emits. CSS for the colours lives in
 * components/status-indicators.css.
 *
 * Visual rule of thumb: a status indicator is a *statement*, not a
 * call-to-action. If the operator needs to do something, render an
 * action link or button next to or beneath the indicator - never style
 * the indicator itself like a button.
 *
 * Markup contract (BEM):
 *
 *   <span class="cb-core-status">
 *     <span class="cb-core-status__dot cb-core-status__dot--{COLOR}" aria-hidden="true"></span>
 *     <span class="cb-core-status__label">{LABEL}</span>
 *   </span>
 *
 * The dot has aria-hidden="true" because the label carries the same
 * info in text - screen-readers should announce "Active" once, not
 * "Active Active".
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Status {

	/**
	 * Map semantic variant → dot colour class. The CSS component uses
	 * colour names because that's what CSS describes; the helper API
	 * uses semantic names because that's what call sites think in.
	 */
	private const VARIANT_TO_DOT = [
		'active'  => 'cb-core-status__dot--success',
		'ready'   => 'cb-core-status__dot--warning',
		'warning' => 'cb-core-status__dot--warning',
		'error'   => 'cb-core-status__dot--danger',
		'idle'    => 'cb-core-status__dot--muted',
	];

	/**
	 * Render a status indicator as inline HTML.
	 *
	 * @param string $variant One of 'active', 'ready', 'warning', 'error', 'idle'.
	 *                        Anything else falls back to 'idle'.
	 * @param string $label   Human-readable label, displayed next to the dot.
	 *                        Caller is responsible for translation; this method
	 *                        only escapes for output.
	 * @return string HTML - safe to echo directly.
	 */
	public static function render( string $variant, string $label ): string {
		$dot_class = self::VARIANT_TO_DOT[ $variant ] ?? self::VARIANT_TO_DOT['idle'];

		return sprintf(
			'<span class="cb-core-status"><span class="cb-core-status__dot %1$s" aria-hidden="true"></span><span class="cb-core-status__label">%2$s</span></span>',
			esc_attr( $dot_class ),
			esc_html( $label )
		);
	}
}
