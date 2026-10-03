<?php
declare(strict_types=1);
/**
 * Internal database schema migration controller.
 *
 * Public extension registration lives in Database\SchemaRegistry. This class
 * owns reconciliation timing, per-schema locking, exact table verification and
 * the Base audit-log schema implementation.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Database\SchemaRegistry;

defined( 'ABSPATH' ) || exit;

final class DB {

	private const HEALTH_CHECK_OPTION   = 'cb_core_db_health_checked_at';
	private const HEALTH_CHECK_INTERVAL = 600;
	private const LOCK_TTL               = 120;
	private const LOCK_PREFIX            = 'cb_core_schema_lock_';

	/** Audit log table basename (without WP prefix). */
	public const AUDIT_LOG_TABLE = 'cb_core_audit_log';

	private static bool $defaults_registered = false;

	/**
	 * Run the central schema reconciliation sweep.
	 *
	 * Version mismatches reconcile immediately. Exact table-health verification
	 * is throttled so ordinary requests do not execute one SHOW TABLES query per
	 * registered schema.
	 */
	public static function maybe_upgrade(): void {
		self::ensure_defaults_registered();

		$now               = time();
		$last_health_check = (int) get_option( self::HEALTH_CHECK_OPTION, 0 );
		$run_health_check  = $last_health_check <= 0 || ( $now - $last_health_check ) >= self::HEALTH_CHECK_INTERVAL;
		$health_complete   = true;

		foreach ( SchemaRegistry::definitions() as $id => $definition ) {
			$installed = (string) get_option( $definition['option_key'], '0' );
			$current   = (string) $definition['version'];

			if ( version_compare( $installed, $current, '<' ) ) {
				if ( ! self::reconcile_registered_schema( $id, false ) ) {
					$health_complete = false;
				}
				continue;
			}

			if ( $run_health_check && ! self::reconcile_registered_schema( $id, true ) ) {
				$health_complete = false;
			}
		}

		if ( $run_health_check && $health_complete ) {
			update_option( self::HEALTH_CHECK_OPTION, $now, true );
		}

		SchemaRegistry::mark_sweep_complete();
	}

	/**
	 * Reconcile one registered schema. Internal lifecycle API.
	 *
	 * @param string $id           Canonical schema id.
	 * @param bool   $verify_health Whether current-version tables must also be verified.
	 */
	public static function reconcile_registered_schema( string $id, bool $verify_health ): bool {
		self::ensure_defaults_registered();
		$definitions = SchemaRegistry::definitions();
		if ( ! isset( $definitions[ $id ] ) ) {
			return false;
		}

		$definition = $definitions[ $id ];
		$installed  = (string) get_option( $definition['option_key'], '0' );
		$current    = (string) $definition['version'];
		$needs_version = version_compare( $installed, $current, '<' );

		if ( ! $needs_version && ! $verify_health ) {
			return true;
		}

		if ( ! $needs_version && self::all_declared_tables_exist( $definition ) ) {
			return true;
		}

		$lock = self::acquire_schema_lock( $id );
		if ( null === $lock ) {
			return false;
		}

		try {
			// State MUST be re-read after lock acquisition. Another request may
			// have completed the migration while this request was waiting.
			$installed = (string) get_option( $definition['option_key'], '0' );
			$needs_version = version_compare( $installed, $current, '<' );
			$tables_healthy = $verify_health || $needs_version
				? self::all_declared_tables_exist( $definition )
				: true;

			if ( ! $needs_version && $tables_healthy ) {
				return true;
			}

			$result = call_user_func( $definition['install'] );
			if ( false === $result ) {
				self::bootstrap_diagnostic( $id, 'installer_reported_failure' );
				return false;
			}

			// Marker moves only after every declared table exists. Multi-table
			// schemas therefore advance all-or-nothing from Base's perspective.
			if ( ! self::all_declared_tables_exist( $definition ) ) {
				self::bootstrap_diagnostic( $id, 'declared_table_missing_after_install' );
				return false;
			}

			update_option( $definition['option_key'], $current, true );
			return true;
		} catch ( \Throwable $e ) {
			self::bootstrap_diagnostic( $id, 'installer_exception:' . get_class( $e ) );
			return false;
		} finally {
			self::release_schema_lock( $lock );
		}
	}

	/**
	 * @param array{id:string,version:string,option_key:string,tables:array<int,callable>,install:callable} $definition
	 */
	private static function all_declared_tables_exist( array $definition ): bool {
		global $wpdb;

		foreach ( $definition['tables'] as $table_callback ) {
			try {
				$table = (string) call_user_func( $table_callback );
			} catch ( \Throwable $e ) {
				self::bootstrap_diagnostic( $definition['id'], 'table_accessor_exception:' . get_class( $e ) );
				return false;
			}
			if ( '' === $table ) {
				return false;
			}

			// Escape LIKE metacharacters so `_` and `%` in WordPress prefixes or
			// table names are matched literally rather than as wildcards.
			$pattern = $wpdb->esc_like( $table );
			$found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Acquire an owner-token lock atomically. Stale takeover uses a compare-and-
	 * swap UPDATE against the exact previous option value, so two contenders
	 * cannot both claim the same stale lock.
	 *
	 * @return array{key:string,raw:string}|null
	 */
	private static function acquire_schema_lock( string $id ): ?array {
		global $wpdb;

		$key     = self::LOCK_PREFIX . md5( $id );
		$payload = [
			'owner'      => wp_generate_uuid4(),
			'acquired_at'=> time(),
		];
		$raw = maybe_serialize( $payload );

		if ( add_option( $key, $payload, '', false ) ) {
			return [ 'key' => $key, 'raw' => $raw ];
		}

		$existing_raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);
		if ( ! is_string( $existing_raw ) || '' === $existing_raw ) {
			return null;
		}

		$existing    = maybe_unserialize( $existing_raw );
		$acquired_at = is_array( $existing ) ? (int) ( $existing['acquired_at'] ?? 0 ) : 0;
		$age         = time() - $acquired_at;
		if ( $acquired_at > 0 && $age >= 0 && $age < self::LOCK_TTL ) {
			return null;
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$raw,
				$key,
				$existing_raw
			)
		);
		if ( 1 !== $affected ) {
			return null;
		}

		wp_cache_delete( $key, 'options' );
		return [ 'key' => $key, 'raw' => $raw ];
	}

	/** @param array{key:string,raw:string} $lock */
	private static function release_schema_lock( array $lock ): void {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock['key'],
				$lock['raw']
			)
		);
		if ( 1 === $affected ) {
			wp_cache_delete( $lock['key'], 'options' );
		}
	}

	private static function bootstrap_diagnostic( string $id, string $reason ): void {
		// Do not depend on AuditLog here: the audit table itself may be the schema
		// currently being repaired. PHP error logging is a bootstrap-safe fallback.
		error_log( sprintf( '[Core Blueprint] Schema reconcile failed for %s (%s).', $id, $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private static function ensure_defaults_registered(): void {
		if ( self::$defaults_registered ) {
			return;
		}
		self::$defaults_registered = true;

		SchemaRegistry::register_base( [
			'id'         => 'audit-log',
			'version'    => CB_CORE_DB_VERSION,
			'option_key' => 'cb_core_db_version',
			'tables'     => [ [ self::class, 'audit_log_table' ] ],
			'install'    => [ self::class, 'create_audit_log_table' ],
		] );
	}

	public static function audit_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::AUDIT_LOG_TABLE;
	}

	/** Create or upgrade the audit log table via idempotent dbDelta. */
	public static function create_audit_log_table(): void {
		global $wpdb;
		$table   = self::audit_log_table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (\n  id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n  event_type  VARCHAR(50)         NOT NULL DEFAULT '',\n  severity    VARCHAR(20)         NOT NULL DEFAULT 'info',\n  user_id     BIGINT(20) UNSIGNED          DEFAULT NULL,\n  user_login  VARCHAR(60)                  DEFAULT NULL,\n  ip_address  VARCHAR(45)                  DEFAULT NULL,\n  user_agent  TEXT                         DEFAULT NULL,\n  context     LONGTEXT                     DEFAULT NULL,\n  created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY  (id),\n  KEY event_type (event_type),\n  KEY severity (severity),\n  KEY user_id (user_id),\n  KEY created_at (created_at)\n) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop_audit_log_table(): void {
		global $wpdb;
		$table = self::audit_log_table();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
