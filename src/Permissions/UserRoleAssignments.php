<?php
declare(strict_types=1);
/**
 * UserRoleAssignments
 *
 * Domain service for Core Blueprint's additive user-role model:
 * one base role plus zero or more additional roles. WordPress already stores
 * multiple roles natively on WP_User; this service adds an explicit base-role
 * designation and safe helpers for managing the additive assignments.
 *
 * Role definitions remain owned by WordPress. Only the base-role designation
 * is stored as Core Blueprint user meta so the primary role remains stable
 * when additional roles are added by Core Blueprint or third-party code.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

defined( 'ABSPATH' ) || exit;

final class UserRoleAssignments {

	/** User-meta key that identifies the primary/base role. */
	public const BASE_ROLE_META = 'cb_core_base_role';

	/**
	 * Resolve the user's normal WordPress base role.
	 *
	 * CB Operator is an additive governance role and is never a valid base-role
	 * candidate. Existing sites may not have Core Blueprint base-role metadata;
	 * in that case choose the first assigned non-operator WordPress role. This
	 * also safely recovers users whose capability map was historically ordered
	 * with cb_operator before their Administrator role.
	 */
	public static function base_role( \WP_User $user ): string {
		$roles = array_values( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) );
		if ( empty( $roles ) ) {
			return '';
		}

		$stored = sanitize_key( (string) get_user_meta( (int) $user->ID, self::BASE_ROLE_META, true ) );
		if (
			'' !== $stored
			&& Roles::OPERATOR_ROLE !== $stored
			&& in_array( $stored, $roles, true )
			&& null !== get_role( $stored )
		) {
			return $stored;
		}

		foreach ( $roles as $role ) {
			if ( Roles::OPERATOR_ROLE !== $role && null !== get_role( $role ) ) {
				return $role;
			}
		}

		return '';
	}

	/**
	 * Return every assigned role except the base role.
	 *
	 * @return string[]
	 */
	public static function additional_roles( \WP_User $user ): array {
		$base  = self::base_role( $user );
		$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) ) );

		return array_values( array_filter(
			$roles,
			static fn( string $role ): bool => '' !== $role && $role !== $base
		) );
	}

	/**
	 * Persist the base-role designation after a successful profile update.
	 */
	public static function set_base_role( int $user_id, string $role ): void {
		$role = sanitize_key( $role );
		if ( '' === $role || Roles::OPERATOR_ROLE === $role ) {
			delete_user_meta( $user_id, self::BASE_ROLE_META );
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) || ! in_array( $role, (array) $user->roles, true ) ) {
			return;
		}

		update_user_meta( $user_id, self::BASE_ROLE_META, $role );
	}

	/**
	 * Whether the current actor may manage additive roles for this user.
	 *
	 * Editing another user's role follows WordPress' promote_user boundary.
	 * Self-assignment is stricter: the actor must be an Administrator as well
	 * as a Core Blueprint role manager, preventing operator-only accounts from
	 * elevating themselves through the profile screen.
	 */
	public static function can_manage_user( int $user_id ): bool {
		if ( ! current_user_can( 'cb_manage_roles' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		if ( get_current_user_id() === $user_id ) {
			return current_user_can( 'manage_options' );
		}

		return current_user_can( 'promote_user', $user_id );
	}

	/**
	 * Whether a role may be newly assigned by the current actor.
	 *
	 * Normal roles must be part of WordPress' editable-role set and the actor
	 * must hold every primitive capability granted by them. CB Operator is
	 * deliberately hidden from WordPress' base-role selector, so its additive
	 * assignment bypasses that presentation check and is instead governed by
	 * the dedicated cb_manage_permissions + Administrator-target boundary below.
	 */
	public static function can_assign_role( string $role_slug, int $user_id ): bool {
		$role_slug = sanitize_key( $role_slug );
		if ( '' === $role_slug || ! self::can_manage_user( $user_id ) ) {
			return false;
		}

		$role = get_role( $role_slug );
		if ( null === $role ) {
			return false;
		}

		if ( Roles::OPERATOR_ROLE !== $role_slug ) {
			$editable = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
			if ( ! isset( $editable[ $role_slug ] ) ) {
				return false;
			}
		}

		// CB Operator assignment is meta-governance and remains behind the
		// existing cb_manage_permissions boundary, not cb_manage_roles alone.
		// Match the existing Permissions screen: new operator assignments are
		// only offered to Administrator-base accounts. Existing operator-only
		// legacy users remain observable but must gain a normal base role before
		// any new trusted assignment or recovery transaction can be completed.
		if ( Roles::OPERATOR_ROLE === $role_slug ) {
			if ( ! current_user_can( 'cb_manage_permissions' ) ) {
				return false;
			}

			$target = get_userdata( $user_id );
			if ( ! ( $target instanceof \WP_User ) || 'administrator' !== self::base_role( $target ) ) {
				return false;
			}
		}

		// Administrators are trusted role managers and may assign custom
		// application roles even when those roles carry domain-specific caps
		// (for example academy_access) that Administrator itself does not use.
		// Delegated non-admin role managers remain bounded by their own effective
		// capabilities so cb_manage_roles + promote_users cannot become a generic
		// privilege-escalation path.
		if ( ! current_user_can( 'manage_options' ) ) {
			foreach ( (array) $role->capabilities as $cap => $granted ) {
				if ( $granted && ! current_user_can( (string) $cap ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether an existing additional role may be removed by the current actor.
	 */
	public static function can_remove_role( string $role_slug, int $user_id ): bool {
		$role_slug = sanitize_key( $role_slug );
		if ( '' === $role_slug || ! self::can_manage_user( $user_id ) ) {
			return false;
		}

		if ( Roles::OPERATOR_ROLE === $role_slug && ! current_user_can( 'cb_manage_permissions' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Assert that a newly requested assignment is safe.
	 *
	 * Existing assignments are allowed to persist even when the actor could
	 * not grant them from scratch; preserving access is different from
	 * escalating access. The Admin integration uses this method only for roles
	 * that were not already assigned before the save.
	 */
	public static function assert_can_assign_role( string $role_slug, int $user_id ): void {
		if ( self::can_assign_role( $role_slug, $user_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
		throw new \RuntimeException(
			sprintf(
				/* translators: %s: role slug */
				__( 'You are not allowed to assign the %s role to this user.', 'core-blueprint' ),
				$role_slug
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Change the base role without invoking WP_User::set_role().
	 *
	 * WordPress' set_role() removes every role before adding the replacement.
	 * For a multi-role user that creates a transient permission drop (including
	 * a false zero-operator state when cb_operator is additional). We instead
	 * add the new base, remove the old base, synchronize additional roles, then
	 * reorder the public WP_User capability map so WordPress continues to see
	 * the designated base role first in its native Role dropdown.
	 *
	 * @param string[] $desired_additional Final desired additional roles.
	 */
	public static function change_base_role( int $user_id, string $old_base, string $new_base, array $desired_additional ): void {
		$old_base = sanitize_key( $old_base );
		$new_base = sanitize_key( $new_base );
		if ( '' === $new_base || Roles::OPERATOR_ROLE === $new_base || null === get_role( $new_base ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}

		if ( ! in_array( $new_base, (array) $user->roles, true ) ) {
			$user->add_role( $new_base );
		}

		if ( '' !== $old_base && $old_base !== $new_base && in_array( $old_base, (array) $user->roles, true ) ) {
			$user->remove_role( $old_base );
		}

		self::sync_additional_roles( $user_id, $new_base, $desired_additional );

		// WP_User::get_role_caps() derives role order from the role keys inside
		// $user->caps. Put the designated base role first without touching
		// direct per-user capabilities or emitting fake role-change actions.
		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) || ! isset( $user->caps[ $new_base ] ) ) {
			return;
		}

		$ordered = [ $new_base => $user->caps[ $new_base ] ];
		foreach ( (array) $user->caps as $key => $value ) {
			if ( (string) $key === $new_base ) {
				continue;
			}
			$ordered[ (string) $key ] = $value;
		}

		$user->caps = $ordered;
		update_user_meta( $user_id, (string) $user->cap_key, $ordered );
		$user->get_role_caps();
		$user->update_user_level_from_caps();
		self::set_base_role( $user_id, $new_base );
	}

	/**
	 * Synchronize the user's additional-role set after WordPress has saved the
	 * base role and normal profile fields.
	 *
	 * @param string[] $desired_additional Final desired additional roles.
	 */
	public static function sync_additional_roles( int $user_id, string $base_role, array $desired_additional ): void {
		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}

		$base_role = sanitize_key( $base_role );
		if ( Roles::OPERATOR_ROLE === $base_role ) {
			return;
		}
		$desired   = array_values( array_unique( array_filter( array_map( 'sanitize_key', $desired_additional ) ) ) );
		$desired   = array_values( array_filter(
			$desired,
			static fn( string $role ): bool => '' !== $role && $role !== $base_role && null !== get_role( $role )
		) );

		$current = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) ) );
		$current_additional = array_values( array_filter(
			$current,
			static fn( string $role ): bool => $role !== $base_role
		) );

		foreach ( array_diff( $current_additional, $desired ) as $role_slug ) {
			if ( self::can_remove_role( (string) $role_slug, $user_id ) ) {
				$user->remove_role( (string) $role_slug );
			}
		}

		foreach ( array_diff( $desired, $current_additional ) as $role_slug ) {
			$role_slug = (string) $role_slug;
			if ( null !== get_role( $role_slug ) ) {
				$user->add_role( $role_slug );
			}
		}

		self::set_base_role( $user_id, $base_role );
	}
}
