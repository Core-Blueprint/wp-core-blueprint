<?php
declare(strict_types=1);
/**
 * RolePolicy
 *
 * Security policy for User Roles mutations. Repository code performs the
 * WordPress writes; this class decides whether those writes are safe for the
 * current actor and whether a role/capability is protected by Core Blueprint.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

defined( 'ABSPATH' ) || exit;

final class RolePolicy {

	/** @var string[] */
	private const WORDPRESS_BUILTIN_ROLES = [
		'administrator', 'editor', 'author', 'contributor', 'subscriber',
	];

	/**
	 * Whether the current user may use the role editor at all.
	 */
	public static function can_manage_roles(): bool {
		return current_user_can( 'cb_manage_roles' );
	}

	/**
	 * Role-level metadata consumed by the UI and Repository.
	 *
	 * @return array<string,mixed>
	 */
	public static function role_state( string $slug ): array {
		$users = count_users();
		$count = isset( $users['avail_roles'][ $slug ] ) ? (int) $users['avail_roles'][ $slug ] : 0;

		$is_operator = Roles::OPERATOR_ROLE === $slug;
		$is_admin    = 'administrator' === $slug;
		$is_builtin  = in_array( $slug, self::WORDPRESS_BUILTIN_ROLES, true );
		$is_default  = (string) get_option( 'default_role', 'subscriber' ) === $slug;

		$delete_reasons = [];
		if ( $is_operator ) {
			$delete_reasons[] = __( 'Core Blueprint system role', 'core-blueprint' );
		}
		if ( $is_builtin ) {
			$delete_reasons[] = __( 'WordPress built-in role', 'core-blueprint' );
		}
		if ( $is_default ) {
			$delete_reasons[] = __( 'Default role for new users', 'core-blueprint' );
		}
		if ( $count > 0 ) {
			$delete_reasons[] = sprintf(
				/* translators: %d: user count */
				_n( '%d user is assigned to this role', '%d users are assigned to this role', $count, 'core-blueprint' ),
				$count
			);
		}

		/**
		 * Allow sibling plugins to add deletion blockers for roles they reference.
		 *
		 * Callbacks receive an initially empty list. Core Blueprint merges valid
		 * external reasons into its own protected reasons afterwards, so an
		 * integration cannot accidentally remove Core's built-in safety rules.
		 *
		 * @since   1.0.0
		 *
		 * @param string[] $external_reasons Additional reasons the role may not be deleted.
		 * @param string   $slug             Role slug being evaluated.
		 */
		$external_reasons = apply_filters( 'cb_core_role_delete_reasons', [], $slug );
		if ( is_array( $external_reasons ) ) {
			foreach ( $external_reasons as $reason ) {
				if ( ! is_scalar( $reason ) ) {
					continue;
				}
				$reason = trim( sanitize_text_field( (string) $reason ) );
				if ( '' !== $reason && ! in_array( $reason, $delete_reasons, true ) ) {
					$delete_reasons[] = $reason;
				}
			}
		}

		return [
			'user_count'          => $count,
			'is_operator'         => $is_operator,
			'is_administrator'    => $is_admin,
			'is_builtin'          => $is_builtin,
			'is_default'          => $is_default,
			'is_system'           => $is_operator || $is_admin,
			'can_delete'          => empty( $delete_reasons ),
			'delete_reasons'      => $delete_reasons,
			'can_rename_label'    => ! $is_operator && ! $is_admin,
			'requires_manage_options_to_edit' => $is_admin,
		];
	}

	/**
	 * Capabilities that Core Blueprint will restore automatically and should
	 * therefore be shown as required/read-only in the role editor.
	 *
	 * @return string[]
	 */
	public static function required_capabilities( string $slug ): array {
		if ( Roles::OPERATOR_ROLE === $slug ) {
			return array_values( array_unique( Roles::OPERATOR_CAPS ) );
		}

		if ( 'administrator' === $slug ) {
			return array_values( array_unique( array_merge(
				[ 'read', 'manage_options' ],
				Roles::ADMIN_VIEW_CAPS
			) ) );
		}

		return [];
	}

	/**
	 * Assert that the actor may modify a role definition.
	 */
	public static function assert_can_edit_role( string $slug ): void {
		if ( ! self::can_manage_roles() ) {
			throw new \RuntimeException( __( 'You do not have permission to manage user roles.', 'core-blueprint' ) );
		}

		if ( 'administrator' === $slug && ! current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( __( 'Only an administrator may modify the Administrator role.', 'core-blueprint' ) );
		}
	}

	/**
	 * Assert that a capability assignment/removal is within the actor's own
	 * authority. A role editor may not grant powers the actor does not hold.
	 * This is the privilege-escalation boundary for operator-only accounts.
	 */
	public static function assert_can_change_capability( string $slug, string $cap, bool $granting ): void {
		self::assert_can_edit_role( $slug );

		if ( ! $granting && in_array( $cap, self::required_capabilities( $slug ), true ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: capability name */
					__( '%s is required for this system role and cannot be removed.', 'core-blueprint' ),
					$cap
				)
			);
		}

		if ( ! current_user_can( $cap ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: capability name */
					__( 'You cannot change the %s capability because your account does not hold it.', 'core-blueprint' ),
					$cap
				)
			);
		}
	}

	/**
	 * Prevent the current user from removing their final path to the role
	 * editor when cb_manage_roles was manually attached to a custom role.
	 * The normal cb_operator path is already protected as a required cap.
	 *
	 * @param string[] $desired_caps
	 */
	public static function assert_no_self_lockout( string $edited_role, array $desired_caps ): void {
		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! $user->ID ) {
			return;
		}
		if ( ! in_array( $edited_role, (array) $user->roles, true ) ) {
			return;
		}
		if ( in_array( 'cb_manage_roles', $desired_caps, true ) ) {
			return;
		}

		foreach ( (array) $user->roles as $role_slug ) {
			if ( $role_slug === $edited_role ) {
				continue;
			}
			$role = get_role( (string) $role_slug );
			if ( $role && $role->has_cap( 'cb_manage_roles' ) ) {
				return;
			}
		}

		throw new \RuntimeException( __( 'This change would remove your last capability to manage user roles.', 'core-blueprint' ) );
	}

	/**
	 * Assert that a role may be deleted safely.
	 */
	public static function assert_can_delete_role( string $slug ): void {
		self::assert_can_edit_role( $slug );
		$state = self::role_state( $slug );
		if ( ! $state['can_delete'] ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: comma-separated safety reasons */
					__( 'This role cannot be deleted: %s.', 'core-blueprint' ),
					implode( ', ', $state['delete_reasons'] )
				)
			);
		}
	}
}
