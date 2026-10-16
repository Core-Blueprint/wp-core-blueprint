<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

use CoreBlueprint\Core\Database\SchemaRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Base-internal, per-blog storage for Base-owned Designer documents only.
 * Schema reconciliation is delegated to the canonical Base registry.
 */
final class Schema {
	public const TABLE_SLUG = 'cb_design_documents';
	public const VERSION = '1.0';
	public const VERSION_OPTION = 'cb_core_design_docs_db_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	public static function register_schema(): void {
		SchemaRegistry::register_base( [
			'id' => 'design-documents',
			'version' => self::VERSION,
			'option_key' => self::VERSION_OPTION,
			'tables' => [ [ self::class, 'table_name' ] ],
			'install' => [ self::class, 'install_schema' ],
		] );
	}

	/** Schema changes are never performed by the document repository. */
	public static function install_schema(): bool {
		global $wpdb;
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			document_type VARCHAR(48) NOT NULL,
			document_key VARCHAR(191) NOT NULL,
			payload_schema SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			document_json LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY identity_hash (identity_hash)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		return self::healthy();
	}

	/**
	 * Fail closed on a missing/non-transactional or structurally unsafe table.
	 * Perform a fresh physical database inspection before each writer operation.
	 * WordPress' generic SchemaRegistry only checks table presence.
	 */
	public static function healthy(): bool {
		global $wpdb;
		$table = self::table_name();
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			return false;
		}
		$metadata = $wpdb->get_row( $wpdb->prepare(
			'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		), ARRAY_A );
		if ( ! is_array( $metadata ) || 'innodb' !== strtolower( (string) ( $metadata['engine'] ?? '' ) ) ) {
			return false;
		}
		$columns = $wpdb->get_results( $wpdb->prepare(
			'SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		), ARRAY_A );
		if ( ! is_array( $columns ) ) {
			return false;
		}
		$actual = [];
		foreach ( $columns as $column ) {
			$actual[(string) ( $column['column_name'] ?? '' )] = $column;
		}
		$expected = [
			'id' => 'bigint',
			'identity_hash' => 'char',
			'document_type' => 'varchar',
			'document_key' => 'varchar',
			'payload_schema' => 'smallint',
			'revision' => 'bigint',
			'document_json' => 'longtext',
			'created_at' => 'datetime',
			'updated_at' => 'datetime',
		];
		foreach ( $expected as $name => $type ) {
			$row = $actual[$name] ?? null;
			if ( ! is_array( $row ) || $type !== strtolower( (string) ( $row['data_type'] ?? '' ) )
				|| 'NO' !== strtoupper( (string) ( $row['nullable'] ?? '' ) ) ) {
				return false;
			}
		}
		if ( (int) $actual['identity_hash']['max_length'] !== 64
			|| (int) $actual['document_type']['max_length'] !== 48
			|| (int) $actual['document_key']['max_length'] !== 191 ) {
			return false;
		}
		foreach ( [ 'id', 'revision' ] as $field ) {
			if ( 1 !== preg_match( '/^bigint(?:\\(\\d+\\))? unsigned$/i', (string) $actual[$field]['column_type'] ) ) {
				return false;
			}
		}
		if ( 1 !== preg_match( '/^smallint(?:\\(\\d+\\))? unsigned$/i', (string) $actual['payload_schema']['column_type'] ) ) {
			return false;
		}
		$indexes = $wpdb->get_results( $wpdb->prepare(
			'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		), ARRAY_A );
		if ( ! is_array( $indexes ) ) {
			return false;
		}
		$by_name = [];
		foreach ( $indexes as $index ) {
			$by_name[(string) ( $index['index_name'] ?? '' )][(int) ( $index['seq'] ?? 0 )] = $index;
		}
		foreach ( [ 'PRIMARY' => 'id', 'identity_hash' => 'identity_hash' ] as $index_name => $column_name ) {
			$parts = $by_name[$index_name] ?? [];
			if ( count( $parts ) !== 1 || ! isset( $parts[1] ) || (int) ( $parts[1]['non_unique'] ?? 1 ) !== 0
				|| $column_name !== (string) ( $parts[1]['column_name'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	private function __construct() {}
}
