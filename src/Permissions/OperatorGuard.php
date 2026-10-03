<?php
declare(strict_types=1);
/**
 * Permissions OperatorGuard
 *
 * Watches the cb_operator population. Two failure modes are handled:
 *
 *   1. Zero operators while hide_from_admins is on → the Permissions page
 *      becomes unreachable for everybody. The guard auto-disables the hide
 *      and writes a critical audit-log entry so the change is visible.
 *
 * Role-definition drift is detected and reported by RolePolicySchema; this guard
 * never recreates a missing role during normal runtime.
 *
 * Hooks both event-driven (set_user_role, delete_user, …) and a periodic
 * safety net on admin_init. The redundancy is intentional: rolemanager
 * plugins routinely write directly to user_meta and never fire the standard
 * role-change actions. The admin_init pass catches them anyway.
 *
 * Naming note: this is NOT the same thing as CoreBlueprint\Core\Security\Failsafe. That
 * class handles lockout-prevention via bypass tokens and is mission-critical
 * to plugin operation. OperatorGuard is a much lighter mechanism scoped to a
 * single feature (the Permissions page hide-toggle).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class OperatorGuard {

	private static bool $bootstrapped = false;

	/**
	 * Wire all guard hooks. Called once from Permissions\Bootstrap::boot().
	 * Idempotent via a static flag.
	 */
	public static function init(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		// Per-user role mutations - fire on the obvious admin actions.
		add_action( 'set_user_role',    [ __CLASS__, 'on_role_change' ], 10, 3 );
		add_action( 'add_user_role',    [ __CLASS__, 'on_role_added' ], 10, 2 );
		add_action( 'remove_user_role', [ __CLASS__, 'on_role_removed' ], 10, 2 );

		// User deletion can drop the only operator on a site silently.
		add_action( 'delete_user', [ __CLASS__, 'on_user_delete' ], 10, 1 );

		// Safety net for zero-effective-operator lockout. Role-definition drift
		// itself is observed by RolePolicySchema and is never auto-healed here.
		add_action( 'admin_init', [ __CLASS__, 'maybe_disable_hide' ] );
	}

	// ─── Event handlers ───────────────────────────────────────────────────────

	/**
	 * Fires when a user's primary role is replaced (set_user_role).
	 *
	 * @param int    $user_id    User whose role is changing.
	 * @param string $role       New role (unused - we recheck via get_users).
	 * @param array  $old_roles  Previous roles (unused).
	 */
	public static function on_role_change( $user_id, $role, $old_roles ): void {
		self::maybe_disable_hide();
	}

	/**
	 * Fires on add_user_role. Adding cannot reduce the operator count, so
	 * there is no lockout action to take here. The hook remains registered for
	 * symmetry with the other role-mutation paths.
	 */
	public static function on_role_added( $user_id, $role ): void {
		// No action needed - adding roles cannot reduce the operator count.
	}

	/**
	 * Fires on remove_user_role.
	 */
	public static function on_role_removed( $user_id, $role ): void {
		if ( Roles::OPERATOR_ROLE !== $role ) {
			return;
		}
		self::maybe_disable_hide();
	}

	/**
	 * Fires on delete_user.
	 */
	public static function on_user_delete( $user_id ): void {
		self::maybe_disable_hide();
	}

	// ─── Guard logic ──────────────────────────────────────────────────────────

	/**
	 * If the hide-from-admins setting is on AND there are zero cb_operator
	 * users, disable the hide automatically. Writes an audit-log entry at
	 * critical severity and emits the suite-wide guard hook.
	 *
	 * Safe to call as a no-op when conditions are not met - it's the cheap
	 * path on most invocations.
	 */
	public static function maybe_disable_hide(): void {
		$settings = Settings::get();
		$hide_on  = ! empty( $settings['permissions']['hide_from_admins'] );

		if ( ! $hide_on ) {
			return;
		}

		if ( Roles::operator_count() > 0 ) {
			return;
		}

		// Zero operators with hide on - flip it off. Use Settings::set_key
		// so the change goes through the same audit-logging path as any
		// other settings mutation. Settings::set_key works on top-level
		// keys only, so we read the 'permissions' block, mutate the
		// nested flag, and write the whole block back.
		$settings    = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];
		$permissions['hide_from_admins'] = false;
		Settings::set_key( 'permissions', $permissions, 'permissions.operator_guard' );

		AuditLog::log(
			'permissions.operator_guard_triggered',
			'critical',
			[
				'reason'         => 'zero_operators_with_hide_active',
				'previous_value' => true,
				'new_value'      => false,
			]
		);

		/**
		 * Fires when the OperatorGuard auto-disables the hide-from-admins
		 * setting. Listeners can show a persistent admin notice or push the
		 * event out via a notification channel.
		 *
		 * @param string $reason Machine-readable reason code.
		 */
		do_action( 'cb_permissions_operator_guard_triggered', 'zero_operators_with_hide_active' );
	}

}
