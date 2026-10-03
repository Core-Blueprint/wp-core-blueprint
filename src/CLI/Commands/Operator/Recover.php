<?php
declare(strict_types=1);
/**
 * Operator\Recover - trusted server-side break-glass Operator recovery.
 *
 * This command is intentionally WP-CLI only. It never appears in the browser
 * Console and never infers trust from a role assignment alone.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\CLI\Commands\Operator;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Permissions\PrivilegedAccessGuard;
use CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\RolePolicySchema;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Permissions\UserRoleAssignments;

defined( 'ABSPATH' ) || exit;

final class Recover implements CommandInterface {

	public function execute( array $args ): Result {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return Result::error( __( 'Operator recovery is available only through trusted server-side WP-CLI.', 'core-blueprint' ) );
		}

		$ref  = (string) ( $args['user'] ?? '' );
		$user = self::resolve_user( $ref );
		if ( null === $user ) {
			/* translators: %s: user reference entered on the command line. */
			return Result::error( sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $ref ) );
		}

		$role_policy = RolePolicySchema::inspect( false, 'operator_recover' );
		if ( ! $role_policy['canonical'] ) {
			$lines = [ 'Role Policy is not canonical.' ];
			foreach ( $role_policy['issues'] as $issue ) { $lines[] = '  - ' . $issue; }
			$lines[] = 'Run `wp cb permissions repair-role-policy`, verify the result, then retry recovery.';
			return Result::error( __( 'Cannot recover Operator trust while the Core Blueprint Role Policy is non-canonical.', 'core-blueprint' ), $lines );
		}

		$was_operator = in_array( Roles::OPERATOR_ROLE, (array) $user->roles, true );
		$base_before  = UserRoleAssignments::base_role( $user );
		if ( '' === $base_before ) {
			return Result::error( __( 'Operator recovery requires a normal WordPress base role. Restore the verified account’s intended WordPress role first, then retry recovery.', 'core-blueprint' ) );
		}
		$was_approved = PrivilegedAccessRegistry::is_approved( $user );
		$review       = PrivilegedAccessRegistry::review_state( $user );
		if ( $was_operator && $was_approved ) {
			return Result::success(
				__( 'This CB Operator already has a valid signed approval; no recovery was required.', 'core-blueprint' ),
				[ sprintf( '%s (#%d) is already a healthy approved CB Operator.', $user->user_login, $user->ID ) ],
				[ 'user_id' => (int) $user->ID, 'changed' => false, 'approved' => true ]
			);
		}

		$role_added = false;
		if ( ! $was_operator ) {
			PrivilegedAccessGuard::trusted_mutation( static function () use ( $user, &$role_added ): void {
				$user->add_role( Roles::OPERATOR_ROLE );
				$role_added = in_array( Roles::OPERATOR_ROLE, (array) $user->roles, true );
			} );
		}

		$user = get_userdata( (int) $user->ID );
		if ( ! ( $user instanceof \WP_User ) || ! in_array( Roles::OPERATOR_ROLE, (array) $user->roles, true ) ) {
			return Result::error( __( 'Recovery could not establish the CB Operator role. No approval was written.', 'core-blueprint' ) );
		}
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
			return Result::error( __( 'Recovery stopped because the resulting identity is not classified as privileged.', 'core-blueprint' ) );
		}

		$approved = PrivilegedAccessRegistry::approve( $user, 0, 'cli_operator_recover' );
		if ( ! $approved || ! PrivilegedAccessRegistry::is_approved( $user ) ) {
			return Result::error( __( 'Recovery could not create a valid signed approval for the current privilege state.', 'core-blueprint' ) );
		}

		AuditLog::log( 'permissions.operator_recovered', 'warning', [
			'user_id'                => (int) $user->ID,
			'user_login'             => (string) $user->user_login,
			'role_added'             => $role_added,
			'previously_approved'    => $was_approved,
			'previous_review_reason' => (string) ( $review['reason'] ?? '' ),
			'previous_review_source' => (string) ( $review['source'] ?? '' ),
			'by'                     => 'cli',
		] );

		$base_role = UserRoleAssignments::base_role( $user );
		$lines = [
			sprintf( '%s (#%d) recovered as an approved CB Operator.', $user->user_login, $user->ID ),
			'Roles: ' . implode( ', ', (array) $user->roles ),
			'Base role: ' . ( '' !== $base_role ? $base_role : '-' ),
			'Approval: VALID',
			'Needs review: no',
		];

		return Result::success(
			__( 'CB Operator recovery completed with a fresh signed approval.', 'core-blueprint' ),
			$lines,
			[ 'user_id' => (int) $user->ID, 'changed' => true, 'role_added' => $role_added, 'approved' => true, 'base_role' => $base_role ]
		);
	}

	public function args_schema(): array {
		return [
			'user' => [
				'type'     => 'user',
				'label'    => __( 'User', 'core-blueprint' ),
				'required' => true,
				'help'     => __( 'Known management identity to recover through trusted server-side WP-CLI.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string { return 'destructive'; }

	/**
	 * Recover or re-approve a known CB Operator through trusted server-side CLI.
	 *
	 * ## OPTIONS
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( [ 'user' => $args[0] ?? '' ] );
		foreach ( $result->lines as $line ) { \WP_CLI::line( $line ); }
		if ( 'error' === $result->status ) { \WP_CLI::error( $result->message ); }
		if ( 'warning' === $result->status ) { \WP_CLI::warning( $result->message ); return; }
		\WP_CLI::success( $result->message );
	}

	private static function resolve_user( string $ref ): ?\WP_User {
		if ( '' === trim( $ref ) ) { return null; }
		if ( ctype_digit( $ref ) ) { $u = get_userdata( (int) $ref ); if ( $u ) { return $u; } }
		if ( false !== strpos( $ref, '@' ) ) { $u = get_user_by( 'email', $ref ); if ( $u ) { return $u; } }
		$u = get_user_by( 'login', $ref );
		return $u ?: null;
	}
}
