<?php
declare(strict_types=1);
/**
 * Canonical Core Blueprint role/capability policy schema lifecycle.
 *
 * This is separate from RolePolicy (the User Roles authorization policy) and
 * from Trust Schema. It owns only Base's canonical WordPress role definitions
 * and capability assignments. It never assigns users, changes approvals,
 * clears Needs Review, rotates privilege fingerprints, or changes Trust Schema.
 *
 * Missing/corrupt schema state on an established site is drift. Normal runtime
 * may detect and report it, but never repairs it. Mutation is limited to a
 * genuine first install, an explicitly implemented future schema migration, or
 * an explicit repair command.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Permissions;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class RolePolicySchema {

	private const OPTION         = 'cb_core_role_policy_schema_version';
	private const DRIFT_OPTION   = 'cb_core_role_policy_drift';
	private const CURRENT_SCHEMA = 2;

	private static int $suspended = 0;

	/** Register observation-only hooks. */
	public static function init(): void {
		add_action( 'updated_option', [ __CLASS__, 'on_option_changed' ], 110, 3 );
	}

	public static function current_schema(): int {
		return self::CURRENT_SCHEMA;
	}

	/** Null means missing or corrupt. */
	public static function stored_schema(): ?int {
		$raw = get_option( self::OPTION, false );
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			$value = (int) $raw;
			return $value > 0 ? $value : null;
		}
		return null;
	}

	/** Genuine first-install initialization only. */
	public static function initialize_first_install(): array {
		$result = self::reconcile_roles();
		update_option( self::OPTION, self::CURRENT_SCHEMA, false );
		self::inspect( true, 'first_install' );
		return $result;
	}

	/**
	 * Apply only explicitly defined future public schema upgrades.
	 * Missing/corrupt metadata deliberately does nothing.
	 */
	public static function maybe_migrate(): void {
		$stored = self::stored_schema();
		if ( null === $stored ) {
			// Missing/corrupt state is observable drift, never initialization.
			// Persist only the drift transition; do not reconcile roles/caps.
			self::inspect( true, 'schema_marker_missing_or_invalid' );
			return;
		}
		if ( self::CURRENT_SCHEMA === $stored ) {
			return;
		}
		if ( $stored > self::CURRENT_SCHEMA ) {
			self::inspect( true, 'newer_schema_detected' );
			return;
		}

		for ( $target = $stored + 1; $target <= self::CURRENT_SCHEMA; $target++ ) {
			$method = 'migrate_to_' . $target;
			if ( ! method_exists( self::class, $method ) || true !== self::{$method}() ) {
				self::inspect( true, 'migration_failed' );
				return;
			}
			update_option( self::OPTION, $target, false );
		}

		self::inspect( true, 'schema_migrated' );
	}

	/**
	 * Schema 2 adds the presentation-only Admin Notices governance capability
	 * to the canonical CB Operator role. The capability is intentionally not a
	 * Privileged Access trigger/fingerprint capability, so existing signed
	 * operator approvals remain valid across this role-policy migration.
	 */
	private static function migrate_to_2(): bool {
		self::reconcile_roles();
		return true;
	}

	/**
	 * Explicit repair of Base-owned role definitions/capabilities only.
	 *
	 * @return array{changed:bool,canonical:bool,schema_changed:bool,operator_changed:bool,administrator_changed:bool,meta_cap_roles_changed:string[],issues_before:string[],issues_after:string[]}
	 */
	public static function repair(): array {
		$before = self::inspect( false, 'repair_preview' );
		$roles  = self::reconcile_roles();

		$schema_changed = self::CURRENT_SCHEMA !== self::stored_schema();
		if ( $schema_changed ) {
			update_option( self::OPTION, self::CURRENT_SCHEMA, false );
		}

		$changed = $schema_changed || $roles['operator_changed'] || $roles['administrator_changed'] || [] !== $roles['meta_cap_roles_changed'];
		$after   = self::inspect( $changed || ! $before['canonical'], 'explicit_repair' );

		// A repair against an already-canonical policy is a true no-op. The
		// Console/WP-CLI execution path may report that fact to the caller, but
		// Role Policy itself performs no durable write merely because `repair`
		// was invoked. Successful mutations remain explicitly auditable.
		if ( $changed ) {
			AuditLog::log( 'permissions.role.policy.repaired', 'notice', [
				'changed'               => true,
				'schema_changed'        => $schema_changed,
				'operator_changed'      => $roles['operator_changed'],
				'administrator_changed' => $roles['administrator_changed'],
				'meta_cap_roles_changed' => $roles['meta_cap_roles_changed'],
				'issues_before'         => $before['issues'],
				'issues_after'          => $after['issues'],
			] );
		}

		return [
			'changed'               => $changed,
			'canonical'             => $after['canonical'],
			'schema_changed'        => $schema_changed,
			'operator_changed'      => $roles['operator_changed'],
			'administrator_changed' => $roles['administrator_changed'],
			'meta_cap_roles_changed' => $roles['meta_cap_roles_changed'],
			'issues_before'         => $before['issues'],
			'issues_after'          => $after['issues'],
		];
	}

	/**
	 * Observe drift without repairing it.
	 *
	 * @return array{schema:?int,issues:string[],canonical:bool}
	 */
	public static function inspect( bool $persist = true, string $source = 'runtime' ): array {
		$issues = [];
		$schema = self::stored_schema();

		if ( null === $schema ) {
			$issues[] = 'schema_marker_missing_or_invalid';
		} elseif ( $schema > self::CURRENT_SCHEMA ) {
			$issues[] = 'schema_newer_than_runtime';
		} elseif ( $schema < self::CURRENT_SCHEMA ) {
			$issues[] = 'schema_upgrade_pending';
		}

		$operator = get_role( Roles::OPERATOR_ROLE );
		if ( null === $operator ) {
			$issues[] = 'operator_role_missing';
		} else {
			foreach ( Roles::OPERATOR_CAPS as $cap ) {
				if ( ! $operator->has_cap( $cap ) ) {
					$issues[] = 'operator_missing_cap:' . $cap;
				}
			}
			foreach ( self::forbidden_operator_caps() as $cap ) {
				if ( $operator->has_cap( $cap ) ) {
					$issues[] = 'operator_forbidden_cap:' . $cap;
				}
			}
		}

		$admin = get_role( 'administrator' );
		if ( null === $admin ) {
			$issues[] = 'administrator_role_missing';
		} else {
			foreach ( Roles::ADMIN_VIEW_CAPS as $cap ) {
				if ( ! $admin->has_cap( $cap ) ) {
					$issues[] = 'administrator_missing_cap:' . $cap;
				}
			}
			foreach ( self::forbidden_admin_caps() as $cap ) {
				if ( $admin->has_cap( $cap ) ) {
					$issues[] = 'administrator_forbidden_cap:' . $cap;
				}
			}
		}

		$wp_roles = wp_roles();
		foreach ( array_keys( (array) $wp_roles->roles ) as $role_slug ) {
			$role = get_role( (string) $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( Roles::META_CAPS as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$issues[] = 'role_stored_meta_cap:' . (string) $role_slug . ':' . $cap;
				}
			}
		}

		sort( $issues, SORT_STRING );
		$result = [
			'schema'    => $schema,
			'issues'    => $issues,
			'canonical' => [] === $issues,
		];

		if ( $persist && 0 === self::$suspended ) {
			self::persist_drift( $issues, $source );
		}
		return $result;
	}

	/** Observe normal WordPress role-definition writes immediately. */
	public static function on_option_changed( $option, $old_value, $value ): void {
		if ( self::$suspended > 0 ) {
			return;
		}
		$wp_roles = wp_roles();
		if ( (string) $option !== (string) $wp_roles->role_key ) {
			return;
		}
		self::inspect( true, 'role_option' );
	}

	/** @return array{operator_changed:bool,administrator_changed:bool,meta_cap_roles_changed:string[]} */
	private static function reconcile_roles(): array {
		$operator_changed       = false;
		$administrator_changed  = false;
		$meta_cap_roles_changed = [];

		self::$suspended++;
		try {
			PrivilegedAccessGuard::trusted_mutation(
				static function () use ( &$operator_changed, &$administrator_changed, &$meta_cap_roles_changed ): void {
					$operator_changed       = Roles::ensure_operator_role();
					$administrator_changed  = Roles::ensure_admin_view_caps();
					$meta_cap_roles_changed = Roles::remove_stored_meta_caps();
				}
			);
		} finally {
			self::$suspended = max( 0, self::$suspended - 1 );
		}

		return [
			'operator_changed'      => $operator_changed,
			'administrator_changed'  => $administrator_changed,
			'meta_cap_roles_changed' => $meta_cap_roles_changed,
		];
	}

	/** @return string[] */
	private static function forbidden_operator_caps(): array {
		$required = array_fill_keys( Roles::OPERATOR_CAPS, true );
		$out      = [];
		foreach ( PrivilegedAccessPolicy::privileged_capabilities() as $cap ) {
			if ( ! isset( $required[ $cap ] ) ) {
				$out[] = $cap;
			}
		}
		return $out;
	}

	/** @return string[] */
	private static function forbidden_admin_caps(): array {
		$allowed = array_fill_keys( array_merge( [ 'read' ], Roles::ADMIN_VIEW_CAPS ), true );
		$out     = [];
		foreach ( Roles::OPERATOR_CAPS as $cap ) {
			if ( ! isset( $allowed[ $cap ] ) ) {
				$out[] = $cap;
			}
		}
		return $out;
	}

	/** Persist/audit only transitions so repeated checks remain quiet. */
	private static function persist_drift( array $issues, string $source ): void {
		$previous        = get_option( self::DRIFT_OPTION, [] );
		$previous_issues = is_array( $previous ) && is_array( $previous['issues'] ?? null )
			? array_values( array_map( 'strval', $previous['issues'] ) )
			: [];
		sort( $previous_issues, SORT_STRING );

		if ( $previous_issues === $issues ) {
			return;
		}

		if ( [] === $issues ) {
			if ( [] !== $previous_issues ) {
				delete_option( self::DRIFT_OPTION );
				AuditLog::log( 'permissions.role.policy.drift.resolved', 'notice', [
					'previous_issues' => $previous_issues,
					'source'          => sanitize_key( $source ),
				] );
			}
			return;
		}

		update_option( self::DRIFT_OPTION, [
			'issues'      => $issues,
			'detected_at' => time(),
			'source'      => sanitize_key( $source ),
		], false );
		AuditLog::log( 'permissions.role.policy.drift.detected', 'warning', [
			'issues' => $issues,
			'source' => sanitize_key( $source ),
		] );
	}
}
