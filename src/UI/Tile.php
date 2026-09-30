<?php
declare(strict_types=1);
/**
 * Tile - CB UI primitive for tile-shaped content cards.
 *
 * Three variants, all built on a shared .cb-core-tile base (component
 * CSS in assets/css/components/tile-grid.css):
 *
 *   - 'navigation' calm navigation card for ordinary admin destinations.
 *
 *   - 'status-nav' navigation card with an optional operational state-dot.
 *
 *   - 'metric'  compact KPI tile with label/value/state-line. Used for
 *               maintenance-report KPI strips and similar at-a-glance
 *               numerics.
 *
 * State modifiers (apply to metric tiles and shared state helpers where meaningful):
 *
 *   active | ok       → green   (operational / on schedule)
 *   warn  | warning   → amber   (attention soon, not urgent)
 *   error | danger    → red     (something is wrong)
 *   overdue           → red     (alias of danger; semantic for schedules)
 *   idle              → grey    (deactivated / paused)
 *   neutral | unknown → muted   (no state to communicate)
 *
 * Tile contracts mirror the Status helper: callers think in semantic
 * terms ("this thing is overdue"), the helper maps to a colour class.
 *
 *   echo Tile::render( [
 *       'variant' => 'metric',
 *       'label'   => __( 'Last backup', 'core-blueprint' ),
 *       'value'   => '4 days ago',
 *       'state'   => 'warn',
 *       'state_text' => __( 'Due soon', 'core-blueprint' ),
 *   ] );
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Tile {

	public const VARIANT_NAVIGATION = 'navigation';
	public const VARIANT_STATUS_NAV = 'status-nav';
	public const VARIANT_METRIC     = 'metric';

	/**
	 * Map semantic state → BEM modifier class. Aliases collapse onto
	 * the same colour: 'overdue'→danger, 'unknown'→neutral, etc. Returns
	 * '' when no state class should apply.
	 */
	private const STATE_TO_CLASS = [
		'active'   => 'cb-core-tile--ok',
		'ok'       => 'cb-core-tile--ok',
		'warn'     => 'cb-core-tile--warn',
		'warning'  => 'cb-core-tile--warn',
		'error'    => 'cb-core-tile--danger',
		'danger'   => 'cb-core-tile--danger',
		'overdue'  => 'cb-core-tile--danger',
		'idle'     => 'cb-core-tile--idle',
		'neutral'  => 'cb-core-tile--neutral',
		'unknown'  => 'cb-core-tile--neutral',
	];

	/**
	 * Map a semantic state to the BEM modifier class used by Tile CSS.
	 * Public so templates that emit tiles inline (without going through
	 * render()) can apply the same state-mapping rules.
	 *
	 * Returns 'cb-core-tile--neutral' for unknown values.
	 */
	public static function state_class( string $state ): string {
		return self::STATE_TO_CLASS[ $state ] ?? 'cb-core-tile--neutral';
	}

	/**
	 * Render a tile to inline HTML.
	 *
	 * @param array $args Variant-specific arguments. See variant docblocks.
	 * @return string HTML - safe to echo directly.
	 */
	public static function render( array $args ): string {
		$variant = (string) ( $args['variant'] ?? self::VARIANT_NAVIGATION );
		switch ( $variant ) {
			case self::VARIANT_STATUS_NAV:
				return self::render_navigation( $args, true );
			case self::VARIANT_METRIC:
				return self::render_metric( $args );
			case self::VARIANT_NAVIGATION:
			default:
				return self::render_navigation( $args, false );
		}
	}

	/**
	 * Render a formal dashboard navigation card. Status navigation may
	 * expose a state-dot; ordinary navigation deliberately does not.
	 */
	private static function render_navigation( array $args, bool $with_state ): string {
		return self::render_navigation_tile(
			$args,
			$with_state ? 'cb-core-tile--status-nav' : 'cb-core-tile--navigation',
			$with_state
		);
	}

	/**
	 * Shared renderer for link-style tile variants.
	 */
	private static function render_navigation_tile( array $args, string $variant_class, bool $allow_state ): string {
		$kicker = (string) ( $args['kicker'] ?? '' );
		$title  = (string) ( $args['title']  ?? '' );
		$meta   = (string) ( $args['meta']   ?? '' );
		$href   = (string) ( $args['href']   ?? '' );
		$state  = $allow_state ? (string) ( $args['state'] ?? '' ) : '';
		$extra  = (string) ( $args['class']  ?? '' );

		$classes = [ 'cb-core-tile', $variant_class ];
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$inner = '';
		if ( '' !== $kicker ) {
			$inner .= '<span class="cb-core-tile__kicker">' . esc_html( $kicker ) . '</span>';
		}
		if ( '' !== $title ) {
			$inner .= '<span class="cb-core-tile__title">' . esc_html( $title ) . '</span>';
		}
		if ( '' !== $meta ) {
			$inner .= '<span class="cb-core-tile__meta">' . esc_html( $meta ) . '</span>';
		}
		if ( '' !== $state ) {
			$dot_class = in_array( $state, [ 'active', 'inactive', 'warning', 'idle', 'error' ], true )
				? 'cb-core-tile__dot--' . $state
				: 'cb-core-tile__dot--inactive';
			$inner .= '<span class="cb-core-tile__dot ' . esc_attr( $dot_class ) . '" aria-hidden="true"></span>';
		}

		if ( '' !== $href ) {
			$data_state = '' !== $state ? sprintf( ' data-state="%s"', esc_attr( $state ) ) : '';
			return sprintf(
				'<a class="%1$s" href="%2$s"%3$s>%4$s</a>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $href ),
				$data_state,
				$inner
			);
		}

		return sprintf(
			'<div class="%1$s">%2$s</div>',
			esc_attr( implode( ' ', $classes ) ),
			$inner
		);
	}

	/**
	 * Metric tile: label, prominent value, state-line.
	 *
	 * Recognised args:
	 *   label      : top label (small, uppercase via CSS)
	 *   value      : the prominent numeric/text value
	 *   state      : 'ok'|'warn'|'overdue'|'neutral'|'unknown' - colour class
	 *                applied to the left-edge accent strip
	 *   state_text : status line below the value (already-localised text)
	 */
	private static function render_metric( array $args ): string {
		$label      = (string) ( $args['label']      ?? '' );
		$value      = (string) ( $args['value']      ?? '' );
		$state      = (string) ( $args['state']      ?? 'neutral' );
		$state_text = (string) ( $args['state_text'] ?? '' );

		$state_class = self::STATE_TO_CLASS[ $state ] ?? 'cb-core-tile--neutral';

		$out  = sprintf(
			'<div class="cb-core-tile cb-core-tile--metric %s">',
			esc_attr( $state_class )
		);
		if ( '' !== $label ) {
			$out .= '<span class="cb-core-tile__label">' . esc_html( $label ) . '</span>';
		}
		if ( '' !== $value ) {
			$out .= '<span class="cb-core-tile__value">' . esc_html( $value ) . '</span>';
		}
		if ( '' !== $state_text ) {
			$out .= '<span class="cb-core-tile__state">' . esc_html( $state_text ) . '</span>';
		}
		$out .= '</div>';

		return $out;
	}
}
