<?php
declare(strict_types=1);
/**
 * ObjectPicker - shared async object selection Foundation.
 *
 * The component owns progressive enhancement, selected-item presentation and
 * the transport contract for async search. Consumers own the search action,
 * authorization, result semantics and persistence.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class ObjectPicker {
	private const IDENTIFIER_MAX_BYTES = 191;

	/**
	 * Render an async searchable object picker over one real text input.
	 *
	 * @param array<string,mixed> $args Component arguments.
	 */
	public static function render( array $args ): string {
		$name          = (string) ( $args['name'] ?? '' );
		$id            = sanitize_html_class( (string) ( $args['id'] ?? '' ) );
		$multiple      = ! empty( $args['multiple'] );
		$action        = sanitize_key( (string) ( $args['action'] ?? '' ) );
		$nonce         = (string) ( $args['nonce'] ?? '' );
		$context       = is_array( $args['context'] ?? null ) ? $args['context'] : [];
		$selected      = is_array( $args['selected'] ?? null ) ? $args['selected'] : [];
		$placeholder   = (string) ( $args['placeholder'] ?? __( 'Search…', 'core-blueprint' ) );
		$empty_message = (string) ( $args['empty_message'] ?? __( 'No matching items found.', 'core-blueprint' ) );
		$show_hint     = ! array_key_exists( 'show_hint', $args ) || (bool) $args['show_hint'];
		$extra         = trim( (string) ( $args['class'] ?? '' ) );

		if ( '' === $name || '' === $action || '' === $nonce ) {
			return '';
		}

		$normalized_selected = [];
		$ids                 = [];
		foreach ( $selected as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id = self::normalize_identifier( $item['id'] ?? null );
			if ( null === $item_id || in_array( $item_id, $ids, true ) ) {
				continue;
			}

			$label = is_scalar( $item['label'] ?? null ) ? (string) $item['label'] : '';
			if ( '' === trim( $label ) ) {
				$label = '#' . $item_id;
			}
			$meta = is_scalar( $item['meta'] ?? null ) ? (string) $item['meta'] : '';

			$ids[] = $item_id;
			$normalized_selected[] = [
				'id'    => $item_id,
				'label' => $label,
				'meta'  => $meta,
			];

			if ( ! $multiple ) {
				break;
			}
		}

		$value   = implode( ',', $ids );
		$classes = [ 'cb-core-object-picker' ];
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$context_json  = wp_json_encode( $context );
		$selected_json = wp_json_encode( $normalized_selected );
		if ( ! is_string( $context_json ) || ! is_string( $selected_json ) ) {
			return '';
		}

		$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-cb-core-object-picker';
		$html .= ' data-search-action="' . esc_attr( $action ) . '"';
		$html .= ' data-search-nonce="' . esc_attr( $nonce ) . '"';
		$html .= ' data-search-context="' . esc_attr( $context_json ) . '"';
		$html .= ' data-selected="' . esc_attr( $selected_json ) . '"';
		$html .= ' data-multiple="' . ( $multiple ? '1' : '0' ) . '"';
		$html .= ' data-empty-message="' . esc_attr( $empty_message ) . '">';
		$html .= '<input' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' class="regular-text code cb-core-object-picker__input" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-cb-core-object-picker-input />';
		$html .= '<div class="cb-core-object-picker__enhanced" data-cb-core-object-picker-enhanced hidden>';
		$html .= '<div class="cb-core-object-picker__selected" data-cb-core-object-picker-selected></div>';
		$html .= '<label class="screen-reader-text"' . ( '' !== $id ? ' for="' . esc_attr( $id . '-search' ) . '"' : '' ) . '>' . esc_html__( 'Search items', 'core-blueprint' ) . '</label>';
		$html .= '<input' . ( '' !== $id ? ' id="' . esc_attr( $id . '-search' ) . '"' : '' ) . ' type="search" class="regular-text cb-core-object-picker__search" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" data-cb-core-object-picker-search />';
		$html .= '<div class="cb-core-object-picker__results" data-cb-core-object-picker-results hidden></div>';
		if ( $show_hint ) {
			$html .= '<p class="description cb-core-object-picker__hint">' . esc_html( $multiple ? __( 'Search and select one or more items. Selected IDs are stored in order.', 'core-blueprint' ) : __( 'Search and select one item.', 'core-blueprint' ) ) . '</p>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Normalize one transport identifier without interpreting its domain meaning.
	 *
	 * The current multiple-value transport is comma-separated, so commas are not
	 * valid inside an identifier. The byte bound keeps rendered dataset/input
	 * payloads predictable while leaving the identifier itself opaque.
	 *
	 * @param mixed $value Candidate identifier.
	 */
	private static function normalize_identifier( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$identifier = trim( (string) $value );
		if (
			'' === $identifier
			|| strlen( $identifier ) > self::IDENTIFIER_MAX_BYTES
			|| str_contains( $identifier, ',' )
		) {
			return null;
		}

		return $identifier;
	}
}
