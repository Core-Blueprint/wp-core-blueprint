<?php
declare(strict_types=1);
/**
 * SettingsMigrator
 *
 * Forward-migrates the public Core Blueprint settings schema. Public schema
 * history starts at schema 1 and future migrations must be registered
 * explicitly as supported runtime contracts.
 *
 * Migration contract:
 *   - Each migration is keyed by its target public schema version.
 *   - Migrations run sequentially from the stored public schema version to
 *     Settings::SCHEMA_VERSION.
 *   - A migration receives the current settings array and must return an array.
 *   - Existing settings are preserved unless a documented public migration
 *     deliberately transforms them.
 *   - Downgrades are never attempted automatically.
 *
 * Adding a public migration:
 *   1. Bump Settings::SCHEMA_VERSION.
 *   2. Add migrate_to_N(), where N is the new public schema version.
 *   3. Register N => [ __CLASS__, 'migrate_to_N' ] in migrations().
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Support\ThrowableBoundary;

defined( 'ABSPATH' ) || exit;

final class SettingsMigrator {

	/**
	 * Public schema migrations keyed by target version.
	 *
	 * Schema 1 is the first public settings schema, so there are currently no
	 * migrations to run. Future public upgrades are registered here.
	 *
	 * @return array<int, callable>
	 */
	private static function migrations(): array {
		return [];
	}

	/**
	 * Forward-migrate a stored public settings document when required.
	 *
	 * Safe to call repeatedly. Fresh installs and schema-current installs are
	 * no-ops. A stored schema newer than this build is left untouched; Base
	 * never attempts an implicit downgrade.
	 */
	public static function maybe_migrate(): void {
		$source = \CoreBlueprint\Core\Settings\OptionCas::read();
		if ( 'ok' !== $source['status'] ) {
			return;
		}
		$stored = $source['value'];

		$current = max( 1, (int) ( $stored['schema_version'] ?? 1 ) );
		$target  = (int) Settings::SCHEMA_VERSION;

		if ( $current >= $target ) {
			return;
		}

		$migrations    = self::migrations();
		$working       = $stored;
		$audit_versions = [];

		for ( $version = $current + 1; $version <= $target; $version++ ) {
			if ( ! isset( $migrations[ $version ] ) || ! is_callable( $migrations[ $version ] ) ) {
				if ( class_exists( AuditLog::class ) ) {
					AuditLog::log( 'settings.migration_failed', 'critical', [
						'target'  => $version,
						'message' => 'missing public settings migration',
					] );
				}
				return;
			}

			try {
				$migrated = call_user_func( $migrations[ $version ], $working );
				if ( ! is_array( $migrated ) ) {
					throw new \RuntimeException( 'migration returned non-array' );
				}

				$migrated['schema_version'] = $version;
				$working                    = $migrated;

				$audit_versions[] = [ 'from' => $version - 1, 'to' => $version ];
			} catch ( \Throwable $e ) {
				if ( class_exists( AuditLog::class ) ) {
					AuditLog::log( 'settings.migration_failed', 'critical', [
						'target'  => $version,
						...ThrowableBoundary::context( $e, 'settings_migration_failed' ),
					] );
				}
				return;
			}
		}

		// Preserve original option bytes/autoload and refuse any concurrent
		// update, even one to a field outside the migration's changed paths.
		$outcome = \CoreBlueprint\Core\Settings\OptionCas::write( $stored, $working, $source['raw'] );
		if ( 'saved' !== ( $outcome['status'] ?? '' ) ) {
			if ( class_exists( AuditLog::class ) ) {
				AuditLog::log( 'settings.migration_failed', 'critical', [
					'target' => $target,
					'reason' => 'storage_not_verified',
				] );
			}
			return;
		}
		foreach ( $audit_versions as $event ) {
			if ( class_exists( AuditLog::class ) ) {
				AuditLog::log( 'settings.migrated', 'notice', $event );
			}
		}
	}
}
