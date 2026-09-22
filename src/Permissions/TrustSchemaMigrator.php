<?php
declare(strict_types=1);
/**
 * TrustSchemaMigrator
 *
 * Owns the public version of Core Blueprint's privileged-access trust schema.
 * The schema describes security-sensitive relationships between the protected
 * operator role, privilege fingerprints, and signed approvals; it is separate
 * from the plugin release version and from general settings schema versions.
 *
 * Public v1 starts at trust schema 1. There are no historical public migration
 * steps yet. Future trust-schema changes must be implemented as explicit,
 * independently reviewed migration steps before CURRENT_SCHEMA is advanced.
 * Missing/unknown schema metadata is never treated as authority to modify roles
 * or approvals automatically.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Permissions;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class TrustSchemaMigrator {

	private const OPTION         = 'cb_core_trust_schema_version';
	private const CURRENT_SCHEMA = 1;

	public static function current_schema(): int {
		return self::CURRENT_SCHEMA;
	}

	public static function stored_schema(): int {
		return max( 0, (int) get_option( self::OPTION, 0 ) );
	}

	/**
	 * Establish the current trust schema for a newly created trust domain.
	 *
	 * Callers must invalidate imported privileged approvals before invoking
	 * this method. It never approves an identity or modifies role assignments.
	 */
	public static function reset_for_new_trust_domain( string $source ): void {
		$before = self::stored_schema();
		update_option( self::OPTION, self::CURRENT_SCHEMA, false );
		AuditLog::log( 'permissions.trust_schema_domain_reset', 'warning', [
			'from_schema' => $before,
			'to_schema'   => self::CURRENT_SCHEMA,
			'source'      => sanitize_key( $source ),
		] );
	}

	/**
	 * Apply explicitly defined public trust-schema migrations.
	 *
	 * A missing marker is deliberately left untouched. Fresh installations call
	 * mark_current() during activation; a missing marker on an existing site is
	 * not sufficient evidence for automatic trust repair or approval mutation.
	 */
	public static function maybe_migrate(): void {
		$stored = (int) get_option( self::OPTION, 0 );
		if ( 0 === $stored || self::CURRENT_SCHEMA === $stored ) {
			return;
		}

		if ( $stored > self::CURRENT_SCHEMA ) {
			self::audit_failure( $stored, self::CURRENT_SCHEMA, 'newer_schema_detected' );
			return;
		}

		for ( $target = $stored + 1; $target <= self::CURRENT_SCHEMA; $target++ ) {
			$method = 'migrate_to_' . $target;
			if ( ! method_exists( self::class, $method ) ) {
				self::audit_failure( $target - 1, $target, 'missing_migration_step' );
				return;
			}

			if ( true !== self::{$method}() ) {
				self::audit_failure( $target - 1, $target, 'migration_step_failed' );
				return;
			}

			update_option( self::OPTION, $target, false );
			AuditLog::log( 'permissions.trust_schema_migrated', 'notice', [
				'from_schema' => $target - 1,
				'to_schema'   => $target,
			] );
		}
	}

	/**
	 * Mark a genuine first installation as using the current public trust schema.
	 */
	public static function mark_current(): void {
		update_option( self::OPTION, self::CURRENT_SCHEMA, false );
	}

	private static function audit_failure( int $from_schema, int $to_schema, string $reason ): void {
		AuditLog::log( 'permissions.trust_schema_migration_failed', 'critical', [
			'from_schema' => $from_schema,
			'to_schema'   => $to_schema,
			'reason'      => sanitize_key( $reason ),
		] );
	}
}
