<?php
declare(strict_types=1);
/**
 * Operator\Add - `wp cb operator add` and Console "cb operator add".
 *
 * Promotes a user to the cb_operator role. State-change because the
 * change widens privilege but is reversible (run `cb operator remove`
 * to undo). Banner-warning in the Console; no confirm-modal needed.
 *
 * The user reference accepts ID, login, or email. The Console renders
 * a user-picker (autocomplete) for the `user` arg type.
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
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\UserRoleAssignments;

defined( 'ABSPATH' ) || exit;

final class Add implements CommandInterface {

	public function execute( array $args ): Result {
		$ref  = (string) ( $args['user'] ?? '' );
		$user = self::resolve_user( $ref );

		if ( null === $user ) {
			return Result::error(
				/* translators: %s: user reference entered on the command line. */
				sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $ref )
			);
		}

		$role_policy = RolePolicySchema::inspect( false, 'operator_add' );
		if ( ! $role_policy['canonical'] ) {
			$lines = [ 'Role Policy is not canonical.' ];
			foreach ( $role_policy['issues'] as $issue ) {
				$lines[] = '  - ' . $issue;
			}
			$lines[] = 'Run `wp cb permissions repair-role-policy` before assigning a CB Operator.';
			return Result::error( __( 'Cannot assign a CB Operator while the Core Blueprint Role Policy is non-canonical.', 'core-blueprint' ), $lines );
		}

		if ( '' === UserRoleAssignments::base_role( $user ) ) {
			return Result::error( __( 'Cannot assign CB Operator to a user without a normal WordPress base role. Assign the intended WordPress role first.', 'core-blueprint' ) );
		}

		if ( in_array( Roles::OPERATOR_ROLE, (array) $user->roles, true ) ) {
			$approved = PrivilegedAccessRegistry::is_approved( $user );
			$lines    = [ sprintf( '%s (#%d) is already a CB Operator.', $user->user_login, $user->ID ) ];
			if ( ! $approved ) {
				$lines[] = sprintf( 'Inspect: wp cb operator status %d', (int) $user->ID );
				$lines[] = sprintf( 'Trusted recovery: wp cb operator recover %d', (int) $user->ID );
			}
			return Result::warning(
				$approved
					/* translators: 1: user login, 2: WordPress user ID. */
			? sprintf( __( '%1$s (#%2$d) is already a CB Operator with a valid approval.', 'core-blueprint' ), $user->user_login, $user->ID )
					/* translators: 1: user login, 2: WordPress user ID. */
			: sprintf( __( '%1$s (#%2$d) is already a CB Operator but does not have a valid approval. Use the Operator recovery command.', 'core-blueprint' ), $user->user_login, $user->ID ),
				$lines,
				[ 'user_id' => (int) $user->ID, 'changed' => false, 'approved' => $approved ]
			);
		}

		PrivilegedAccessGuard::trusted_mutation( static function () use ( $user ): void {
			$user->add_role( Roles::OPERATOR_ROLE );
		} );
		PrivilegedAccessRegistry::approve( $user, 0, 'cli_operator_add' );

		AuditLog::log( 'permissions.operator_added', 'notice', [
			'user_id'    => (int) $user->ID,
			'user_login' => $user->user_login,
			'by'         => self::execution_origin(),
		] );

		do_action( 'cb_permissions_operator_added', (int) $user->ID, 0 );

		$total = Roles::operator_count();
		$lines = [
			sprintf( '%s (#%d) added to CB Operator role.', $user->user_login, $user->ID ),
			sprintf( 'Total operators now: %d', $total ),
		];

		return Result::success(
			sprintf(
				/* translators: 1: login, 2: ID, 3: total operator count */
				__( '%1$s (#%2$d) promoted to CB Operator. Total operators now: %3$d', 'core-blueprint' ),
				$user->user_login,
				$user->ID,
				$total
			),
			$lines,
			[
				'user_id'        => (int) $user->ID,
				'user_login'     => $user->user_login,
				'operator_count' => $total,
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
				'help'     => __( 'Search by login, email, or display name. The selected user is promoted to the cb_operator role.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Promote a user to the cb_operator role.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb operator add chris
	 *     wp cb operator add operator@example.com
	 *     wp cb operator add 42
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( [ 'user' => $args[0] ?? '' ] );

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

	/**
	 * Resolve a user reference to a WP_User. Returns null when no match -
	 * the caller decides how to surface that. Supports numeric ID, email,
	 * and login formats with the same precedence as the legacy command.
	 */
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
