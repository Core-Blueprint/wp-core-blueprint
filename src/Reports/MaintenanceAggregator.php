<?php
declare(strict_types=1);
/**
 * MaintenanceAggregator - assembles all data the Maintenance Report PDF needs.
 *
 * Sits between MaintenanceReport (raw event queries + KPI snapshots) and the
 * PDF template. Returns a single normalized shape; the template renders only
 * what's present without knowing where it came from.
 *
 * Real-data collectors landed incrementally per the build plan. Currently:
 *
 *   ✔ sections (3.1)         - query cb_core_audit_log per event_type
 *   ✔ kpis.updates_performed - derived from sections
 *   ✔ kpis.active_users      - DISTINCT audit-log actors across the full period
 *   ✔ site_state (3.2)       - local WP / theme / plugins / PHP / DB checks
 *   ✔ kpis.updates_pending   - point-in-time, derived from update transients
 *   ✔ backups (3.3)          - available full-site recovery providers
 *   ✔ kpis.backups_created   - derived from backups
 *   ✔ notes (3.4)            - hardcoded if-rules + cb_core_report_notes filter
 *   ✔ status (3.4)           - derived from notes severity precedence
 *   ○ kpis.security_issues   - addon-provided via cb_core_report_security filter
 *   ○ security (block)       - addon-provided via cb_core_report_security filter
 *
 * The ○ rows are NOT unfinished Base work. CB Base ships a maintenance-only
 * report by design; full security reporting is the separate CB Reports addon
 * (which uses its own PDF/A-capable library). When no addon hooks
 * cb_core_report_security the security block is omitted and the KPI strip shows
 * 4 tiles instead of 5 - intended behaviour, not a gap.
 *
 * Design constraints
 * ──────────────────
 * - Future-proof shape: when SystemLog gains pre-upgrade version snapshots,
 *   only the `version_from` field on each row needs to start being non-null -
 *   determine_active_columns() will then auto-add the "From" column to the
 *   rendered table without any template changes.
 * - Active-columns pattern: section row-data carries every possible field;
 *   the section's `columns` array tells the template which to render. Empty
 *   columns hide themselves automatically across all sections.
 * - Localized strings stay inside the aggregator so the template focuses on
 *   layout, not language.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class MaintenanceAggregator {

	/** Immutable maintenance-report snapshot contract version. */
	public const SNAPSHOT_VERSION = 1;

	/**
	 * Maximum rows fetched per section. For typical 30-day reports this is
	 * many times the realistic event count; sites that exceed it indicate
	 * either log-spam (which has its own concerns) or genuinely heavy
	 * maintenance activity warranting a follow-up summary tool. Either way,
	 * truncating here keeps the report bounded to a printable size.
	 */
	private const SECTION_ROW_LIMIT = 500;

	/**
	 * Mapping of section key → audit log event_type. The aggregator queries
	 * one event type per section. Section keys must match those expected
	 * by the PDF template (templates/pdf/maintenance-report.php).
	 *
	 * AuditLog stores event slugs in its canonical underscore form. The map
	 * remains human-readable dotted notation; every query normalizes the slug
	 * through AuditLog::normalize_event_type() before it reaches the DB layer.
	 */
	private const SECTION_EVENT_MAP = [
		'theme_updates'        => 'system.theme_updated',
		'plugin_updates'       => 'system.plugin_updated',
		'plugin_installations' => 'system.plugin_installed',
		'plugin_removals'      => 'system.plugin_deleted',
		'core_updates'         => 'system.core_updated',
	];

	/**
	 * Main entry - returns the full data tree for the PDF template.
	 *
	 * @param int $start_ts Period start as unix timestamp.
	 * @param int $end_ts   Period end as unix timestamp.
	 * @return array
	 */
	public static function collect( int $start_ts, int $end_ts ): array {
		$timezone  = wp_timezone();
		$start_day = ( new \DateTimeImmutable( '@' . $start_ts ) )->setTimezone( $timezone )->setTime( 0, 0 );
		$end_day   = ( new \DateTimeImmutable( '@' . $end_ts ) )->setTimezone( $timezone )->setTime( 0, 0 );
		$days      = max( 1, (int) $start_day->diff( $end_day )->days + 1 );

		$sections          = self::collect_sections( $start_ts, $end_ts );
		$active_user_count = self::collect_active_user_count( $start_ts, $end_ts );
		$update_counts     = self::collect_update_counts();
		$site_state    = self::collect_site_state( $update_counts );
		$backups       = self::collect_backups( $start_ts, $end_ts );
		$security      = self::collect_security( $start_ts, $end_ts );
		$kpis          = self::compute_kpis( $sections, $active_user_count, $update_counts, $backups, $security );

		// Build the context that drives notes + status. Both are derived
		// from the already-collected data; this is the only place the
		// pipeline reads its own intermediate state.
		$context = [
			'snapshot_version' => self::SNAPSHOT_VERSION,
			'period'           => [
				'start_ts' => $start_ts,
				'end_ts'   => $end_ts,
				'days'     => $days,
			],
			'site'             => [
				'title' => (string) get_bloginfo( 'name' ),
				'url'   => (string) home_url(),
			],
			'kpis'             => $kpis,
			'site_state'       => $site_state,
			'sections'         => $sections,
			'security'         => $security,
			'backups'          => $backups,
		];

		$notes  = self::collect_notes( $context );
		$status = self::derive_status( $notes );

		return $context + [
			'status' => $status,
			'notes'  => $notes,
		];
	}

	// ─── Section column auto-detection ────────────────────────────────────────

	/**
	 * Inspect rows and return which optional columns should be rendered.
	 * Mandatory columns are always present; optional columns appear only
	 * when at least one row has a non-null/non-empty value for them.
	 *
	 * Mandatory: target_name, action_or_version, date, actor
	 * Optional:  version_from (activates "From"/"To" split), notes
	 *
	 * Used by the template renderer; exposed publicly so smoke tests and
	 * future consumers can call it on their own row sets.
	 */
	public static function determine_active_columns( array $rows ): array {
		$columns = [ 'target_name', 'version_to', 'date', 'actor' ];

		foreach ( $rows as $row ) {
			if ( ! empty( $row['version_from'] ) && ! in_array( 'version_from', $columns, true ) ) {
				array_splice( $columns, 1, 0, 'version_from' ); // insert before version_to
			}
			if ( ! empty( $row['notes'] ) && ! in_array( 'notes', $columns, true ) ) {
				$columns[] = 'notes';
			}
		}

		return $columns;
	}

	// ─── Section collectors (real-data) ───────────────────────────────────────

	/**
	 * Collect all five activity sections from the audit log within the period.
	 * Each section corresponds to one event_type; empty sections are still
	 * returned (with count = 0) so the template can decide to hide them.
	 *
	 * @param int $start_ts Period start as unix timestamp (inclusive).
	 * @param int $end_ts   Period end as unix timestamp (inclusive).
	 * @return array<string, array{title: string, count: int, columns: array, rows: array}>
	 */
	private static function collect_sections( int $start_ts, int $end_ts ): array {
		$sections = [];

		foreach ( self::SECTION_EVENT_MAP as $section_key => $event_type ) {
			$result = self::collect_section_rows( $event_type, $start_ts, $end_ts );
			$rows   = $result['rows'];
			$total  = $result['total'];

			$sections[ $section_key ] = [
				'title'     => self::section_title( $section_key ),
				'count'     => $total,
				'truncated' => $total > count( $rows ),
				'columns'   => self::determine_active_columns( $rows ),
				'rows'      => $rows,
			];
		}

		return $sections;
	}

	/**
	 * Fetch the bounded printable row set plus the real unbounded total.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	private static function collect_section_rows( string $event_type, int $start_ts, int $end_ts ): array {
		$result = AuditLog::query( [
			'event_type' => AuditLog::normalize_event_type( $event_type ),
			'since'      => gmdate( 'Y-m-d H:i:s', $start_ts ),
			'until'      => gmdate( 'Y-m-d H:i:s', $end_ts ),
			'per_page'   => self::SECTION_ROW_LIMIT,
			'page'       => 1,
		] );

		$rows = [];
		foreach ( $result['rows'] ?? [] as $event ) {
			$rows[] = self::reshape_event_row( $event_type, $event );
		}

		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['date'], (string) $a['date'] ) );

		return [
			'rows'  => $rows,
			'total' => max( count( $rows ), (int) ( $result['total'] ?? count( $rows ) ) ),
		];
	}

	/**
	 * Count distinct authenticated actors across the full period without the
	 * printable 500-row detail cap. AuditLog::rows_iterator() pages through
	 * matching events so memory remains bounded on busy sites.
	 */
	private static function collect_active_user_count( int $start_ts, int $end_ts ): int {
		$users = [];

		foreach ( self::SECTION_EVENT_MAP as $event_type ) {
			foreach ( AuditLog::rows_iterator( [
				'event_type' => AuditLog::normalize_event_type( $event_type ),
				'since'      => gmdate( 'Y-m-d H:i:s', $start_ts ),
				'until'      => gmdate( 'Y-m-d H:i:s', $end_ts ),
			] ) as $event ) {
				$login = trim( (string) ( $event['user_login'] ?? '' ) );
				if ( '' !== $login ) {
					$users[ $login ] = true;
				}
			}
		}

		return count( $users );
	}

	/**
	 * Reshape a raw audit log event into the section-row contract the
	 * template renders against.
	 *
	 * Row contract: target_name, target_slug, version_from, version_to,
	 * date, actor, notes. Mandatory keys are always present; optional
	 * fields (version_from, notes) carry null/'' when not applicable so
	 * determine_active_columns() can collapse their columns globally per
	 * section.
	 *
	 * Context-key conventions (see SystemLog::on_upgrader_complete and
	 * sibling handlers for the source of truth):
	 *   - plugin_*  → context: { plugin: name, file: path, version: post-upgrade version }
	 *                          (deletions omit `version`)
	 *   - theme_*   → context: { theme: name, slug: stylesheet, version: post-upgrade version }
	 *   - core_*    → context: { version: WP version }
	 *
	 * @param string $event_type Dotted event type, e.g. 'system.plugin_updated'.
	 * @param object $event      Row from AuditLog::query() (with context_decoded).
	 * @return array<string, mixed>
	 */
	private static function reshape_event_row( string $event_type, $event ): array {
		$context = is_array( $event->context_decoded ?? null ) ? $event->context_decoded : [];

		// Sensible defaults - all keys present, optional ones empty.
		$row = [
			'target_name'  => '',
			'target_slug'  => '',
			'version_from' => null,
			'version_to'   => null,
			'date'         => (string) ( $event->created_at ?? '' ),
			'actor'        => (string) ( $event->user_login ?? '' ),
			'notes'        => '',
		];

		// SystemLog logs events with consistent context shapes per category;
		// map each relevant event_type to the right context keys.
		switch ( $event_type ) {
			case 'system.plugin_updated':
			case 'system.plugin_installed':
				$row['target_name'] = (string) ( $context['plugin'] ?? '' );
				$row['target_slug'] = (string) ( $context['file']   ?? '' );
				$row['version_to']  = isset( $context['version'] ) && '' !== $context['version']
					? (string) $context['version']
					: null;
				break;

			case 'system.plugin_deleted':
				// Deletions don't carry a version - column collapses via
				// determine_active_columns() if every row in the section
				// has null version_to.
				$row['target_name'] = (string) ( $context['plugin'] ?? '' );
				$row['target_slug'] = (string) ( $context['file']   ?? '' );
				$row['version_to']  = null;
				break;

			case 'system.theme_updated':
			case 'system.theme_installed':
				$row['target_name'] = (string) ( $context['theme'] ?? '' );
				$row['target_slug'] = (string) ( $context['slug']  ?? '' );
				$row['version_to']  = isset( $context['version'] ) && '' !== $context['version']
					? (string) $context['version']
					: null;
				break;

			case 'system.core_updated':
				$row['target_name'] = __( 'WordPress', 'core-blueprint' );
				$row['target_slug'] = 'wordpress';
				$row['version_to']  = isset( $context['version'] ) && '' !== $context['version']
					? (string) $context['version']
					: null;
				break;
		}

		// Fallback for empty actor - verify-data showed 100% coverage on
		// SystemLog-written events, but Beacon-driven remote events run
		// without a WP user context. Localised placeholder keeps the
		// "Performed By" column readable.
		if ( '' === $row['actor'] ) {
			$row['actor'] = __( 'System', 'core-blueprint' );
		}

		return $row;
	}

	/**
	 * Localised section titles, keyed by section identifier.
	 */
	private static function section_title( string $section_key ): string {
		return match ( $section_key ) {
			'theme_updates'        => __( 'Theme Updates',        'core-blueprint' ),
			'plugin_updates'       => __( 'Plugin Updates',       'core-blueprint' ),
			'plugin_installations' => __( 'Plugin Installations', 'core-blueprint' ),
			'plugin_removals'      => __( 'Plugin Removals',      'core-blueprint' ),
			'core_updates'         => __( 'Core Updates',         'core-blueprint' ),
			default                => $section_key,
		};
	}

	// ─── KPI computation (real-data, derived from sections) ───────────────────

	/**
	 * Compute KPIs from the collected sections + live update counts. Three
	 * KPIs are now derived from real data:
	 *
	 *   - updates_performed - sum of theme_updates, plugin_updates,
	 *                          plugin_installations, core_updates counts.
	 *                          Removals are NOT counted (they have their own
	 *                          section in the report and are not updates).
	 *   - active_users      - DISTINCT user_login across all matching audit-log
	 *                         events in the full period (not detail-capped).
	 *                          Definition: "users who performed at least one
	 *                          tracked maintenance action in this period".
	 *                          The "System" fallback (used for unattended
	 *                          events) is excluded from this count.
	 *   - updates_pending   - POINT-IN-TIME site state, intentionally NOT
	 *                          period-relative. Reflects the current state
	 *                          of the site at report-generation time, not what
	 *                          was outstanding during the period. This is
	 *                          consistent with the Site State table (also
	 *                          point-in-time) - non-technical readers
	 *                          expect "is my site up-to-date now". The
	 *                          accepted inconsistency with the period-bound
	 *                          KPIs is documented here so future devs don't
	 *                          "fix" it.
	 *
	 * Two KPIs are sourced outside the audit-log path:
	 *
	 *   - security_issues   - not produced by Base; filled by the CB Reports
	 *                         addon via cb_core_report_security, else omitted.
	 *   - backups_created   - derived from the Beacon backup providers (3.3).
	 */
	/**
	 * Build the backups_created KPI tile. Three distinct states:
	 *
	 *   - count > 0          → "Last backup:" + formatted timestamp
	 *   - count = 0, no available full-site provider → "No backup provider configured"
	 *   - count = 0, provider available              → "No backups in this period"
	 *     and, when known, the most recent backup outside the period
	 *
	 * The tri-state matters for the reader: registration alone is not
	 * configuration. A provider must explicitly represent full-site recovery and
	 * report itself available before a zero count can mean a quiet period.
	 */
	private static function compute_backups_kpi( array $backups ): array {
		$count = (int) ( $backups['count'] ?? 0 );

		if ( $count > 0 ) {
			$last_ts   = strtotime( (string) ( $backups['last_at'] ?? '' ) . ' UTC' );
			$breakdown = [
				__( 'Last backup:', 'core-blueprint' ),
				$last_ts ? wp_date( 'd-m-Y H:i', $last_ts, wp_timezone() ) : '',
			];
		} elseif ( empty( $backups['providers'] ) ) {
			$breakdown = [ __( 'No backup provider configured', 'core-blueprint' ) ];
		} else {
			$breakdown = [ __( 'No backups in this period', 'core-blueprint' ) ];

			$last_overall_ts = strtotime( (string) ( $backups['last_at_overall'] ?? '' ) . ' UTC' );
			if ( $last_overall_ts ) {
				$breakdown[] = sprintf(
					/* translators: %s: formatted date and time of the last backup. */
					__( 'Last backup: %s', 'core-blueprint' ),
					wp_date( 'd-m-Y H:i', $last_overall_ts, wp_timezone() )
				);
			}
		}

		return [
			'count'     => $count,
			'breakdown' => $breakdown,
		];
	}

	private static function compute_kpis( array $sections, int $active_user_count, array $update_counts, array $backups, ?array $security ): array {
		$plugin_updates       = (int) ( $sections['plugin_updates']['count']       ?? 0 );
		$theme_updates        = (int) ( $sections['theme_updates']['count']        ?? 0 );
		$plugin_installations = (int) ( $sections['plugin_installations']['count'] ?? 0 );
		$core_updates         = (int) ( $sections['core_updates']['count']         ?? 0 );

		$updates_total = $plugin_updates + $theme_updates + $plugin_installations + $core_updates;

		$updates_breakdown = [
			sprintf(
				/* translators: %d is the number of plugin updates. */
				_n( '%d plugin update', '%d plugin updates', $plugin_updates, 'core-blueprint' ),
				$plugin_updates
			),
			sprintf(
				/* translators: %d is the number of theme updates. */
				_n( '%d theme update', '%d theme updates', $theme_updates, 'core-blueprint' ),
				$theme_updates
			),
		];
		if ( $plugin_installations > 0 ) {
			$updates_breakdown[] = sprintf(
				/* translators: %d is the number of plugins installed. */
				_n( '%d plugin installed', '%d plugins installed', $plugin_installations, 'core-blueprint' ),
				$plugin_installations
			);
		}
		if ( $core_updates > 0 ) {
			$updates_breakdown[] = sprintf(
				/* translators: %d is the number of core updates. */
				_n( '%d core update', '%d core updates', $core_updates, 'core-blueprint' ),
				$core_updates
			);
		}


		// updates_pending - point-in-time at snapshot generation. Sum of pending plugin
		// updates, theme updates, and core updates. Translation updates are
		// deliberately excluded - they're not visible to non-technical
		// stakeholders and rarely warrant a KPI line.
		$plugins_pending = (int) ( $update_counts['plugins'] ?? 0 );
		$themes_pending  = (int) ( $update_counts['themes']  ?? 0 );
		$core_pending    = ! empty( $update_counts['core_has_update'] ) ? 1 : 0;
		$pending_total   = $plugins_pending + $themes_pending + $core_pending;

		if ( 0 === $pending_total ) {
			$pending_breakdown = [ __( 'All software up-to-date', 'core-blueprint' ) ];
		} else {
			$pending_breakdown = [];
			if ( $plugins_pending > 0 ) {
				$pending_breakdown[] = sprintf(
					/* translators: %d is the number of pending plugin updates. */
					_n( '%d plugin update', '%d plugin updates', $plugins_pending, 'core-blueprint' ),
					$plugins_pending
				);
			}
			if ( $themes_pending > 0 ) {
				$pending_breakdown[] = sprintf(
					/* translators: %d is the number of pending theme updates. */
					_n( '%d theme update', '%d theme updates', $themes_pending, 'core-blueprint' ),
					$themes_pending
				);
			}
			if ( $core_pending > 0 ) {
				$pending_breakdown[] = __( 'WordPress core update', 'core-blueprint' );
			}
		}

		// Build the KPI strip. The security_issues tile is conditional -
		// CB Base ships without a security data source, so without an
		// addon hooking cb_core_report_security the strip shows 4 tiles
		// instead of 5 rather than fake numbers.
		$kpis = [
			'updates_performed' => [
				'count'     => $updates_total,
				'breakdown' => $updates_breakdown,
			],
		];

		if ( null !== $security ) {
			$detected = (int) $security['detected'];
			$kpis['security_issues'] = [
				'count'     => $detected,
				'breakdown' => $detected > 0
					? [ __( 'Review security activity', 'core-blueprint' ) ]
					: [ __( 'No incidents recorded', 'core-blueprint' ) ],
			];
		}

		$kpis['backups_created'] = self::compute_backups_kpi( $backups );

		$kpis['updates_pending'] = [
			'count'     => $pending_total,
			'breakdown' => $pending_breakdown,
		];

		$kpis['active_users'] = [
			'count'     => $active_user_count,
			'breakdown' => [
				$active_user_count > 0
					? __( 'Performed maintenance actions', 'core-blueprint' )
					: __( 'No maintenance activity', 'core-blueprint' ),
			],
		];

		return $kpis;
	}

	// ─── Site state + update counts (real-data) ───────────────────────────────

	/**
	 * Collect pending-update counts in one pass. Returns plain integers + a
	 * boolean for core, so both collect_site_state() and compute_kpis() can
	 * read the same numbers without each re-running the WP update API.
	 *
	 * Trusts WP's update transients (populated twice daily by the wp_version_check
	 * + wp_update_plugins + wp_update_themes cron events). Does NOT force-refresh
	 * because that hits api.wordpress.org for every installed plugin/theme and
	 * would dominate report-generation latency.
	 *
	 * @return array{plugins:int, themes:int, translations:int, core_has_update:bool}
	 */
	private static function collect_update_counts(): array {
		// wp_get_update_data() lives in wp-admin includes - load defensively
		// so the report still works under WP-CLI / REST contexts.
		if ( ! function_exists( 'wp_get_update_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$data = function_exists( 'wp_get_update_data' ) ? wp_get_update_data() : [];

		$core_has_update = false;
		if ( function_exists( 'get_core_updates' ) ) {
			foreach ( (array) get_core_updates() as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_has_update = true;
					break;
				}
			}
		}

		return [
			'plugins'         => (int) ( $data['counts']['plugins']      ?? 0 ),
			'themes'          => (int) ( $data['counts']['themes']       ?? 0 ),
			'translations'    => (int) ( $data['counts']['translations'] ?? 0 ),
			'core_has_update' => $core_has_update,
		];
	}

	/**
	 * Build the live site-state block - the right column of the Current State
	 * table in the PDF. Each entry has label / status / state / detail.
	 *
	 * Status values:
	 *   - 'ok'       : healthy
	 *   - 'warn'     : action recommended (update available, EOL)
	 *   - 'critical' : action required (unsupported PHP, etc.)
	 *
	 * @param array $update_counts Output of collect_update_counts().
	 */
	private static function collect_site_state( array $update_counts ): array {
		// ─── WordPress core ───────────────────────────────────────────────
		$wp_version      = (string) get_bloginfo( 'version' );
		$has_core_update = (bool) $update_counts['core_has_update'];

		$wp_core = [
			'label'  => __( 'WordPress Core', 'core-blueprint' ),
			'status' => $has_core_update ? 'warn' : 'ok',
			'state'  => $has_core_update
				? __( 'Update available', 'core-blueprint' )
				: __( 'Up-to-date', 'core-blueprint' ),
			'detail' => sprintf(
				/* translators: %s is the WordPress version, e.g. "6.5.3". */
				__( 'Version %s', 'core-blueprint' ),
				$wp_version
			),
		];

		// ─── Active theme ─────────────────────────────────────────────────
		$theme = wp_get_theme();
		$theme_has_update = false;
		if ( function_exists( 'get_theme_updates' ) ) {
			$theme_updates    = get_theme_updates();
			$theme_has_update = is_array( $theme_updates ) && isset( $theme_updates[ $theme->get_stylesheet() ] );
		}

		$theme_name    = (string) $theme->get( 'Name' );
		$theme_version = (string) $theme->get( 'Version' );

		$theme_state = [
			'label'  => __( 'Active Theme', 'core-blueprint' ),
			'status' => $theme_has_update ? 'warn' : 'ok',
			'state'  => $theme_has_update
				? __( 'Update available', 'core-blueprint' )
				: __( 'Up-to-date', 'core-blueprint' ),
			'detail' => trim( $theme_name . ( '' !== $theme_version ? ' ' . $theme_version : '' ) ),
		];

		// ─── Plugins ──────────────────────────────────────────────────────
		$plugins_pending = (int) $update_counts['plugins'];

		$plugins = [
			'label'  => __( 'Plugins', 'core-blueprint' ),
			'status' => $plugins_pending > 0 ? 'warn' : 'ok',
			'state'  => $plugins_pending > 0
				? __( 'Updates available', 'core-blueprint' )
				: __( 'Up-to-date', 'core-blueprint' ),
			'detail' => sprintf(
				/* translators: %d is the number of plugin updates pending. */
				_n( '%d update pending', '%d updates pending', $plugins_pending, 'core-blueprint' ),
				$plugins_pending
			),
		];

		// ─── PHP ──────────────────────────────────────────────────────────
		// Core Blueprint Base requires PHP 8.4. PHP 8.5 is the suite-wide
		// recommended runtime, but 8.4 remains fully supported and must not be
		// surfaced as a warning merely because a newer recommended branch exists.
		$minimum_php = defined( 'CB_CORE_MIN_PHP' ) ? CB_CORE_MIN_PHP : '8.4';
		if ( version_compare( PHP_VERSION, $minimum_php, '<' ) ) {
			$php_status = 'critical';
			$php_state  = __( 'Unsupported', 'core-blueprint' );
		} else {
			$php_status = 'ok';
			$php_state  = __( 'Supported', 'core-blueprint' );
		}

		$php = [
			'label'  => __( 'PHP Version', 'core-blueprint' ),
			'status' => $php_status,
			'state'  => $php_state,
			'detail' => 'PHP ' . PHP_VERSION,
		];

		// ─── Database ─────────────────────────────────────────────────────
		// Reachability is implied - we wouldn't have got here if the DB was
		// down (every WP page load opens a connection). db_server_info()
		// returns "10.11.6-MariaDB" / "8.0.32" / similar; split engine vs
		// version for clean display.
		global $wpdb;
		$db_info = '';
		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$db_info = (string) $wpdb->db_server_info();
		} elseif ( method_exists( $wpdb, 'db_version' ) ) {
			$db_info = (string) $wpdb->db_version();
		}
		$db_engine  = ( false !== stripos( $db_info, 'mariadb' ) ) ? 'MariaDB' : 'MySQL';
		$db_version = preg_replace( '/[^0-9.].*$/', '', $db_info ) ?: '';

		$database = [
			'label'  => __( 'Database', 'core-blueprint' ),
			'status' => 'ok',
			'state'  => __( 'Reachable', 'core-blueprint' ),
			'detail' => trim( $db_engine . ( '' !== $db_version ? ' ' . $db_version : '' ) ),
		];

		// ─── Website status ───────────────────────────────────────────────
		// "If PHP is executing, the site is at least serving requests." This
		// is intentionally limited - true external reachability needs a
		// remote probe (future Beacon-driven check). Surface that limitation
		// honestly via the detail line.
		$website = [
			'label'  => __( 'Website Status', 'core-blueprint' ),
			'status' => 'ok',
			'state'  => __( 'Online', 'core-blueprint' ),
			'detail' => __( 'Serving requests', 'core-blueprint' ),
		];

		return [
			'wp_core'  => $wp_core,
			'theme'    => $theme_state,
			'plugins'  => $plugins,
			'php'      => $php,
			'database' => $database,
			'website'  => $website,
		];
	}

	// ─── Notes + Status (real-data, derived from collected context) ───────────

	/**
	 * Generate observations worth flagging based on the assembled context.
	 *
	 * Hardcoded if-rules - deliberately not a rule-engine. Adding a new note
	 * type means adding an if-block here. Keeping this simple makes it easy
	 * to audit which conditions raise which note, and avoids the indirection
	 * cost of a generic rules layer for ~6 rules total.
	 *
	 * Notes are observations or warnings worth surfacing - NOT a redundant
	 * "all OK" message. The status banner already handles the all-good case.
	 * An empty notes list is a valid state; the template skips the Notes
	 * section entirely and renders Current State at full width.
	 */
	private static function collect_notes( array $context ): array {
		$notes = [];

		// 1. WordPress core update available.
		if ( 'warn' === ( $context['site_state']['wp_core']['status'] ?? '' ) ) {
			$notes[] = [
				'type'  => 'warn',
				'title' => __( 'WordPress update available', 'core-blueprint' ),
				'body'  => __( 'A new version of WordPress core is available. Update at your earliest convenience.', 'core-blueprint' ),
			];
		}

		// 2. PHP unsupported (below CB Base minimum).
		if ( 'critical' === ( $context['site_state']['php']['status'] ?? '' ) ) {
			$notes[] = [
				'type'  => 'critical',
				'title' => __( 'PHP version unsupported', 'core-blueprint' ),
				'body'  => sprintf(
					/* translators: %s is the PHP version, e.g. "PHP 7.4.33". */
					__( 'This site runs %s, which is below the minimum supported version. Upgrade as soon as possible.', 'core-blueprint' ),
					'PHP ' . PHP_VERSION
				),
			];
		}

		// 3. Backup health - three states, mutually exclusive:
		//      - no provider                     → warn
		//      - has provider, last backup stale → warn (stale = > 7 days)
		//      - has provider, recent backup     → no warning note (a positive
		//        observation may still be added later if backups landed in
		//        this period).
		$bk          = $context['backups'] ?? [];
		$no_provider = empty( $bk['providers'] )
			&& 0 === (int) ( $bk['count'] ?? 0 )
			&& '' === (string) ( $bk['last_at_overall'] ?? '' );

		if ( $no_provider ) {
			$notes[] = [
				'type'  => 'warn',
				'title' => __( 'No backup provider configured', 'core-blueprint' ),
				'body'  => __( 'This site has no backup provider configured. Configure one to ensure recovery options are available.', 'core-blueprint' ),
			];
		} else {
			$last_overall = (int) strtotime( (string) ( $bk['last_at_overall'] ?? '' ) );
			if ( $last_overall > 0 && ( time() - $last_overall ) > 7 * DAY_IN_SECONDS ) {
				$notes[] = [
					'type'  => 'warn',
					'title' => __( 'No recent backup', 'core-blueprint' ),
					'body'  => sprintf(
						/* translators: %s is a humanised duration, e.g. "9 days". */
						__( 'The most recent backup is %s old. Run a backup as soon as possible.', 'core-blueprint' ),
						function_exists( 'human_time_diff' )
							? human_time_diff( $last_overall, time() )
							: gmdate( 'Y-m-d', $last_overall )
					),
				];
			}
		}

		// 4. Security issues detected - only meaningful when a security
		//    addon has supplied numbers via cb_core_report_security. CB
		//    Base alone returns null and this rule never fires.
		if ( null !== ( $context['security'] ?? null ) ) {
			$sec_count = (int) ( $context['security']['detected'] ?? 0 );
			if ( $sec_count > 0 ) {
				$notes[] = [
					'type'  => 'critical',
					'title' => __( 'Security issues require attention', 'core-blueprint' ),
					'body'  => sprintf(
						/* translators: %d is the number of security issues. */
						_n(
							'%d security issue was detected during this period and needs to be addressed.',
							'%d security issues were detected during this period and need to be addressed.',
							$sec_count,
							'core-blueprint'
						),
						$sec_count
					),
				];
			}
		}

		// 5. Positive observation - backups created during the period.
		$bk_count = (int) ( $bk['count'] ?? 0 );
		if ( $bk_count > 0 ) {
			$notes[] = [
				'type'  => 'info',
				'title' => __( 'Backups', 'core-blueprint' ),
				'body'  => sprintf(
					/* translators: %d is the number of backups. */
					_n(
						'%d backup was created in this period.',
						'%d backups were created in this period.',
						$bk_count,
						'core-blueprint'
					),
					$bk_count
				),
			];
		}

		// 6. Security module boot failures - operational state, NOT a security
		//    incident (= why this is its own note instead of feeding the
		//    security 'detected' count). A boot failure means a CB Base
		//    security module crashed during initialization, so its
		//    protections are off until fixed. Period-bound query: only
		//    failures within the report window count.
		$period   = $context['period'] ?? [];
		$start_ts = (int) ( $period['start_ts'] ?? 0 );
		$end_ts   = (int) ( $period['end_ts']   ?? 0 );
		if ( $start_ts > 0 && $end_ts > 0 && class_exists( '\CoreBlueprint\Core\Log\AuditLog' ) ) {
			$boot_fail_query = AuditLog::query( [
				'event_type' => AuditLog::normalize_event_type( 'module.boot_failed' ),
				'since'      => gmdate( 'Y-m-d H:i:s', $start_ts ),
				'until'      => gmdate( 'Y-m-d H:i:s', $end_ts ),
				'per_page'   => 100,
				'page'       => 1,
			] );
			$boot_fail_count = (int) ( $boot_fail_query['total'] ?? 0 );
			if ( $boot_fail_count > 0 ) {
				$notes[] = [
					'type'  => 'critical',
					'title' => __( 'Security module did not start', 'core-blueprint' ),
					'body'  => sprintf(
						/* translators: %d is the number of module boot failures. */
						_n(
							'%d security module failed to initialize. Its protections are not active until the issue is resolved.',
							'%d security modules failed to initialize. Their protections are not active until the issue is resolved.',
							$boot_fail_count,
							'core-blueprint'
						),
						$boot_fail_count
					),
				];
			}
		}

		/**
		 * Filter the maintenance report notes after generation.
		 *
		 * Listeners can suppress, reorder, modify, or append observations.
		 * Useful for agencies that want to inject client-specific copy or
		 * hide observations covered elsewhere in the report.
		 *
		 * @since   1.0.0
		 *
		 * @param array $notes   List of note arrays, each: { type, title, body }.
		 *                       Type is one of 'info', 'warn', 'critical'.
		 * @param array $context The full report context (period, kpis,
		 *                       site_state, sections, security, backups).
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$notes = (array) apply_filters( 'cb_core_report_notes', $notes, $context );
		}

		return $notes;
	}

	/**
	 * Derive the top-of-report status banner from the generated notes.
	 *
	 * Severity precedence: critical > warn > ok. The detail subline counts
	 * how many notes contributed at the dominant severity so the reader gets
	 * a quick scope indication ("3 issues need attention" vs "1").
	 */
	private static function derive_status( array $notes ): array {
		$crit_count = 0;
		$warn_count = 0;
		foreach ( $notes as $note ) {
			switch ( $note['type'] ?? '' ) {
				case 'critical':
					$crit_count++;
					break;
				case 'warn':
					$warn_count++;
					break;
			}
		}

		if ( $crit_count > 0 ) {
			return [
				'banner'          => 'critical',
				'headline'        => __( 'Overall status: action required', 'core-blueprint' ),
				'subline'         => __( 'Critical issues need attention.', 'core-blueprint' ),
				'detail_headline' => sprintf(
					/* translators: %d is the number of critical issues. */
					_n( '%d critical issue detected', '%d critical issues detected', $crit_count, 'core-blueprint' ),
					$crit_count
				),
				'detail_subline'  => __( 'See the notes below for details.', 'core-blueprint' ),
			];
		}

		if ( $warn_count > 0 ) {
			return [
				'banner'          => 'warn',
				'headline'        => __( 'Overall status: action recommended', 'core-blueprint' ),
				'subline'         => __( 'Some maintenance tasks are pending.', 'core-blueprint' ),
				'detail_headline' => sprintf(
					/* translators: %d is the number of issues found. */
					_n( '%d issue found', '%d issues found', $warn_count, 'core-blueprint' ),
					$warn_count
				),
				'detail_subline'  => __( 'See the notes below for details.', 'core-blueprint' ),
			];
		}

		return [
			'banner'          => 'ok',
			'headline'        => __( 'Overall status: OK', 'core-blueprint' ),
			'subline'         => __( 'Website is up-to-date and stable.', 'core-blueprint' ),
			'detail_headline' => __( 'No critical issues detected', 'core-blueprint' ),
			'detail_subline'  => __( 'All systems are functioning as expected.', 'core-blueprint' ),
		];
	}

	// ─── Security (filter-driven, no built-in CB Base detector) ───────────────

	/**
	 * Build the security data block for the report, or return null when no
	 * data source is available.
	 *
	 * CB Base does NOT ship a firewall, brute-force detector, or malware
	 * scanner. It would be dishonest to populate "blocked_attempts" or
	 * "brute_force" counts from nothing. The default return is therefore
	 * null - which signals to the template + KPI strip to hide the
	 * Security Activity block entirely.
	 *
	 * Security-addon plugins (e.g. a future CB Security Plus, Wordfence
	 * integration, malware-scan addon) hook the filter below and return
	 * a populated array. When that happens the report renders the section.
	 *
	 * @param int $start_ts Period start as unix timestamp.
	 * @param int $end_ts   Period end as unix timestamp.
	 * @return array|null   Either { detected, blocked_attempts, brute_force, summary }
	 *                      or null to indicate "no data source".
	 */
	private static function collect_security( int $start_ts, int $end_ts ): ?array {
		/**
		 * Filter the security data block before it lands in the report.
		 *
		 * Default value is null - CB Base ships no firewall/IDS/scanner, so
		 * there are no real numbers to populate. Listeners (security addons)
		 * return an array with these keys to make the Security Activity
		 * section appear:
		 *
		 *   detected         int     Count of incidents requiring attention
		 *                            in the period.
		 *   blocked_attempts int|null Count of attempts blocked by a firewall.
		 *                            null = the listener has no firewall (the
		 *                            line is hidden in the template).
		 *   brute_force      int     Count of successful brute-force attacks
		 *                            in the period.
		 *   summary          string  Localised text shown when detected = 0.
		 *
		 * Returning null (the default) hides the entire Security Activity
		 * section and shrinks the KPI strip from 5 to 4 tiles.
		 *
		 * Listeners typically REPLACE the value - there is no contract for
		 * merging. If multiple addons need to coexist they must coordinate
		 * via priorities or wrap each other.
		 *
		 * @since   1.0.0
		 *
		 * @param array|null $security  Default null.
		 * @param int        $start_ts  Period start (UTC unix timestamp).
		 * @param int        $end_ts    Period end (UTC unix timestamp).
		 */
		$security = function_exists( 'apply_filters' )
			? apply_filters( 'cb_core_report_security', null, $start_ts, $end_ts )
			: null;

		// Defensive normalisation: only accept a properly-shaped array.
		// Anything else (truthy non-array, malformed listener return) → null.
		if ( ! is_array( $security ) ) {
			return null;
		}
		if ( ! isset( $security['detected'] ) || ! isset( $security['summary'] ) ) {
			return null;
		}

		// Coerce types so downstream code never has to defend against them.
		return [
			'detected'         => (int) $security['detected'],
			'blocked_attempts' => isset( $security['blocked_attempts'] ) && null !== $security['blocked_attempts']
				? (int) $security['blocked_attempts']
				: null,
			'brute_force'      => isset( $security['brute_force'] ) ? (int) $security['brute_force'] : 0,
			'summary'          => (string) $security['summary'],
		];
	}

	// ─── Backups (real-data, via Reports backup discovery) ────────────────────

	/**
	 * Collect full-site backup activity within the period.
	 *
	 * Reports owns this read-only boundary so backup health remains available when
	 * the optional Beacon extension is not installed. Core Blueprint discovers
	 * All-in-One WP Migration natively and extensions may contribute additional
	 * sources through BackupDiscovery without coupling reporting to remote backup
	 * transport or Hub REST routes.
	 *
	 * Output shape consumed by the PDF template and KPI:
	 *   count           int
	 *   last_at         string   newest in-period backup, UTC `Y-m-d H:i:s`
	 *   last_at_overall string   newest known backup across configured providers
	 *   providers       string[] labels of available full-site providers
	 *   summary         string   human-readable backup status
	 */
	private static function collect_backups( int $start_ts, int $end_ts ): array {
		$configured_providers = BackupDiscovery::sources();

		if ( empty( $configured_providers ) ) {
			return [
				'count'           => 0,
				'last_at'         => '',
				'last_at_overall' => '',
				'providers'       => [],
				'summary'         => __( 'No backup provider configured.', 'core-blueprint' ),
			];
		}

		$count             = 0;
		$latest_ts         = 0;
		$latest_overall_ts = 0;

		foreach ( $configured_providers as $provider ) {
			try {
				foreach ( (array) ( $provider['backups'] ?? [] ) as $backup ) {
					$created_str = (string) ( $backup['created'] ?? '' );
					if ( '' === $created_str ) {
						continue;
					}

					$ts = strtotime( $created_str );
					if ( false === $ts ) {
						continue;
					}

					$latest_overall_ts = max( $latest_overall_ts, $ts );
					if ( $ts < $start_ts || $ts > $end_ts ) {
						continue;
					}

					$count++;
					$latest_ts = max( $latest_ts, $ts );
				}
			} catch ( \Throwable $e ) {
				// Listing failures are isolated to this provider. Availability was
				// already established, so it remains configured for health state.
				continue;
			}
		}

		$provider_labels = array_values( array_map(
			static fn( array $provider ): string => (string) $provider['label'],
			$configured_providers
		) );

		if ( 0 === $count ) {
			return [
				'count'           => 0,
				'last_at'         => '',
				'last_at_overall' => $latest_overall_ts ? gmdate( 'Y-m-d H:i:s', $latest_overall_ts ) : '',
				'providers'       => $provider_labels,
				'summary'         => __( 'No backups were created in this period.', 'core-blueprint' ),
			];
		}

		return [
			'count'           => $count,
			'last_at'         => gmdate( 'Y-m-d H:i:s', $latest_ts ),
			'last_at_overall' => gmdate( 'Y-m-d H:i:s', $latest_overall_ts ),
			'providers'       => $provider_labels,
			'summary'         => __( 'Backup system is working correctly.', 'core-blueprint' ),
		];
	}

}
