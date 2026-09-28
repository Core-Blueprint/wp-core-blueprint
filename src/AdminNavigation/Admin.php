<?php
declare(strict_types=1);
/**
 * Preferences management boundary for Admin Navigation Governance.
 *
 * The editor manages only canonical presentation policy. WordPress remains the
 * runtime menu/Toolbar registry and authorization source of truth.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\AdminNavigation;

use CB\Core\Ajax\Request;
use CB\Core\Permissions\CapabilityCatalog;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const FORM_ACTION = 'cb_core_admin_navigation_save';
	public const NONCE_ACTION = 'cb_core_admin_navigation_preferences';
	public const NONCE_NAME = '_cb_admin_navigation_nonce';
	public const PICKER_NONCE_ACTION = 'cb_core_admin_navigation_picker';
	public const ROLE_SEARCH_ACTION = 'cb_core_admin_navigation_search_roles';
	public const CAPABILITY_SEARCH_ACTION = 'cb_core_admin_navigation_search_capabilities';

	public static function boot(): void {
		add_action( 'admin_post_' . self::FORM_ACTION, [ self::class, 'handle_save' ] );
		add_action( 'wp_ajax_' . self::ROLE_SEARCH_ACTION, [ self::class, 'ajax_search_roles' ] );
		add_action( 'wp_ajax_' . self::CAPABILITY_SEARCH_ACTION, [ self::class, 'ajax_search_capabilities' ] );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/** @return array<string,mixed> */
	public static function editor_state(): array {
		$policy = Policy::get();
		$menu_ids = self::editor_order( Discovery::menu_identities(), $policy['menu']['order'] );
		$current_menu = array_fill_keys( Discovery::current_menu_identities(), true );
		$current_toolbar = array_fill_keys( Discovery::current_toolbar_identities(), true );
		$menu_hidden = self::rules_by_id( $policy['menu']['hidden'] );
		$toolbar_hidden = self::rules_by_id( $policy['toolbar']['hidden'] );
		$toolbar_renamed = self::rules_by_id( $policy['toolbar']['renamed'] );

		$menu = [];
		foreach ( $menu_ids as $id ) {
			$menu[] = [
				'id'      => $id,
				'label'   => Discovery::menu_label( $id ),
				'present' => isset( $current_menu[ $id ] ),
				'hidden'  => $menu_hidden[ $id ] ?? null,
			];
		}

		$toolbar = [];
		foreach ( Discovery::toolbar_identities() as $id ) {
			$toolbar[] = [
				'id'      => $id,
				'label'   => Discovery::toolbar_label( $id ),
				'present' => isset( $current_toolbar[ $id ] ),
				'hidden'  => $toolbar_hidden[ $id ] ?? null,
				'renamed' => $toolbar_renamed[ $id ] ?? null,
			];
		}

		return [
			'policy'  => $policy,
			'menu'    => $menu,
			'toolbar' => $toolbar,
		];
	}

	/** Canonical save entry used by the admin-post handler and integration tests. */
	public static function save_editor_payload( array $payload, string $actor = 'preferences' ): bool {
		return Policy::replace( $payload, $actor );
	}

	/** Canonical reset entry used by the admin-post handler and integration tests. */
	public static function reset_editor_policy( string $actor = 'preferences' ): bool {
		return Policy::reset( $actor );
	}

	public static function handle_save(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Admin Navigation.', 'core-blueprint' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$redirect = admin_url( 'admin.php?page=core-blueprint-preferences&tab=admin-navigation' );
		$is_reset = isset( $_POST['cb_admin_navigation_reset'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['cb_admin_navigation_reset'] ) );

		if ( $is_reset ) {
			$ok = self::reset_editor_policy();
			wp_safe_redirect( add_query_arg( 'admin_navigation_notice', $ok ? 'reset' : 'invalid', $redirect ) );
			exit;
		}

		$raw = isset( $_POST['cb_admin_navigation_payload'] )
			? wp_unslash( $_POST['cb_admin_navigation_payload'] )
			: ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strict JSON policy normalization below.

		try {
			$decoded = is_string( $raw ) ? json_decode( $raw, true, 64, JSON_THROW_ON_ERROR ) : null;
			if ( ! is_array( $decoded ) ) {
				throw new \InvalidArgumentException( 'Invalid Admin Navigation payload.' );
			}
			$ok = self::save_editor_payload( $decoded );
		} catch ( \Throwable ) {
			$ok = false;
		}

		wp_safe_redirect( add_query_arg( 'admin_navigation_notice', $ok ? 'saved' : 'invalid', $redirect ) );
		exit;
	}

	/** @param list<string> $roles @return list<array{id:string,label:string,meta:string}> */
	public static function role_picker_items( array $roles ): array {
		$registry = (array) wp_roles()->roles;
		$items = [];
		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || '' === $role ) {
				continue;
			}
			$details = isset( $registry[ $role ] ) && is_array( $registry[ $role ] ) ? $registry[ $role ] : [];
			$name = isset( $details['name'] ) && is_string( $details['name'] ) ? translate_user_role( $details['name'] ) : $role;
			$items[] = [
				'id'    => $role,
				'label' => '' !== $name ? $name : $role,
				'meta'  => $name !== $role ? $role : '',
			];
		}
		return $items;
	}

	/** @param list<string> $capabilities @return list<array{id:string,label:string,meta:string}> */
	public static function capability_picker_items( array $capabilities ): array {
		$catalog = CapabilityCatalog::all();
		$items = [];
		foreach ( $capabilities as $capability ) {
			if ( ! is_string( $capability ) || '' === $capability ) {
				continue;
			}
			$entry = isset( $catalog[ $capability ] ) && is_array( $catalog[ $capability ] ) ? $catalog[ $capability ] : [];
			$label = isset( $entry['label'] ) && is_string( $entry['label'] ) && '' !== $entry['label'] ? $entry['label'] : $capability;
			$items[] = [
				'id'    => $capability,
				'label' => $label,
				'meta'  => $label !== $capability ? $capability : '',
			];
		}
		return $items;
	}

	/** @return list<array{id:string,label:string,meta:string}> */
	public static function search_roles( string $search ): array {
		$search = trim( $search );
		if ( strlen( $search ) < 2 ) {
			return [];
		}

		$items = [];
		foreach ( (array) wp_roles()->roles as $slug => $details ) {
			if ( ! is_string( $slug ) || ! is_array( $details ) ) {
				continue;
			}
			$name = isset( $details['name'] ) && is_string( $details['name'] ) ? translate_user_role( $details['name'] ) : $slug;
			if ( false === mb_stripos( $slug . ' ' . $name, $search ) ) {
				continue;
			}
			$items[] = [
				'id'    => $slug,
				'label' => '' !== $name ? $name : $slug,
				'meta'  => $name !== $slug ? $slug : '',
			];
		}

		usort( $items, static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['label'], (string) $b['label'] ) );
		return array_slice( $items, 0, 50 );
	}

	/** @return list<array{id:string,label:string,meta:string}> */
	public static function search_capabilities( string $search ): array {
		$search = trim( $search );
		if ( strlen( $search ) < 2 ) {
			return [];
		}

		$items = [];
		foreach ( CapabilityCatalog::all() as $capability => $entry ) {
			if ( ! is_string( $capability ) || ! is_array( $entry ) ) {
				continue;
			}
			$label = isset( $entry['label'] ) && is_string( $entry['label'] ) ? $entry['label'] : $capability;
			$group = isset( $entry['group'] ) && is_string( $entry['group'] ) ? $entry['group'] : '';
			$source = isset( $entry['source'] ) && is_string( $entry['source'] ) ? $entry['source'] : '';
			if ( false === mb_stripos( $capability . ' ' . $label . ' ' . $group . ' ' . $source, $search ) ) {
				continue;
			}
			$items[] = [
				'id'    => $capability,
				'label' => '' !== $label ? $label : $capability,
				'meta'  => $label !== $capability ? $capability : '',
			];
			if ( count( $items ) >= 50 ) {
				break;
			}
		}
		return $items;
	}

	public static function ajax_search_roles(): void {
		Request::nonce( self::PICKER_NONCE_ACTION, '_ajax_nonce' );
		Request::cap( 'manage_options' );
		wp_send_json_success( [ 'items' => self::search_roles( Request::text( 'search' ) ) ] );
	}

	public static function ajax_search_capabilities(): void {
		Request::nonce( self::PICKER_NONCE_ACTION, '_ajax_nonce' );
		Request::cap( 'manage_options' );
		wp_send_json_success( [ 'items' => self::search_capabilities( Request::text( 'search' ) ) ] );
	}

	/** @param list<string> $catalog @param list<string> $preferred @return list<string> */
	private static function editor_order( array $catalog, array $preferred ): array {
		$known = array_fill_keys( $catalog, true );
		$result = [];
		$seen = [];
		foreach ( $preferred as $id ) {
			if ( isset( $known[ $id ] ) && ! isset( $seen[ $id ] ) ) {
				$result[] = $id;
				$seen[ $id ] = true;
			}
		}
		foreach ( $catalog as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$result[] = $id;
				$seen[ $id ] = true;
			}
		}
		return $result;
	}

	/** @return array<string,array<string,mixed>> */
	private static function rules_by_id( array $rules ): array {
		$map = [];
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && isset( $rule['id'] ) && is_string( $rule['id'] ) ) {
				$map[ $rule['id'] ] = $rule;
			}
		}
		return $map;
	}

	private function __construct() {}
}
