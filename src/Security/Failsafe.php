<?php
declare(strict_types=1);
/**
 * Failsafe
 *
 * Mission-critical lockout prevention system. Four independent layers ensure
 * that Core Blueprint can never permanently lock an administrator out of
 * their own site.
 *
 *   Layer 1 - `CB_CORE_BYPASS` constant in wp-config.php
 *   Layer 2 - WP-CLI commands (handled in Command)
 *   Layer 3 - Secret bypass URL with rotating single-use token
 *   Layer 4 - Admin panic button (handled in Admin)
 *
 * Every restrictive feature in Core Blueprint MUST call ::is_bypassed() before
 * enforcing. If ::is_bypassed() returns true, the feature must become a no-op.
 *
 * The failsafe is loaded before any other subsystem so that a broken module
 * cannot prevent bypass mechanisms from functioning.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Security;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Failsafe {

	/** Transient key for the 60-minute bypass window triggered by secret URL. */
	const BYPASS_TRANSIENT = 'cb_core_bypass_window';

	/** Bypass window duration in seconds (60 minutes). */
	const BYPASS_WINDOW = 3600;

	/** Query parameter used on the secret bypass URL. */
	const BYPASS_PARAM = 'cb_core_bypass';

	/** Fixed, bounded gate for anonymous rejected-URL audit writes. */
	private const REJECT_AUDIT_GATE = 'cb_core_failsafe_rejected_audit_gate';

	/** Log at most one rejected bypass attempt per five-minute abuse window. */
	private const REJECT_AUDIT_WINDOW = 300;

	private static bool $bootstrapped = false;

	// ─── Bootstrap ────────────────────────────────────────────────────────────

	public static function init(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		// Listen for the secret bypass URL as early as possible.
		add_action( 'init', [ __CLASS__, 'maybe_handle_bypass_url' ], 1 );

		// Ensure a token exists so the bypass URL can be generated from the admin.
		add_action( 'admin_init', [ __CLASS__, 'ensure_token' ] );
	}

	// ─── Public API - used by every restrictive feature ──────────────────────

	/**
	 * The single source of truth for whether restrictive features should enforce.
	 *
	 * Returns true if ANY bypass layer is active. Restrictive features must
	 * short-circuit and become a no-op when this returns true.
	 *
	 * Non-restrictive features (fingerprint removal, security headers,
	 * diagnostics) continue to work during bypass - only features that can
	 * lock the user out are affected.
	 */
	public static function is_bypassed(): bool {
		// Layer 1 - wp-config.php constant (fastest check).
		if ( defined( 'CB_CORE_BYPASS' ) && CB_CORE_BYPASS === true ) {
			return true;
		}

		// Layer 2 - Persistent option (set by WP-CLI or panic button).
		if ( get_option( CB_CORE_BYPASS_OPT, false ) === 'emergency' ) {
			return true;
		}

		// Layer 3 - Transient window (set by secret bypass URL).
		if ( get_transient( self::BYPASS_TRANSIENT ) === 'active' ) {
			return true;
		}

		/**
		 * Allow a Base-owned, request-scoped recovery authority to suspend
		 * restrictive features without opening a global failsafe window.
		 */
		return (bool) apply_filters( 'cb_core_failsafe_is_bypassed', false );
	}

	/**
	 * Return which layer(s) are currently active. Used in the admin UI and
	 * audit log to help diagnose which failsafe has been triggered.
	 *
	 * @return array<string, bool> Associative array of layer => active.
	 */
	public static function active_layers(): array {
		return [
			'constant'  => defined( 'CB_CORE_BYPASS' ) && CB_CORE_BYPASS === true,
			'option'    => get_option( CB_CORE_BYPASS_OPT, false ) === 'emergency',
			'transient' => get_transient( self::BYPASS_TRANSIENT ) === 'active',
		];
	}

	// ─── Layer 2 - Persistent emergency bypass ───────────────────────────────

	/**
	 * Activate emergency bypass via a persistent option. Used by:
	 *   - WP-CLI command `wp core-blueprint emergency-disable`
	 *   - Admin panic button
	 *
	 * Persists until explicitly cleared. Audit-logged with actor context.
	 */
	public static function activate_emergency_bypass( string $actor = 'unknown', ?string $reason = null ): void {
		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'failsafe.emergency_activated', 'critical', [
				'actor'   => $actor,
				'reason'  => $reason,
				'layers'  => self::active_layers(),
			] );
		}
	}

	/**
	 * Clear the persistent emergency bypass option. Restrictive features
	 * resume enforcement (unless another layer is still active).
	 */
	public static function deactivate_emergency_bypass( string $actor = 'unknown' ): void {
		delete_option( CB_CORE_BYPASS_OPT );

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'failsafe.emergency_deactivated', 'warning', [
				'actor' => $actor,
			] );
		}
	}

	// ─── Layer 3 - Secret bypass URL ─────────────────────────────────────────

	/**
	 * Ensure a bypass token exists. Called on admin_init so tokens are
	 * generated at install time, not on first bypass attempt.
	 */
	public static function ensure_token(): void {
		if ( ! get_option( CB_CORE_BYPASS_TOK, '' ) ) {
			self::rotate_token();
		}
	}

	/**
	 * Generate a new 64-character token and persist it. Called on ensure_token()
	 * at install, after successful bypass use, and from the admin UI on demand.
	 *
	 * @return string The new plaintext token (shown once in the UI).
	 */
	public static function rotate_token(): string {
		$token = bin2hex( random_bytes( 32 ) ); // 64 chars
		update_option( CB_CORE_BYPASS_TOK, wp_hash_password( $token ), false );

		// A legitimate rotation starts a new token lifecycle. Reset only the
		// fixed rejected-attempt bucket so the first rejection against the new
		// lifecycle is visible while repeated anonymous rejects remain bounded.
		delete_transient( self::REJECT_AUDIT_GATE );

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'failsafe.token_rotated', 'notice', [
				'hint' => substr( $token, 0, 4 ) . '…',
			] );
		}

		return $token;
	}

	/**
	 * Build the bypass URL for display in the admin. The token is visible
	 * exactly once - after that, only the hash is stored. Administrators
	 * must copy the URL to a safe location (password manager).
	 *
	 * @param string $token Plaintext token from rotate_token().
	 */
	public static function build_bypass_url( string $token ): string {
		return add_query_arg( self::BYPASS_PARAM, $token, home_url( '/' ) );
	}

	/**
	 * Handle a hit on the secret bypass URL. Verifies the token, opens a
	 * 60-minute bypass window, rotates the token, and notifies the admin by email.
	 */
	public static function maybe_handle_bypass_url(): void {
		if ( ! isset( $_GET[ self::BYPASS_PARAM ] ) ) {
			return;
		}

		$provided = sanitize_text_field( wp_unslash( $_GET[ self::BYPASS_PARAM ] ) );

		if ( empty( $provided ) || strlen( $provided ) !== 64 ) {
			self::log_bypass_attempt( false, 'malformed' );
			return; // Silent failure - do not confirm/deny existence.
		}

		$stored_hash = get_option( CB_CORE_BYPASS_TOK, '' );

		if ( empty( $stored_hash ) ) {
			self::log_bypass_attempt( false, 'no_token' );
			return;
		}

		if ( ! wp_check_password( $provided, $stored_hash ) ) {
			self::log_bypass_attempt( false, 'invalid' );
			return;
		}

		// ── Valid token: open the bypass window.
		set_transient( self::BYPASS_TRANSIENT, 'active', self::BYPASS_WINDOW );

		// ── Rotate the token so this same URL cannot be reused.
		self::rotate_token();

		// ── Audit-log the activation.
		self::log_bypass_attempt( true, 'activated' );

		// ── Notify the admin by email.
		self::notify_admin_of_bypass();

		// ── Show a minimal confirmation page (no WP chrome to avoid theme errors).
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!DOCTYPE html><html><head><title>Core Blueprint - Bypass Active</title>';
		echo '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:80px auto;padding:0 20px;color:#1d2327}h1{color:#d63638}code{background:#f0f0f1;padding:2px 6px;border-radius:3px}</style>';
		echo '</head><body>';
		echo '<h1>Core Blueprint - Emergency Bypass Active</h1>';
		echo '<p>All restrictive security features are disabled for the next <strong>60 minutes</strong>.</p>';
		echo '<p>An email notification has been sent to the site administrator.</p>';
		echo '<p>The bypass token has been rotated and is no longer valid. Generate a new token from the Core Blueprint admin panel after resolving the issue.</p>';
		echo '<p><a href="' . esc_url( admin_url() ) . '">Continue to admin area</a></p>';
		echo '</body></html>';
		exit;
	}

	private static function log_bypass_attempt( bool $success, string $reason ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}

		// Rejected hits are anonymous and attacker-controlled. Keep visibility of
		// the first event while bounding database writes during a request flood.
		// One fixed key is intentional: never derive transient keys from IPs or
		// supplied tokens, which would let an attacker create unbounded state.
		if ( ! $success ) {
			if ( false !== get_transient( self::REJECT_AUDIT_GATE ) ) {
				return;
			}
			set_transient( self::REJECT_AUDIT_GATE, 'active', self::REJECT_AUDIT_WINDOW );
		}

		$context = [
			'reason'     => $reason,
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '',
		];
		if ( ! $success ) {
			$context['suppression_window_seconds'] = self::REJECT_AUDIT_WINDOW;
		}

		AuditLog::log(
			$success ? 'failsafe.bypass_url_used' : 'failsafe.bypass_url_rejected',
			$success ? 'critical' : 'warning',
			$context
		);
	}

	/**
	 * Notify the site administrator that a bypass was just activated.
	 * Sent to the email stored in the 'admin_email' option.
	 */
	private static function notify_admin_of_bypass(): void {
		$admin_email = get_option( 'admin_email' );

		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();
		$time      = wp_date( 'Y-m-d H:i:s T' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Core Blueprint emergency bypass activated', 'core-blueprint' ), $site_name );

		$body  = __( 'The Core Blueprint emergency bypass URL has been used on your site.', 'core-blueprint' ) . "\n\n";
		$body .= __( 'Site:', 'core-blueprint' ) . ' ' . $site_url . "\n";
		$body .= __( 'Time:', 'core-blueprint' ) . ' ' . $time . "\n\n";
		$body .= __( 'All restrictive security features are temporarily disabled for 60 minutes.', 'core-blueprint' ) . "\n\n";
		$body .= __( 'If you did not trigger this bypass, log into the admin panel immediately and:', 'core-blueprint' ) . "\n";
		$body .= __( '  1. Change your administrator password.', 'core-blueprint' ) . "\n";
		$body .= __( '  2. Rotate the Core Blueprint bypass token.', 'core-blueprint' ) . "\n";
		$body .= __( '  3. Review the Core Blueprint audit log for suspicious activity.', 'core-blueprint' ) . "\n\n";
		$body .= __( '- Core Blueprint', 'core-blueprint' );

		wp_mail( $admin_email, $subject, $body );
	}

	/**
	 * Close the active bypass window immediately. Used when the admin wants
	 * to re-enable protections before the 60-minute window expires.
	 */
	public static function close_bypass_window(): void {
		delete_transient( self::BYPASS_TRANSIENT );

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'failsafe.window_closed', 'notice', [] );
		}
	}

	// ─── Self-test - verify all layers are functional ────────────────────────

	/**
	 * Run a non-destructive self-test of the failsafe infrastructure.
	 * Used by the WP-CLI `test-failsafe` command and the admin diagnostic panel.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public static function self_test(): array {
		$results = [];

		// Check 1: Can we read/write options?
		$test_key = 'cb_core_failsafe_test_' . time();
		$write_ok = update_option( $test_key, 'ok', false );
		$read_ok  = get_option( $test_key ) === 'ok';
		delete_option( $test_key );
		$results['options'] = [
			'ok'      => $write_ok && $read_ok,
			'message' => ( $write_ok && $read_ok )
				? __( 'Option read/write works.', 'core-blueprint' )
				: __( 'Could not read/write options - failsafe Layer 2 may not work.', 'core-blueprint' ),
		];

		// Check 2: Is a bypass token generated?
		$token_exists = (bool) get_option( CB_CORE_BYPASS_TOK, '' );
		$results['token'] = [
			'ok'      => $token_exists,
			'message' => $token_exists
				? __( 'Bypass token is present.', 'core-blueprint' )
				: __( 'No bypass token - generate one from the admin panel.', 'core-blueprint' ),
		];

		// Check 3: Can we set/read transients?
		$transient_key = 'cb_core_failsafe_t_' . time();
		set_transient( $transient_key, 'ok', 60 );
		$transient_ok = get_transient( $transient_key ) === 'ok';
		delete_transient( $transient_key );
		$results['transients'] = [
			'ok'      => $transient_ok,
			'message' => $transient_ok
				? __( 'Transient read/write works.', 'core-blueprint' )
				: __( 'Transients not functional - failsafe Layer 3 cannot open a bypass window.', 'core-blueprint' ),
		];

		// Check 4: Is wp_mail configured?
		$mail_ok = function_exists( 'wp_mail' );
		$results['mail'] = [
			'ok'      => $mail_ok,
			'message' => $mail_ok
				? __( 'wp_mail() is available for bypass notifications.', 'core-blueprint' )
				: __( 'wp_mail() missing - bypass notifications will not send.', 'core-blueprint' ),
		];

		// Check 5: Is random_bytes available?
		$random_ok = function_exists( 'random_bytes' );
		$results['random'] = [
			'ok'      => $random_ok,
			'message' => $random_ok
				? __( 'random_bytes() available for secure token generation.', 'core-blueprint' )
				: __( 'random_bytes() missing - cannot generate secure tokens.', 'core-blueprint' ),
		];

		return $results;
	}
}
