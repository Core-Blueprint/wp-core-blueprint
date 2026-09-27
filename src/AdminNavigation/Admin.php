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

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const FORM_ACTION = 'cb_core_admin_navigation_save';
	public const NONCE_ACTION = 'cb_core_admin_navigation_preferences';
	public const NONCE_NAME = '_cb_admin_navigation_nonce';

	public static function boot(): void {
		add_action( 'admin_post_' . self::FORM_ACTION, [ self::class, 'handle_save' ] );
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
