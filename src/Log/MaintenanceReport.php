<?php
declare(strict_types=1);
/**
 * Core Blueprint - Maintenance Report data access.
 *
 * Combines two sources into a single client-facing audit view:
 *   - System Log  (local maintenance, stored in audit_log under system.* prefix)
 *   - Connection Log (remote actions via Beacon REST API)
 *
 * Rows are normalized to a common shape so the UI can render them
 * uniformly. Filterable by actor, unified category, source, date range.
 *
 * Performance note: this class does an in-memory combine + sort + filter.
 * That's fine for typical client sites with 90-day retention and modest
 * traffic. If this ever hits scale issues, it can be rewritten as a
 * UNION SELECT with column aliasing - but for now the simpler approach
 * keeps the code readable.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Log;

defined( 'ABSPATH' ) || exit;

class MaintenanceReport {

	/**
	 * Unified category taxonomy - what the client-facing filter shows.
	 * Each row from either source maps to exactly one of these.
	 */
	const CATEGORIES = [
		'plugin' => 'Plugin',
		'theme'  => 'Theme',
		'core'   => 'Core',
		'user'   => 'User',
		'backup' => 'Backup',
		'update' => 'Update',
		'status' => 'Status',
		'other'  => 'Other',
	];

	/**
	 * Sources of maintenance activity.
	 */
	const SOURCES = [
		'local'  => 'Local',
		'remote' => 'Remote',
	];

	/**
	 * Registered row-collector sources, keyed by identifier.
	 *
	 * Each entry is a callable receiving ( int $since_ts ) and returning
	 * an array of normalized rows with at minimum:
	 *   - timestamp    (string, UTC MySQL)
	 *   - event_type   (string)
	 *   - severity     (string: info|notice|warning|critical)
	 *   - category     (string, one of CATEGORIES keys)
	 *   - source       (string: local|remote)
	 *   - user_login   (string)
	 *   - actor_role   (string)
	 *   - description  (string, plain-language)
	 *
	 * Third-party CB plugins register here to contribute their own
	 * activity to the Maintenance Report without patching Core Blueprint.
	 *
	 * Example (from CB Invoice):
	 *   MaintenanceReport::register_source( 'cb_invoice', function ( $since ) {
	 *       return CB_Invoice_Activity::collect_since( $since );
	 *   } );
	 *
	 * @var array<string, callable>
	 */
	private static array $sources = [];

	/**
	 * True once built-ins are registered + the filter has fired.
	 */
	private static bool $sources_initialized = false;

	/**
	 * Register a maintenance activity source.
	 *
	 * @param string   $id        Unique identifier (slug-style).
	 * @param callable $collector fn( int $since_ts ) : array<int, array>
	 */
	public static function register_source( string $id, callable $collector ): void {
		if ( '' === $id ) {
			_doing_it_wrong( __METHOD__, 'Source id cannot be empty.', '1.0.0' );
			return;
		}
		self::ensure_sources_initialized();
		self::$sources[ $id ] = $collector;
	}

	/**
	 * All registered sources, keyed by id.
	 *
	 * @return array<string, callable>
	 */
	public static function all_sources(): array {
		self::ensure_sources_initialized();
		return self::$sources;
	}

	/**
	 * Run every registered collector and return the concatenated rows.
	 */
	private static function collect_all_rows( int $since_ts ): array {
		self::ensure_sources_initialized();
		$rows = [];
		foreach ( self::$sources as $id => $collector ) {
			try {
				$result = (array) $collector( $since_ts );
				foreach ( $result as $row ) {
					// Tag with source id for debugging. Optional - row keys
					// from Core Blueprint's own collectors already carry this
					// info via the 'source' field.
					if ( is_array( $row ) ) {
						$row['_source_id'] = $id;
						$rows[]            = $row;
					}
				}
			} catch ( \Throwable $e ) {
				// One faulty source must not break the whole report.
				if ( WP_DEBUG ) {
					error_log( "CB Maintenance Report source '{$id}' failed: " . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only collector diagnostic.
				}
			}
		}
		return $rows;
	}

	/**
	 * Register built-in sources + fire the extension filter. Lazy.
	 */
	private static function ensure_sources_initialized(): void {
		if ( self::$sources_initialized ) {
			return;
		}
		self::$sources_initialized = true;

		// Core Blueprint's own source - System Log (core/plugin/theme/user).
		// Beacon's Connection Log is no longer built-in here; Beacon registers
		// its own source from boot_paired_hooks() via register_source(). That
		// means MaintenanceReport has zero knowledge of Beacon now.
		self::$sources['system_log'] = [ __CLASS__, 'collect_system_rows' ];

		/**
		 * Filter: cb_core_maintenance_sources
		 *
		 * Contribute additional activity sources to the Maintenance Report.
		 * Each callable must accept an int $since_ts and return an array
		 * of normalized rows. See class docblock for the row shape.
		 *
		 * @param array<string, callable> $sources
		 */
		$filtered = apply_filters( 'cb_core_maintenance_sources', self::$sources );
		if ( is_array( $filtered ) ) {
			self::$sources = $filtered;
		}
	}

	/**
	 * Query combined maintenance events.
	 *
	 * @param array $args Filters:
	 *   - actor     (string) user_login to filter on
	 *   - category  (string) one of CATEGORIES keys
	 *   - source    (string) 'local' | 'remote'
	 *   - since     (int)    unix timestamp - events at or after
	 *   - page      (int)    1-based
	 *   - per_page  (int)    rows per page (max 200)
	 *
	 * @return array {
	 *   rows:     array<int, array> normalized rows
	 *   total:    int
	 *   page:     int
	 *   per_page: int
	 * }
	 */
	public static function query( array $args = [] ): array {
		$defaults = [
			'actor'    => '',
			'category' => '',
			'source'   => '',
			'since'    => 0,
			'page'     => 1,
			'per_page' => 50,
		];
		$args = wp_parse_args( $args, $defaults );

		$combined = self::collect_all_rows( (int) $args['since'] );

		// Sort by timestamp, newest first.
		usort( $combined, static function ( $a, $b ) {
			return strcmp( (string) $b['timestamp'], (string) $a['timestamp'] );
		} );

		// Apply filters in-memory.
		if ( '' !== $args['actor'] ) {
			$combined = array_values( array_filter(
				$combined,
				static fn( $r ) => ( $r['user_login'] ?? '' ) === $args['actor']
			) );
		}
		if ( '' !== $args['category'] ) {
			$combined = array_values( array_filter(
				$combined,
				static fn( $r ) => ( $r['category'] ?? '' ) === $args['category']
			) );
		}
		if ( '' !== $args['source'] ) {
			$combined = array_values( array_filter(
				$combined,
				static fn( $r ) => ( $r['source'] ?? '' ) === $args['source']
			) );
		}

		$total    = count( $combined );
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$rows     = array_slice( $combined, $offset, $per_page );

		return [
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Compute counts per category for the last N days. Used by the
	 * summary header on the report page.
	 *
	 * @return array<string, int> Keys are category slugs; values are counts.
	 */
	public static function summary_counts( int $days = 30 ): array {
		$since    = strtotime( sprintf( '-%d days', $days ) );
		$combined = self::collect_all_rows( $since );

		$counts = array_fill_keys( array_keys( self::CATEGORIES ), 0 );
		$counts['_total']  = count( $combined );
		$counts['_local']  = 0;
		$counts['_remote'] = 0;
		foreach ( $combined as $row ) {
			$cat = $row['category'] ?? 'other';
			if ( isset( $counts[ $cat ] ) ) {
				$counts[ $cat ]++;
			}
			if ( 'local' === ( $row['source'] ?? '' ) ) {
				$counts['_local']++;
			} elseif ( 'remote' === ( $row['source'] ?? '' ) ) {
				$counts['_remote']++;
			}
		}
		return $counts;
	}

	/**
	 * Client-facing KPI snapshot - everything the dashboard-strip on the
	 * Maintenance Report needs in one structured payload.
	 *
	 * SLA thresholds (calibrated to "Standard preset = biweekly cadence"):
	 *   ok       : last event ≤ 14 days ago
	 *   warn     : 14 < days ≤ 21
	 *   overdue  : > 21 days OR never
	 *
	 * @return array {
	 *   last_backup       : ['age_seconds' => int|null, 'state' => string, 'timestamp' => string],
	 *   last_update       : same shape,
	 *   total_events      : int   (last 30 days),
	 *   total_prev        : int   (days 31-60, for trend delta),
	 *   trend_pct         : int   (signed, null if prev was zero),
	 *   active_users      : ['count' => int, 'list' => string[]],
	 *   top_category      : ['slug' => string, 'count' => int, 'label' => string],
	 *   sla_status        : 'ok' | 'warn' | 'overdue',
	 *   daily_counts      : array<int, ['date' => 'Y-m-d', 'count' => int]>  (30 entries, oldest first)
	 * }
	 */
	public static function kpi_snapshot( int $days = 30 ): array {
		// Pull a wide window once - 60 days covers current + previous period
		// for trend calculation; 90 days would give us more headroom but
		// 60 matches typical retention for most CB installs.
		$wide_since = strtotime( sprintf( '-%d days', $days * 2 ) );
		$combined   = self::collect_all_rows( $wide_since );

		$now           = time();
		$current_since = strtotime( sprintf( '-%d days', $days ) );
		$prev_since    = strtotime( sprintf( '-%d days', $days * 2 ) );
		$prev_until    = $current_since;

		$current_events = [];
		$prev_events    = [];
		foreach ( $combined as $row ) {
			$ts = '' !== $row['timestamp'] ? strtotime( $row['timestamp'] . ' UTC' ) : 0;
			if ( $ts <= 0 ) {
				continue;
			}
			if ( $ts >= $current_since ) {
				$current_events[] = $row;
			} elseif ( $ts >= $prev_since && $ts < $prev_until ) {
				$prev_events[] = $row;
			}
		}

		// ─── Last backup / last update ────────────────────────────────
		$last_backup = self::find_latest_by_category( $combined, [ 'backup' ] );
		$last_update = self::find_latest_by_category( $combined, [ 'update', 'plugin', 'theme', 'core' ] );

		$backup_state = self::classify_freshness( $last_backup['age_seconds'] ?? null );
		$update_state = self::classify_freshness( $last_update['age_seconds'] ?? null );

		// ─── Trend ────────────────────────────────────────────────────
		$total_cur  = count( $current_events );
		$total_prev = count( $prev_events );
		$trend_pct  = null;
		if ( $total_prev > 0 ) {
			$trend_pct = (int) round( ( ( $total_cur - $total_prev ) / $total_prev ) * 100 );
		}

		// ─── Active users (in the current window) ─────────────────────
		$user_set = [];
		foreach ( $current_events as $row ) {
			$login = trim( (string) ( $row['user_login'] ?? '' ) );
			if ( '' !== $login ) {
				$user_set[ $login ] = true;
			}
		}
		$active_users = [
			'count' => count( $user_set ),
			'list'  => array_keys( $user_set ),
		];

		// ─── Top category (in the current window) ─────────────────────
		$cat_counts = array_fill_keys( array_keys( self::CATEGORIES ), 0 );
		foreach ( $current_events as $row ) {
			$cat = $row['category'] ?? 'other';
			if ( isset( $cat_counts[ $cat ] ) ) {
				$cat_counts[ $cat ]++;
			}
		}
		arsort( $cat_counts );
		$top_slug  = array_key_first( $cat_counts );
		$top_count = $top_slug ? $cat_counts[ $top_slug ] : 0;
		$top_category = [
			'slug'  => $top_count > 0 ? $top_slug : '',
			'count' => $top_count,
			'label' => $top_count > 0 ? self::category_label( $top_slug ) : '',
		];

		// ─── Overall SLA status ───────────────────────────────────────
		// Precedence: overdue > warn > ok > unknown.
		// If both components are unknown, the rollup is unknown too -
		// we have nothing to report on rather than claiming all is well
		// OR that something is broken.
		$states       = [ $backup_state, $update_state ];
		$known_states = array_filter( $states, static fn( $s ) => 'unknown' !== $s );
		if ( empty( $known_states ) ) {
			$sla_status = 'unknown';
		} elseif ( in_array( 'overdue', $known_states, true ) ) {
			$sla_status = 'overdue';
		} elseif ( in_array( 'warn', $known_states, true ) ) {
			$sla_status = 'warn';
		} else {
			$sla_status = 'ok';
		}

		// ─── Daily counts for the line chart ──────────────────────────
		$daily = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day_ts = $now - ( $i * DAY_IN_SECONDS );
			$key    = gmdate( 'Y-m-d', $day_ts );
			$daily[ $key ] = [ 'date' => $key, 'count' => 0 ];
		}
		foreach ( $current_events as $row ) {
			$ts = '' !== $row['timestamp'] ? strtotime( $row['timestamp'] . ' UTC' ) : 0;
			if ( $ts <= 0 ) {
				continue;
			}
			$key = gmdate( 'Y-m-d', $ts );
			if ( isset( $daily[ $key ] ) ) {
				$daily[ $key ]['count']++;
			}
		}

		return [
			'last_backup'  => [
				'age_seconds' => $last_backup['age_seconds'] ?? null,
				'state'       => $backup_state,
				'timestamp'   => $last_backup['timestamp']   ?? '',
			],
			'last_update'  => [
				'age_seconds' => $last_update['age_seconds'] ?? null,
				'state'       => $update_state,
				'timestamp'   => $last_update['timestamp']   ?? '',
			],
			'total_events' => $total_cur,
			'total_prev'   => $total_prev,
			'trend_pct'    => $trend_pct,
			'active_users' => $active_users,
			'top_category' => $top_category,
			'sla_status'   => $sla_status,
			'daily_counts' => array_values( $daily ),
		];
	}

	/**
	 * Find the most recent event whose category matches one of the given
	 * slugs. Returns null-age shape when nothing matches.
	 */
	private static function find_latest_by_category( array $rows, array $categories ): array {
		$latest_ts   = 0;
		$latest_iso  = '';
		foreach ( $rows as $row ) {
			$cat = $row['category'] ?? '';
			if ( ! in_array( $cat, $categories, true ) ) {
				continue;
			}
			$ts = '' !== $row['timestamp'] ? strtotime( $row['timestamp'] . ' UTC' ) : 0;
			if ( $ts > $latest_ts ) {
				$latest_ts  = $ts;
				$latest_iso = (string) $row['timestamp'];
			}
		}
		if ( $latest_ts <= 0 ) {
			return [ 'age_seconds' => null, 'timestamp' => '' ];
		}
		return [
			'age_seconds' => max( 0, time() - $latest_ts ),
			'timestamp'   => $latest_iso,
		];
	}

	/**
	 * Map a freshness age (seconds) to a state slug for tile rendering.
	 * Thresholds calibrated to biweekly basis-pakket with 1-week buffer.
	 */
	private static function classify_freshness( ?int $age_seconds ): string {
		if ( null === $age_seconds ) {
			// No event on record. Could mean (a) Core Blueprint just started
			// tracking, (b) the action is performed by an external tool
			// that doesn't write to the audit log. Either way we cannot
			// honestly call that "overdue" - mark it unknown so the UI
			// can render a neutral state.
			return 'unknown';
		}
		if ( $age_seconds <= 14 * DAY_IN_SECONDS ) {
			return 'ok';
		}
		if ( $age_seconds <= 21 * DAY_IN_SECONDS ) {
			return 'warn';
		}
		return 'overdue';
	}

	/**
	 * Human-friendly relative age ("3 days ago", "2 months ago").
	 * Returns a localized label. Null input → localized "Never".
	 */
	public static function format_age( ?int $age_seconds ): string {
		if ( null === $age_seconds ) {
			return __( 'Never', 'core-blueprint' );
		}
		if ( $age_seconds < HOUR_IN_SECONDS ) {
			$mins = max( 1, (int) round( $age_seconds / MINUTE_IN_SECONDS ) );
			/* translators: %d: number of minutes. */
			return sprintf( _n( '%d minute ago', '%d minutes ago', $mins, 'core-blueprint' ), $mins );
		}
		if ( $age_seconds < DAY_IN_SECONDS ) {
			$hours = max( 1, (int) round( $age_seconds / HOUR_IN_SECONDS ) );
			/* translators: %d: number of hours. */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'core-blueprint' ), $hours );
		}
		if ( $age_seconds < 30 * DAY_IN_SECONDS ) {
			$days = max( 1, (int) round( $age_seconds / DAY_IN_SECONDS ) );
			/* translators: %d: number of days. */
			return sprintf( _n( '%d day ago', '%d days ago', $days, 'core-blueprint' ), $days );
		}
		$months = max( 1, (int) round( $age_seconds / ( 30 * DAY_IN_SECONDS ) ) );
		/* translators: %d: number of months. */
		return sprintf( _n( '%d month ago', '%d months ago', $months, 'core-blueprint' ), $months );
	}

	/**
	 * List of distinct actors (user_login strings) who appear in the
	 * maintenance log. Used to populate the actor filter dropdown.
	 */
	public static function known_actors(): array {
		$rows    = self::collect_all_rows( 0 );
		$actors  = [];
		foreach ( $rows as $row ) {
			$login = trim( (string) ( $row['user_login'] ?? '' ) );
			if ( '' !== $login ) {
				$actors[ $login ] = true;
			}
		}
		$list = array_keys( $actors );
		sort( $list, SORT_STRING | SORT_FLAG_CASE );
		return $list;
	}

	// ─── Source collectors ────────────────────────────────────────────────

	/**
	 * Pull system.* events from Core Blueprint's audit log and normalize.
	 */
	private static function collect_system_rows( int $since_ts ): array {
		if ( ! class_exists( AuditLog::class ) || ! class_exists( SystemLog::class ) ) {
			return [];
		}

		// event_prefix is matched against DB values, which are dotless
		// because AuditLog::log() sanitize_key()-normalises at write time.
		// So 'system.foo' becomes 'systemfoo' in the DB; the prefix must
		// match that stored form.
		$sys_args = [
			'event_prefix' => 'system',
			'per_page'     => 500,
			'page'         => 1,
		];
		if ( $since_ts > 0 ) {
			$sys_args['since'] = gmdate( 'Y-m-d H:i:s', $since_ts );
		}

		$result = AuditLog::query( $sys_args );
		$rows   = $result['rows'] ?? [];

		$out = [];
		foreach ( $rows as $r ) {
			$context = is_array( $r->context_decoded ?? null ) ? $r->context_decoded : [];

			// Technical: SystemLog's templated sentence keeps its original
			// shape (e.g. "Plugin activated: Yoast SEO"). This has always
			// been the value of `description` and stays as such for CSV
			// export and external listener BC.
			$desc_technical = SystemLog::describe( (string) $r->event_type, $context );

			// Plain: Language helper turns event_type + context into a
			// fully-formed sentence ("Plugin 'Yoast SEO' was activated").
			// Falls back to the technical form when there's no plain
			// translation yet - no silent data loss.
			$desc_plain = class_exists( Language::class )
				? Language::describe_event( (string) $r->event_type, $context, 'plain' )
				: $desc_technical;

			$out[] = [
				'timestamp'             => (string) $r->created_at,
				'source'                => 'local',
				'category'              => self::system_category( (string) $r->event_type ),
				'user_login'            => (string) ( $r->user_login ?? '' ),
				'actor_role'            => '',
				'description'           => $desc_technical,
				'description_technical' => $desc_technical,
				'description_plain'     => $desc_plain,
				'severity'              => (string) ( $r->severity ?? 'info' ),
				'event_type'            => (string) $r->event_type,
				'ip'                    => (string) ( $r->ip_address ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Map a system_* event_type to its unified category slug.
	 *
	 * Events are stored in normalised form (dot → underscore) by
	 * AuditLog::normalize_event_type(), so 'system.plugin_activated'
	 * lives as 'system_plugin_activated'. Single-prefix match against
	 * the normalised form; if the caller passes a raw dotted slug,
	 * normalise it first so both work.
	 */
	private static function system_category( string $event_type ): string {
		$et = class_exists( AuditLog::class )
			? AuditLog::normalize_event_type( $event_type )
			: $event_type;
		if ( 0 === strpos( $et, 'system_plugin_' ) ) {
			return 'plugin';
		}
		if ( 0 === strpos( $et, 'system_theme_' ) ) {
			return 'theme';
		}
		if ( 0 === strpos( $et, 'system_core_' ) ) {
			return 'core';
		}
		if ( 0 === strpos( $et, 'system_user_' ) ) {
			return 'user';
		}
		if ( 0 === strpos( $et, 'system_foundation_' ) ) {
			return 'update';
		}
		return 'other';
	}

	/**
	 * Translated label for a category slug (used in UI rendering).
	 */
	public static function category_label( string $slug ): string {
		$labels = [
			'plugin' => __( 'Plugin', 'core-blueprint' ),
			'theme'  => __( 'Theme',  'core-blueprint' ),
			'core'   => __( 'Core',   'core-blueprint' ),
			'user'   => __( 'User',   'core-blueprint' ),
			'backup' => __( 'Backup', 'core-blueprint' ),
			'update' => __( 'Update', 'core-blueprint' ),
			'status' => __( 'Status', 'core-blueprint' ),
			'other'  => __( 'Other',  'core-blueprint' ),
		];
		return $labels[ $slug ] ?? $slug;
	}

	public static function source_label( string $slug ): string {
		$labels = [
			'local'  => __( 'Local',  'core-blueprint' ),
			'remote' => __( 'Remote', 'core-blueprint' ),
		];
		return $labels[ $slug ] ?? $slug;
	}

	/**
	 * Stream the combined maintenance report as CSV to a file handle.
	 *
	 * Uses the same filter semantics as query() so whatever the user is
	 * currently viewing in the UI gets exported. Forces per_page=1000
	 * so even busy sites export in a single pass (tables are retention-
	 * limited to 90 days anyway).
	 *
	 * @param resource $handle Open file handle (typically php://output).
	 * @param array    $args   Same filter args as query().
	 * @return int Number of data rows written.
	 */
	/**
	 * Columns exposed by maintenance report exports. Order matches what
	 * users see on screen, plus machine-readable fields (event_type,
	 * category, source) appended for precision in the JSON / CSV output.
	 *
	 * @return array<string,string>
	 */
	public static function columns(): array {
		return [
			'timestamp'   => __( 'time', 'core-blueprint' ),
			'description' => __( 'description', 'core-blueprint' ),
			'user_login'  => __( 'user', 'core-blueprint' ),
			'actor_role'  => __( 'role', 'core-blueprint' ),
			'category'    => __( 'category', 'core-blueprint' ),
			'source'      => __( 'source', 'core-blueprint' ),
			'severity'    => __( 'severity', 'core-blueprint' ),
			'event_type'  => __( 'event_type', 'core-blueprint' ),
		];
	}

	/**
	 * Yield maintenance report rows for an export. MaintenanceReport's
	 * query() is already in-memory (merges multiple sources + sorts) so
	 * "streaming" here is a wrapper that resolves category/source slugs
	 * to their human labels and yields one row at a time.
	 *
	 * @param array $args Same filter args as query().
	 * @return \Generator<int, array<string,mixed>>
	 */
	public static function rows_iterator( array $args = [] ): \Generator {
		$args = array_merge( [
			'actor'    => '',
			'category' => '',
			'source'   => '',
			'since'    => 0,
			'page'     => 1,
			'per_page' => 1000,
		], $args );

		$result = self::query( $args );
		foreach ( $result['rows'] ?? [] as $row ) {
			yield [
				'timestamp'   => (string) ( $row['timestamp']   ?? '' ),
				'description' => (string) ( $row['description'] ?? '' ),
				'user_login'  => (string) ( $row['user_login']  ?? '' ),
				'actor_role'  => (string) ( $row['actor_role']  ?? '' ),
				'category'    => self::category_label( (string) ( $row['category'] ?? 'other' ) ),
				'source'      => self::source_label( (string) ( $row['source'] ?? '' ) ),
				'severity'    => (string) ( $row['severity']    ?? '' ),
				'event_type'  => (string) ( $row['event_type']  ?? '' ),
			];
		}
	}

	/**
	 * Envelope metadata for JSON exports / PDF cover pages.
	 * Includes the Plain/Technical description pair from the Language
	 * catalog so exported reports are self-describing.
	 */
	public static function export_meta( array $filters = [] ): array {
		$meta = LogExporter::base_meta( 'maintenance_report', $filters );
		if ( class_exists( Language::class ) ) {
			$meta['description'] = Language::describe_log_both( 'maintenance' );
		}
		return $meta;
	}

	/**
	 * Export maintenance report to CSV. Kept for BC; delegates to LogExporter
	 * so all format-specific logic lives in one place.
	 *
	 * Uses the same filter semantics as query() so whatever the user is
	 * currently viewing in the UI gets exported. Forces per_page=1000
	 * so even busy sites export in a single pass (tables are retention-
	 * limited to 90 days anyway).
	 *
	 * @param resource $handle Open file handle (typically php://output).
	 * @param array    $args   Same filter args as query().
	 * @return int Number of data rows written.
	 */
	public static function export_csv( $handle, array $args = [] ): int {
		return LogExporter::to_csv( $handle, self::rows_iterator( $args ), self::columns() );
	}
}
