<?php
declare(strict_types=1);
/**
 * PrimaryNav - canonical Level 1 navigation for Core Blueprint admin workspaces.
 *
 * Consumers own routes, capabilities and labels. Base owns WordPress-native
 * tab markup, active-state presentation and accessibility.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class PrimaryNav {

	/**
	 * Render primary workspace navigation.
	 *
	 * Recognised args:
	 *   items      : map of item id => [ 'label' => string, 'href' => string ]
	 *   active     : active item id
	 *   aria_label : accessible navigation label
	 *   class      : optional additional class string
	 *
	 * @param array<string,mixed> $args Navigation arguments.
	 */
	public static function render( array $args ): string {
		$items = is_array( $args['items'] ?? null ) ? $args['items'] : [];
		$active = (string) ( $args['active'] ?? '' );
		$aria_label = trim( (string) ( $args['aria_label'] ?? __( 'Sections', 'core-blueprint' ) ) );
		$extra = trim( (string) ( $args['class'] ?? '' ) );

		$links = [];
		foreach ( $items as $id => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = trim( (string) ( $item['label'] ?? '' ) );
			$href = trim( (string) ( $item['href'] ?? '' ) );
			if ( '' === $label || '' === $href ) {
				continue;
			}

			$is_active = (string) $id === $active;
			$classes = [ 'nav-tab' ];
			if ( $is_active ) {
				$classes[] = 'nav-tab-active';
			}

			$links[] = sprintf(
				'<a class="%1$s" href="%2$s"%3$s>%4$s</a>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $href ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		if ( [] === $links ) {
			return '';
		}

		$classes = [ 'nav-tab-wrapper', 'cb-core-tab-wrapper' ];
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		return sprintf(
			'<nav class="%1$s" aria-label="%2$s">%3$s</nav>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( '' !== $aria_label ? $aria_label : __( 'Sections', 'core-blueprint' ) ),
			implode( '', $links )
		);
	}
}
