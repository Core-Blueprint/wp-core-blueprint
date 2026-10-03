<?php
declare(strict_types=1);
/**
 * StatusMenu - compact status trigger with an accessible action popover.
 *
 * The component owns presentation and menu semantics only. Callers supply the
 * current status plus declarative button/link actions; domain mutations remain
 * with the owning subsystem (for example Modules\ActivationRegistry).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class StatusMenu {

	private const STATES = [ 'active', 'warning', 'error', 'idle', 'inactive' ];

	/**
	 * Render a compact status/action menu.
	 *
	 * Args:
	 * - id      unique DOM id seed.
	 * - state   active|warning|error|idle|inactive.
	 * - label   trigger label, e.g. On / Off / Active.
	 * - detail  optional non-interactive summary shown at top of the menu.
	 * - actions list of declarative link/button entries:
	 *     type    link|button (default link)
	 *     label   visible label
	 *     url     required for link
	 *     target  _self|_blank
	 *     variant default|danger
	 *     attrs   optional data-/aria- attributes for button integrations
	 *
	 * @param array $args Rendering arguments.
	 * @return string Escape-clean HTML.
	 */
	public static function render( array $args ): string {
		$id     = sanitize_html_class( (string) ( $args['id'] ?? wp_unique_id( 'cb-core-status-menu-' ) ) );
		$state  = (string) ( $args['state'] ?? 'idle' );
		$label  = trim( (string) ( $args['label'] ?? '' ) );
		$detail = trim( (string) ( $args['detail'] ?? '' ) );
		$actions = is_array( $args['actions'] ?? null ) ? $args['actions'] : [];

		if ( ! in_array( $state, self::STATES, true ) ) {
			$state = 'idle';
		}
		if ( '' === $label ) {
			$label = __( 'Status', 'core-blueprint' );
		}

		$panel_id = $id . '-panel';
		$items    = [];
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$item = self::render_action( $action );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		$detail_html = '';
		if ( '' !== $detail ) {
			$detail_html = '<li class="cb-core-status-menu__detail" role="none">' . esc_html( $detail ) . '</li>';
		}

		return sprintf(
			'<div class="cb-core-status-menu" data-cb-core-status-menu>' .
			'<button type="button" class="cb-core-status-menu__trigger" data-cb-core-status-menu-trigger aria-haspopup="menu" aria-expanded="false" aria-controls="%1$s">' .
			'<span class="cb-core-status-menu__dot cb-core-status-menu__dot--%2$s" aria-hidden="true"></span>' .
			'<span class="cb-core-status-menu__label">%3$s</span>' .
			'%4$s' .
			'</button>' .
			'<ul id="%1$s" class="cb-core-status-menu__panel" data-cb-core-status-menu-panel role="menu" hidden>%5$s%6$s</ul>' .
			'</div>',
			esc_attr( $panel_id ),
			esc_attr( $state ),
			esc_html( $label ),
			Icon::render( 'chevron-down', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-status-menu__chevron' ] ),
			$detail_html,
			implode( '', $items )
		);
	}

	private static function render_action( array $action ): string {
		$type    = (string) ( $action['type'] ?? 'link' );
		$label   = trim( wp_strip_all_tags( (string) ( $action['label'] ?? '' ) ) );
		$variant = 'danger' === (string) ( $action['variant'] ?? '' ) ? 'danger' : 'default';
		if ( '' === $label ) {
			return '';
		}

		$class = 'cb-core-status-menu__item';
		if ( 'danger' === $variant ) {
			$class .= ' cb-core-status-menu__item--danger';
		}

		if ( 'button' === $type ) {
			$attrs = self::render_attrs( is_array( $action['attrs'] ?? null ) ? $action['attrs'] : [] );
			return '<li role="none"><button type="button" class="' . esc_attr( $class ) . '" role="menuitem"' . $attrs . '>' . esc_html( $label ) . '</button></li>';
		}

		$url = esc_url( (string) ( $action['url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}
		$target = '_blank' === (string) ( $action['target'] ?? '' ) ? '_blank' : '_self';
		$rel    = '_blank' === $target ? ' rel="noopener noreferrer"' : '';

		return '<li role="none"><a class="' . esc_attr( $class ) . '" role="menuitem" href="' . $url . '" target="' . esc_attr( $target ) . '"' . $rel . '>' . esc_html( $label ) . '</a></li>';
	}

	/** Render only safe data-/aria- attributes for integration hooks. */
	private static function render_attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( ! preg_match( '/^(?:data|aria)-[a-z0-9_-]+$/', $name ) ) {
				continue;
			}
			$out .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}
}
