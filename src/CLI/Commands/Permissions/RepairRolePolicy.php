<?php
declare(strict_types=1);
/**
 * Permissions\RepairRolePolicy - explicit role-policy recovery.
 *
 * Server WP-CLI is the break-glass path and does not depend on a WordPress
 * user's current role state. The browser Console is separately gated by both
 * cb_use_cli and cb_manage_roles in Console\Registry/RunController.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\CLI\Commands\Permissions;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\RolePolicySchema;

defined( 'ABSPATH' ) || exit;

final class RepairRolePolicy implements CommandInterface {

	public function execute( array $args ): Result {
		$is_wp_cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! $is_wp_cli && ! current_user_can( 'cb_manage_roles' ) ) {
			return Result::error( __( 'You do not have permission to repair the Core Blueprint role policy.', 'core-blueprint' ) );
		}

		$approved_before = self::snapshot_approved_identities();
		$result          = RolePolicySchema::repair();
		if ( ! $result['canonical'] ) {
			return Result::error(
				__( 'Role policy repair could not restore a canonical state. Review the reported issues before continuing.', 'core-blueprint' ),
				array_map( static fn ( string $issue ): string => '  - ' . $issue, $result['issues_after'] ),
				$result
			);
		}
		if ( ! $result['changed'] ) {
			return Result::success(
				__( 'Role policy is already canonical; no role or capability changes were required.', 'core-blueprint' ),
				[ 'Role Policy: already canonical.' ],
				$result
			);
		}

		$continuity = self::restore_verified_trust_continuity( $approved_before );
		$lines      = [
			'Role Policy: repaired.',
			'User role assignments: unchanged.',
			'Trust Schema: unchanged.',
		];
		if ( $continuity['restored'] > 0 ) {
			$lines[] = sprintf( 'Verified trust continuity restored for %d previously approved privileged identity(s).', $continuity['restored'] );
		}
		if ( $continuity['skipped'] > 0 ) {
			$lines[] = sprintf( '%d previously approved identity(s) were not re-signed because continuity could not be proven.', $continuity['skipped'] );
		}
		if ( 0 === $continuity['restored'] && 0 === $continuity['skipped'] ) {
			$lines[] = 'Privileged approvals / Needs Review: unchanged.';
		}
		$result['trust_continuity'] = $continuity;

		return Result::success(
			__( 'Canonical Core Blueprint role definitions and capabilities were repaired.', 'core-blueprint' ),
			$lines,
			$result
		);
	}

	/**
	 * Snapshot only identities whose signed approval is valid immediately before
	 * the explicit repair. A broken/unapproved identity never gains trust merely
	 * because Role Policy repair was requested.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function snapshot_approved_identities(): array {
		$snapshots = [];
		foreach ( get_users() as $user ) {
			if ( ! ( $user instanceof \WP_User ) || ! PrivilegedAccessRegistry::is_approved( $user ) ) {
				continue;
			}

			$state = PrivilegedAccessPolicy::fingerprint_state( $user );
			$roles = array_values( array_map( 'strval', (array) $user->roles ) );
			sort( $roles, SORT_STRING );
			$snapshots[ (int) $user->ID ] = [
				'fingerprint' => PrivilegedAccessPolicy::fingerprint( $user ),
				'roles'       => $roles,
				'direct_caps' => $state['direct_caps'],
				'blog_id'     => $state['blog_id'],
				'super_admin' => $state['super_admin'],
			];
		}
		return $snapshots;
	}

	/**
	 * Re-sign a previously approved identity only when every user-owned part of
	 * the signed privilege state remained unchanged during the canonical repair.
	 * A fingerprint delta is then attributable solely to Base-owned role-policy
	 * reconciliation. Any ambiguity stays fail-closed.
	 *
	 * @param array<int,array<string,mixed>> $snapshots
	 * @return array{restored:int,skipped:int}
	 */
	private static function restore_verified_trust_continuity( array $snapshots ): array {
		$restored = 0;
		$skipped  = 0;

		foreach ( $snapshots as $user_id => $before ) {
			$user = get_userdata( (int) $user_id );
			if ( ! ( $user instanceof \WP_User ) || ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
				$skipped++;
				continue;
			}

			$state = PrivilegedAccessPolicy::fingerprint_state( $user );
			$roles = array_values( array_map( 'strval', (array) $user->roles ) );
			sort( $roles, SORT_STRING );
			$continuity_ok = $roles === (array) ( $before['roles'] ?? [] )
				&& $state['direct_caps'] === (array) ( $before['direct_caps'] ?? [] )
				&& (int) $state['blog_id'] === (int) ( $before['blog_id'] ?? -1 )
				&& (bool) $state['super_admin'] === (bool) ( $before['super_admin'] ?? false );

			if ( ! $continuity_ok ) {
				$skipped++;
				AuditLog::log( 'permissions.role_policy_trust_continuity_skipped', 'warning', [
					'user_id' => (int) $user->ID,
					'reason'  => 'user_owned_privilege_state_changed',
				] );
				continue;
			}

			$before_fingerprint = (string) ( $before['fingerprint'] ?? '' );
			$after_fingerprint  = PrivilegedAccessPolicy::fingerprint( $user );
			if ( '' === $before_fingerprint || hash_equals( $before_fingerprint, $after_fingerprint ) ) {
				continue;
			}

			PrivilegedAccessRegistry::approve( $user, 0, 'role_policy_repair_continuity' );
			$restored++;
			AuditLog::log( 'permissions.role_policy_trust_continuity_restored', 'notice', [
				'user_id'            => (int) $user->ID,
				'before_fingerprint' => $before_fingerprint,
				'after_fingerprint'  => $after_fingerprint,
			] );
		}

		return [ 'restored' => $restored, 'skipped' => $skipped ];
	}

	public function args_schema(): array { return []; }
	public function side_effects(): string { return 'destructive'; }

	/** @when after_wp_load */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );
		foreach ( $result->lines as $line ) { \WP_CLI::line( $line ); }
		if ( 'error' === $result->status ) { \WP_CLI::error( $result->message ); }
		if ( '' !== $result->message ) { \WP_CLI::success( $result->message ); }
	}
}
