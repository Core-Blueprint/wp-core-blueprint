<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * InnoDB ledger schema contract; registration/migration belongs to integration.
 * This class NEVER issues DDL. An absent ledger must refuse Save.
 *
 * @internal
 */
final class OperationSchema {
	public const TABLE_SLUG = 'cb_design_save_operations';
	public const VERSION = '1.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/** SQL supplied to the canonical Base SchemaRegistry during later integration. */
	public static function create_table_sql(): string {
		global $wpdb;
		$table = self::table_name();
		if ( ! self::safe_table_name( $table ) ) {
			throw new \RuntimeException( 'SAVE_SCHEMA_UNAVAILABLE' );
		}
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL,
			actor_id BIGINT(20) UNSIGNED NOT NULL,
			operation_id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			resource_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			resource_type VARCHAR(48) NOT NULL,
			resource_key VARCHAR(191) NOT NULL,
			profile_id VARCHAR(128) NOT NULL,
			project_schema VARCHAR(128) NOT NULL,
			registry_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			submitted_project_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			submitted_fields_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			expected_revision VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			accepted_revision VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
			state VARCHAR(24) NOT NULL,
			receipt_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_scope (blog_id, actor_id, operation_id_hash)
		) ENGINE=InnoDB {$charset};";
	}

	/** Fresh information_schema inspection, including exact unique claim index. */
	public static function healthy(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! self::safe_table_name( self::table_name() ) ) {
			return false;
		}
		$table = self::table_name();
		$wpdb->last_error = '';
		$metadata = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1', $table ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $metadata )
			|| 'innodb' !== strtolower( (string) ( $metadata['engine'] ?? '' ) ) ) {
			return false;
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS max_length, COLLATION_NAME AS collation_name, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		$columns = [];
		foreach ( $rows as $row ) {
			$columns[(string) $row['name']] = $row;
		}
		$types = [
			'id' => 'bigint', 'blog_id' => 'bigint', 'actor_id' => 'bigint',
			'operation_id_hash' => 'char', 'resource_hash' => 'char',
			'resource_type' => 'varchar', 'resource_key' => 'varchar',
			'profile_id' => 'varchar', 'project_schema' => 'varchar',
			'registry_fingerprint' => 'char', 'request_digest' => 'char',
			'submitted_project_digest' => 'char', 'submitted_fields_digest' => 'char',
			'expected_revision' => 'varchar', 'accepted_revision' => 'varchar',
			'state' => 'varchar', 'receipt_json' => 'longtext',
			'created_at' => 'datetime', 'updated_at' => 'datetime',
		];
		foreach ( $types as $column => $type ) {
			$row = $columns[$column] ?? null;
			if ( ! is_array( $row ) || $type !== strtolower( (string) ( $row['data_type'] ?? '' ) )
				|| ( in_array( $column, [ 'receipt_json', 'accepted_revision' ], true ) ? 'YES' : 'NO' ) !== strtoupper( (string) ( $row['nullable'] ?? '' ) ) ) {
				return false;
			}
			if ( in_array( $column, [ 'id', 'blog_id', 'actor_id' ], true )
				&& ! preg_match( '/^bigint(?:\([0-9]+\))? unsigned$/i', (string) $row['column_type'] ) ) {
				return false;
			}
		}
		if ( ! str_contains( strtolower( (string) $columns['id']['extra'] ), 'auto_increment' ) ) {
			return false;
		}
		foreach ( [
			'operation_id_hash' => 64, 'resource_hash' => 64,
			'registry_fingerprint' => 64, 'request_digest' => 64,
			'submitted_project_digest' => 64, 'submitted_fields_digest' => 64,
			'expected_revision' => 20, 'accepted_revision' => 20,
		] as $column => $length ) {
			if ( (int) $columns[$column]['max_length'] !== $length
				|| 'ascii_bin' !== strtolower( (string) $columns[$column]['collation_name'] ) ) {
				return false;
			}
		}
		foreach ( [ 'resource_type' => 48, 'resource_key' => 191, 'profile_id' => 128, 'project_schema' => 128, 'state' => 24 ] as $name => $length ) {
			if ( (int) $columns[$name]['max_length'] !== $length ) {
				return false;
			}
		}
		$wpdb->last_error = '';
		$indexes = $wpdb->get_results( $wpdb->prepare(
			'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $indexes ) ) {
			return false;
		}
		$found = [];
		foreach ( $indexes as $row ) {
			$found[(string) $row['index_name']][(int) $row['seq']] = $row;
		}
		foreach ( [ 'PRIMARY' => [ 'id' ], 'operation_scope' => [ 'blog_id', 'actor_id', 'operation_id_hash' ] ] as $name => $parts ) {
			$actual = $found[$name] ?? [];
			if ( count( $actual ) !== count( $parts ) ) {
				return false;
			}
			foreach ( $parts as $i => $column ) {
				$index = $actual[$i + 1] ?? null;
				if ( ! is_array( $index ) || (int) $index['non_unique'] !== 0
					|| $index['column_name'] !== $column || null !== $index['sub_part'] ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function safe_table_name( string $table ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $table );
	}

	private function __construct() {}
}
