<?php
declare(strict_types=1);
namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

/**
 * EmailAlerts
 *
 * Sends administrator email notifications when audit events of selected
 * severity levels are written. Consumes the 'cb_core_audit_log_written'
 * action fired by AuditLog::log().
 *
 * Design decisions:
 *   - THROTTLING: a given event_type can only trigger one email per
 *     EmailAlerts::THROTTLE_SECONDS window. Prevents a burst of
 *     failed-login events (or similar) from spamming the inbox and
 *     becoming noise.
 *   - CONFIGURATION: uses the existing CB_CORE_SETTINGS['audit']['email_alerts']
 *     block that was scaffolded in 1.0.0. Per-severity toggles.
 *   - RECIPIENT: three-layer resolution.
 *       1. DEFAULT: site admin_email option.
 *       2. UI OVERRIDE: the 'audit.email_recipient' settings key, set from
 *          Preferences > Notifications. Comma-separated list supported.
 *          Empty falls back to the default.
 *       3. DEVELOPER FILTER: 'cb_core_alert_recipient' runs last on the
 *          resolved value, so integrators can still override the UI choice
 *          (multi-admin routing, external ticketing forwarders, etc.).
 *      See ::resolve_recipients() for the canonical implementation.
 *
 * Multi-group convention (documented for future sibling plugins, 1.0.20):
 * Each notification group claims its own subtree under CB_CORE_SETTINGS.
 * The audit group uses `audit.email_recipient` + `audit.email_alerts`.
 * A future CB Hub pairing group would use `hub.email_recipient` + its own
 * event-category toggles under `hub.email_alerts`. A CB License group
 * would use `license.email_recipient`. One AJAX handler
 * (`cb_core_set_alert_recipient`) accepts a `group` parameter with an
 * allowlist - no per-group handler duplicates needed. When a new group
 * lands, three things happen: (1) add it to the allowlist in
 * Ajax\Handlers\Settings::set_alert_recipient, (2) render a matching
 * `<section class="cb-core-notification-group">` in
 * templates/notifications.php, (3) register the default shape in
 * Settings::defaults(). No new classes, no filter infrastructure - the
 * UI scales through markup + settings keys, nothing else.
 *   - CONTENT: deliberately sparse - subject carries the signal, body
 *     summarises context. No secrets are ever included (handled by the
 *     log layer; alerts merely render what was recorded).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


final class EmailAlerts {

	/** Throttle: at most one email per event_type per window (15 minutes). */
	const THROTTLE_SECONDS = 900;

	/** Transient key prefix used for throttling. */
	const THROTTLE_PREFIX = 'cb_core_alert_';

	/**
	 * Reentrancy guard. Set for the duration of a single send() call so that
	 * audit events triggered by the act of sending (e.g. wp_mail logging) do
	 * not recursively re-enter maybe_alert(). Reset after each send so that
	 * multiple distinct events within the same request can all be processed.
	 */
	private static bool $sending = false;

	/**
	 * Alerts emitted before WordPress reaches `init` are kept in memory and
	 * delivered as soon as translations are safe to resolve. Security events
	 * themselves remain synchronously persisted by AuditLog; only the email
	 * presentation layer is deferred for the remainder of the same request.
	 *
	 * @var array<int,array{event_type:string,severity:string,context:array,insert_id:int,group:string}>
	 */
	private static array $pending = [];

	// ─── Bootstrap ───────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'cb_core_audit_log_written', [ __CLASS__, 'maybe_alert' ], 10, 4 );
		add_action( 'init', [ __CLASS__, 'flush_pending' ], 2 );
	}

	// ─── Handler ─────────────────────────────────────────────────────────────

	/**
	 * Consumer of cb_core_audit_log_written. Decides whether to send a mail
	 * based on severity config and throttle state.
	 *
	 * @param int    $insert_id
	 * @param string $event_type
	 * @param string $severity
	 * @param array  $context
	 */
	public static function maybe_alert( int $insert_id, string $event_type, string $severity, array $context ): void {
		// Reentrancy guard: skip if we are already inside a send for another
		// event in this call stack. The audit log written during send() would
		// otherwise loop back through this handler.
		if ( self::$sending ) {
			return;
		}

		$decision = self::should_alert( $event_type, $severity );
		if ( null === $decision ) {
			return;
		}

		if ( self::is_throttled( $event_type, $context ) ) {
			return;
		}

		self::mark_throttled( $event_type, $context );

		// WordPress 6.7+ warns when a custom text domain is resolved before
		// `init`. Governance events can legitimately be written on
		// `plugins_loaded` (privileged-access bootstrap, version lifecycle,
		// migrations), so keep logging synchronous but defer the translated
		// email presentation until Core::load_textdomain() has run at init p0.
		if ( ! did_action( 'init' ) ) {
			self::$pending[] = [
				'event_type' => $event_type,
				'severity'   => $severity,
				'context'    => $context,
				'insert_id'  => $insert_id,
				'group'      => $decision,
			];
			return;
		}

		self::deliver( $event_type, $severity, $context, $insert_id, $decision );
	}

	/**
	 * Deliver any alert captured before init. Hooked at init priority 2; Core
	 * loads the text domain at priority 0, so all translated mail strings are
	 * resolved within WordPress' supported lifecycle.
	 */
	public static function flush_pending(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$pending      = self::$pending;
		self::$pending = [];

		foreach ( $pending as $alert ) {
			self::deliver(
				$alert['event_type'],
				$alert['severity'],
				$alert['context'],
				$alert['insert_id'],
				$alert['group']
			);
		}
	}

	private static function deliver( string $event_type, string $severity, array $context, int $insert_id, string $group ): void {
		if ( self::$sending ) {
			return;
		}

		self::$sending = true;
		try {
			self::send( $event_type, $severity, $context, $insert_id, $group );
		} finally {
			self::$sending = false;
		}
	}

	// ─── Configuration check ────────────────────────────────────────────────

	/**
	 * Decide whether to alert on a given event, and via which routing group.
	 *
	 * Two-track dispatch:
	 *
	 *   1. **Group-specific event subscriptions** (preferred for v1.1+ events).
	 *      Events with a known prefix (`permissions.*`, `reports.*`) are routed
	 *      via that group's `email_alerts` toggles. Group-specific subscriptions
	 *      use semantic alert keys ("role_change", "generation_failed", …) that
	 *      may map to multiple raw event types - see classify_event() below.
	 *
	 *   2. **Severity-based subscription** (legacy fallback for v1.0 events).
	 *      Anything not matched by a group is dispatched on `audit.email_alerts.{severity}`.
	 *      This preserves the v1.0 contract for security-related events that
	 *      pre-date the per-feature notification settings.
	 *
	 * Returns the routing group on a hit (so send() can resolve recipients
	 * per-group), or null if no subscription matches.
	 *
	 * @return string|null one of 'permissions' | 'reports' | 'audit', or null when no alert.
	 */
	private static function should_alert( string $event_type, string $severity ): ?string {
		$settings = get_option( CB_CORE_SETTINGS, [] );
		if ( ! is_array( $settings ) ) {
			return null;
		}

		[ $group, $alert_key ] = self::classify_event( $event_type );

		if ( null !== $group && null !== $alert_key ) {
			$enabled = ! empty( $settings[ $group ]['email_alerts'][ $alert_key ] );
			return $enabled ? $group : null;
		}

		// Fallback: severity-based audit routing.
		$enabled = ! empty( $settings['audit']['email_alerts'][ $severity ] );
		return $enabled ? 'audit' : null;
	}

	/**
	 * Map a raw event_type slug to a (group, alert_key) tuple, or [null, null]
	 * if the event has no group-specific routing and falls back to severity.
	 *
	 * Multiple raw events can share an alert key - for instance, both
	 * `permissions.operator_added` and `permissions.operator_removed` route
	 * via the `role_change` umbrella so a single toggle covers both directions.
	 *
	 * @return array{0:string|null, 1:string|null}
	 */
	private static function classify_event( string $event_type ): array {
		// Permissions group.
		$role_change_events = array_map( [ AuditLog::class, 'normalize_event_type' ], [
			'permissions.operator_added',
			'permissions.operator_removed',
			'permissions.first_operator_assigned',
		] );
		if ( in_array( $event_type, $role_change_events, true ) ) {
			return [ 'permissions', 'role_change' ];
		}
		if ( AuditLog::normalize_event_type( 'permissions.operator_guard_triggered' ) === $event_type ) {
			return [ 'permissions', 'operator_guard_triggered' ];
		}
		if ( AuditLog::normalize_event_type( 'permissions.privileged_user_review_required' ) === $event_type ) {
			return [ 'permissions', 'privileged_review' ];
		}

		// Reports group. AuditLog normalizes dotted event slugs before dispatch.
		if ( AuditLog::normalize_event_type( 'reports.generation_failed' ) === $event_type ) {
			return [ 'reports', 'generation_failed' ];
		}

		// Core Scanner group. Scan-completed audit entries stay informational;
		// only lifecycle transitions are notification events.
		if ( AuditLog::normalize_event_type( 'integrity_scan_critical_anomalies_detected' ) === $event_type ) {
			return [ 'integrity', 'critical_anomaly' ];
		}
		if ( AuditLog::normalize_event_type( 'integrity_scan_warning_anomalies_detected' ) === $event_type ) {
			return [ 'integrity', 'warning_anomaly' ];
		}
		if ( AuditLog::normalize_event_type( 'integrity_scan_anomalies_resolved' ) === $event_type ) {
			return [ 'integrity', 'resolved' ];
		}

		return [ null, null ];
	}

	// ─── Throttle helpers ───────────────────────────────────────────────────

	private static function is_throttled( string $event_type, array $context = [] ): bool {
		$key = self::throttle_key( $event_type, $context );
		return (bool) get_transient( $key );
	}

	private static function mark_throttled( string $event_type, array $context = [] ): void {
		set_transient( self::throttle_key( $event_type, $context ), 1, self::THROTTLE_SECONDS );
	}

	private static function throttle_key( string $event_type, array $context = [] ): string {
		$scope = $event_type;

		// Privileged-account review is identity-specific. Two different
		// suspicious admins created within 15 minutes must each generate a mail;
		// repeated detections of the same user/fingerprint remain throttled.
		if ( AuditLog::normalize_event_type( 'permissions.privileged_user_review_required' ) === $event_type ) {
			$scope .= '|' . (string) (int) ( $context['user_id'] ?? 0 );
			$scope .= '|' . (string) ( $context['fingerprint'] ?? '' );
		}

		// Scanner lifecycle events carry a stable incident key. Two distinct
		// anomaly sets within 15 minutes must each be deliverable, while an
		// accidental duplicate write of the same lifecycle event stays throttled.
		if ( str_starts_with( $event_type, 'integrity_scan_' ) && ! empty( $context['incident_key'] ) ) {
			$scope .= '|' . (string) $context['incident_key'];
		}

		// Hash to stay within option-name length constraints on long event names.
		return self::THROTTLE_PREFIX . substr( md5( $scope ), 0, 12 );
	}

	// ─── Send ────────────────────────────────────────────────────────────────

	private static function send( string $event_type, string $severity, array $context, int $insert_id, string $group = 'audit' ): void {
		$recipient = self::resolve_recipients( $group );

		if ( '' === $recipient ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$label = class_exists( AuditLog::class )
			? AuditLog::event_label( $event_type )
			: $event_type;

		if ( AuditLog::normalize_event_type( 'permissions.privileged_user_review_required' ) === $event_type ) {
			/* translators: %s: site name */
			$subject = sprintf( __( '[Core Blueprint][SECURITY] Privileged account requires review - %s', 'core-blueprint' ), $site_name );
		} else {
			$subject = sprintf(
				/* translators: 1: severity in uppercase, 2: site name */
				__( '[Core Blueprint][%1$s] %2$s', 'core-blueprint' ),
				strtoupper( $severity ),
				$site_name
			);
		}

		$body  = sprintf( /* translators: %s: event label */
			__( 'Event: %s', 'core-blueprint' ),
			$label
		) . "\n";
		$body .= sprintf( /* translators: %s: raw event type slug */
			__( 'Type: %s', 'core-blueprint' ),
			$event_type
		) . "\n";
		$body .= sprintf( /* translators: %s: severity level */
			__( 'Severity: %s', 'core-blueprint' ),
			$severity
		) . "\n";
		$body .= __( 'Site:', 'core-blueprint' ) . ' ' . $site_url . "\n";
		$body .= __( 'Time:', 'core-blueprint' ) . ' ' . wp_date( 'Y-m-d H:i:s T' ) . "\n";

		if ( ! empty( $context ) ) {
			$body .= "\n" . __( 'Context:', 'core-blueprint' ) . "\n";
			foreach ( $context as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$body .= '  ' . $key . ': ' . (string) $value . "\n";
				} elseif ( is_array( $value ) ) {
					$body .= '  ' . $key . ': ' . wp_json_encode( $value ) . "\n";
				}
			}
		}

		$body .= "\n" . sprintf( /* translators: %d: audit log entry id */
			__( 'Audit log entry ID: %d', 'core-blueprint' ),
			$insert_id
		) . "\n";

		$audit_url = admin_url( 'admin.php?page=' . \CoreBlueprint\Core\Admin\Admin::LOGS_SLUG . '&tab=audit' );
		$body     .= __( 'View audit log:', 'core-blueprint' ) . ' ' . $audit_url . "\n\n";
		$body     .= __( 'This alert was throttled to at most one notification per event type per 15 minutes. Additional events of the same type within that window were not emailed - check the audit log for the complete record.', 'core-blueprint' ) . "\n\n";
		$body     .= '- Core Blueprint';

		wp_mail( $recipient, $subject, $body );
	}

	// ─── Recipient resolution ──────────────────────────────────────────────

	/**
	 * Resolve the effective recipient string for alert emails.
	 *
	 * Resolution order:
	 *   1. **Group-specific override** (`{group}.email_recipient`). When set
	 *      and non-empty, takes priority - lets operators route permissions
	 *      events to a different mailbox than security audits, for instance.
	 *   2. **Audit-tab override** (`audit.email_recipient`). The legacy
	 *      "general CB recipient" field - applies to any group that has no
	 *      override of its own.
	 *   3. **DEFAULT**: `admin_email` (WP core "site administrator").
	 *   4. **FILTER**: `cb_core_alert_recipient` runs last so integrators can
	 *      still override the UI choice (signature unchanged from 1.0.20).
	 *
	 * Returns a comma-separated string suitable for wp_mail() - wp_mail()
	 * accepts either a single address or a comma-separated list, so
	 * callers do not need to split or array-ify.
	 *
	 * Returns '' when nothing is deliverable (empty overrides AND empty
	 * admin_email). Callers must check before handing off to wp_mail.
	 *
	 * @param string $group Routing group: 'audit' (default), 'permissions', 'reports'.
	 */
	public static function resolve_recipients( string $group = 'audit' ): string {
		$settings = get_option( CB_CORE_SETTINGS, [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$group_override = ( 'audit' !== $group && isset( $settings[ $group ]['email_recipient'] ) )
			? trim( (string) $settings[ $group ]['email_recipient'] )
			: '';

		$audit_override = isset( $settings['audit']['email_recipient'] )
			? trim( (string) $settings['audit']['email_recipient'] )
			: '';

		// Layered fallback: group override → audit override → admin_email.
		if ( '' !== $group_override ) {
			$raw = $group_override;
		} elseif ( '' !== $audit_override ) {
			$raw = $audit_override;
		} else {
			$raw = (string) get_option( 'admin_email', '' );
		}

		// Split on comma, validate each piece, keep valid ones - partial
		// validity is better than all-or-nothing (if one comma-entry is
		// malformed, the rest still get their alert).
		$clean = self::sanitize_recipients( $raw );

		// Layer 3: developer filter. Passes through whether the caller
		// wants a single address or a comma-joined string.
		/** This filter predates the 1.0.20 UI and is preserved verbatim. */
		$filtered = apply_filters( 'cb_core_alert_recipient', $clean );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Split a raw comma-separated recipient string into a comma-joined
	 * string of only the valid email addresses. Whitespace-tolerant;
	 * de-duplicates case-insensitively so "a@b.com, A@B.COM" yields one.
	 *
	 * Exposed publicly so the Retention tab's AJAX save handler can use
	 * exactly the same logic - the recipient that ends up in settings is
	 * the same shape the sender will eventually consume.
	 */
	public static function sanitize_recipients( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$parts = array_map( 'trim', explode( ',', $raw ) );
		$valid = [];
		$seen  = [];
		foreach ( $parts as $addr ) {
			if ( '' === $addr ) {
				continue;
			}
			if ( ! is_email( $addr ) ) {
				continue;
			}
			$key = strtolower( $addr );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$valid[]      = $addr;
		}

		return implode( ', ', $valid );
	}
}
