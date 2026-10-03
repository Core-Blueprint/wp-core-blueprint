<?php
declare(strict_types=1);
/**
 * Permissions Roles
 *
 * Owns the cb_operator role and the capability sets attached to it. Two cap
 * tiers exist:
 *
 *   - View caps  (cb_view_*)    - granted to administrators by the canonical
 *                                  Role Policy; provide read-only access to relevant pages.
 *   - Manage caps (cb_manage_*) - exclusive to cb_operator; administrators
 *                                  only get cb_manage_reports if the admin-
 *                                  toggle in Reports settings is enabled
 *                                  (see Caps::filter_user_has_cap).
 *
 * Operator counting is the trust anchor for the OperatorGuard failsafe: when
 * the count drops to zero with hide_from_admins still enabled, the page would
 * become unreachable. The guard recovers from that by auto-disabling the hide.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\AdminNotices\Capabilities as AdminNoticeCapabilities;

defined( 'ABSPATH' ) || exit;

final class Roles {

	/** Role slug - stored in WP's roles table. */
	const OPERATOR_ROLE = 'cb_operator';

	/**
	 * Capabilities granted to the administrator role by the canonical Role Policy.
	 * Most are read-oriented; narrowly scoped Base features may deliberately
	 * grant management authority here when Administrator is a canonical owner.
	 *
	 * Notes and Media Replace module management intentionally live in BOTH
	 * ADMIN_VIEW_CAPS and OPERATOR_CAPS: Notes has no view/manage split, while
	 * Media Replace separates site-wide module authority from attachment use.
	 *
	 * @var string[]
	 */
	const ADMIN_VIEW_CAPS = [
		'cb_view_reports',
		'cb_view_permissions',
		'cb_manage_notes',
		// Safe SVG upload permission. Inert unless Media Formats + SVG are enabled.
		'cb_upload_svg',
		'cb_core_hud_use',
		'cb_manage_media_replace',
	];

	/**
	 * Full capability set granted to the cb_operator role. Includes the view
	 * caps so an operator who is NOT also an administrator can still navigate
	 * to the pages they're allowed to manage.
	 *
	 * SECURITY INVARIANT: when a newly added operator capability also changes
	 * PrivilegedAccessPolicy's signed/trigger state, advance the public trust
	 * schema and provide an explicit reviewed migration step before role policy
	 * reconciliation. Never mutate signed privilege state implicitly.
	 *
	 * @var string[]
	 */
	const OPERATOR_CAPS = [
		// Inherited view caps - operators always see what they can manage.
		'cb_view_reports',
		'cb_view_permissions',
		// Manage caps - exclusive to operators (and admins via the toggle filter).
		'cb_manage_reports',
		'cb_manage_branding',
		'cb_manage_permissions',
		'cb_manage_roles',
		// Audience governance for WordPress admin notices. Presentation-only;
		// deliberately not part of the privileged fingerprint trigger set.
		AdminNoticeCapabilities::MANAGE,
		'cb_manage_content_models',
		'cb_manage_media_replace',
		'cb_upload_svg',
		'cb_manage_integrity',
		// Integrity trust/policy authority. Unlike cb_manage_integrity this cap
		// is never granted through the "administrators may run scans" toggle.
		// It protects baseline approval, scanner configuration, evidence cleanup,
		// locale trust settings, and security-notification routing.
		'cb_manage_integrity_policy',
		// Managed PHP snippets are arbitrary site-level code execution and are
		// intentionally operator-only.
		'cb_manage_snippets',
		'cb_manage_notes',
		// CLI access - operator-only, no admin-toggle. Gates Preferences › CLI
		// tab visibility, the HUD CLI documentation item, and any future
		// per-user CLI-related UI. Has no effect on actual `wp cb` execution
		// - that is gated by server-shell access, not WordPress capabilities.
		'cb_use_cli',
		// Explicit HUD access; not part of the privileged fingerprint boundary.
		'cb_core_hud_use',
		// WP basics so an operator without the administrator role can still
		// reach wp-admin and load the CB pages.
		'read',
	];

	/**
	 * Base-owned meta-capabilities that must never be persisted on a role.
	 * Their authorization is resolved dynamically from object-specific native
	 * WordPress capabilities. Role Policy repair removes accidental stored copies.
	 *
	 * @var string[]
	 */
	const META_CAPS = [
		'cb_replace_media',
	];


	// ─── Role + cap creation ──────────────────────────────────────────────────

	/**
	 * Ensure the cb_operator role exists with the full capability set.
	 * Idempotent role-definition reconciler used only by approved Role Policy lifecycle paths. Adds missing caps to an
	 * existing role rather than recreating it, so user assignments survive.
	 */
	public static function ensure_operator_role(): bool {
		$role    = get_role( self::OPERATOR_ROLE );
		$changed = false;

		if ( null === $role ) {
			$caps_map = array_fill_keys( self::OPERATOR_CAPS, true );
			add_role( self::OPERATOR_ROLE, 'CB Operator', $caps_map );
			return true;
		}

		foreach ( self::OPERATOR_CAPS as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
				$changed = true;
			}
		}

		// Remove only privilege-bearing capabilities that violate the canonical
		// CB Operator policy. Benign third-party/custom capabilities are left
		// untouched; the repair command is not a generic role reset.
		foreach ( PrivilegedAccessPolicy::privileged_capabilities() as $cap ) {
			if ( in_array( $cap, self::OPERATOR_CAPS, true ) || ! $role->has_cap( $cap ) ) {
				continue;
			}
			$role->remove_cap( $cap );
			$changed = true;
		}

		return $changed;
	}


	/**
	 * Reconcile Core Blueprint capabilities on the administrator role.
	 *
	 * Administrators keep the small ADMIN_VIEW_CAPS set, while capabilities
	 * owned by the protected operator role are removed when they are found as
	 * stored Administrator capabilities. This repairs capability drift from
	 * older builds without touching capabilities owned by sibling plugins.
	 *
	 * Runtime admin-toggle capabilities (for example cb_manage_reports and
	 * cb_manage_integrity) are deliberately removed from the stored role too;
	 * Caps::filter_user_has_cap() grants them dynamically when policy allows.
	 */
	public static function ensure_admin_view_caps(): bool {
		$admin = get_role( 'administrator' );
		if ( null === $admin ) {
			return false;
		}

		$changed = false;

		PrivilegedAccessGuard::trusted_mutation( static function () use ( $admin, &$changed ): void {
			foreach ( self::ADMIN_VIEW_CAPS as $cap ) {
				if ( ! $admin->has_cap( $cap ) ) {
					$admin->add_cap( $cap );
					$changed = true;
				}
			}

			$allowed = array_fill_keys( array_merge( [ 'read' ], self::ADMIN_VIEW_CAPS ), true );
			foreach ( self::OPERATOR_CAPS as $cap ) {
				if ( isset( $allowed[ $cap ] ) || ! $admin->has_cap( $cap ) ) {
					continue;
				}

				$admin->remove_cap( $cap );
				$changed = true;
			}
		} );

		return $changed;
	}

	/**
	 * Remove Base-owned meta-capabilities accidentally persisted as role caps.
	 *
	 * Meta capabilities are resolved at runtime and are never canonical stored
	 * role state. This is a current policy invariant, not a version migration.
	 *
	 * @return string[] Role slugs changed by the cleanup.
	 */
	public static function remove_stored_meta_caps(): array {
		$changed_roles = [];
		$wp_roles      = wp_roles();

		foreach ( array_keys( (array) $wp_roles->roles ) as $role_slug ) {
			$role = get_role( (string) $role_slug );
			if ( ! $role ) {
				continue;
			}

			$changed = false;
			foreach ( self::META_CAPS as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					continue;
				}
				$role->remove_cap( $cap );
				$changed = true;
			}

			if ( $changed ) {
				$changed_roles[] = (string) $role_slug;
			}
		}

		sort( $changed_roles, SORT_STRING );
		return $changed_roles;
	}

	/**
	 * Remove the cb_operator role entirely. Used by uninstall.php only -
	 * never on deactivation, since deactivation must be reversible without
	 * losing operator assignments.
	 */
	public static function remove_operator_role(): void {
		if ( null !== get_role( self::OPERATOR_ROLE ) ) {
			remove_role( self::OPERATOR_ROLE );
		}
	}

	// ─── Operator counting ────────────────────────────────────────────────────

	/**
	 * Total number of users with the cb_operator role. Used by OperatorGuard
	 * to detect zero-operator states and by the Permissions tab to warn the
	 * admin before a self-demotion.
	 */
	public static function operator_count(): int {
		if ( null === get_role( self::OPERATOR_ROLE ) ) {
			return 0;
		}

		$users = get_users( [
			'role'   => self::OPERATOR_ROLE,
			'fields' => 'ID',
		] );
		return count( $users );
	}

	/**
	 * Operator count excluding a specific user - used to validate
	 * self-demotion in the Permissions save handler:
	 * "if I remove myself, are there any operators left?"
	 *
	 * @param int $user_id User to exclude from the count.
	 */
	public static function operator_count_excluding( int $user_id ): int {
		if ( null === get_role( self::OPERATOR_ROLE ) ) {
			return 0;
		}

		$users = get_users( [
			'role'    => self::OPERATOR_ROLE,
			'fields'  => 'ID',
			'exclude' => [ $user_id ],
		] );
		return count( $users );
	}

	/**
	 * IDs of every user with the cb_operator role. Returned as an array of
	 * ints so callers can do `in_array( $user_id, $ids, true )`.
	 *
	 * @return int[]
	 */
	public static function operator_ids(): array {
		if ( null === get_role( self::OPERATOR_ROLE ) ) {
			return [];
		}

		$users = get_users( [
			'role'   => self::OPERATOR_ROLE,
			'fields' => 'ID',
		] );
		return array_map( 'intval', $users );
	}
}
