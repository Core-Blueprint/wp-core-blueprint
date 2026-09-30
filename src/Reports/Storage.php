<?php
declare(strict_types=1);
/**
 * Reports Storage - schema and CRUD for immutable maintenance-report snapshots.
 *
 * Reports stores report data, not generated PDF files. A PDF is a
 * transient presentation of the stored snapshot and is rendered only when an
 * authorised user requests it.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Reports;

use CB\Core\Governance\RetentionStoreRegistry;

use CB\Core\Database\SchemaRegistry;

use CB\Core\DB\DeleteBuilder;
use CB\Core\DB\InsertBuilder;
use CB\Core\DB\QueryBuilder;

defined( 'ABSPATH' ) || exit;

final class Storage {

	const TABLE_SLUG = 'cb_maintenance_reports';
	const DB_VERSION = '2.0';
	const DB_OPT_KEY = 'cb_core_reports_db_version';

	const STATUSES = [ 'generated' ];

	const RETENTION_MIN_DAYS = 7;
	const RETENTION_MAX_DAYS = 3650;

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	public static function register_schema(): void {
		SchemaRegistry::register_base( [
			'id'         => 'maintenance-reports',
			'version'    => self::DB_VERSION,
			'option_key' => self::DB_OPT_KEY,
			'tables'     => [ [ self::class, 'table_name' ] ],
			'install'    => [ self::class, 'install_schema' ],
		] );
	}

	/**
	 * Install the canonical snapshot-only schema.
	 *
	 * Reports uses a single table-backed snapshot model. No alternate file-backed
	 * runtime is retained.
	 */
	public static function install_schema(): void {
		global $wpdb;

		$table = self::table_name();

		// Public v1 uses the snapshot schema as its baseline. Reconciliation must
		// therefore be non-destructive even when the version marker is missing or
		// stale: dbDelta() repairs the declared shape in place and preserves rows.
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			period_start DATE NOT NULL,
			period_end DATE NOT NULL,
			generated_at DATETIME NOT NULL,
			generated_by BIGINT(20) UNSIGNED DEFAULT NULL,
			report_data LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'generated',
			PRIMARY KEY  (id),
			KEY period_start (period_start),
			KEY generated_at (generated_at),
			KEY status (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function register_retention_store(): void {
		$days = (int) ( \CB\Core\Settings::get()['reports']['retention_days'] ?? 365 );
		$days = max( self::RETENTION_MIN_DAYS, min( self::RETENTION_MAX_DAYS, $days ) );
		RetentionStoreRegistry::register( [
			'id'           => 'core-maintenance-reports',
			'label'        => __( 'Maintenance reports', 'core-blueprint' ),
			'days'         => $days,
			'prune'        => [ self::class, 'cleanup_expired_registered' ],
			'settings_url' => admin_url( 'admin.php?page=core-blueprint-preferences&tab=reports' ),
		] );
	}


	public static function cleanup_expired_registered( int $days ): int {
		if ( class_exists( State::class ) && ! State::is_enabled() ) {
			return 0;
		}
		return self::cleanup_expired( $days );
	}

	/**
	 * Persist one immutable report snapshot.
	 *
	 * @param array $data {
	 *     @type string $period_start Y-m-d.
	 *     @type string $period_end   Y-m-d.
	 *     @type int    $generated_by User ID.
	 *     @type array  $report_data  Immutable report snapshot.
	 *     @type string $status       generated.
	 * }
	 * @return int New row ID, or 0 on failure.
	 */
	public static function save( array $data ): int {
		$period_start = isset( $data['period_start'] ) ? (string) $data['period_start'] : '';
		$period_end   = isset( $data['period_end'] ) ? (string) $data['period_end'] : '';
		$report_data  = $data['report_data'] ?? null;

		if (
			! self::is_valid_date( $period_start )
			|| ! self::is_valid_date( $period_end )
			|| $period_start > $period_end
			|| ! is_array( $report_data )
			|| MaintenanceAggregator::SNAPSHOT_VERSION !== (int) ( $report_data['snapshot_version'] ?? 0 )
		) {
			return 0;
		}

		$encoded = wp_json_encode( $report_data );
		if ( false === $encoded || '' === $encoded ) {
			return 0;
		}

		$status = isset( $data['status'] ) ? (string) $data['status'] : 'generated';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'generated';
		}

		return ( new InsertBuilder( self::table_name() ) )
			->values( [
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'generated_at' => current_time( 'mysql', true ),
				'generated_by' => isset( $data['generated_by'] ) ? max( 0, (int) $data['generated_by'] ) : null,
				'report_data'  => $encoded,
				'status'       => $status,
			] )
			->execute();
	}

	public static function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$rows = ( new QueryBuilder( self::table_name() ) )
			->int_equals_if_set( 'id', $id )
			->limit( 1 )
			->get_rows( ARRAY_A );

		$row = $rows[0] ?? null;
		return null === $row ? null : self::decode_row( (array) $row );
	}

	/** @return array<int,array<string,mixed>> */
	public static function find_recent( int $limit = 10 ): array {
		$limit = max( 1, min( 500, $limit ) );

		$rows = ( new QueryBuilder( self::table_name() ) )
			->order_by_desc( 'generated_at' )
			->limit( $limit )
			->get_rows( ARRAY_A );

		return array_map( [ self::class, 'decode_row' ], $rows );
	}

	public static function delete( int $id ): bool {
		if ( $id <= 0 || null === self::find( $id ) ) {
			return false;
		}

		return ( new DeleteBuilder( self::table_name() ) )
			->int_equals_if_set( 'id', $id )
			->execute() > 0;
	}

	public static function cleanup_expired( int $retention_days ): int {
		$retention_days = max( self::RETENTION_MIN_DAYS, min( self::RETENTION_MAX_DAYS, $retention_days ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		return ( new DeleteBuilder( self::table_name() ) )
			->lt_if_set( 'generated_at', $cutoff )
			->execute();
	}

	public static function delete_all(): int {
		return ( new DeleteBuilder( self::table_name() ) )->match_all()->execute();
	}

	/** @param array<string,mixed> $row */
	private static function decode_row( array $row ): array {
		$encoded = isset( $row['report_data'] ) ? (string) $row['report_data'] : '';
		$decoded = '' !== $encoded ? json_decode( $encoded, true ) : null;
		$row['report_data'] = is_array( $decoded ) ? $decoded : null;

		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}
		if ( array_key_exists( 'generated_by', $row ) ) {
			$row['generated_by'] = null === $row['generated_by'] ? null : (int) $row['generated_by'];
		}

		return $row;
	}

	private static function is_valid_date( string $candidate ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $candidate, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

}
