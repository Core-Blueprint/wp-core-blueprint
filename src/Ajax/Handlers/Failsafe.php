<?php
declare(strict_types=1);
/**
 * Failsafe - AJAX handlers for the Failsafe subsystem.
 *
 * Destructive actions (token rotation, panic bypass) require password
 * reconfirmation on top of the cap check. Deactivating a bypass does
 * not - you can always turn protections back on.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Guards;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Security\Failsafe as SecurityFailsafe;

defined( 'ABSPATH' ) || exit;

final class Failsafe {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_rotate_token',     [ __CLASS__, 'rotate_token' ] );
		add_action( 'wp_ajax_cb_core_panic_activate',   [ __CLASS__, 'panic_activate' ] );
		add_action( 'wp_ajax_cb_core_panic_deactivate', [ __CLASS__, 'panic_deactivate' ] );
		add_action( 'wp_ajax_cb_core_close_window',     [ __CLASS__, 'close_bypass_window' ] );
	}

	public static function rotate_token(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();
		self::require_password_reconfirm();

		$token      = SecurityFailsafe::rotate_token();
		$bypass_url = SecurityFailsafe::build_bypass_url( $token );
		$admin_email = (string) get_option( 'admin_email', '' );

		// The plaintext token deliberately exists only in this authenticated
		// response and the current browser DOM. Never persist it in options,
		// transients, query strings, or other server-side recovery state.
		wp_send_json_success( [
			'message'    => __( 'Token rotated. Save the new bypass URL now; it will not be shown again.', 'core-blueprint' ),
			'bypass_url' => $bypass_url,
			'reveal'     => [
				'title'       => __( 'New bypass token - save this now', 'core-blueprint' ),
				'message'     => __( 'This is the only time the bypass URL will be shown in plaintext. Copy it to your password manager immediately.', 'core-blueprint' ),
				'label'       => __( 'Bypass URL', 'core-blueprint' ),
				'copy_label'  => __( 'Copy URL', 'core-blueprint' ),
				'copy_title'  => __( 'Click to copy', 'core-blueprint' ),
				'description' => __( 'Store it in a password manager or another secure location outside this WordPress installation.', 'core-blueprint' ),
				'items'       => [
					__( 'All restrictive Core Blueprint features are disabled for 60 minutes when the URL is used.', 'core-blueprint' ),
					__( 'The token is rotated after use.', 'core-blueprint' ),
					/* translators: %s: administrator email address. */
				sprintf( __( 'An email notification is sent to %s.', 'core-blueprint' ), $admin_email ),
					__( 'The event is recorded in the audit log.', 'core-blueprint' ),
				],
			],
		] );
	}

	public static function panic_activate(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();
		self::require_password_reconfirm();

		// `reason` is optional - the audit-log entry expects null for "no
		// reason supplied", distinct from an explicit empty string. Request
		// helper returns '' when a field is absent, so normalise back to null.
		$reason = Request::text( 'reason' );
		$reason = '' === $reason ? null : $reason;

		$user = wp_get_current_user();
		SecurityFailsafe::activate_emergency_bypass( 'admin:' . $user->user_login, $reason );

		wp_send_json_success( [
			'message' => __( 'Emergency bypass activated. All restrictive features are now disabled.', 'core-blueprint' ),
		] );
	}

	public static function panic_deactivate(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$user = wp_get_current_user();
		SecurityFailsafe::deactivate_emergency_bypass( 'admin:' . $user->user_login );

		wp_send_json_success( [
			'message' => __( 'Emergency bypass deactivated.', 'core-blueprint' ),
		] );
	}

	public static function close_bypass_window(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		SecurityFailsafe::close_bypass_window();

		wp_send_json_success( [
			'message' => __( 'Active bypass window closed.', 'core-blueprint' ),
		] );
	}
}
