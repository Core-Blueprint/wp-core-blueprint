<?php
declare(strict_types=1);
/**
 * Language - plain/technical rendering helper for log rows.
 *
 * Core Blueprint's logs run on two parallel tracks:
 *
 *   - Technical: raw event_type slugs ("system.plugin_activated"), HTTP
 *                methods + paths ("GET /status"), raw status codes ("200"),
 *                context JSON as key=value pairs. This is what an auditor
 *                or developer wants to see - unambiguous, copy-pasteable,
 *                machine-grep-able.
 *
 *   - Plain: human-readable sentences ("Plugin 'Yoast SEO' was activated",
 *            "Hub status check completed successfully"). This is what a
 *            care-home administrator or a municipal clerk wants to see -
 *            no jargon, no paths, same information but packaged readably.
 *
 * Data-wise, both tracks show the same thing. Technical details (duration,
 * source IP, actor, status badge) appear in both modes - the AVG-compliant
 * contract is "no information is hidden, only jargon is repackaged". The
 * only thing plain-mode strips is raw event_type slugs, raw endpoint paths,
 * and raw HTTP codes - replaced by equivalents the reader can understand
 * without domain knowledge.
 *
 * This class is the single source of truth for those equivalents. The
 * catalogs live here as class constants (EVENTS_PLAIN, ENDPOINTS_PLAIN,
 * STATUS_PLAIN) so adding a new event type or Beacon endpoint means
 * editing one file, not hunting through templates.
 *
 * Consumer pattern:
 *
 *     use CB\Core\Log\Language;
 *     use CB\Core\UI;
 *
 *     $mode = UI::current_mode(); // 'plain' | 'technical' | 'sync'
 *     $line = Language::describe_event( $row->event_type, $row->context_decoded, $mode );
 *
 * All public methods accept a mode parameter and return strings ready to
 * render. Missing translations fall back to the technical form - there's
 * no silent data loss if a new event type ships before its plain label.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Language {

	/**
	 * Plain-language descriptions for AuditLog + SystemLog event types.
	 *
	 * Values are either:
	 *   - A static string ("Successful login")
	 *   - A template string with {placeholder} tokens that resolve against
	 *     the event's context array ("Plugin '{plugin}' was activated")
	 *
	 * When a placeholder has no value in context, it's replaced with "-"
	 * rather than leaving "{plugin}" in the output - keeps plain mode
	 * readable even when older rows lack the richer context format.
	 */
	const EVENTS_PLAIN = [
		// Plugin lifecycle
		'plugin.activated'                   => 'A plugin was activated',
		'plugin.deactivated'                 => 'A plugin was deactivated',

		// System log (mirror of plugin.* but with more context)
		'system.plugin_activated'            => "Plugin '{plugin}' was activated",
		'system.plugin_deactivated'          => "Plugin '{plugin}' was deactivated",
		'system.plugin_installed'            => "Plugin '{plugin}' was installed",
		'system.plugin_updated'              => "Plugin '{plugin}' was updated",
		'system.plugin_deleted'              => "Plugin '{plugin}' was removed",
		'system.theme_switched'              => "Theme was switched from '{from}' to '{to}'",
		'system.theme_installed'             => "Theme '{theme}' was installed",
		'system.theme_updated'               => "Theme '{theme}' was updated",
		'system.theme_deleted'               => "Theme '{theme}' was removed",
		'system.core_updated'                => 'WordPress was updated to version {version}',
		'system.user_created'                => "User '{user_login}' was created",
		'system.user_deleted'                => "User '{user_login}' was removed",
		'system.user_role_changed'           => "User '{user_login}' role was changed to {role}",

		// Settings + configuration
		'settings.changed'                   => 'Setting changed: {key}',
		'settings.module_toggled'            => 'A security module was turned on or off',
		'settings.feature_toggled'           => 'A security feature was turned on or off',
		'settings.defaults_applied'          => 'Recommended security defaults were applied',
		'settings.migrated'                  => 'Settings were migrated to a new schema',
		'settings.migration_failed'          => 'Settings migration ran into a problem',

		// Failsafe / emergency bypass
		'failsafe.emergency_activated'       => 'Emergency bypass was activated - all restrictions temporarily off',
		'failsafe.emergency_deactivated'     => 'Emergency bypass was turned off - restrictions resumed',
		'failsafe.window_closed'             => 'Active bypass window was closed',
		'failsafe.bypass_url_used'           => 'Secret bypass URL was used to regain access',
		'failsafe.bypass_url_rejected'       => 'An attempt to use a secret bypass URL was rejected',
		'failsafe.token_rotated'             => 'Bypass token was rotated (new secret generated)',

		// Login
		'login.success'                      => 'Successful sign-in',
		'login.failed'                       => 'Sign-in attempt failed',
		'system.login'                       => "Successful sign-in by '{user_login}'",

		// Diagnostic / maintenance
		'diagnostic.header_test'             => 'Security header test was run',
		'audit.exported'                     => 'Audit log was downloaded as CSV',
		'audit.pruned'                       => 'Old audit log entries were deleted (retention cleanup)',
		'audit.prune_failed'                 => 'Audit log retention cleanup ran into a problem',
		'module.boot_failed'                 => 'A security module failed to start',
		'security.password_reconfirm_failed' => 'A password re-check failed for a sensitive action',

		// UI preferences
		'ui.site_mode_changed'               => 'Description mode site default was changed',

		// Privacy
		'privacy.settings_updated'           => 'Privacy settings were updated',
		'privacy.ip_mode_changed'            => 'IP-address logging mode was changed from {from} to {to}',
		'privacy.preset_applied'             => "Privacy preset '{preset}' was applied",

		// Access mode
		'access_mode.changed'                => 'Site access mode was changed from {from} to {to}',

		// Console
		'console.executed'                   => "Console command '{command_name}' was run",

		// Login Shield
		'login.url_changed'                  => "Login URL was changed from '{from}' to '{to}'",
		'login.shield_enabled'               => 'Login Shield was enabled',
		'login.shield_disabled'              => 'Login Shield was disabled',
		'login.route_blocked'                => 'A blocked login attempt was rejected',

		// Beacon (CLI + redirect signing)
		'beacon.cli_ping'                    => 'Hub connectivity test was run from the command line',
		'beacon.redirect.key_rotated'        => 'Hub redirect signing key was rotated',
		'beacon.redirect.minted'             => 'Hub redirect token was issued',
		'beacon.redirect.consumed'           => 'Hub redirect token was used',
		'beacon.redirect.rate_limited'       => 'Hub redirect token request was rate-limited',

		// Bulk module toggles
		'settings.modules_bulk_toggled'      => '{count} security module(s) were turned on or off in bulk',

		// Permissions - operators
		'permissions.first_operator_assigned'        => "User '{user_login}' was assigned as the first Operator",
		'permissions.operator_added'                 => "User '{user_login}' was promoted to Operator",
		'permissions.operator_removed'               => "User '{user_login}' was removed as Operator",
		'permissions.operator_guard_triggered'       => 'Operator-Guard prevented an unsafe permissions change ({reason})',
		'permissions.operator_role_recreated'        => 'Operator role definition was restored',

		// Permissions - admin caps + page visibility
		'permissions.admin_caps_changed'     => 'Administrator privileges were updated',
		'permissions.hide_changed'           => 'Permissions tab visibility was changed',
		'permissions.hide_toggled'           => 'Permissions tab visibility was toggled',

		// Permissions - drift monitor (background changes detected on user role mutations)
		'permissions.role_added'             => "Role '{role}' was added to a user",
		'permissions.role_removed'           => "Role '{role}' was removed from a user",
		'permissions.role_set'               => "A user's role was changed to '{new_role}'",
		'permissions.user_deleted'           => "User '{user_login}' was deleted from the site",

		// Reports - generation
		'reports.maintenance_generated'      => 'A maintenance report was generated',
		'reports.generation_failed'          => 'A maintenance report could not be generated ({reason})',
		'reports.pdf_render_failed'          => 'A maintenance report PDF could not be rendered ({reason})',
		'reports.maintenance_downloaded'     => 'A maintenance report was downloaded',

		// Reports - branding + housekeeping
		'reports.branding_updated'           => 'Report branding was updated',
		'reports.branding_reset'             => 'Report branding was reset to defaults',
		'reports.deleted'                    => 'A maintenance report was deleted',
		'reports.bulk_deleted'               => '{count} reports were deleted in bulk',

		// Log exports
		'maintenance_report.exported'        => 'Maintenance report was downloaded as {format}',
		'system_log.exported'                => 'System log was downloaded as {format}',

		// System events not covered above
		'system.foundation_installed'        => 'Core Blueprint Foundation {version} was installed',
		'system.foundation_upgraded'         => 'Core Blueprint Foundation was upgraded from {from} to {to}',
		'system.login_failed'                => "Sign-in attempt failed for '{user_login}'",
		'system.option_changed'              => "Setting '{option_name}' was changed",

		// Core Scanner (Integrity) - dotless slugs because emitted via $this->audit() wrapper
		'integrity_scan_started'                 => 'Core Scanner: scan started',
		'integrity_scan_completed'               => 'Core Scanner: scan completed',
		'integrity_scan_failed'                  => 'Core Scanner: scan failed ({message})',
		'integrity_settings_changed'             => 'Core Scanner settings were updated',
		'integrity_results_cleared'              => 'Core Scanner scan results were cleared',
		'integrity_baseline_approved'            => 'Core Scanner baseline was approved ({entry_count} entries)',
		'integrity_baseline_cleared'             => 'Core Scanner baseline was cleared',
		'integrity_baseline_entry_removed'       => "Core Scanner baseline entry was removed ({type} '{slug}')",
		'integrity_component_baseline_approved'  => "Core Scanner: a {type} baseline was approved for '{slug}' ({entry_count} entries)",
		'integrity_distribution_locale_changed'  => 'Core Scanner: WordPress distribution locale was set ({mode})',
		'integrity_distribution_locale_detected' => "Core Scanner detected the WordPress distribution locale: {detected}",
		'integrity.quarantine.restore.acknowledged' => 'Backup and recovery responsibility was acknowledged before a quarantined item was restored',

		// Notes
		'note_created'         => 'A note was created',
		'note_updated'         => 'A note was updated',
		'note_deleted'         => 'A note was deleted',
		'note_archived'        => 'A note was archived',
		'note_duplicated'      => 'A note was duplicated',
		'note_status_changed'  => "A note's status was changed to '{status}'",
		'notes_bulk_deleted'   => 'All {count} note(s) were deleted in bulk',
		'notes_exported'       => '{count} note(s) were exported as JSON',
		'notes_imported'       => 'Notes import completed (created: {created}, overwritten: {overwritten}, copied: {copied}, skipped: {skipped})',
		'notes.import.overwrite.acknowledged' => 'Backup and recovery responsibility was acknowledged before existing Notes were overwritten',

		// Consequential mutation acknowledgements
		'media.replace.acknowledged' => 'Backup and recovery responsibility was acknowledged before a media file was replaced',
		'content.models.schema.import.acknowledged' => 'Backup and recovery responsibility was acknowledged before a Content Models schema was imported',
		'content.models.native.import.acknowledged' => 'Backup and recovery responsibility was acknowledged before WordPress schema registrations were adopted',
		'snippets.restore.acknowledged' => 'Backup and recovery responsibility was acknowledged before snippets were restored with preserved IDs',
	];

	/**
	 * Plain-language descriptions for Beacon REST endpoints, keyed by the
	 * trailing path segment. A request to `GET /core-blueprint/v1/status`
	 * has endpoint="/status" in the Connection Log - the leading
	 * namespace is already stripped before storage.
	 *
	 * The Hub is the only legitimate caller of these endpoints, so the
	 * plain wording reflects that: "Hub requested …" rather than generic
	 * "Someone requested …".
	 */
	const ENDPOINTS_PLAIN = [
		'/status'           => 'Hub status check',
		'/ping'             => 'Hub ping',
		'/backup/list'      => 'Hub listed the backups',
		'/backup/start'     => 'Hub requested a new backup',
		'/backup/status'    => 'Hub checked backup progress',
		'/backup/download'  => 'Hub downloaded a backup file',
		'/backup/delete'    => 'Hub removed a backup file',
		'/update/inventory' => 'Hub asked what updates are available',
		'/update/apply'     => 'Hub applied pending updates',
		'/update/status'    => 'Hub checked update progress',
	];

	/**
	 * HTTP status-code plain equivalents. Only the families that matter
	 * for a Core Blueprint audit context - 2xx, 4xx, 5xx. 1xx + 3xx
	 * never surface in the Connection Log (auth + endpoint completion
	 * always lands in 2xx/4xx/5xx territory).
	 */
	const STATUS_PLAIN = [
		200 => 'Succeeded',
		201 => 'Succeeded (new item created)',
		204 => 'Succeeded (no content)',
		400 => 'Rejected (bad request)',
		401 => 'Blocked (authentication failed)',
		403 => 'Blocked (not allowed)',
		404 => 'Not found',
		409 => 'Conflict',
		429 => 'Rate-limited',
		500 => 'Server error',
		502 => 'Upstream error',
		503 => 'Service unavailable',
	];

	/**
	 * Per-log descriptions rendered above each log viewer. Plain and
	 * Technical variants of the same message so the page description
	 * switches with the Plain/Technical toggle in the filter bar.
	 *
	 * Keys match the log "kind" slug used in templates:
	 *   'audit'        - CB-internal events
	 *   'system'       - WordPress lifecycle events
	 *   'connection'   - Beacon inbound REST requests
	 *   'maintenance'  - aggregated client-facing report
	 *
	 * Values: ['plain' => ..., 'technical' => ...]. Keep each string to
	 * 2-3 short sentences - it's a page intro, not a manual entry.
	 *
	 * These are intentionally verbose in NL-leaning Plain mode because
	 * Peter's non-technical buyers (zorg, gemeente) read them first to
	 * understand what the plugin logs before they sign anything.
	 */
	const LOG_DESCRIPTIONS = [
		'audit' => [
			'plain'     => 'Shows what happens inside Core Blueprint itself: when someone changes settings, switches modules on or off, activates the emergency bypass, or signs in. Use this log for accountability ("who did what, when") and to quickly spot unusual activity.',
			'technical' => 'Append-only event store for CB-internal actions. Writes from AuditLog::log() across the plugin: settings mutations, failsafe state transitions, module lifecycle, privacy config changes, module boot errors, export actions. Schema: event_type + severity + user_id + ip_address + user_agent + context JSON + created_at.',
		],
		'system' => [
			'plain'     => 'Tracks changes to WordPress itself: plugins and themes being installed, updated or removed, new users being created, role changes, and core WordPress updates. Deliberately excludes normal website activity like publishing posts or visitor logins - those are content actions, not maintenance.',
			'technical' => 'WordPress core lifecycle recorder. Subscribes to plugin/theme/user/core hooks and emits system.* events into the shared audit_log table. Covers plugin_activated / _deactivated / _installed / _updated / _deleted, theme_switched / _installed / _updated / _deleted, core_updated, user_created / _deleted / _role_changed, login, login_failed, option_changed.',
		],
		'connection' => [
			'plain'     => 'Records every time your central management console (the Hub) talks to this site: when, which request, from which IP address, and whether it succeeded. This way you can always see what was done remotely on your site.',
			'technical' => 'Black-box recorder for inbound REST requests on the core-blueprint/v1 namespace. Captures timestamp, endpoint, HTTP method, source IP, user-agent, HTTP status, request duration, and a JSON context blob per request. Append-only, separate table (cb_core_beacon_connection_log), retention independent from the audit log.',
		],
		'maintenance' => [
			'plain'     => 'A client-facing summary that combines local updates (plugins, themes, WordPress) with work done remotely via your Hub. Intended as a readable report you can show to a customer or auditor - not a place to change anything. Only actual maintenance work is listed here; pure status checks and monitoring traffic can be found in the Connection Log.',
			'technical' => 'Read-only aggregator over the System Log (local system.* events) and the Beacon Connection Log (remote Hub actions). Normalises both sources to a shared row shape, sorts by timestamp and applies the active filter set in-memory. Maps events to a unified category taxonomy: plugin / theme / core / user / backup / update / status / other. Connection Log rows are filtered through an allowlist of maintenance endpoints plus a severity/HTTP-failure fallback; read-only monitoring traffic (/status, /ping, /backup/list, /update/inventory, /backup/status, /update/status) is excluded on success - see Connection Log for the complete record.',
		],
	];

	/**
	 * Resolve the description for a given log type and mode. Unknown
	 * log types and missing modes fall back gracefully: unknown type
	 * returns the empty string (caller decides whether to render nothing
	 * or a fallback), missing mode falls back to the other mode.
	 *
	 * @param string $log_type 'audit' | 'system' | 'connection' | 'maintenance'
	 * @param string $mode     'plain' | 'technical'
	 */
	public static function describe_log( string $log_type, string $mode = 'technical' ): string {
		$entry = self::LOG_DESCRIPTIONS[ $log_type ] ?? null;
		if ( null === $entry ) {
			return '';
		}
		if ( isset( $entry[ $mode ] ) ) {
			return (string) $entry[ $mode ];
		}
		// Fall back to whichever variant does exist.
		return (string) ( $entry['plain'] ?? $entry['technical'] ?? '' );
	}

	/**
	 * Return both Plain and Technical descriptions as an associative
	 * array - convenience for export envelopes (JSON / future PDF)
	 * that want to ship both strings so the document is self-describing.
	 *
	 * @return array{plain: string, technical: string}
	 */
	public static function describe_log_both( string $log_type ): array {
		return [
			'plain'     => self::describe_log( $log_type, 'plain' ),
			'technical' => self::describe_log( $log_type, 'technical' ),
		];
	}

	/**
	 * Describe an AuditLog / SystemLog event row.
	 *
	 * Returns the plain sentence when mode is 'plain', or the raw
	 * event_type slug otherwise (callers wanting the short Technical
	 * label "Plugin activated" should call AuditLog::event_label()
	 * with mode='technical' - this method deliberately leans hard into
	 * the slug in Technical mode because that's the auditor's currency).
	 *
	 * @param string               $event_type e.g. 'system.plugin_activated'
	 * @param array<string, mixed> $context    Decoded context from the row.
	 * @param string               $mode       'plain' | 'technical'
	 */
	public static function describe_event( string $event_type, array $context = [], string $mode = 'technical' ): string {
		if ( 'plain' !== $mode ) {
			return $event_type;
		}

		// AuditLog::log() runs every event_type through normalize_event_type()
		// before storing, which converts dots to underscores. So the DB stores
		// 'settings_changed' (not 'settingschanged') even though callers pass
		// 'settings.changed'. Our catalog keys stay dotted for readability;
		// resolve the lookup via a normalised index so both paths work:
		// direct dotted match for in-memory callers, normalised match for
		// rows read back from the DB.
		$template = self::EVENTS_PLAIN[ $event_type ]
			?? self::plain_lookup()[ class_exists( AuditLog::class ) ? AuditLog::normalize_event_type( $event_type ) : $event_type ]
			?? null;

		if ( null === $template ) {
			// Unknown event in plain mode: fall back to the technical
			// label if AuditLog has one, otherwise the raw slug. No new
			// event should ever ship without a Plain translation, but
			// if it slips through we degrade gracefully rather than
			// silently losing the row.
			return class_exists( AuditLog::class )
				? AuditLog::event_label( $event_type, 'technical' )
				: $event_type;
		}

		return self::interpolate( $template, $context );
	}

	/**
	 * Lazily-built map of normalize_event_type(catalog_key) => template,
	 * so we can match event_types that were stored through
	 * AuditLog::log()'s normalize_event_type() (dot → underscore). Built
	 * once per request and cached.
	 *
	 * @return array<string, string>
	 */
	private static function plain_lookup(): array {
		static $cache = null;
		if ( null === $cache ) {
			$cache = [];
			foreach ( self::EVENTS_PLAIN as $key => $template ) {
				$norm = class_exists( AuditLog::class )
					? AuditLog::normalize_event_type( $key )
					: $key;
				$cache[ $norm ] = $template;
			}
		}
		return $cache;
	}

	/**
	 * Describe a Connection Log endpoint action.
	 *
	 * Technical mode: "GET /status"
	 * Plain mode:     "Hub status check"
	 *
	 * Unknown endpoints in plain mode fall back to a sensible pattern
	 * ("Hub called an unknown endpoint (/foo/bar)") so new endpoints
	 * don't silently render as empty strings.
	 */
	public static function describe_endpoint( string $method, string $path, string $mode = 'technical' ): string {
		if ( 'plain' !== $mode ) {
			return trim( strtoupper( $method ) . ' ' . $path );
		}

		return self::ENDPOINTS_PLAIN[ $path ]
			?? sprintf( 'Hub called an unknown endpoint (%s)', $path );
	}

	/**
	 * Describe an HTTP status code.
	 *
	 * Technical mode: "200", "403"
	 * Plain mode:     "Succeeded", "Blocked (not allowed)"
	 *
	 * Unknown codes fall back to a family-level description (4xx →
	 * "Rejected", 5xx → "Server error") rather than the raw number.
	 */
	public static function describe_status_code( int $code, string $mode = 'technical' ): string {
		if ( 'plain' !== $mode ) {
			return (string) $code;
		}

		if ( isset( self::STATUS_PLAIN[ $code ] ) ) {
			return self::STATUS_PLAIN[ $code ];
		}

		if ( $code >= 200 && $code < 300 ) {
			return 'Succeeded';
		}
		if ( $code >= 400 && $code < 500 ) {
			return 'Rejected';
		}
		if ( $code >= 500 && $code < 600 ) {
			return 'Server error';
		}
		return (string) $code;
	}

	/**
	 * Replace {placeholder} tokens in a template against a context array.
	 * Missing keys become "-" rather than the literal "{placeholder}"
	 * - older rows stored context in a slimmer format and we prefer a
	 * readable gap to literal template syntax leaking through.
	 */
	private static function interpolate( string $template, array $context ): string {
		return preg_replace_callback(
			'/\{([a-z_][a-z0-9_]*)\}/i',
			static function ( array $m ) use ( $context ): string {
				$key   = $m[1];
				$value = $context[ $key ] ?? null;
				if ( null === $value || '' === $value ) {
					return '-';
				}
				if ( is_scalar( $value ) ) {
					return (string) $value;
				}
				// Arrays / objects in context - render as a JSON snippet.
				return wp_json_encode( $value ) ?: '-';
			},
			$template
		);
	}
}
