<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Mail\Designer;

use CoreBlueprint\Core\Database\SchemaRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Mail-owned reusable template assets. NOT the Designer Platform document
 * repository, operational Mail resources, or a public Base template datastore.
 */
final class SavedTemplateSchema {
	public const TABLE = 'cb_core_mail_saved_templates';
	public const VERSION = '1.0';
	public const VERSION_OPTION = 'cb_core_mail_saved_templates_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function register_schema(): void {
		SchemaRegistry::register_base( [
			'id' => 'mail-saved-templates',
			'version' => self::VERSION,
			'option_key' => self::VERSION_OPTION,
			'tables' => [ [ self::class, 'table' ] ],
			'install' => [ self::class, 'install' ],
		] );
	}

	public static function install(): bool {
		global $wpdb;
		$table = self::table();
		$collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			owner_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			visibility VARCHAR(8) NOT NULL,
			title VARCHAR(191) NOT NULL,
			revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			document_json LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY owner_visibility (owner_id,visibility),
			KEY visibility (visibility)
		) ENGINE=InnoDB {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		return self::healthy( true );
	}

	/** Fresh physical integrity check before Mail template writes and reads. */
	/** @var array<string,bool> Per-blog verified physical schemas. */
	private static array $healthy_tables = [];

	public static function healthy( bool $fresh = false ): bool {
		$table = self::table();
		if ( ! $fresh && true === ( self::$healthy_tables[$table] ?? false ) ) return true;
		$verified = self::inspect();
		if ( $verified ) self::$healthy_tables[$table] = true;
		else unset( self::$healthy_tables[$table] );
		return $verified;
	}

	private static function inspect(): bool {
		global $wpdb;
		$table = self::table();
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) return false;
		$engine = $wpdb->get_var( $wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		) );
		if ( ! is_string( $engine ) || 'innodb' !== strtolower( $engine ) ) return false;
		$columns = $wpdb->get_results( $wpdb->prepare(
			'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_TYPE AS definition, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		), ARRAY_A );
		if ( ! is_array( $columns ) ) return false;
		$found = [];
		foreach ( $columns as $row ) $found[(string) ( $row['name'] ?? '' )] = $row;
		foreach ( [
			'id' => 'varchar', 'owner_id' => 'bigint', 'visibility' => 'varchar',
			'title' => 'varchar', 'revision' => 'bigint', 'document_json' => 'longtext',
			'created_at' => 'datetime', 'updated_at' => 'datetime',
		] as $column => $type ) {
			if ( ! isset( $found[$column] ) || $found[$column]['type'] !== $type
				|| $found[$column]['nullable'] !== 'NO' ) return false;
		}
		if ( $found['id']['collation'] !== 'ascii_bin'
			|| ! str_contains( strtolower( (string) $found['owner_id']['definition'] ), 'unsigned' )
			|| ! str_contains( strtolower( (string) $found['revision']['definition'] ), 'unsigned' ) ) return false;
		$indexes = $wpdb->get_results( $wpdb->prepare(
			'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS position FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		), ARRAY_A );
		if ( ! is_array( $indexes ) ) return false;
		$primary = array_values( array_filter( $indexes, static fn ( array $row ): bool =>
			'PRIMARY' === $row['name'] ) );
		return count( $primary ) === 1
			&& $primary[0]['column_name'] === 'id'
			&& (int) $primary[0]['non_unique'] === 0
			&& (int) $primary[0]['position'] === 1;
	}

	private function __construct() {}
}
