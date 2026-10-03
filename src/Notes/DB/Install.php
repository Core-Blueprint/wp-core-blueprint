<?php
declare(strict_types=1);
/**
 * Notes - DB schema installer.
 *
 * Owns the `{prefix}cb_core_notes` table. Schema version stored in
 * `cb_core_notes_db_version` (separate from CB Base's audit-log
 * `cb_core_db_version` so the two evolve independently).
 *
 * Schema decisions:
 *   - No context_type / context_id columns. The polymorphic association
 *     pattern was considered and dropped - see CHANGELOG 1.1.4-dev for
 *     rationale. Notes are free-text, not anchored to other CB records.
 *   - All timestamps are stored as WordPress site-local datetime values via
 *     current_time('mysql'); no separate timezone column.
 *   - Indexes on the four columns the list view filters/sorts by:
 *     status, type, assigned_to, updated_at.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Notes\DB;

use CoreBlueprint\Core\Database\SchemaRegistry;

defined( 'ABSPATH' ) || exit;

final class Install {

	public const DB_VERSION     = '1.4';
	public const VERSION_OPTION = 'cb_core_notes_db_version';


	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'cb_core_notes';
	}

	/** Register Notes with the central Base schema/migration registry. */
	public static function register_schema(): void {
		SchemaRegistry::register_base( [
			'id'         => 'notes',
			'version'    => self::DB_VERSION,
			'option_key' => self::VERSION_OPTION,
			'tables'     => [ [ self::class, 'table_name' ] ],
			'install'    => [ self::class, 'install' ],
		] );
	}

	/**
	 * Install or upgrade the schema. Idempotent - safe to call on every
	 * activation; dbDelta diffs the SQL against the live schema.
	 *
	 * Note: dbDelta does NOT accept `CREATE TABLE IF NOT EXISTS` for
	 * column-add migrations - must use plain `CREATE TABLE`.
	 */
	public static function install(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
 id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
 title text NOT NULL,
 content longtext NOT NULL,
 content_format varchar(20) NOT NULL DEFAULT 'markdown',
 type varchar(20) NOT NULL DEFAULT 'General',
 status varchar(20) NOT NULL DEFAULT 'Backlog',
 tags text NULL,
 created_by bigint(20) UNSIGNED NULL,
 updated_by bigint(20) UNSIGNED NULL,
 assigned_to bigint(20) UNSIGNED NULL,
 created_at datetime NULL,
 updated_at datetime NULL,
 PRIMARY KEY  (id),
 KEY status (status),
 KEY type (type),
 KEY assigned_to (assigned_to),
 KEY updated_at (updated_at)
) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

	}

	/**
	 * Run install only if the stored DB version doesn't match the
	 * current target. Cheap option-check on each Notes bootstrap, so
	 * activating a newer build picks up schema changes without needing
	 * a manual deactivate/reactivate dance.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
