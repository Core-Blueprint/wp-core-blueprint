<?php
declare(strict_types=1);
/**
 * Guards - shared request-validation helpers.
 *
 * Every CoreBlueprint\Core\Ajax\Handlers\* class uses this trait to pick up the
 * standard security gates:
 *   - require_admin()              - cap check, fails with 403
 *   - require_password_reconfirm() - re-verify current user's password
 *                                    for destructive actions
 *
 * Trait (not abstract class) so handler classes can remain final.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Ajax;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

trait Guards {

	/**
	 * Cap-check guard. Call right after check_ajax_referer().
	 * Sends a 403 JSON error and terminates the request on failure.
	 */
	protected static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'core-blueprint' ) ], 403 );
		}
	}

	/**
	 * Password-reconfirm guard for destructive actions (panic-button,
	 * token rotation, etc.). Ensures a stolen session cookie alone is
	 * not enough to disable protections.
	 *
	 * Logs failures to the audit log as a warning - the pattern is a
	 * credential-probe signal.
	 */
	protected static function require_password_reconfirm(): void {
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $password ) ) {
			wp_send_json_error( [ 'message' => __( 'Password confirmation required.', 'core-blueprint' ) ], 401 );
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			wp_send_json_error( [ 'message' => __( 'No authenticated user.', 'core-blueprint' ) ], 401 );
		}

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			if ( class_exists( AuditLog::class ) ) {
				AuditLog::log( 'security.password_reconfirm_failed', 'warning', [
					'user_login' => $user->user_login,
				] );
			}
			wp_send_json_error( [ 'message' => __( 'Password incorrect.', 'core-blueprint' ) ], 401 );
		}
	}
}
