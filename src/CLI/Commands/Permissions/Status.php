<?php
declare(strict_types=1);
/**
 * Permissions\Status - `wp cb permissions status`.
 *
 * Read-only. Prints operator count + IDs, hide-from-admins state,
 * admin-toggle states.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Permissions;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\RolePolicySchema;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Status implements CommandInterface {

	public function execute( array $args ): Result {
		$settings    = Settings::get();
		$permissions = is_array( $settings['permissions'] ?? null ) ? $settings['permissions'] : [];
		$reports     = is_array( $settings['reports']     ?? null ) ? $settings['reports']     : [];

		$op_count           = Roles::operator_count();
		$op_ids             = Roles::operator_ids();
		$approved_op_ids    = PrivilegedAccessRegistry::approved_operator_ids();
		$approved_op_count  = count( $approved_op_ids );
		$review_count       = count( PrivilegedAccessRegistry::review_snapshot() );
		$hide_active        = ! empty( $permissions['hide_from_admins'] );
		$admin_can_generate = ! empty( $reports['admin_can_generate']['maintenance'] );
		$role_policy        = RolePolicySchema::inspect( false, 'permissions_status' );

		$lines   = [];
		$lines[] = '';
		$lines[] = 'Core Blueprint - Permissions Status';
		$lines[] = str_repeat( '─', 40 );
		$lines[] = 'Operator count:           ' . $op_count;
		$lines[] = 'Operator IDs:             ' . ( $op_count > 0 ? implode( ', ', $op_ids ) : '-' );
		$lines[] = 'Approved operators:       ' . $approved_op_count;
		$lines[] = 'Approved operator IDs:    ' . ( $approved_op_count > 0 ? implode( ', ', $approved_op_ids ) : '-' );
		$lines[] = 'Privileged needs review:  ' . $review_count;
		$lines[] = 'Hide tab from admins:     ' . ( $hide_active ? 'YES' : 'no' );
		$lines[] = 'Admins can gen reports:   ' . ( $admin_can_generate ? 'YES' : 'no' );
		$lines[] = 'Role Policy schema:       ' . ( null === $role_policy['schema'] ? 'MISSING/INVALID' : (string) $role_policy['schema'] );
		$lines[] = 'Role Policy canonical:    ' . ( $role_policy['canonical'] ? 'YES' : 'NO' );
		if ( ! $role_policy['canonical'] ) {
			foreach ( $role_policy['issues'] as $issue ) {
				$lines[] = '  - ' . $issue;
			}
			$lines[] = '  Recovery: wp cb permissions repair-role-policy';
		}

		if ( $hide_active && 0 === $op_count ) {
			$lines[] = '';
			$lines[] = '⚠ Inconsistent state: hide-from-admins is ON but zero operators exist.';
			$lines[] = '  Run `wp cb permissions show-page` to recover, then add an operator.';
		}
		if ( $review_count > 0 && 0 === $approved_op_count ) {
			$lines[] = '';
			$lines[] = '⚠ Recovery required: privileged identities need review but no approved CB Operator is available.';
			$lines[] = '  Inspect the verified management identity: wp cb operator status <user>';
			$lines[] = '  Recover it through trusted server-side WP-CLI: wp cb operator recover <user>';
		}
		$lines[] = '';

		$data = [
			'operator_count'                 => $op_count,
			'operator_ids'                   => $op_ids,
			'approved_operator_count'         => $approved_op_count,
			'approved_operator_ids'           => $approved_op_ids,
			'privileged_review_count'         => $review_count,
			'hide_from_admins'               => $hide_active,
			'admin_can_generate_maintenance' => $admin_can_generate,
			'role_policy'                    => $role_policy,
		];

		$status = ( ( $hide_active && 0 === $op_count ) || ! $role_policy['canonical'] || ( $review_count > 0 && 0 === $approved_op_count ) ) ? 'warning' : 'success';
		$msg    = $status === 'warning'
			? __( 'Permissions are in an inconsistent state - review immediately.', 'core-blueprint' )
			: sprintf(
				/* translators: %d: operator count */
				_n( '%d Core Blueprint operator on this site.', '%d Core Blueprint operators on this site.', $op_count, 'core-blueprint' ),
				$op_count
			);

		return new Result( $status, $msg, $lines, $data );
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Print the current permissions configuration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb permissions status
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );
		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
	}
}
