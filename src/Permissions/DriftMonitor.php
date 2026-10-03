<?php
declare(strict_types=1);
/**
 * Permissions DriftMonitor
 *
 * Listens for WordPress role-change events and writes one audit-log entry
 * per change. Provides visibility into role mutations regardless of whether
 * they came from our own UI, a role-management plugin, direct DB edit, or
 * WP-CLI. The Permissions tab's own save handler logs richer events for
 * the cb_operator additions/removals it performs; DriftMonitor adds the
 * "everything else" coverage.
 *
 * Severity policy:
 *   - cb_operator changes        → notice  (audit-relevant)
 *   - any other role change      → info    (visibility, low-noise)
 *   - user deletion              → notice  (always relevant)
 *
 * Sibling to OperatorGuard, which reacts to the same events but for a
 * different purpose: OperatorGuard mutates state (auto-disabling hide
 * when operators drop to zero); DriftMonitor only observes and records.
 *
 * Email alerts: when the v1.1 sessie 7 wiring lands, the audit events
 * emitted here will route through EmailAlerts based on the new
 * permissions.email_alerts.role_change toggle. The audit-log entries
 * themselves are already in place from this milestone.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class DriftMonitor {

	private static bool $bootstrapped = false;

	/**
	 * Wire role-change listeners. Called once from Permissions\Bootstrap.
	 */
	public static function init(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		add_action( 'set_user_role',    [ __CLASS__, 'on_set_role' ], 20, 3 );
		add_action( 'add_user_role',    [ __CLASS__, 'on_add_role' ], 20, 2 );
		add_action( 'remove_user_role', [ __CLASS__, 'on_remove_role' ], 20, 2 );
		add_action( 'delete_user',      [ __CLASS__, 'on_delete_user' ], 20, 1 );
	}

	// ─── Listeners ────────────────────────────────────────────────────────────

	/**
	 * Fires when set_user_role replaces a user's primary role outright.
	 *
	 * @param int      $user_id
	 * @param string   $new_role
	 * @param string[] $old_roles
	 */
	public static function on_set_role( $user_id, $new_role, $old_roles ): void {
		// Skip pure no-ops - avoid logging set_user_role calls that don't
		// actually change anything (some plugins fire this on every save).
		if ( is_array( $old_roles ) && [ $new_role ] === array_values( $old_roles ) ) {
			return;
		}

		$severity = self::touches_operator( $new_role, $old_roles ) ? 'notice' : 'info';

		AuditLog::log( 'permissions.role_set', $severity, [
			'user_id'   => (int) $user_id,
			'new_role'  => (string) $new_role,
			'old_roles' => is_array( $old_roles ) ? array_values( $old_roles ) : [],
			'by'        => get_current_user_id(),
		] );
	}

	public static function on_add_role( $user_id, $role ): void {
		$severity = Roles::OPERATOR_ROLE === $role ? 'notice' : 'info';

		AuditLog::log( 'permissions.role_added', $severity, [
			'user_id' => (int) $user_id,
			'role'    => (string) $role,
			'by'      => get_current_user_id(),
		] );
	}

	public static function on_remove_role( $user_id, $role ): void {
		$severity = Roles::OPERATOR_ROLE === $role ? 'notice' : 'info';

		AuditLog::log( 'permissions.role_removed', $severity, [
			'user_id' => (int) $user_id,
			'role'    => (string) $role,
			'by'      => get_current_user_id(),
		] );
	}

	/**
	 * Fires before a user is deleted. Capture the roles they held so the
	 * audit trail still has that information after the user record is
	 * gone.
	 */
	public static function on_delete_user( $user_id ): void {
		$user = get_userdata( (int) $user_id );

		AuditLog::log( 'permissions.user_deleted', 'notice', [
			'user_id'    => (int) $user_id,
			'user_login' => $user instanceof \WP_User ? $user->user_login : '',
			'roles_held' => $user instanceof \WP_User ? array_values( (array) $user->roles ) : [],
			'by'         => get_current_user_id(),
		] );
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Whether a set_user_role call touches the operator role on either side.
	 *
	 * @param string   $new_role
	 * @param string[] $old_roles
	 */
	private static function touches_operator( string $new_role, $old_roles ): bool {
		if ( Roles::OPERATOR_ROLE === $new_role ) {
			return true;
		}
		if ( is_array( $old_roles ) && in_array( Roles::OPERATOR_ROLE, $old_roles, true ) ) {
			return true;
		}
		return false;
	}
}
