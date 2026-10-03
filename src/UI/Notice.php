<?php
declare(strict_types=1);
/**
 * Notice - Core Blueprint semantic notice primitive.
 *
 * Use for persistent in-page information that deserves more visual weight
 * than body copy but should stay inside the Core Blueprint design system.
 * This is intentionally separate from WordPress' global `.notice` API:
 * Core Blueprint notices are component-owned, theme-token driven and safe
 * to reuse across Base modules and sibling Core Blueprint plugins.
 *
 * Variants:
 *   info     - neutral contextual information
 *   success  - completed / healthy state
 *   warning  - operator attention required; action may be needed
 *   error    - manifest failure that needs correction
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Notice {

	public const INFO    = 'info';
	public const SUCCESS = 'success';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	private const SUPPORTED_VARIANTS = [
		self::INFO,
		self::SUCCESS,
		self::WARNING,
		self::ERROR,
	];

	private const ICONS = [
		self::INFO    => 'feedback-info',
		self::SUCCESS => 'feedback-success',
		self::WARNING => 'feedback-warning',
		self::ERROR   => 'feedback-error',
	];

	/**
	 * Render a semantic Core Blueprint notice.
	 *
	 * Recognised args:
	 *   variant : info|success|warning|error. Defaults to info.
	 *   title   : plain-text heading. Empty string omits the heading.
	 *   message : plain-text body copy.
	 *   icon    : bool, defaults to true.
	 *   class   : optional extra wrapper class.
	 *
	 * @param array $args Notice arguments.
	 * @return string Escaped HTML safe to echo directly.
	 */
	public static function render( array $args ): string {
		$variant = (string) ( $args['variant'] ?? self::INFO );
		$title   = (string) ( $args['title'] ?? '' );
		$message = (string) ( $args['message'] ?? '' );
		$items   = is_array( $args['items'] ?? null ) ? $args['items'] : [];
		$icon    = (bool) ( $args['icon'] ?? true );
		$extra   = trim( (string) ( $args['class'] ?? '' ) );

		if ( ! in_array( $variant, self::SUPPORTED_VARIANTS, true ) ) {
			$variant = self::INFO;
		}

		$classes = [ 'cb-core-notice', 'cb-core-notice--' . $variant ];
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$role = in_array( $variant, [ self::WARNING, self::ERROR ], true ) ? 'alert' : 'status';
		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="' . esc_attr( $role ) . '">';

		if ( $icon ) {
			$html .= '<span class="cb-core-notice__icon" aria-hidden="true">' . Icon::render( self::ICONS[ $variant ], [ 'class' => 'cb-core-notice__glyph' ] ) . '</span>';
		}

		$html .= '<div class="cb-core-notice__content">';
		if ( '' !== $title ) {
			$html .= '<div class="cb-core-notice__title">' . esc_html( $title ) . '</div>';
		}
		if ( '' !== $message ) {
			$html .= '<p class="cb-core-notice__message">' . esc_html( $message ) . '</p>';
		}
		if ( ! empty( $items ) ) {
			$html .= '<ul class="cb-core-notice__list">';
			foreach ( $items as $item ) {
				$item = trim( (string) $item );
				if ( '' !== $item ) {
					$html .= '<li>' . esc_html( $item ) . '</li>';
				}
			}
			$html .= '</ul>';
		}
		$html .= '</div></div>';

		return $html;
	}
}
