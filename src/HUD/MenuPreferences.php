<?php
declare(strict_types=1);
/**
 * MenuPreferences - site-wide HUD menu presentation overrides.
 *
 * The HUD Registry remains the source of truth for available sections/items.
 * This repository persists only presentation overrides (order/visibility) and
 * administrator-defined custom links. Runtime capability/module gates remain
 * authoritative and are never weakened by these preferences.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\HUD;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class MenuPreferences {

	public const OPTION = 'cb_core_hud_menu_preferences';
	public const VERSION = 1;
	public const FORM_ACTION = 'cb_core_hud_menu_save';
	public const NONCE_ACTION = 'cb_core_hud_menu_preferences';
	public const NONCE_NAME = '_cb_hud_menu_nonce';

	/** Wire the site-wide Preferences form handler independently from HUD visibility. */
	public static function boot(): void {
		if ( RequestContext::is_admin_post() ) {
			add_action( 'admin_post_' . self::FORM_ACTION, [ self::class, 'handle_save' ] );
		}
	}

	/** Capability required to change the site-wide HUD menu. */
	public static function manage_capability(): string {
		return (string) apply_filters( 'cb_core_hud_menu_manage_capability', 'manage_options' );
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return [
			'version'         => self::VERSION,
			'section_order'   => [],
			'hidden_sections' => [],
			'item_order'      => [],
			'hidden_items'    => [],
			'custom_items'    => [],
		];
	}

	/** @return array<string, mixed> */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return self::sanitize_configuration( array_merge( self::defaults(), $stored ) );
	}

	/** Whether a registry section is editable through Preferences. */
	public static function is_manageable_section( string $section_id ): bool {
		return Registry::is_manageable_section( $section_id );
	}

	/**
	 * Apply saved section visibility/order without changing registry defaults.
	 *
	 * @param array<string, array<string, mixed>> $sections
	 * @return array<string, array<string, mixed>>
	 */
	public static function apply_sections( array $sections ): array {
		$config = self::get();
		$hidden = array_fill_keys( (array) $config['hidden_sections'], true );

		foreach ( array_keys( $sections ) as $section_id ) {
			if ( self::is_manageable_section( (string) $section_id ) && isset( $hidden[ $section_id ] ) ) {
				unset( $sections[ $section_id ] );
			}
		}

		return self::order_sections( $sections );
	}

	/**
	 * Apply only the saved section ordering. Used by the Preferences editor so
	 * hidden sections remain visible and can be re-enabled.
	 *
	 * @param array<string, array<string, mixed>> $sections
	 * @return array<string, array<string, mixed>>
	 */
	public static function order_sections( array $sections ): array {
		$order = self::position_map( (array) self::get()['section_order'] );
		uasort(
			$sections,
			static function ( array $a, array $b ) use ( $order ): int {
				$a_id = (string) ( $a['id'] ?? '' );
				$b_id = (string) ( $b['id'] ?? '' );
				$a_has = array_key_exists( $a_id, $order );
				$b_has = array_key_exists( $b_id, $order );
				if ( $a_has && $b_has ) {
					return $order[ $a_id ] <=> $order[ $b_id ];
				}
				if ( $a_has !== $b_has ) {
					return $a_has ? -1 : 1;
				}
				$by_order = (int) ( $a['order'] ?? 10 ) <=> (int) ( $b['order'] ?? 10 );
				return 0 !== $by_order ? $by_order : strcmp( $a_id, $b_id );
			}
		);
		return $sections;
	}

	/**
	 * Apply saved item visibility/order within one section.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply_items( string $section_id, array $items ): array {
		$config = self::get();
		$hidden = array_fill_keys( (array) $config['hidden_items'], true );
		$items  = array_values(
			array_filter(
				$items,
				static fn( array $item ): bool => ! isset( $hidden[ (string) ( $item['id'] ?? '' ) ] )
			)
		);

		return self::order_items( $section_id, $items );
	}

	/**
	 * Apply only saved item ordering. Used by the Preferences editor so hidden
	 * items stay in the management list and can be restored.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	public static function order_items( string $section_id, array $items ): array {
		$config        = self::get();
		$section_order = is_array( $config['item_order'][ $section_id ] ?? null )
			? $config['item_order'][ $section_id ]
			: [];
		$order = self::position_map( $section_order );

		usort(
			$items,
			static function ( array $a, array $b ) use ( $order ): int {
				$a_id = (string) ( $a['id'] ?? '' );
				$b_id = (string) ( $b['id'] ?? '' );
				$a_has = array_key_exists( $a_id, $order );
				$b_has = array_key_exists( $b_id, $order );
				if ( $a_has && $b_has ) {
					return $order[ $a_id ] <=> $order[ $b_id ];
				}
				if ( $a_has !== $b_has ) {
					return $a_has ? -1 : 1;
				}
				$by_order = (int) ( $a['order'] ?? 10 ) <=> (int) ( $b['order'] ?? 10 );
				return 0 !== $by_order ? $by_order : strcmp( $a_id, $b_id );
			}
		);
		return $items;
	}

	/** Register administrator-defined links into the normal runtime registry. */
	public static function register_custom_items(): void {
		$config   = self::get();
		$sections = Registry::catalog_sections();
		$index    = 0;

		foreach ( (array) $config['custom_items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$section = (string) ( $item['section'] ?? 'cb-core' );
			if ( ! isset( $sections[ $section ] ) || ! self::is_manageable_section( $section ) ) {
				$section = 'cb-core';
			}
			Registry::add_item( [
				'id'         => (string) ( $item['id'] ?? '' ),
				'label'      => (string) ( $item['label'] ?? '' ),
				'type'       => 'link',
				'section'    => $section,
				'url'        => (string) ( $item['url'] ?? '' ),
				'capability' => Access::capability(),
				'order'      => 1000 + $index,
				'icon'       => 'admin-links',
			] );
			++$index;
		}
	}

	/** Handle Preferences → Floating Menu save/reset. */
	public static function handle_save(): void {
		if ( ! current_user_can( self::manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the floating menu.', 'core-blueprint' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$redirect    = admin_url( 'admin.php?page=core-blueprint-preferences&tab=floating-menu' );
		$hud_enabled = isset( $_POST['cb_hud_enabled'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['cb_hud_enabled'] ) );
		$is_reset    = isset( $_POST['cb_hud_menu_reset'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['cb_hud_menu_reset'] ) );
		$actor       = wp_get_current_user()->user_login;

		if ( $is_reset ) {
			if ( ! Settings::set_site_enabled( $hud_enabled, $actor ) ) {
				wp_safe_redirect( add_query_arg( 'hud_menu_notice', 'invalid', $redirect ) );
				exit;
			}
			self::reset();
			wp_safe_redirect( add_query_arg( 'hud_menu_notice', 'reset', $redirect ) );
			exit;
		}

		$raw_payload = isset( $_POST['cb_hud_menu_payload'] ) ? wp_unslash( $_POST['cb_hud_menu_payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded + field-sanitized below.
		$decoded     = is_string( $raw_payload ) ? json_decode( $raw_payload, true ) : null;
		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( add_query_arg( 'hud_menu_notice', 'invalid', $redirect ) );
			exit;
		}

		self::ensure_registry();
		$previous_hud_enabled = Settings::site_enabled();
		if ( ! Settings::set_site_enabled( $hud_enabled, $actor ) ) {
			wp_safe_redirect( add_query_arg( 'hud_menu_notice', 'invalid', $redirect ) );
			exit;
		}

		$saved = self::save_editor_payload( $decoded );
		if ( ! $saved ) {
			Settings::set_site_enabled( $previous_hud_enabled, $actor );
		}

		wp_safe_redirect( add_query_arg( 'hud_menu_notice', $saved ? 'saved' : 'invalid', $redirect ) );
		exit;
	}

	/** Reset all site overrides. Registry defaults become effective immediately. */
	public static function reset(): void {
		$before = self::get();
		delete_option( self::OPTION );
		if ( $before !== self::defaults() && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'hud.menu.reset', 'notice', [
				'actor' => wp_get_current_user()->user_login,
			] );
		}
	}

	/**
	 * Save the editor payload while preserving overrides for registrations that
	 * are temporarily unavailable to the editing user/request.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function save_editor_payload( array $payload ): bool {
		$current          = self::get();
		$catalog_sections = Registry::catalog_sections();
		$known_sections   = [];
		$known_items      = [];
		$old_custom_ids   = [];
		foreach ( (array) $current['custom_items'] as $old_custom ) {
			if ( is_array( $old_custom ) && ! empty( $old_custom['id'] ) ) {
				$old_custom_ids[] = sanitize_key( (string) $old_custom['id'] );
			}
		}

		foreach ( $catalog_sections as $section_id => $section ) {
			unset( $section );
			if ( ! self::is_manageable_section( (string) $section_id ) ) {
				continue;
			}
			$known_sections[] = (string) $section_id;
			foreach ( Registry::catalog_items_for_section( (string) $section_id ) as $item ) {
				$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
				if ( '' !== $id ) {
					$known_items[] = $id;
				}
			}
		}

		$incoming = self::sanitize_configuration( $payload );
		$custom   = (array) $incoming['custom_items'];
		foreach ( $custom as $item ) {
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' !== $id ) {
				$known_items[] = $id;
			}
		}
		$known_items        = array_values( array_unique( $known_items ) );
		$incoming_custom_ids = array_values( array_filter( array_map( static fn( array $item ): string => sanitize_key( (string) ( $item['id'] ?? '' ) ), $custom ) ) );
		$deleted_custom_ids  = array_values( array_diff( $old_custom_ids, $incoming_custom_ids ) );

		$unknown_section_order  = array_values( array_diff( (array) $current['section_order'], $known_sections ) );
		$unknown_hidden_sections = array_values( array_diff( (array) $current['hidden_sections'], $known_sections ) );
		$unknown_hidden_items    = array_values( array_diff( (array) $current['hidden_items'], $known_items, $deleted_custom_ids ) );

		$incoming['section_order']   = self::unique_keys( array_merge( $incoming['section_order'], $unknown_section_order ) );
		$incoming['hidden_sections'] = self::unique_keys( array_merge( $incoming['hidden_sections'], $unknown_hidden_sections ) );
		$incoming['hidden_items']    = self::unique_keys( array_merge( $incoming['hidden_items'], $unknown_hidden_items ) );

		// Preserve old ordering entries for temporarily absent registry items.
		foreach ( (array) $current['item_order'] as $section_id => $old_order ) {
			$section_id = sanitize_key( (string) $section_id );
			if ( '' === $section_id || ! is_array( $old_order ) ) {
				continue;
			}
			$new_order = is_array( $incoming['item_order'][ $section_id ] ?? null ) ? $incoming['item_order'][ $section_id ] : [];
			$unknown   = array_values( array_diff( self::unique_keys( $old_order ), $known_items, $deleted_custom_ids ) );
			$incoming['item_order'][ $section_id ] = self::unique_keys( array_merge( $new_order, $unknown ) );
		}

		$incoming['version'] = self::VERSION;
		$incoming            = self::sanitize_configuration( $incoming );
		if ( $incoming === $current ) {
			return true;
		}

		$result = update_option( self::OPTION, $incoming, false );
		if ( ! $result ) {
			return false;
		}

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'hud.menu.changed', 'notice', [
				'actor'           => wp_get_current_user()->user_login,
				'hidden_sections' => count( $incoming['hidden_sections'] ),
				'hidden_items'    => count( $incoming['hidden_items'] ),
				'custom_items'    => count( $incoming['custom_items'] ),
			] );
		}
		return true;
	}

	/** Ensure the registry is available even when the HUD display kill-switch is off. */
	public static function ensure_registry(): void {
		if ( empty( Registry::catalog_sections() ) ) {
			Bootstrap::register_items();
		}
	}

	/** @param array<string, mixed> $config @return array<string, mixed> */
	private static function sanitize_configuration( array $config ): array {
		$clean = self::defaults();
		$clean['section_order']   = self::unique_keys( is_array( $config['section_order'] ?? null ) ? $config['section_order'] : [] );
		$clean['hidden_sections'] = array_values( array_filter(
			self::unique_keys( is_array( $config['hidden_sections'] ?? null ) ? $config['hidden_sections'] : [] ),
			static fn( string $id ): bool => self::is_manageable_section( $id )
		) );
		$clean['hidden_items'] = self::unique_keys( is_array( $config['hidden_items'] ?? null ) ? $config['hidden_items'] : [] );

		$item_order = is_array( $config['item_order'] ?? null ) ? $config['item_order'] : [];
		foreach ( $item_order as $section_id => $ids ) {
			$section_id = sanitize_key( (string) $section_id );
			if ( '' === $section_id || ! is_array( $ids ) ) {
				continue;
			}
			$clean['item_order'][ $section_id ] = self::unique_keys( $ids );
		}

		$custom_items = is_array( $config['custom_items'] ?? null ) ? $config['custom_items'] : [];
		foreach ( $custom_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id      = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$label   = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$url     = self::sanitize_url( (string) ( $item['url'] ?? '' ) );
			$section = sanitize_key( (string) ( $item['section'] ?? 'cb-core' ) );
			if ( '' === $id || ! str_starts_with( $id, 'cb-hud-custom-' ) || '' === $label || '' === $url ) {
				continue;
			}
			if ( ! self::is_manageable_section( $section ) ) {
				$section = 'cb-core';
			}
			$clean['custom_items'][ $id ] = [
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'section' => $section,
			];
		}
		$clean['custom_items'] = array_values( $clean['custom_items'] );
		$clean['version']      = self::VERSION;
		return $clean;
	}

	private static function sanitize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		return (string) esc_url_raw( $url, [ 'http', 'https' ] );
	}

	/** @param array<int, mixed> $values @return array<int, string> */
	private static function unique_keys( array $values ): array {
		$keys = [];
		foreach ( $values as $value ) {
			$key = sanitize_key( (string) $value );
			if ( '' !== $key ) {
				$keys[ $key ] = $key;
			}
		}
		return array_values( $keys );
	}

	/** @param array<int, mixed> $values @return array<string, int> */
	private static function position_map( array $values ): array {
		$map = [];
		foreach ( self::unique_keys( $values ) as $position => $id ) {
			$map[ $id ] = (int) $position;
		}
		return $map;
	}
}
