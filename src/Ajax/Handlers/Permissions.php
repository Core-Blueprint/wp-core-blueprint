<?php
declare(strict_types=1);
/**
 * Permissions - AJAX handlers for the Safeguards → Permissions tab.
 *
 * Three endpoints, one per save action so the lockout-prevention logic
 * stays scoped to the data each call actually touches:
 *
 *   wp_ajax_cb_core_save_permission_operators
 *     POST. Promotes/demotes administrators to/from cb_operator. Computes
 *     the diff against the current operator set so audit-log entries and
 *     hooks are emitted only for actual changes.
 *
 *   wp_ajax_cb_core_save_permission_hide
 *     POST. Toggles permissions.hide_from_admins. Refuses to enable hide
 *     when there are zero operators - that would create the very lockout
 *     condition OperatorGuard exists to prevent.
 *
 *   wp_ajax_cb_core_save_permission_admin_caps
 *     POST. Toggles reports.admin_can_generate.maintenance. No lockout
 *     impact - purely additive cap (admins inheriting cb_manage_reports).
 *
 * Capability gate is cb_manage_permissions for all three. That cap is
 * exclusive to cb_operator (NOT mapped via the Caps filter), so this
 * page truly is operator-only territory.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Guards;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Permissions\PrivilegedAccessGuard;
use CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Permissions {

	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_save_permission_operators',  [ __CLASS__, 'save_operators' ] );
		add_action( 'wp_ajax_cb_core_save_permission_hide',       [ __CLASS__, 'save_hide' ] );
		add_action( 'wp_ajax_cb_core_save_permission_admin_caps', [ __CLASS__, 'save_admin_caps' ] );
		add_action( 'wp_ajax_cb_core_approve_privileged_user',          [ __CLASS__, 'approve_privileged_user' ] );
		add_action( 'wp_ajax_cb_core_set_privileged_access_mode',      [ __CLASS__, 'set_privileged_access_mode' ] );
	}

	// ─── Save: operator assignments ───────────────────────────────────────────

	public static function save_operators(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_permissions();

		// Read the desired operator set. Empty array = "no operators".
		$raw = isset( $_POST['operator_ids'] ) ? (array) wp_unslash( $_POST['operator_ids'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$promoted = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

		// Lockout prevention: promoting nobody while hide is on creates a
		// state OperatorGuard would auto-disable on next admin_init. Refuse
		// up-front so the user gets a clear error rather than a "settings
		// changed twice" surprise.
		$hide_active = ! empty( Settings::get()['permissions']['hide_from_admins'] );
		if ( empty( $promoted ) && $hide_active ) {
			wp_send_json_error( [
				'message' => __(
					'Cannot save: with zero operators and the page hidden from administrators, no one would be able to change these settings. Assign at least one operator first, or turn off "Hide this page".',
					'core-blueprint'
				),
			], 400 );
		}

		// Limit promotion candidates to actual administrators - prevents a
		// payload that promotes a subscriber to operator.
		$admin_ids = array_map( 'intval', get_users( [
			'role'   => 'administrator',
			'fields' => 'ID',
		] ) );
		$promoted = array_values( array_intersect( $promoted, $admin_ids ) );

		// Diff against current state to know what actually changes.
		$current_ids = Roles::operator_ids();
		$to_add      = array_diff( $promoted, $current_ids );
		$to_remove   = array_diff( $current_ids, $promoted );

		// Self-demotion guard: refuse if the current user is removing
		// themselves AND the resulting set is empty AND hide is on. The
		// generic "no operators + hide" check above already covers this -
		// but the dedicated message here is more actionable for the user.
		$current_user_id = get_current_user_id();
		if (
			in_array( $current_user_id, $to_remove, true )
			&& empty( $promoted )
			&& $hide_active
		) {
			wp_send_json_error( [
				'message' => __(
					'You cannot remove yourself as the last operator while the page is hidden from administrators. Add another operator first, or turn off the visibility setting.',
					'core-blueprint'
				),
			], 400 );
		}

		// Apply changes.
		foreach ( $to_add as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user ) {
				PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
					$user->add_role( Roles::OPERATOR_ROLE );
				} );
				PrivilegedAccessRegistry::approve( $user, $current_user_id, 'operator_assignment' );
				AuditLog::log( 'permissions.operator_added', 'notice', [
					'user_id'    => (int) $user_id,
					'user_login' => $user->user_login,
					'by'         => $current_user_id,
				] );
				/** @see Generator hook docblock for parameter contract. */
				do_action( 'cb_permissions_operator_added', (int) $user_id, $current_user_id );
			}
		}

		foreach ( $to_remove as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user ) {
				PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
					$user->remove_role( Roles::OPERATOR_ROLE );
				} );
				if ( PrivilegedAccessPolicy::is_privileged( $user ) ) {
					PrivilegedAccessRegistry::approve( $user, $current_user_id, 'operator_removal' );
				} else {
					PrivilegedAccessRegistry::clear( $user );
				}
				AuditLog::log( 'permissions.operator_removed', 'warning', [
					'user_id'    => (int) $user_id,
					'user_login' => $user->user_login,
					'by'         => $current_user_id,
				] );
				do_action( 'cb_permissions_operator_removed', (int) $user_id, $current_user_id );
			}
		}

		wp_send_json_success( [
			'operator_count'  => Roles::operator_count(),
			'operator_ids'    => Roles::operator_ids(),
			'changes_applied' => count( $to_add ) + count( $to_remove ),
		] );
	}

	// ─── Save: hide-toggle ────────────────────────────────────────────────────

	public static function save_hide(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_permissions();

		$enabled = Request::bool( 'enabled' );

		// Lockout prevention: enabling hide with zero operators present
		// would orphan the page. Refuse with a clear message.
		if ( $enabled && Roles::operator_count() === 0 ) {
			wp_send_json_error( [
				'message' => __(
					'Hide can only be enabled after at least one CB Operator has been assigned.',
					'core-blueprint'
				),
			], 400 );
		}

		$settings    = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];
		$permissions['hide_from_admins'] = $enabled;
		Settings::set_key( 'permissions', $permissions, 'permissions.tab' );

		AuditLog::log( 'permissions.hide_toggled', 'notice', [
			'enabled' => $enabled,
			'by'      => get_current_user_id(),
		] );

		wp_send_json_success( [
			'hide_active' => $enabled,
		] );
	}

	// ─── Save: admin capability toggles ──────────────────────────────────────

	public static function save_admin_caps(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_permissions();

		// Two booleans posted from the form, each gating its own admin-toggle.
		$reports_enabled   = Request::bool( 'admin_can_generate_maintenance' );
		$integrity_enabled = Request::bool( 'admin_can_run_integrity' );

		$settings = Settings::get();

		// reports.admin_can_generate.maintenance
		$reports = is_array( $settings['reports'] ?? null ) ? $settings['reports'] : [];
		if ( ! isset( $reports['admin_can_generate'] ) || ! is_array( $reports['admin_can_generate'] ) ) {
			$reports['admin_can_generate'] = [];
		}
		$reports['admin_can_generate']['maintenance'] = $reports_enabled;
		Settings::set_key( 'reports', $reports, 'permissions.tab' );

		// integrity.admin_can_run
		$integrity                  = is_array( $settings['integrity'] ?? null ) ? $settings['integrity'] : [];
		$integrity['admin_can_run'] = $integrity_enabled;
		Settings::set_key( 'integrity', $integrity, 'permissions.tab' );

		AuditLog::log( 'permissions.admin_caps_changed', 'notice', [
			'admin_can_generate_maintenance' => $reports_enabled,
			'admin_can_run_integrity'        => $integrity_enabled,
			'by'                             => get_current_user_id(),
		] );

		wp_send_json_success( [
			'admin_can_generate_maintenance' => $reports_enabled,
			'admin_can_run_integrity'        => $integrity_enabled,
		] );
	}


	// ─── Privileged Access Protection ───────────────────────────────────────


	public static function set_privileged_access_mode(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_permissions();

		$mode = Request::sanitize_key( 'mode', [
			PrivilegedAccessPolicy::MODE_ENFORCE,
			PrivilegedAccessPolicy::MODE_MONITOR,
		] );

		$settings    = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];
		$before      = PrivilegedAccessPolicy::enforcement_mode();

		if ( $before !== $mode ) {
			$permissions['privileged_access_mode'] = $mode;
			if ( ! Settings::set_key( 'permissions', $permissions, 'core_shield.privileged_access' ) ) {
				wp_send_json_error( [
					'message' => __( 'Could not update Privileged Access Protection.', 'core-blueprint' ),
				], 500 );
			}

			AuditLog::log( 'permissions.privileged_access_mode_changed', 'warning', [
				'from' => $before,
				'to'   => $mode,
				'by'   => get_current_user_id(),
			] );
		}

		$review_count = PrivilegedAccessGuard::reconcile_all( 'policy_mode_changed', 'core_shield_policy' );

		wp_send_json_success( [
			'mode'         => $mode,
			'review_count' => $review_count,
		] );
	}

	public static function approve_privileged_user(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_permissions();

		$user_id = Request::int( 'user_id' );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! ( $user instanceof \WP_User ) ) {
			wp_send_json_error( [
				'message' => __( 'The user no longer exists.', 'core-blueprint' ),
			], 404 );
		}

		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
			PrivilegedAccessRegistry::clear( $user );
			wp_send_json_error( [
				'message' => __( 'This user no longer has administrator-level privileges and does not require approval.', 'core-blueprint' ),
			], 409 );
		}

		$approved_by = get_current_user_id();
		PrivilegedAccessRegistry::approve( $user, $approved_by, 'core_shield_review' );

		wp_send_json_success( [
			'user_id' => (int) $user->ID,
			'message' => __( 'Privileged access approved.', 'core-blueprint' ),
		] );
	}

	// ─── Internals ────────────────────────────────────────────────────────────

	/**
	 * Trust-authority guard. The capability must be present and the current
	 * identity must be a signed, approved CB Operator. A stored/injected
	 * capability alone can never authorize approval or policy changes.
	 */
	private static function require_manage_permissions(): void {
		$user = wp_get_current_user();
		if (
			! current_user_can( 'cb_manage_permissions' )
			|| ! ( $user instanceof \WP_User )
			|| ! PrivilegedAccessGuard::is_trusted_operator( $user )
		) {
			wp_send_json_error( [
				'message' => __( 'Only an approved Core Blueprint Operator may change privileged access or permissions.', 'core-blueprint' ),
			], 403 );
		}
	}
}
