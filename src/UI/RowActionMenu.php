<?php
declare(strict_types=1);
/**
 * Domain-neutral row action menu. Navigation and mutation authority belong
 * exclusively to the consumer; Base only renders safe controls.
 *
 * @package Core_Blueprint
 */
namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class RowActionMenu {

	/**
	 * Render a progressive-enhancement overflow menu. Without JavaScript the
	 * trigger stays hidden; the consumer MUST provide a visible View/Edit link
	 * to its full, capability-protected detail screen.
	 *
	 * Action types:
	 * - link: url, label, optional target (_self/_blank)
	 * - button: label, optional data-/aria- attrs (consumer handles click)
	 * - submit: label, form_id (existing POST form with nonce/capability),
	 *           optional name/value submitted with the button.
	 * All types support variant=danger; disabled actions are omitted.
	 *
	 * @param array<string,mixed> $args Menu id seed, item label, actions.
	 */
	public static function render( array $args ): string {
		$id = sanitize_html_class( (string) ( $args['id'] ?? wp_unique_id( 'cb-core-row-menu-' ) ) );
		$label = trim( wp_strip_all_tags( (string) ( $args['label'] ?? '' ) ) );
		if ( '' === $label ) {
			$label = __( 'More actions', 'core-blueprint' );
		}
		$actions = is_array( $args['actions'] ?? null ) ? $args['actions'] : [];
		$items = [];
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || ! empty( $action['disabled'] ) ) {
				continue;
			}
			$html = self::render_action( $action );
			if ( '' !== $html ) {
				$items[] = $html;
			}
		}
		if ( [] === $items ) {
			return '';
		}

		$panel_id = $id . '-panel';
		return '<div class="cb-core-row-action-menu" data-cb-core-row-menu>'
			. '<button type="button" class="cb-core-row-action-menu__trigger"'
			. ' data-cb-core-row-menu-trigger aria-haspopup="menu" aria-expanded="false"'
			. ' aria-controls="' . esc_attr( $panel_id ) . '"'
			. ' aria-label="' . esc_attr( $label ) . '" hidden>'
			. Icon::render( 'ellipsis', [ 'size' => Icon::SIZE_COMPACT ] )
			. '</button>'
			. '<ul id="' . esc_attr( $panel_id ) . '" class="cb-core-row-action-menu__panel"'
			. ' role="menu" aria-label="' . esc_attr( $label ) . '"'
			. ' data-cb-core-row-menu-panel hidden>'
			. implode( '', $items ) . '</ul></div>';
	}

	/** @param array<string,mixed> $action */
	private static function render_action( array $action ): string {
		$type = (string) ( $action['type'] ?? 'link' );
		$label = trim( wp_strip_all_tags( (string) ( $action['label'] ?? '' ) ) );
		if ( '' === $label ) {
			return '';
		}
		$class = 'cb-core-row-action-menu__item';
		if ( 'danger' === (string) ( $action['variant'] ?? '' ) ) {
			$class .= ' cb-core-row-action-menu__item--danger';
		}
		$attrs = self::safe_attrs( $action['attrs'] ?? [] );

		if ( 'link' === $type ) {
			$url = esc_url( (string) ( $action['url'] ?? '' ) );
			if ( '' === $url ) {
				return '';
			}
			$target = '_blank' === (string) ( $action['target'] ?? '' ) ? '_blank' : '_self';
			$rel = '_blank' === $target ? ' rel="noopener noreferrer"' : '';
			return '<li role="none"><a role="menuitem" class="' . esc_attr( $class )
				. '" href="' . $url . '" target="' . esc_attr( $target ) . '"' . $rel
				. $attrs . '>' . esc_html( $label ) . '</a></li>';
		}
		if ( 'submit' === $type ) {
			$form = (string) ( $action['form_id'] ?? '' );
			if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_:.-]*$/', $form ) ) {
				return '';
			}
			$name = (string) ( $action['name'] ?? '' );
			$extra = '';
			if ( '' !== $name ) {
				if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $name ) ) {
					return '';
				}
				$extra = ' name="' . esc_attr( $name ) . '" value="'
					. esc_attr( (string) ( $action['value'] ?? '' ) ) . '"';
			}
			return '<li role="none"><button role="menuitem" type="submit" form="'
				. esc_attr( $form ) . '" class="' . esc_attr( $class ) . '"' . $extra
				. $attrs . '>' . esc_html( $label ) . '</button></li>';
		}
		if ( 'button' === $type ) {
			return '<li role="none"><button role="menuitem" type="button" class="'
				. esc_attr( $class ) . '"' . $attrs . '>'
				. esc_html( $label ) . '</button></li>';
		}
		return '';
	}

	private static function safe_attrs( mixed $values ): string {
		if ( ! is_array( $values ) ) {
			return '';
		}
		$out = '';
		foreach ( $values as $name => $value ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^(?:data|aria)-[a-z0-9_-]+$/', $name ) || ! is_scalar( $value ) ) {
				continue;
			}
			$out .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	private function __construct() {}
}
