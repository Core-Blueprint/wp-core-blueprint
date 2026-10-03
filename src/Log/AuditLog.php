<?php
declare(strict_types=1);
/**
 * AuditLog
 *
 * Central audit log for Core Blueprint. Every security-relevant event in the
 * plugin passes through this class. Also provides the reader interface used
 * by the admin audit log viewer.
 *
 * Event naming convention: dotted lowercase, e.g.
 *   - 'plugin.activated'
 *   - 'login.success'
 *   - 'login.failed'
 *   - 'failsafe.emergency_activated'
 *   - 'settings.changed'
 *
 * Severities: 'info' | 'notice' | 'warning' | 'critical'.
 *
 * The context parameter accepts any JSON-serializable array. Sensitive data
 * (passwords, full tokens, secret keys) must NEVER be passed as context -
 * only hints, hashes, or IDs.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Log;

use CB\Core\Governance\RetentionPolicy;

use CB\Core\DB;
use CB\Core\DB\QueryBuilder;
use CB\Core\Privacy\Anonymizer;
use CB\Core\Governance\EventRegistry;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	/** Valid severity levels. */
	const SEVERITIES = [ 'info', 'notice', 'warning', 'critical' ];

	/** Maximum user_agent length stored to prevent bloat. */
	const UA_MAX_LENGTH = 512;

	//  Write 

	/**
	 * Write a single audit event.
	 *
	 * @param string $event_type Dotted event type, e.g. 'login.failed'.
	 * @param string $severity   One of SEVERITIES.
	 * @param array  $context    JSON-serializable event-specific payload.
	 * @return int|false Insert ID on success, false on failure.
	 */
	/**
	 * In-memory dedup cache: event_type|user_id|context_hash => unix_ts.
	 * Prevents the same event+actor+context from being logged twice within
	 * the dedup window during one PHP request. Does NOT persist across
	 * requests - that would require a DB-backed cache with its own write
	 * cost, and most duplication bursts happen in a single request anyway
	 * (plugin loops, bulk settings imports).
	 *
	 * @var array<string, int>
	 */
	private static array $dedup_cache = [];

	/** Dedup window in seconds. Same event+context within this window = dropped. */
	const DEDUP_WINDOW_SECONDS = 60;

	/**
	 * Pending queued events awaiting flush. Filled by queue(), drained by
	 * flush() on the shutdown hook.
	 *
	 * @var array<int, array{event_type: string, severity: string, context: array}>
	 */
	private static array $queue = [];

	/**
	 * Register the shutdown flusher. Called once per request by Core.
	 */
	public static function init_queue(): void {
		add_action( 'shutdown', [ __CLASS__, 'flush_queue' ], 0 );
	}

	/**
	 * Normalise an event-type slug to its canonical storage form.
	 *
	 * Source code uses dotted slugs ('system.login', 'console.executed',
	 * 'beacon.cli_ping') because they read like namespaces and group
	 * naturally. WordPress's sanitize_key() strips dots without
	 * replacement, which would collapse 'system.login' to 'systemlogin' -
	 * unreadable in Technical mode and indistinguishable from a
	 * hypothetical 'systemlogin' event.
	 *
	 * Convert dot to underscore first, THEN sanitize. Result: 'system.login'
	 *  'system_login'. Round-trips through sanitize_key safely (underscores
	 * are preserved); idempotent on already-underscored slugs.
	 *
	 * 50-char cap matches the DB column width.
	 *
	 * @since   1.0.0
	 */
	public static function normalize_event_type( string $raw ): string {
		$with_underscore = str_replace( '.', '_', $raw );
		return substr( sanitize_key( $with_underscore ), 0, 50 );
	}

	/**
	 * Queue a non-critical event for batched writing at shutdown. Use for
	 * high-volume events (logins, option changes) where a few ms of
	 * latency is fine and PHP-fatal loss is acceptable.
	 *
	 * For critical events (panic button, security incidents) use log()
	 * directly - queued events are lost if PHP crashes before shutdown.
	 */
	public static function queue( string $event_type, string $severity = 'info', array $context = [] ): void {
		self::$queue[] = [
			'event_type' => $event_type,
			'severity'   => $severity,
			'context'    => $context,
		];
	}

	/**
	 * Flush queued events to the DB. Called on shutdown. Safe to call
	 * multiple times (queue drains as it goes).
	 */
	public static function flush_queue(): void {
		if ( empty( self::$queue ) ) {
			return;
		}
		// Snapshot + empty so re-entry doesn't double-write.
		$pending      = self::$queue;
		self::$queue  = [];
		foreach ( $pending as $event ) {
			self::log( $event['event_type'], $event['severity'], $event['context'] );
		}
	}

	/**
	 * Write an audit event directly. Synchronous - the row is committed
	 * before this method returns. Use for critical events (security
	 * incidents, governance changes) that must not be lost on PHP fatal.
	 *
	 * For high-volume non-critical events (logins, option changes) prefer
	 * queue() which batches writes on shutdown.
	 *
	 * Deduplication: if the same (event_type + user_id + context_hash)
	 * combination was logged within the DEDUP_WINDOW_SECONDS, the call
	 * is silently dropped and returns 0 (distinct from 'false' failure).
	 */
	public static function log( string $event_type, string $severity = 'info', array $context = [] ): int|false {
		global $wpdb;

		$event_type = self::normalize_event_type( $event_type );
		if ( empty( $event_type ) ) {
			return false;
		}

		if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
			$severity = 'info';
		}

		// Verbosity filter - only applies to system_* events (user-configurable
		// categories). Core Blueprint's own events (security_*, settings_*, etc.)
		// are always logged; their existence is itself a security signal.
		if ( class_exists( Verbosity::class ) && 0 === strpos( $event_type, 'system_' ) ) {
			if ( ! Verbosity::should_log( $event_type, $severity ) ) {
				return 0;
			}
		}

		$user_id    = get_current_user_id() ?: null;
		$user_login = null;
		if ( $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				$user_login = $user->user_login;
			}
		}

		// Deduplication check - drop identical events within the window.
		// Critical severity bypasses dedup since those may come in bursts
		// but each one genuinely matters (e.g. repeated failed logins).
		if ( ! in_array( $severity, [ 'warning', 'critical' ], true ) ) {
			$hash_key = $event_type . '|' . ( $user_id ?: 0 ) . '|' . md5( wp_json_encode( $context ) ?: '' );
			$now      = time();
			if ( isset( self::$dedup_cache[ $hash_key ] ) ) {
				$age = $now - self::$dedup_cache[ $hash_key ];
				if ( $age < self::DEDUP_WINDOW_SECONDS ) {
					// Duplicate within window - skip.
					return 0;
				}
			}
			self::$dedup_cache[ $hash_key ] = $now;

			// Keep cache size bounded - evict entries older than the window.
			if ( count( self::$dedup_cache ) > 1000 ) {
				$cutoff = $now - self::DEDUP_WINDOW_SECONDS;
				self::$dedup_cache = array_filter(
					self::$dedup_cache,
					static fn( $ts ) => $ts >= $cutoff
				);
			}
		}

		// IP handling - run through the privacy layer so the stored value
		// respects the configured anonymization mode.
		$raw_ip = self::detect_ip();
		$ip     = class_exists( Anonymizer::class )
			? Anonymizer::anonymize_ip( $raw_ip )
			: (string) ( $raw_ip ?? '' );
		// Keep nullable column contract - empty string  null.
		$ip = '' === $ip ? null : $ip;

		$ua = self::detect_user_agent();

		$context_json = ! empty( $context ) ? wp_json_encode( $context ) : null;
		if ( false === $context_json ) {
			$context_json = null; // JSON encoding failed - silently drop payload
		}

		$result = $wpdb->insert(
			DB::audit_log_table(),
			[
				'event_type' => $event_type,
				'severity'   => $severity,
				'user_id'    => $user_id,
				'user_login' => $user_login,
				'ip_address' => $ip,
				'user_agent' => $ua,
				'context'    => $context_json,
				// created_at defaults to CURRENT_TIMESTAMP
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		$insert_id = false === $result ? false : (int) $wpdb->insert_id;

		if ( false !== $insert_id ) {
			do_action( 'cb_core_audit_log_written', $insert_id, $event_type, $severity, $context );
		}

		return $insert_id;
	}

	//  Read 

	/**
	 * Query the audit log with filters + pagination.
	 *
	 * @param array $args {
	 *     @type string      $event_type Exact match (optional).
	 *     @type string      $event_like LIKE match on event_type (optional).
	 *     @type string      $severity   Exact match (optional).
	 *     @type int         $user_id    Exact match (optional).
	 *     @type string      $since      ISO datetime, events >= this (optional).
	 *     @type string      $until      ISO datetime, events <= this (optional).
	 *     @type int         $per_page   Default 50, max 500.
	 *     @type int         $page       1-indexed page number.
	 * }
	 * @return array{ rows: array, total: int, page: int, per_page: int }
	 */
	public static function query( array $args = [] ): array {
		$defaults = [
			'event_type'       => '',
			'event_like'       => '',
			'event_prefix'     => '',
			'event_not_prefix' => '',
			'severity'         => '',
			'user_id'          => 0,
			'since'            => '',
			'until'            => '',
			'per_page'         => 50,
			'page'             => 1,
		];
		$args = wp_parse_args( $args, $defaults );

		// event_like is a convenience that accepts a dotted slug fragment
		// - sanitize once here so the builder gets a clean input.
		$event_like = isset( $args['event_like'] ) ? sanitize_key( (string) $args['event_like'] ) : '';

		$qb = ( new QueryBuilder( DB::audit_log_table() ) )
			->equals_if_set( 'event_type', $args['event_type'], 'sanitize_key' )
			->like_if_set( 'event_type', $event_like )
			->starts_with_if_set( 'event_type', $args['event_prefix'] )
			->not_starts_with_if_set( 'event_type', $args['event_not_prefix'] )
			->equals_enum_if_set( 'severity', $args['severity'], self::SEVERITIES )
			->int_equals_if_set( 'user_id', $args['user_id'] )
			->gte_if_set( 'created_at', $args['since'] )
			->lte_if_set( 'created_at', $args['until'] )
			->order_by_desc( 'id' )
			->paginate( (int) $args['page'], (int) $args['per_page'] );

		$result = $qb->get_paginated( (int) $args['page'], (int) $args['per_page'] );

		// Decode context JSON on each row for convenience - callers iterate
		// over `context_decoded` instead of re-parsing the raw string.
		foreach ( $result['rows'] as $row ) {
			if ( ! empty( $row->context ) ) {
				$decoded              = json_decode( $row->context, true );
				$row->context_decoded = is_array( $decoded ) ? $decoded : null;
			} else {
				$row->context_decoded = null;
			}
		}

		return $result;
	}

	/**
	 * Get a single event by ID. Used for detail views.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$table = DB::audit_log_table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i binds the Base-owned table identifier and the row ID uses %d.

		if ( $row && ! empty( $row->context ) ) {
			$decoded = json_decode( $row->context, true );
			$row->context_decoded = is_array( $decoded ) ? $decoded : null;
		}

		return $row ?: null;
	}

	//  Retention 


	/**
	 * Prune events of one canonical retention category older than $days.
	 *
	 * Selection deliberately happens through RetentionPolicy::category_for_event()
	 * rather than a second SQL prefix map. Rows are scanned in bounded batches,
	 * classified by the one canonical mapper, then deleted by exact primary key.
	 *
	 * @param string $category Canonical retention category.
	 * @param int    $days     Retention window in days. < 1 = keep forever.
	 */
	public static function prune_by_category( string $category, int $days ): int {
		global $wpdb;

		if ( $days < 1 || ! RetentionPolicy::is_category( $category ) ) {
			return 0;
		}

		$table       = DB::audit_log_table();
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$batch_size  = 5000;
		$max_batches = 20;
		$last_id     = 0;
		$total       = 0;

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- %i binds the Base-owned table identifier; remaining values use typed placeholders.
				$wpdb->prepare(
					'SELECT id, event_type FROM %i WHERE created_at < %s AND id > %d ORDER BY id ASC LIMIT %d',
					$table,
					$cutoff,
					$last_id,
					$batch_size
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			$delete_ids = [];
			foreach ( $rows as $row ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id > $last_id ) {
					$last_id = $id;
				}
				if ( $id > 0 && RetentionPolicy::category_for_event( (string) ( $row['event_type'] ?? '' ) ) === $category ) {
					$delete_ids[] = $id;
				}
			}

			if ( ! empty( $delete_ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $delete_ids ), '%d' ) );
				$sql = $wpdb->prepare(
					"DELETE FROM %i WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- generated %d placeholders from integer IDs.
					array_merge( [ $table ], $delete_ids )
				);
				$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is produced immediately above by prepare() with %i and generated %d placeholders.
				if ( false !== $deleted ) {
					$total += (int) $deleted;
				}
			}

			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		return $total;
	}

	//  Export 

	/**
	 * Columns exposed by audit log exports. Ordered so the CSV column order
	 * and JSON field order match the screen table left-to-right where
	 * sensible. Keys are field slugs (stable for machine consumption);
	 * values are human labels used in CSV headers.
	 *
	 * @return array<string,string>
	 */
	public static function columns(): array {
		return [
			'id'         => __( 'id', 'core-blueprint' ),
			'event_type' => __( 'event_type', 'core-blueprint' ),
			'severity'   => __( 'severity', 'core-blueprint' ),
			'user_id'    => __( 'user_id', 'core-blueprint' ),
			'user_login' => __( 'user_login', 'core-blueprint' ),
			'ip_address' => __( 'ip_address', 'core-blueprint' ),
			'user_agent' => __( 'user_agent', 'core-blueprint' ),
			'context'    => __( 'context', 'core-blueprint' ),
			'created_at' => __( 'created_at', 'core-blueprint' ),
		];
	}

	/**
	 * Yield audit log rows for an export, one at a time. Internally pages
	 * through the DB so memory stays flat regardless of how many rows
	 * match. Consumers get plain associative arrays - the same keys as
	 * columns() returns.
	 *
	 * For JSON consumers, `context` is decoded from its stored JSON string
	 * into a nested structure. For CSV consumers, the raw string would be
	 * preserved - but since CSV gets the same row shape, context is
	 * always decoded here and LogExporter::to_csv() re-encodes it if the
	 * field ends up being an array. Net effect: both formats see clean
	 * structured data.
	 *
	 * @param array $args Same filter args as query(). per_page is forced
	 *                    to the export batch size regardless of caller input.
	 * @return \Generator<int, array<string,mixed>>
	 */
	public static function rows_iterator( array $args = [] ): \Generator {
		$args['per_page'] = 500;
		$args['page']     = 1;

		do {
			$result = self::query( $args );
			foreach ( $result['rows'] as $row ) {
				$context = null;
				if ( ! empty( $row->context ) ) {
					$decoded = json_decode( (string) $row->context, true );
					$context = is_array( $decoded ) ? $decoded : $row->context;
				}
				yield [
					'id'         => (int) $row->id,
					'event_type' => (string) $row->event_type,
					'severity'   => (string) $row->severity,
					'user_id'    => isset( $row->user_id ) ? (int) $row->user_id : null,
					'user_login' => (string) ( $row->user_login ?? '' ),
					'ip_address' => (string) ( $row->ip_address ?? '' ),
					'user_agent' => (string) ( $row->user_agent ?? '' ),
					'context'    => $context,
					'created_at' => (string) $row->created_at,
				];
			}
			$args['page']++;
		} while ( ! empty( $result['rows'] ) && count( $result['rows'] ) === $args['per_page'] );
	}

	/**
	 * Envelope metadata for JSON exports (and PDF cover pages later).
	 * Merges the caller's filter snapshot with LogExporter's standard
	 * site/user/version fields, and appends the Plain/Technical
	 * description pair from Language::LOG_DESCRIPTIONS so the exported
	 * document is self-describing for auditors and clients who receive
	 * the file without having the plugin installed.
	 *
	 * @param string $sub_type   Sub-type for display in the envelope
	 *                           (e.g. 'audit' for the main audit tab,
	 *                           'system_log' for the system tab).
	 * @param array  $filters    Active filters the user applied.
	 */
	public static function export_meta( string $sub_type, array $filters = [] ): array {
		$meta = LogExporter::base_meta( $sub_type, $filters );

		// Map export sub-type to the Language catalog key. 'audit' and
		// 'system_log' come from the same AuditLog source but get
		// separate descriptions in the catalog ('audit' / 'system').
		$catalog_key = 'system_log' === $sub_type ? 'system' : $sub_type;
		if ( class_exists( Language::class ) ) {
			$meta['description'] = Language::describe_log_both( $catalog_key );
		}

		return $meta;
	}

	//  Helpers 

	/**
	 * Detect the client IP address, respecting common reverse-proxy headers.
	 *
	 * Only trusts proxy headers (X-Forwarded-For, CF-Connecting-IP) when the
	 * request originates from a trusted proxy IP range. This prevents IP
	 * spoofing in audit logs while still supporting reverse proxy setups.
	 *
	 * @return string|null The detected IP address, or null if none found.
	 */
	private static function detect_ip(): ?string {
		$trusted_proxies = [
			'127.0.0.1',      // IPv4 localhost
			'::1',            // IPv6 localhost
			'10.0.0.0/8',     // Private network
			'172.16.0.0/12',  // Private network
			'192.168.0.0/16', // Private network
		];

		$remote_addr = sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$candidates = [];

		// Check if the request comes from a trusted proxy
		$is_trusted_proxy = self::is_trusted_proxy( $remote_addr, $trusted_proxies );

		// Only trust proxy headers if the request is from a trusted proxy
		if ( $is_trusted_proxy ) {
			// Cloudflare
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			}

			// Standard reverse proxy
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				// XFF can contain a comma-separated list - the client IP is leftmost.
				$parts = array_map( 'trim', explode( ',', $forwarded ) );
				if ( ! empty( $parts[0] ) ) {
					$candidates[] = $parts[0];
				}
			}
		}

		// Always include REMOTE_ADDR as fallback
		if ( ! empty( $remote_addr ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $remote_addr ) );
		}

		// Return first valid IP from candidates.
		foreach ( $candidates as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return substr( $ip, 0, 45 ); // IPv6 max
			}
		}

		return null;
	}

	/**
	 * Check if an IP address falls within a trusted proxy range.
	 *
	 * @param string $ip     The IP address to check.
	 * @param array  $ranges Array of IP addresses or CIDR ranges.
	 * @return bool True if the IP is in a trusted range.
	 */
	private static function is_trusted_proxy( string $ip, array $ranges ): bool {
		if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			if ( strpos( $range, '/' ) === false ) {
				// Single IP comparison
				if ( $ip === $range ) {
					return true;
				}
			} else {
				// CIDR range comparison
				if ( self::ip_in_range( $ip, $range ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if an IP address is within a CIDR range.
	 *
	 * @param string $ip     The IP address to check.
	 * @param string $range  The CIDR range (e.g., '192.168.1.0/24').
	 * @return bool True if the IP is in the range.
	 */
	private static function ip_in_range( string $ip, string $range ): bool {
		list( $subnet, $mask ) = explode( '/', $range );
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		$mask_long   = ~ ( ( 1 << ( 32 - (int) $mask ) ) - 1 );

		return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
	}

	private static function detect_user_agent(): ?string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		return substr( $ua, 0, self::UA_MAX_LENGTH );
	}

	/**
	 * Human-readable label for an event type. Unknown events return the raw
	 * event_type string.
	 */
	/**
	 * Human-readable label for an event type. In 1.0.15+ the second
	 * parameter selects which flavour of label is returned:
	 *
	 *   - 'technical' (default): concise technical label (\"Plugin activated\")
	 *   - 'plain': full sentence from {@see Language::EVENTS_PLAIN}
	 *     (\"A plugin was activated\"). Falls back to the technical label
	 *     when an event type has no plain translation yet.
	 *
	 * Technical labels are resolved through the canonical governance event
	 * registry. Unknown events fall back to their event ID.
	 */
	public static function event_label( string $event_type, string $mode = 'technical' ): string {
		// Event labels are presentation metadata. Before init, return the raw
		// event slug so an early sibling consumer cannot trigger JIT i18n.
		if ( ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
			return $event_type;
		}

		if ( 'plain' === $mode && class_exists( Language::class ) ) {
			// Language::describe_event falls back to the technical label
			// when no Plain translation exists - so we can hand it the
			// raw event_type and it sorts out the right answer either way.
			return Language::describe_event( $event_type, [], 'plain' );
		}

		$label = EventRegistry::label( $event_type );
		return null !== $label ? $label : $event_type;
	}
}
