<?php
declare(strict_types=1);
/**
 * RoleRepository
 *
 * Thin persistence layer over WordPress' native WP_Roles storage. Core
 * Blueprint does not maintain a parallel roles table: every mutation here is
 * immediately visible to WordPress and to other role-aware plugins.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class RoleRepository {

	/**
	 * Return the complete role-editor snapshot.
	 *
	 * @return array{roles:array<int,array<string,mixed>>,capabilities:array<string,array<string,mixed>>}
	 */
	public static function snapshot(): array {
		$catalog = CapabilityCatalog::all();
		$roles   = [];
		$wp_roles = wp_roles();

		foreach ( (array) $wp_roles->roles as $slug => $role_data ) {
			$roles[] = self::serialize_role( (string) $slug, is_array( $role_data ) ? $role_data : [], $catalog );
		}

		usort( $roles, static function ( array $a, array $b ): int {
			if ( ! empty( $a['is_administrator'] ) ) { return -1; }
			if ( ! empty( $b['is_administrator'] ) ) { return 1; }
			if ( ! empty( $a['is_operator'] ) ) { return -1; }
			if ( ! empty( $b['is_operator'] ) ) { return 1; }
			return strnatcasecmp( (string) $a['name'], (string) $b['name'] );
		} );

		return [
			'roles'        => $roles,
			'capabilities' => $catalog,
		];
	}

	/**
	 * Create a new role, optionally copying capabilities from an existing role.
	 */
	public static function create( string $name, string $slug, string $source_role = '' ): string {
		RolePolicy::assert_can_edit_role( '' );

		$name = trim( wp_strip_all_tags( $name ) );
		$slug = sanitize_key( $slug );
		if ( '' === $name || '' === $slug ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Role name and slug are required.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( strlen( $slug ) > 64 ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Role slug must be 64 characters or fewer.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( null !== get_role( $slug ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'A role with this slug already exists.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$caps = [];
		if ( '' !== $source_role ) {
			$source = get_role( sanitize_key( $source_role ) );
			if ( ! $source ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
				throw new \InvalidArgumentException( __( 'The source role no longer exists.', 'core-blueprint' ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			foreach ( (array) $source->capabilities as $cap => $granted ) {
				if ( $granted ) {
					RolePolicy::assert_can_change_capability( $slug, (string) $cap, true );
					$caps[ (string) $cap ] = true;
				}
			}
		}

		$result = add_role( $slug, $name, $caps );
		if ( null === $result ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'WordPress could not create the role.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		AuditLog::log( 'permissions.role_created', 'notice', [
			'role'        => $slug,
			'name'        => $name,
			'source_role' => $source_role,
			'capabilities'=> array_keys( $caps ),
		] );

		return $slug;
	}

	/**
	 * Duplicate an existing role under a new slug/name.
	 */
	public static function duplicate( string $source_slug, string $name, string $slug ): string {
		$source_slug = sanitize_key( $source_slug );
		if ( null === get_role( $source_slug ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'The role to duplicate no longer exists.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return self::create( $name, $slug, $source_slug );
	}

	/**
	 * Update a role's human-facing display name. WordPress exposes no public
	 * rename method; the role slug remains immutable and only the native
	 * WP_Roles option entry is updated.
	 */
	public static function update_label( string $slug, string $name ): void {
		$slug  = sanitize_key( $slug );
		$name  = trim( wp_strip_all_tags( $name ) );
		$state = RolePolicy::role_state( $slug );
		RolePolicy::assert_can_edit_role( $slug );

		if ( ! $state['can_rename_label'] ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'The display name of this system role is protected.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( '' === $name ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Role name cannot be empty.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$wp_roles = wp_roles();
		if ( ! isset( $wp_roles->roles[ $slug ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Role not found.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$old_name = (string) ( $wp_roles->roles[ $slug ]['name'] ?? $slug );
		$wp_roles->roles[ $slug ]['name'] = $name;
		$wp_roles->role_names[ $slug ]    = $name;

		if ( false === update_option( $wp_roles->role_key, $wp_roles->roles ) && $old_name !== $name ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'WordPress could not update the role name.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		AuditLog::log( 'permissions.role_updated', 'notice', [
			'role'     => $slug,
			'old_name' => $old_name,
			'new_name' => $name,
		] );
	}

	/**
	 * Replace the role's granted primitive capabilities with a desired set.
	 * Required system-role caps are force-preserved before mutation.
	 *
	 * @param string[] $desired_caps
	 */
	public static function update_capabilities( string $slug, array $desired_caps ): void {
		$slug = sanitize_key( $slug );
		RolePolicy::assert_can_edit_role( $slug );

		$role = get_role( $slug );
		if ( ! $role ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Role not found.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$desired = [];
		foreach ( $desired_caps as $cap ) {
			$cap = sanitize_key( (string) $cap );
			if ( '' !== $cap ) {
				$desired[ $cap ] = true;
			}
		}
		foreach ( RolePolicy::required_capabilities( $slug ) as $cap ) {
			$desired[ $cap ] = true;
		}

		RolePolicy::assert_no_self_lockout( $slug, array_keys( $desired ) );

		$current = [];
		foreach ( (array) $role->capabilities as $cap => $granted ) {
			if ( $granted ) {
				$current[ (string) $cap ] = true;
			}
		}

		$added   = array_diff_key( $desired, $current );
		$removed = array_diff_key( $current, $desired );

		foreach ( array_keys( $added ) as $cap ) {
			RolePolicy::assert_can_change_capability( $slug, $cap, true );
		}
		foreach ( array_keys( $removed ) as $cap ) {
			RolePolicy::assert_can_change_capability( $slug, $cap, false );
		}

		foreach ( array_keys( $added ) as $cap ) {
			$role->add_cap( $cap, true );
		}
		foreach ( array_keys( $removed ) as $cap ) {
			$role->remove_cap( $cap );
		}

		if ( $added || $removed ) {
			AuditLog::log( 'permissions.role_capabilities_changed', 'notice', [
				'role'    => $slug,
				'added'   => array_values( array_keys( $added ) ),
				'removed' => array_values( array_keys( $removed ) ),
			] );
		}
	}

	/**
	 * Delete a custom, unused, non-default role.
	 */
	public static function delete( string $slug ): void {
		$slug = sanitize_key( $slug );
		if ( null === get_role( $slug ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( __( 'Role not found.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		RolePolicy::assert_can_delete_role( $slug );

		remove_role( $slug );
		AuditLog::log( 'permissions.role_deleted', 'warning', [ 'role' => $slug ] );
	}

	/**
	 * Serialize one WP role for the REST/UI boundary.
	 *
	 * @param array<string,mixed>                       $role_data
	 * @param array<string,array<string,mixed>>         $catalog
	 * @return array<string,mixed>
	 */
	private static function serialize_role( string $slug, array $role_data, array $catalog ): array {
		$state    = RolePolicy::role_state( $slug );
		$required = RolePolicy::required_capabilities( $slug );
		$caps     = [];

		foreach ( $catalog as $cap => $meta ) {
			$granted = ! empty( $role_data['capabilities'][ $cap ] );
			$caps[ $cap ] = [
				'granted'       => $granted,
				'required'      => in_array( $cap, $required, true ),
				'actor_can_edit'=> current_user_can( $cap ),
				'policy_grant'  => ! empty( $meta['policy_grant'] ) && ! empty( $role_data['capabilities']['manage_options'] ),
			];
		}

		return array_merge( $state, [
			'slug'         => $slug,
			'name'         => (string) ( $role_data['name'] ?? $slug ),
			'capabilities' => $caps,
			'users_url'    => admin_url( 'users.php?role=' . rawurlencode( $slug ) ),
		] );
	}
}
