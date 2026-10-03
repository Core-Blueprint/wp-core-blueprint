<?php
declare(strict_types=1);
/**
 * Settings - AJAX handlers for settings toggles.
 *
 * Covers: Access Mode, Core Shield, module/feature toggles, applying
 * recommended defaults, and email-alert severity toggles. Preamble +
 * typed input handled via {@see Request}; permission gating via
 * {@see Guards::require_admin()} so all handlers share the same
 * cap-check entry point as `panic_activate`, `rotate_token`, etc.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Guards;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\EmailAlerts;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Settings as CoreSettings;

defined( 'ABSPATH' ) || exit;

final class Settings {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_toggle_module',        [ __CLASS__, 'toggle_module' ] );
		add_action( 'wp_ajax_cb_core_toggle_all_modules',   [ __CLASS__, 'toggle_all_modules' ] );
		add_action( 'wp_ajax_cb_core_toggle_feature',       [ __CLASS__, 'toggle_feature' ] );
		add_action( 'wp_ajax_cb_core_apply_defaults',       [ __CLASS__, 'apply_defaults' ] );
		add_action( 'wp_ajax_cb_core_toggle_alert',         [ __CLASS__, 'toggle_alert' ] );
		add_action( 'wp_ajax_cb_core_set_alert_recipient',  [ __CLASS__, 'set_alert_recipient' ] );
	}


	public static function toggle_module(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();
		$module  = Request::sanitize_key( 'module' );
		$enabled = Request::bool( 'enabled' );

		$user = wp_get_current_user();
		CoreSettings::set_module_enabled( $module, $enabled, 'admin:' . $user->user_login );

		wp_send_json_success();
	}

	/**
	 * Flip every registered module to the given state in one atomic write.
	 * The JS "All modules" master toggle calls this instead of N parallel
	 * per-module toggles, which would race on the shared settings option.
	 */
	public static function toggle_all_modules(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();
		$enabled = Request::bool( 'enabled' );

		$user    = wp_get_current_user();
		$changed = CoreSettings::set_all_modules_enabled( $enabled, 'admin:' . $user->user_login );

		wp_send_json_success( [
			'enabled' => $enabled,
			'changed' => array_keys( $changed ),
		] );
	}

	public static function toggle_feature(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();
		$module  = Request::sanitize_key( 'module' );
		$feature = Request::sanitize_key( 'feature' );
		$enabled = Request::bool( 'enabled' );

		$user = wp_get_current_user();
		CoreSettings::set_feature_enabled( $module, $feature, $enabled, 'admin:' . $user->user_login );

		wp_send_json_success();
	}

	public static function apply_defaults(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$user = wp_get_current_user();
		CoreSettings::apply_recommended_defaults( 'admin:' . $user->user_login );

		wp_send_json_success( [
			'message' => __( 'Recommended defaults applied.', 'core-blueprint' ),
		] );
	}

	/**
	 * Toggle a single notification flag for a given canonical group/key pair.
	 *
	 * Input: `{ group: 'permissions', alert_key: 'role_change', enabled: 1 }`.
	 * Per-group allowlists prevent arbitrary settings subtree writes.
	 */
	public static function toggle_alert(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$allowed = [
			'audit'       => AuditLog::SEVERITIES, // critical / warning / notice / info
			'permissions' => [ 'role_change', 'operator_guard_triggered', 'privileged_review' ],
			'reports'     => [ 'generation_failed' ],
			'integrity'   => [ 'critical_anomaly', 'warning_anomaly', 'resolved' ],
		];

		$group = Request::sanitize_key( 'group', array_keys( $allowed ) );
		if ( '' === $group ) {
			wp_send_json_error( [ 'message' => __( 'Invalid notification group.', 'core-blueprint' ) ], 400 );
		}

		// Permissions/security-governance notifications are operator-owned.
		// A normal administrator may not silence privileged-access review
		// alerts or redirect them away from the operator mailbox.
		if ( 'permissions' === $group && ! current_user_can( 'cb_manage_permissions' ) ) {
			wp_send_json_error( [
				'message' => __( 'Only CB Operators may change permissions notifications.', 'core-blueprint' ),
			], 403 );
		}

		if ( 'integrity' === $group && ! current_user_can( 'cb_manage_integrity_policy' ) ) {
			wp_send_json_error( [
				'message' => __( 'Only CB Operators may change Core Scanner security notifications.', 'core-blueprint' ),
			], 403 );
		}

		$alert_key = Request::sanitize_key( 'alert_key', $allowed[ $group ] );
		if ( '' === $alert_key ) {
			wp_send_json_error( [
				'message' => __( 'Invalid alert key for this group.', 'core-blueprint' ),
			], 400 );
		}

		$enabled = Request::bool( 'enabled' );

		$settings = CoreSettings::get();
		$block    = is_array( $settings[ $group ] ?? null ) ? $settings[ $group ] : [];
		if ( ! isset( $block['email_alerts'] ) || ! is_array( $block['email_alerts'] ) ) {
			$block['email_alerts'] = [];
		}
		$block['email_alerts'][ $alert_key ] = $enabled;

		$user = wp_get_current_user();
		CoreSettings::set_key( $group, $block, 'admin:' . $user->user_login );

		wp_send_json_success();
	}

	/**
	 * Persist the alert-email recipient override for a notification group.
	 *
	 * Input shape:
	 *   - `recipient` (string): new value. Empty clears the override.
	 *     Comma-separated list supported for multi-recipient.
	 *   - `group` (string, required): notification group to target. Future sibling
	 *     plugins (CB Hub pairing, CB License expirations, etc.) can target
	 *     their own namespace under CB_CORE_SETTINGS by passing a different
	 *     group here. An allowlist prevents arbitrary subtrees from being
	 *     written; the allowlist grows as groups are registered.
	 *
	 * Storage: each group claims `CB_CORE_SETTINGS[<group>]['email_recipient']`.
	 * One handler, multiple groups - saves a per-group handler duplicate.
	 *
	 * Validation runs through EmailAlerts::sanitize_recipients() so the
	 * stored shape matches what wp_mail() will eventually consume.
	 */
	public static function set_alert_recipient(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		// Allowlist of recognised notification groups. Keep this in sync
		// with the groups rendered in templates/notifications.php and the
		// per-group routing in EmailAlerts::resolve_recipients().
		$allowed_groups = [ 'audit', 'permissions', 'reports', 'integrity' ];

		$group = Request::sanitize_key( 'group', $allowed_groups );
		if ( '' === $group ) {
			wp_send_json_error( [ 'message' => __( 'Invalid notification group.', 'core-blueprint' ) ], 400 );
		}

		if ( 'permissions' === $group && ! current_user_can( 'cb_manage_permissions' ) ) {
			wp_send_json_error( [
				'message' => __( 'Only CB Operators may change permissions notifications.', 'core-blueprint' ),
			], 403 );
		}

		if ( 'integrity' === $group && ! current_user_can( 'cb_manage_integrity_policy' ) ) {
			wp_send_json_error( [
				'message' => __( 'Only CB Operators may change Core Scanner security notifications.', 'core-blueprint' ),
			], 403 );
		}

		$raw   = Request::text( 'recipient' );
		$clean = EmailAlerts::sanitize_recipients( $raw );

		// User typed something but none of it was a valid address → error.
		if ( '' !== trim( $raw ) && '' === $clean ) {
			wp_send_json_error( [
				'message' => __( 'No valid email addresses found. Use a comma to separate multiple addresses.', 'core-blueprint' ),
			], 400 );
		}

		$settings = CoreSettings::get();
		if ( ! isset( $settings[ $group ] ) || ! is_array( $settings[ $group ] ) ) {
			$settings[ $group ] = [];
		}
		$settings[ $group ]['email_recipient'] = $clean;

		$user = wp_get_current_user();
		CoreSettings::set_key( $group, $settings[ $group ], 'admin:' . $user->user_login );

		wp_send_json_success( [
			'group'     => $group,
			'recipient' => $clean,
			'fallback'  => '' === $clean ? (string) get_option( 'admin_email', '' ) : '',
			'message'   => '' === $clean
				? __( 'Cleared - alerts will go to the site admin email.', 'core-blueprint' )
				: __( 'Saved.', 'core-blueprint' ),
		] );
	}
}
