<?php
declare(strict_types=1);
/**
 * PrivilegedAccessPolicy
 *
 * Classifies WordPress users by their stored role/capability state without
 * calling current_user_can() or WP_User::has_cap(). That separation matters:
 * runtime enforcement itself is implemented through user_has_cap, so the
 * classifier must never recurse through the filter it feeds.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class PrivilegedAccessPolicy {

	/**
	 * Primitive capabilities that make a custom user/role administrator-like.
	 * Keep this list focused on site control, code/plugin/theme management,
	 * user privilege management, and network administration. Application-level
	 * capabilities such as manage_woocommerce intentionally do not qualify.
	 *
	 * @var string[]
	 */
	// SECURITY INVARIANT: adding a Core Blueprint-owned capability here that is
	// granted by cb_operator changes signed trust state. Advance the public trust
	// schema and implement an explicit reviewed migration before role policy is
	// reconciled; never infer approval from a release-version change.
	private const PRIVILEGE_TRIGGER_CAPS = [
		'manage_options',
		'update_core',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'create_users',
		'edit_users',
		'delete_users',
		'promote_users',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		// Core Blueprint trust-authority capabilities. Directly injecting either
		// one must never turn an already-approved admin into an operator-equivalent
		// identity without a fresh approval.
		'cb_manage_permissions',
		'cb_manage_roles',
		// Core Scanner trust authority can approve a changed filesystem as the
		// expected state, disable checks, or clear integrity evidence. Directly
		// injecting it is therefore a trust escalation, not merely scan access.
		'cb_manage_integrity_policy',
		// Managed PHP snippets are arbitrary site-level code execution. Direct
		// injection is therefore an operator-equivalent trust escalation.
		'cb_manage_snippets',
		// Browser Console access can run state-changing commands, including
		// operator assignment. Injecting cb_use_cli is therefore itself a trust
		// escalation and belongs behind the same approval boundary.
		'cb_use_cli',
	];

	/**
	 * Security-sensitive primitives that do not by themselves make an identity
	 * administrator-like, but remain part of the signed privilege fingerprint
	 * once an identity is already behind the approval boundary.
	 *
	 * @var string[]
	 */
	private const FINGERPRINT_SENSITIVE_CAPS = [
		'switch_themes',
		'edit_theme_options',
		'unfiltered_html',
	];

	/** Recommended/default: unapproved privileged identities are restricted. */
	public const MODE_ENFORCE = 'enforce';

	/** Detection/review remain active while native WordPress permissions continue working. */
	public const MODE_MONITOR = 'monitor';

	public static function privileged_capabilities(): array {
		return self::PRIVILEGE_TRIGGER_CAPS;
	}

	/** Current Privileged Access Protection policy. */
	public static function enforcement_mode(): string {
		$settings = Settings::get();
		$mode     = sanitize_key( (string) ( $settings['permissions']['privileged_access_mode'] ?? self::MODE_ENFORCE ) );
		return self::is_valid_mode( $mode ) ? $mode : self::MODE_ENFORCE;
	}

	public static function is_valid_mode( string $mode ): bool {
		return in_array( $mode, [ self::MODE_ENFORCE, self::MODE_MONITOR ], true );
	}

	public static function enforces_approval(): bool {
		return self::MODE_ENFORCE === self::enforcement_mode();
	}

	/** @return string[] */
	private static function fingerprint_capabilities(): array {
		return array_values( array_unique( array_merge( self::PRIVILEGE_TRIGGER_CAPS, self::FINGERPRINT_SENSITIVE_CAPS ) ) );
	}

	/**
	 * Build the effective *stored* primitive capability map for a user.
	 *
	 * Role capabilities are merged in role order, then user-level capability
	 * overrides are applied, mirroring WP_User::get_role_caps() closely enough
	 * for the privilege boundary while avoiding user_has_cap recursion.
	 *
	 * @return array<string,bool>
	 */
	public static function stored_capabilities( \WP_User $user ): array {
		$allcaps = [];

		foreach ( (array) $user->roles as $role_slug ) {
			$role = get_role( (string) $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( (array) $role->capabilities as $cap => $granted ) {
				$allcaps[ (string) $cap ] = (bool) $granted;
			}
		}

		foreach ( (array) $user->caps as $cap => $granted ) {
			$allcaps[ (string) $cap ] = (bool) $granted;
		}

		return $allcaps;
	}

	/**
	 * Whether this identity belongs behind the CB Operator approval boundary.
	 */
	public static function is_privileged( \WP_User $user ): bool {
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}

		if ( is_multisite() && is_super_admin( (int) $user->ID ) ) {
			return true;
		}

		return ! empty( self::privilege_trigger_capabilities_for_user( $user ) );
	}

	/**
	 * Capabilities that independently place the identity behind the approval
	 * boundary. Sensitive-but-non-admin capabilities are intentionally excluded
	 * here to avoid flagging legitimate Editor-like roles.
	 *
	 * @return string[]
	 */
	public static function privilege_trigger_capabilities_for_user( \WP_User $user ): array {
		$stored = self::stored_capabilities( $user );
		$hits   = [];

		foreach ( self::PRIVILEGE_TRIGGER_CAPS as $cap ) {
			if ( ! empty( $stored[ $cap ] ) ) {
				$hits[] = $cap;
			}
		}

		sort( $hits, SORT_STRING );
		return $hits;
	}

	/**
	 * Privileged primitives currently granted by stored role/user data.
	 *
	 * @return string[]
	 */
	public static function critical_capabilities_for_user( \WP_User $user ): array {
		$stored = self::stored_capabilities( $user );
		$hits   = [];

		foreach ( self::fingerprint_capabilities() as $cap ) {
			if ( ! empty( $stored[ $cap ] ) ) {
				$hits[] = $cap;
			}
		}

		sort( $hits, SORT_STRING );
		return $hits;
	}

	/**
	 * Roles that materially contribute privileged capabilities. Harmless
	 * secondary roles (for example membership/content roles) are excluded so
	 * adding them to an approved administrator does not create a false
	 * quarantine. cb_operator is always included because it is a trust role.
	 *
	 * @return string[]
	 */
	public static function privileged_roles_for_user( \WP_User $user ): array {
		$roles = [];
		foreach ( (array) $user->roles as $role_slug ) {
			$role_slug = (string) $role_slug;
			if ( Roles::OPERATOR_ROLE === $role_slug || 'administrator' === $role_slug ) {
				$roles[] = $role_slug;
				continue;
			}

			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::PRIVILEGE_TRIGGER_CAPS as $cap ) {
				if ( ! empty( $role->capabilities[ $cap ] ) ) {
					$roles[] = $role_slug;
					break;
				}
			}
		}

		$roles = array_values( array_unique( $roles ) );
		sort( $roles, SORT_STRING );
		return $roles;
	}

	/**
	 * Stable fingerprint of the privilege-bearing identity state.
	 *
	 * Any role change, direct user-cap change, privileged-cap change, or
	 * cb_operator assignment changes the fingerprint and therefore invalidates
	 * a previous approval until a trusted operator approves the new state.
	 */
	/**
	 * Canonical privilege state used by both the signed fingerprint and trusted
	 * privilege-schema migrations. Keeping the structured state available lets
	 * migrations prove that only an explicitly registered Core-managed delta
	 * occurred before rotating an existing approval.
	 *
	 * @return array{user_id:int,blog_id:int,roles:string[],direct_caps:array<string,bool>,critical_caps:string[],super_admin:bool}
	 */
	public static function fingerprint_state( \WP_User $user ): array {
		$roles = self::privileged_roles_for_user( $user );

		$direct_caps = [];
		foreach ( self::fingerprint_capabilities() as $cap ) {
			if ( array_key_exists( $cap, (array) $user->caps ) ) {
				$direct_caps[ $cap ] = (bool) $user->caps[ $cap ];
			}
		}
		ksort( $direct_caps, SORT_STRING );

		return [
			'user_id'       => (int) $user->ID,
			'blog_id'       => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'roles'         => $roles,
			'direct_caps'   => $direct_caps,
			'critical_caps' => self::critical_capabilities_for_user( $user ),
			'super_admin'   => is_multisite() && is_super_admin( (int) $user->ID ),
		];
	}

	public static function fingerprint( \WP_User $user ): string {
		return hash( 'sha256', (string) wp_json_encode( self::fingerprint_state( $user ) ) );
	}
}
