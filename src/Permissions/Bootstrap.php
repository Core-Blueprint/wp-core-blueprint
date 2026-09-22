<?php
declare(strict_types=1);
/**
 * Permissions Bootstrap
 *
 * Wires the Permissions subsystem into WordPress. Called synchronously from
 * Core::init_hooks() so capability guards are available before consumers.
 *
 * Wires capability policy, operator safeguards, permission drift auditing,
 * and the native WordPress User Roles editor. Role definitions themselves
 * remain owned by WordPress; Core Blueprint adds the management UI, safety
 * policy, capability metadata, and audit trail around those primitives.
 *
 * Roles remains the small helper that owns the protected cb_operator role
 * and its required Core Blueprint capabilities. Generic role CRUD lives in
 * RoleRepository and is guarded by RolePolicy.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Permissions;

use CB\Core\Admin\PageRegistry;
use CB\Core\Permissions\Admin\RolesPage;
use CB\Core\Permissions\Admin\UserRolesFields;
use CB\Core\Permissions\Rest\RolesController;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register all Permissions hooks. Called once from Core::init_hooks().
	 *
	 * Runtime guards are registered synchronously here rather than via
	 * plugins_loaded: capability filters must exist before other modules call
	 * current_user_can(), while role-change listeners can fire arbitrarily
	 * early during admin requests.
	 */
	public static function boot(): void {
		Caps::init();
		PrivilegedAccessGuard::init();
		OperatorGuard::init();
		DriftMonitor::init();
		RolePolicySchema::init();
		if ( UserRolesState::is_enabled() ) {
			UserRolesFields::init();
		}

		// User Roles editor: REST-backed top-level page within the existing
		// Permissions subsystem. The page is deliberately separate from
		// Preferences > Permissions, which remains Core Blueprint meta-governance.
		if ( UserRolesState::is_enabled() ) {
			add_action( 'rest_api_init', [ RolesController::class, 'register' ] );
		}
		add_action( 'cb_core_register_pages', static function (): void {
			if ( ! UserRolesState::is_enabled() ) {
				return;
			}
			PageRegistry::register_base( new RolesPage() );
		} );
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		// Public trust-schema migrations run before protected-role reconciliation.
		// Every future schema change must provide an explicit reviewed migration
		// step; missing schema metadata never authorizes automatic trust repair.
		add_action( 'plugins_loaded', [ TrustSchemaMigrator::class, 'maybe_migrate' ], 3 );

		// Role Policy Schema has its own public lifecycle. Known future schema
		// upgrades may reconcile explicitly; a missing/corrupt marker on an
		// established site is drift and never triggers automatic repair.
		add_action( 'plugins_loaded', [ RolePolicySchema::class, 'maybe_migrate' ], 4 );
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * Human-readable labels for role-definition mutations in the audit log.
	 *
	 * @param array<string,string> $labels Existing labels.
	 * @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels['user.roles.subsystem.enabled']  = __( 'User Roles: subsystem enabled', 'core-blueprint' );
		$labels['user.roles.subsystem.disabled'] = __( 'User Roles: subsystem disabled', 'core-blueprint' );
		$labels['permissions.role.created']              = __( 'Permissions: role created', 'core-blueprint' );
		$labels['permissions.role.updated']              = __( 'Permissions: role updated', 'core-blueprint' );
		$labels['permissions.role.capabilities.changed'] = __( 'Permissions: role capabilities changed', 'core-blueprint' );
		$labels['permissions.role.deleted']              = __( 'Permissions: role deleted', 'core-blueprint' );
		$labels['permissions.privileged.user.review.required'] = __( 'Permissions: privileged user requires review', 'core-blueprint' );
		$labels['permissions.privileged.access.mode.changed'] = __( 'Permissions: privileged access mode changed', 'core-blueprint' );
		$labels['permissions.privileged.user.approved']    = __( 'Permissions: privileged user approved', 'core-blueprint' );
		$labels['permissions.privileged.guard.bootstrapped']= __( 'Permissions: privileged access guard bootstrapped', 'core-blueprint' );
		$labels['permissions.trust.schema.migrated']          = __( 'Permissions: trust schema migrated', 'core-blueprint' );
		$labels['permissions.trust.schema.migration.failed'] = __( 'Permissions: trust schema migration failed', 'core-blueprint' );
		$labels['permissions.role.policy.drift.detected'] = __( 'Permissions: role policy drift detected', 'core-blueprint' );
		$labels['permissions.role.policy.drift.resolved'] = __( 'Permissions: role policy drift resolved', 'core-blueprint' );
		$labels['permissions.role.policy.repaired'] = __( 'Permissions: role policy repaired', 'core-blueprint' );
		$labels['permissions.operator.recovered'] = __( 'Permissions: operator recovered', 'core-blueprint' );
		$labels['permissions.role.policy.trust.continuity.restored'] = __( 'Permissions: role policy trust continuity restored', 'core-blueprint' );
		$labels['permissions.role.policy.trust.continuity.skipped'] = __( 'Permissions: role policy trust continuity not restored', 'core-blueprint' );
		$labels['migration.recovery.ticket_issued'] = __( 'Migration recovery: ticket issued', 'core-blueprint' );
		$labels['migration.recovery.destination_activated'] = __( 'Migration recovery: destination activated', 'core-blueprint' );
		$labels['migration.recovery.destination_reconciled'] = __( 'Migration recovery: destination reconciled', 'core-blueprint' );
		$labels['migration.recovery.identity_approved'] = __( 'Migration recovery: management identity approved', 'core-blueprint' );
		$labels['migration.recovery.reconcile_failed'] = __( 'Migration recovery: reconciliation failed', 'core-blueprint' );
		$labels['migration.recovery.completed'] = __( 'Migration recovery: completed', 'core-blueprint' );
		$labels['permissions.privileged.guard.migration.root.established'] = __( 'Permissions: migration trust root established', 'core-blueprint' );
		$labels['permissions.trust.schema.domain.reset'] = __( 'Permissions: migration trust schema established', 'core-blueprint' );
		return $labels;
	}

}
