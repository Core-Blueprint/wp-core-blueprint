<?php
declare(strict_types=1);
/**
 * Operator\Remove - `wp cb operator remove` and Console "cb operator remove".
 *
 * Demotes a user from the cb_operator role. Destructive because it
 * narrows privilege and the lockout-guard exists for a reason - removing
 * the last operator on a site with hide-from-admins enabled means
 * nobody can approve privileged identities afterwards. Recovery then
 * requires trusted server-side CLI access.
 *
 * The lockout-guard refuses to drop the last operator unless `--force` is
 * passed. Console renders a destructive modal-confirm with explicit
 * action lines that include the lockout-warning in the action list.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CB\Core\CLI\Commands\Operator;

use CB\Core\Console\CommandInterface;
use CB\Core\Console\Result;
use CB\Core\Log\AuditLog;
use CB\Core\Permissions\Roles;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessPolicy;
use CB\Core\Permissions\PrivilegedAccessRegistry;

defined( 'ABSPATH' ) || exit;

final class Remove implements CommandInterface {

	public function execute( array $args ): Result {
		$ref   = (string) ( $args['user'] ?? '' );
		$force = ! empty( $args['force'] );
		$user  = self::resolve_user( $ref );

		if ( null === $user ) {
			return Result::error(
				/* translators: %s: user reference entered on the command line. */
				sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $ref )
			);
		}

		if ( ! in_array( Roles::OPERATOR_ROLE, (array) $user->roles, true ) ) {
			return Result::warning(
				sprintf(
					/* translators: 1: login, 2: ID */
					__( '%1$s (#%2$d) is not a CB Operator - nothing to remove.', 'core-blueprint' ),
					$user->user_login,
					$user->ID
				),
				[ sprintf( '%s (#%d) is not a CB Operator.', $user->user_login, $user->ID ) ],
				[ 'user_id' => (int) $user->ID, 'changed' => false ]
			);
		}

		// Lockout-guard: refuse to drop the last operator unless --force.
		// OperatorGuard will make the Permissions page visible again if the
		// count reaches zero, but visibility is not trust recovery. Without a
		// CB Operator, privileged approvals require trusted server-side CLI.
		if ( ! $force && 1 === Roles::operator_count() ) {
			return Result::error(
				__( 'Refusing to remove the last CB Operator on this site. Add another operator first, or pass --force to override.', 'core-blueprint' ),
				[
					sprintf( '%s (#%d) is the last CB Operator on this site.', $user->user_login, $user->ID ),
					'Removing them would leave zero operators.',
					'Add another operator first, or enable Force to override the lockout-guard.',
				],
				[ 'user_id' => (int) $user->ID, 'is_last_operator' => true, 'changed' => false ]
			);
		}

		PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
			$user->remove_role( Roles::OPERATOR_ROLE );
		} );
		if ( PrivilegedAccessPolicy::is_privileged( $user ) ) {
			PrivilegedAccessRegistry::approve( $user, 0, 'cli_operator_remove' );
		} else {
			PrivilegedAccessRegistry::clear( $user );
		}

		AuditLog::log( 'permissions.operator_removed', 'warning', [
			'user_id'    => (int) $user->ID,
			'user_login' => $user->user_login,
			'by'         => self::execution_origin(),
			'forced'     => $force,
		] );

		do_action( 'cb_permissions_operator_removed', (int) $user->ID, 0 );

		$total = Roles::operator_count();
		$lines = [
			sprintf( '%s (#%d) removed from CB Operator role.', $user->user_login, $user->ID ),
			sprintf( 'Total operators now: %d', $total ),
		];
		if ( $force && 0 === $total ) {
			$lines[] = '';
			$lines[] = '⚠ This site now has zero operators.';
			$lines[] = '  hide-from-admins (if previously enabled) was auto-disabled.';
			$lines[] = '  Privileged approval recovery now requires trusted server-side CLI access.';
		}

		return Result::success(
			sprintf(
				/* translators: 1: login, 2: ID, 3: operator count */
				__( '%1$s (#%2$d) demoted from CB Operator. Total operators now: %3$d', 'core-blueprint' ),
				$user->user_login,
				$user->ID,
				$total
			),
			$lines,
			[
				'user_id'        => (int) $user->ID,
				'user_login'     => $user->user_login,
				'operator_count' => $total,
				'forced'         => $force,
				'changed'        => true,
			]
		);
	}

	public function args_schema(): array {
		return [
			'user' => [
				'type'     => 'user',
				'label'    => __( 'User', 'core-blueprint' ),
				'required' => true,
				'help'     => __( 'Search by login, email, or display name. The selected user is demoted from the cb_operator role.', 'core-blueprint' ),
			],
			'force' => [
				'type'    => 'boolean',
				'label'   => __( 'Force (override lockout-guard)', 'core-blueprint' ),
				'default' => false,
				'help'    => __( 'Allow removing the last operator on the site. Without this, removing the last operator is rejected to prevent permissions-page lockout.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'destructive';
	}

	/**
	 * Demote a user from the cb_operator role.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * [--force]
	 * : Remove even if this would leave zero operators.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb operator remove chris
	 *     wp cb operator remove 42 --force
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( [
			'user'  => $args[0] ?? '',
			'force' => ! empty( $assoc_args['force'] ),
		] );

		if ( 'error' === $result->status ) {
			\WP_CLI::error( $result->message );
		}
		if ( 'warning' === $result->status ) {
			\WP_CLI::warning( $result->message );
			return;
		}
		\WP_CLI::success( $result->message );
	}

	private static function execution_origin(): string {
		return defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'console';
	}

	private static function resolve_user( string $ref ): ?\WP_User {
		if ( '' === trim( $ref ) ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			$u = get_userdata( (int) $ref );
			if ( $u ) {
				return $u;
			}
		}
		if ( false !== strpos( $ref, '@' ) ) {
			$u = get_user_by( 'email', $ref );
			if ( $u ) {
				return $u;
			}
		}
		$u = get_user_by( 'login', $ref );
		return $u ?: null;
	}
}
