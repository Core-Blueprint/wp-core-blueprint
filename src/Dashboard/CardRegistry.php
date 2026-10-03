<?php
declare(strict_types=1);
/**
 * Dashboard Card Registry - public shortcut contract for dashboard cards.
 *
 * Core Blueprint owns dashboard card rendering and module activation. Sibling
 * plugins may attach safe, declarative navigation shortcuts to a stable card
 * ID without injecting dashboard HTML or JavaScript.
 *
 * Base module card IDs use ActivationRegistry slugs (for example
 * `content-models` or `mail`). Extension card IDs use the detected plugin slug
 * (for example `core-blueprint-lms`).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Dashboard;

defined( 'ABSPATH' ) || exit;

final class CardRegistry {

	/** @var array<string,array<string,array<string,mixed>>> */
	private static array $shortcuts = [];

	private static bool $registration_fired = false;

	/**
	 * Register one navigation shortcut for a dashboard card.
	 *
	 * Re-registering the same shortcut ID for the same card replaces the prior
	 * declaration. This lets an extension refine its own destination later in
	 * bootstrap without producing duplicate menu items.
	 *
	 * Supported shortcut keys:
	 * - id         Stable shortcut identifier (required).
	 * - label      User-facing label (required).
	 * - url        Destination URL (required).
	 * - capability Optional capability; hidden when the user lacks it.
	 * - order      Optional integer sort order; defaults to 100.
	 * - target     Optional `_self` (default) or `_blank`.
	 *
	 * @param string $card_id  Stable dashboard card ID.
	 * @param array  $shortcut Shortcut declaration.
	 */
	public static function register_shortcut( string $card_id, array $shortcut ): bool {
		$card_id = self::normalize_card_id( $card_id );
		$entry   = self::normalize_shortcut( $shortcut, false );
		if ( '' === $card_id || null === $entry ) {
			return false;
		}

		if ( ! isset( self::$shortcuts[ $card_id ] ) ) {
			self::$shortcuts[ $card_id ] = [];
		}

		self::$shortcuts[ $card_id ][ $entry['id'] ] = $entry;
		return true;
	}

	/**
	 * Register multiple shortcuts for one card.
	 *
	 * @param string $card_id   Stable dashboard card ID.
	 * @param array  $shortcuts List of shortcut declarations.
	 */
	public static function register_shortcuts( string $card_id, array $shortcuts ): void {
		foreach ( $shortcuts as $shortcut ) {
			if ( is_array( $shortcut ) ) {
				self::register_shortcut( $card_id, $shortcut );
			}
		}
	}

	/**
	 * Resolve visible shortcuts for a card.
	 *
	 * Registration is lazy: the first read fires
	 * `core_blueprint_dashboard_register_cards`, giving sibling plugins a predictable
	 * hook regardless of plugin load order. Filters then allow request-specific
	 * additions or adjustments.
	 *
	 * @param string $card_id Stable dashboard card ID.
	 * @param array  $context Optional read-only context supplied by Base.
	 * @return array<int,array{id:string,label:string,url:string,capability:string,order:int,target:string}>
	 */
	public static function shortcuts( string $card_id, array $context = [] ): array {
		$card_id = self::normalize_card_id( $card_id );
		if ( '' === $card_id ) {
			return [];
		}

		self::fire_registration_hook();

		$shortcuts = array_values( self::$shortcuts[ $card_id ] ?? [] );

		/**
		 * Filter all shortcuts for one dashboard card.
		 *
		 * @param array  $shortcuts Shortcut declarations.
		 * @param string $card_id   Stable dashboard card ID.
		 * @param array  $context   Read-only card/runtime context.
		 */
		$shortcuts = apply_filters( 'core_blueprint_dashboard_card_shortcuts', $shortcuts, $card_id, $context );

		/**
		 * Filter shortcuts for a specific dashboard card.
		 *
		 * Example for LMS:
		 * `core_blueprint_dashboard_card_shortcuts_core-blueprint-lms`.
		 *
		 * @param array $shortcuts Shortcut declarations.
		 * @param array $context   Read-only card/runtime context.
		 */
		$shortcuts = apply_filters( "core_blueprint_dashboard_card_shortcuts_{$card_id}", $shortcuts, $context );
		if ( ! is_array( $shortcuts ) ) {
			return [];
		}

		$out = [];
		foreach ( $shortcuts as $shortcut ) {
			if ( ! is_array( $shortcut ) ) {
				continue;
			}

			$entry = self::normalize_shortcut( $shortcut, true );
			if ( null === $entry ) {
				continue;
			}

			if ( '' !== $entry['capability'] && ! current_user_can( $entry['capability'] ) ) {
				continue;
			}

			$out[ $entry['id'] ] = $entry;
		}

		$out = array_values( $out );
		usort( $out, static function ( array $a, array $b ): int {
			$order = $a['order'] <=> $b['order'];
			return 0 !== $order ? $order : strcasecmp( $a['label'], $b['label'] );
		} );

		return $out;
	}

	/** Fire the public registration hook once per request. */
	private static function fire_registration_hook(): void {
		if ( self::$registration_fired ) {
			return;
		}
		self::$registration_fired = true;

		/**
		 * Register declarative shortcuts for Core Blueprint dashboard cards.
		 *
		 * Sibling plugins should call CardRegistry::register_shortcut(s) from
		 * this hook. Do not render markup here.
		 */
		do_action( 'core_blueprint_dashboard_register_cards' );
	}

	private static function normalize_card_id( string $card_id ): string {
		return sanitize_key( $card_id );
	}

	/**
	 * @return array{id:string,label:string,url:string,capability:string,order:int,target:string}|null
	 */
	private static function normalize_shortcut( array $shortcut, bool $resolve_url ): ?array {
		$id    = isset( $shortcut['id'] ) ? sanitize_key( (string) $shortcut['id'] ) : '';
		$label = isset( $shortcut['label'] ) ? trim( wp_strip_all_tags( (string) $shortcut['label'] ) ) : '';
		$url   = isset( $shortcut['url'] ) ? (string) $shortcut['url'] : '';

		if ( $resolve_url ) {
			$url = esc_url_raw( $url );
		}

		if ( '' === $id || '' === $label || '' === $url ) {
			return null;
		}

		$capability = isset( $shortcut['capability'] ) ? sanitize_key( (string) $shortcut['capability'] ) : '';
		$order      = isset( $shortcut['order'] ) && is_numeric( $shortcut['order'] ) ? (int) $shortcut['order'] : 100;
		$target     = isset( $shortcut['target'] ) && '_blank' === (string) $shortcut['target'] ? '_blank' : '_self';

		return [
			'id'         => $id,
			'label'      => $label,
			'url'        => $url,
			'capability' => $capability,
			'order'      => $order,
			'target'     => $target,
		];
	}
}
