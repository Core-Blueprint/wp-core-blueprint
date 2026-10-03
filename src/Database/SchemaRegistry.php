<?php
declare(strict_types=1);
/**
 * Public database schema registration boundary.
 *
 * Extensions declare owned schemas here. Base owns reconciliation timing,
 * locking, health verification and version-marker updates.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Database;

use CoreBlueprint\Core\DB;

defined( 'ABSPATH' ) || exit;

final class SchemaRegistry {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';
	private const BASE_RESERVED_IDS = [ 'audit-log', 'ai-activity', 'notes', 'mail-log', 'maintenance-reports' ];
	private const BASE_RESERVED_OPTIONS = [ 'cb_core_db_version', 'cb_core_ai_activity_db_version', 'cb_core_notes_db_version', 'cb_core_mail_log_db_version', 'cb_core_reports_db_version' ];

	/**
	 * @var array<string, array{id:string,version:string,option_key:string,tables:array<int,callable>,install:callable}>
	 */
	private static array $definitions = [];

	/** @var array<string,string> option_key => schema id */
	private static array $option_owners = [];

	private static bool $sweep_complete = false;

	/**
	 * Register an extension-owned database schema.
	 *
	 * Install callbacks MUST be idempotent/re-runnable. Base may invoke them for
	 * first install, version upgrades or repair of missing declared tables.
	 * Installers must not advance the schema version option themselves.
	 *
	 * @param array{id:string,version:string,option_key:string,tables:array<int,callable>,install:callable} $definition
	 */
	public static function register( array $definition ): bool {
		return self::register_definition( $definition, false );
	}

	/** Internal Base-owned registration path; not part of the documented public API. */
	public static function register_base( array $definition ): bool {
		return self::register_definition( $definition, true );
	}

	/** @param array<string,mixed> $definition */
	private static function register_definition( array $definition, bool $base_owned ): bool {
		$normalized = self::normalize_definition( $definition );
		if ( null === $normalized ) {
			return false;
		}

		$id         = $normalized['id'];
		$option_key = $normalized['option_key'];

		if ( ! $base_owned && ( in_array( $id, self::BASE_RESERVED_IDS, true ) || in_array( $option_key, self::BASE_RESERVED_OPTIONS, true ) ) ) {
			return false;
		}
		if ( isset( self::$definitions[ $id ] ) || isset( self::$option_owners[ $option_key ] ) ) {
			return false;
		}

		self::$definitions[ $id ] = $normalized;
		self::$option_owners[ $option_key ] = $id;

		// A schema registered after the normal migration sweep (for example from
		// an activation callback) is reconciled immediately. Normal request-time
		// registrations happen before the sweep and therefore do not cause an
		// extra table-health probe per request.
		if ( self::$sweep_complete ) {
			DB::reconcile_registered_schema( $id, true );
		}

		return true;
	}

	/**
	 * Internal registry view for Base's migration controller.
	 * PHP visibility does not make this part of the documented public API.
	 *
	 * @return array<string, array{id:string,version:string,option_key:string,tables:array<int,callable>,install:callable}>
	 */
	public static function definitions(): array {
		return self::$definitions;
	}

	/** Internal lifecycle marker used by the Base migration controller. */
	public static function mark_sweep_complete(): void {
		self::$sweep_complete = true;
	}

	/** Test/support reset. Not a documented public contract. */
	public static function reset_for_tests(): void {
		self::$definitions = [];
		self::$option_owners = [];
		self::$sweep_complete = false;
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array{id:string,version:string,option_key:string,tables:array<int,callable>,install:callable}|null
	 */
	private static function normalize_definition( array $definition ): ?array {
		$id         = isset( $definition['id'] ) ? trim( (string) $definition['id'] ) : '';
		$version    = isset( $definition['version'] ) ? trim( (string) $definition['version'] ) : '';
		$option_key = isset( $definition['option_key'] ) ? trim( (string) $definition['option_key'] ) : '';
		$tables     = $definition['tables'] ?? null;
		$install    = $definition['install'] ?? null;

		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}
		if ( '' === $version || 1 !== preg_match( '/^\d+(?:\.\d+)*$/D', $version ) ) {
			return null;
		}
		if ( '' === $option_key || strlen( $option_key ) > 191 || 1 !== preg_match( '/^[a-z0-9_]+$/D', $option_key ) ) {
			return null;
		}
		if ( ! is_array( $tables ) || [] === $tables || ! is_callable( $install ) ) {
			return null;
		}

		$validated_tables = [];
		foreach ( $tables as $table ) {
			if ( ! is_callable( $table ) ) {
				return null;
			}
			$validated_tables[] = $table;
		}

		return [
			'id'         => $id,
			'version'    => $version,
			'option_key' => $option_key,
			'tables'     => $validated_tables,
			'install'    => $install,
		];
	}
}
