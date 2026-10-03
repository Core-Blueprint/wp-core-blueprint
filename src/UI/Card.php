<?php
declare(strict_types=1);
/**
 * Card - CB UI primitive for static content cards.
 *
 * Slot-component: callers fill body/header/footer with already-built
 * HTML strings or partials. This pattern is intentional - cards hold
 * arbitrary mixed content (paragraphs, tables, headings, lists) and a
 * fully-typed API would either be too restrictive or too verbose.
 * Helpers are responsible for the frame, slots, and BEM contract.
 *
 * Cards are different from Tiles: tiles are nav targets with
 * hover-affordance, status communication, or compact metrics. Cards
 * are static content containers that group related information.
 *
 *   echo Card::render( [
 *       'icon'   => 'media-document',
 *       'title'  => __( 'Recent reports', 'core-blueprint' ),
 *       'body'   => $reports_table_html,
 *       'footer' => $delete_all_button_html,
 *   ] );
 *
 * Variants:
 *
 *   default     padding-md frame (default)
 *   spacious    padding-lg frame - for full-page cards (about,
 *               full-width content blocks)
 *
 * Empty-state convenience: when 'body' is empty and 'empty' is given,
 * the body slot renders a centered empty-state instead.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Card {

	public const VARIANT_DEFAULT  = 'default';
	public const VARIANT_SPACIOUS = 'spacious';

	/**
	 * Render a card to inline HTML.
	 *
	 * Recognised args:
	 *   variant   : 'default' | 'spacious'
	 *   icon      : dashicon name (without the dashicons- prefix), shown
	 *               in the header next to the title
	 *   title     : header title text (caller-localised)
	 *   body      : HTML string for the body slot. NOT escaped - caller
	 *               is responsible for escaping content inside
	 *   body_flush: bool - drop body padding (use when body holds a table
	 *               that should align to the card frame)
	 *   footer    : HTML string for the footer-actions slot, or
	 *               structured array of [ 'url', 'label', 'class' ] action
	 *               links. NOT escaped when string; escaped per-action when
	 *               array
	 *   footer_stripe : HTML string for a typographic footer stripe (used
	 *                   for in-card link rows like the About page footer)
	 *   empty     : array with 'title' and 'description' shown when body
	 *               is empty (renders centered empty-state)
	 *   class     : extra class string appended to the wrapper
	 */
	public static function render( array $args ): string {
		$variant       = (string) ( $args['variant']     ?? self::VARIANT_DEFAULT );
		$icon          = (string) ( $args['icon']        ?? '' );
		$title         = (string) ( $args['title']       ?? '' );
		$body          = (string) ( $args['body']        ?? '' );
		$body_flush    = (bool)   ( $args['body_flush']  ?? false );
		$footer        = $args['footer']                 ?? '';
		$footer_stripe = (string) ( $args['footer_stripe'] ?? '' );
		$empty         = is_array( $args['empty'] ?? null ) ? $args['empty'] : null;
		$extra         = (string) ( $args['class']       ?? '' );

		$classes = [ 'cb-core-card' ];
		if ( self::VARIANT_SPACIOUS === $variant ) {
			$classes[] = 'cb-core-card--spacious';
		}
		if ( '' !== $extra ) {
			$classes[] = $extra;
		}

		$out = '<section class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		// Header slot.
		if ( '' !== $title || '' !== $icon ) {
			$out .= '<header class="cb-core-card__header">';
			if ( '' !== $icon ) {
				$out .= '<span class="cb-core-card__icon dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			}
			if ( '' !== $title ) {
				$out .= '<h2 class="cb-core-card__title">' . esc_html( $title ) . '</h2>';
			}
			$out .= '</header>';
		}

		// Body slot.
		if ( '' === $body && null !== $empty ) {
			$empty_title = (string) ( $empty['title'] ?? '' );
			$empty_desc  = (string) ( $empty['description'] ?? '' );
			$out .= '<div class="cb-core-card__empty">';
			if ( '' !== $empty_title ) {
				$out .= '<strong>' . esc_html( $empty_title ) . '</strong>';
			}
			if ( '' !== $empty_desc ) {
				$out .= esc_html( $empty_desc );
			}
			$out .= '</div>';
		} else {
			$body_classes = [ 'cb-core-card__body' ];
			if ( $body_flush ) {
				$body_classes[] = 'cb-core-card__body--flush';
			}
			$out .= '<div class="' . esc_attr( implode( ' ', $body_classes ) ) . '">' . $body . '</div>';
		}

		// Footer-stripe slot (typographic, in-card link row).
		if ( '' !== $footer_stripe ) {
			$out .= '<div class="cb-core-card__footer-stripe">' . $footer_stripe . '</div>';
		}

		// Footer-actions slot.
		if ( ! empty( $footer ) ) {
			$out .= '<footer class="cb-core-card__footer">';
			if ( is_array( $footer ) ) {
				foreach ( $footer as $action ) {
					if ( ! is_array( $action ) || empty( $action['url'] ) || empty( $action['label'] ) ) {
						continue;
					}
					$cls = 'cb-core-card__action';
					if ( ! empty( $action['class'] ) ) {
						$cls .= ' ' . (string) $action['class'];
					}
					$out .= sprintf(
						'<a class="%s" href="%s">%s</a>',
						esc_attr( $cls ),
						esc_url( $action['url'] ),
						esc_html( $action['label'] )
					);
				}
			} else {
				$out .= (string) $footer;
			}
			$out .= '</footer>';
		}

		$out .= '</section>';
		return $out;
	}
}
