<?php
declare(strict_types=1);
/**
 * StateBadge - compact semantic workflow/status badge.
 *
 * Use for terse state labels such as REVIEWED, WARNING, COMPLETE or FAILED.
 * This primitive is intentionally separate from cb-core-badge, which is used
 * for taxonomy/compliance/technology labels rather than live UI state.
 *
 * Variants describe meaning, not colour:
 *   neutral, info, success, warning, danger, error.
 *
 * Density is independent from meaning:
 *   compact (default), default.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class StateBadge {

	public const NEUTRAL = 'neutral';
	public const INFO    = 'info';
	public const SUCCESS = 'success';
	public const WARNING = 'warning';
	public const DANGER  = 'danger';
	public const ERROR   = 'error';

	public const DENSITY_COMPACT = 'compact';
	public const DENSITY_DEFAULT = 'default';

	private const VARIANTS = [
		self::NEUTRAL,
		self::INFO,
		self::SUCCESS,
		self::WARNING,
		self::DANGER,
		self::ERROR,
	];

	private const DENSITIES = [
		self::DENSITY_COMPACT,
		self::DENSITY_DEFAULT,
	];

	/**
	 * Render a semantic state badge.
	 *
	 * Recognised args:
	 *   variant : neutral|info|success|warning|danger|error
	 *   density : compact|default
	 *   class   : optional extra class string
	 *
	 * @param string $label Human-readable label; caller owns translation.
	 * @param array  $args  Rendering options.
	 * @return string Escape-clean HTML.
	 */
	public static function render( string $label, array $args = [] ): string {
		$variant = (string) ( $args['variant'] ?? self::NEUTRAL );
		$density = (string) ( $args['density'] ?? self::DENSITY_COMPACT );
		$extra   = trim( (string) ( $args['class'] ?? '' ) );

		if ( ! in_array( $variant, self::VARIANTS, true ) ) {
			$variant = self::NEUTRAL;
		}
		if ( ! in_array( $density, self::DENSITIES, true ) ) {
			$density = self::DENSITY_COMPACT;
		}

		$classes = [
			'cb-core-state-badge',
			'cb-core-state-badge--' . $variant,
			'cb-core-state-badge--' . $density,
		];
		if ( '' !== $extra ) {
			foreach ( preg_split( '/\s+/', $extra ) ?: [] as $class ) {
				$class = sanitize_html_class( $class );
				if ( '' !== $class ) {
					$classes[] = $class;
				}
			}
		}

		return '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">' . esc_html( $label ) . '</span>';
	}
}
