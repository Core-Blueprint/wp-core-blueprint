<?php
declare(strict_types=1);
/**
 * Operator\Status - inspect one user's Operator/trust state.
 *
 * Read-only companion to `cb operator recover`. Safe for the browser Console;
 * it exposes health state, not approval material or secret signatures.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\CLI\Commands\Operator;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Permissions\UserRoleAssignments;

defined( 'ABSPATH' ) || exit;

final class Status implements CommandInterface {

	public function execute( array $args ): Result {
		$ref  = (string) ( $args['user'] ?? '' );
		$user = self::resolve_user( $ref );
		if ( null === $user ) {
			/* translators: %s: user reference entered on the command line. */
			return Result::error( sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $ref ) );
		}

		$roles        = array_values( array_map( 'strval', (array) $user->roles ) );
		$base_role    = UserRoleAssignments::base_role( $user );
		$additional   = UserRoleAssignments::additional_roles( $user );
		$is_operator  = in_array( Roles::OPERATOR_ROLE, $roles, true );
		$privileged   = PrivilegedAccessPolicy::is_privileged( $user );
		$approved     = PrivilegedAccessRegistry::is_approved( $user );
		$review       = PrivilegedAccessRegistry::review_state( $user );
		$needs_review = ! empty( $review ) && ! $approved;

		$lines = [
			'',
			'Core Blueprint - Operator Status',
			str_repeat( '─', 48 ),
			'User:                    ' . $user->user_login . ' (#' . (int) $user->ID . ')',
			'Roles:                   ' . ( $roles ? implode( ', ', $roles ) : '-' ),
			'Base role:               ' . ( '' !== $base_role ? $base_role : '-' ),
			'Additional roles:        ' . ( $additional ? implode( ', ', $additional ) : '-' ),
			'CB Operator:             ' . ( $is_operator ? 'YES' : 'no' ),
			'Privileged:              ' . ( $privileged ? 'YES' : 'no' ),
			'Approved:                ' . ( $approved ? 'YES' : 'NO' ),
			'Needs review:            ' . ( $needs_review ? 'YES' : 'no' ),
			'Review reason:           ' . ( $needs_review ? (string) ( $review['reason'] ?? '-' ) : '-' ),
			'Review source:           ' . ( $needs_review ? (string) ( $review['source'] ?? '-' ) : '-' ),
			'Fingerprint health:      ' . ( $approved ? 'VALID' : ( $privileged ? 'NOT APPROVED' : 'N/A' ) ),
		];
		$assignment_invalid = $is_operator && '' === $base_role;
		if ( $assignment_invalid ) {
			$lines[] = '⚠ This Operator has no normal WordPress base role. CB Operator must remain additive to a normal site role.';
		}
		$lines[] = '';

		$data = [
			'user_id'          => (int) $user->ID,
			'user_login'       => (string) $user->user_login,
			'roles'            => $roles,
			'base_role'        => $base_role,
			'additional_roles' => $additional,
			'operator'         => $is_operator,
			'privileged'       => $privileged,
			'approved'         => $approved,
			'needs_review'     => $needs_review,
			'review_reason'    => (string) ( $review['reason'] ?? '' ),
			'review_source'    => (string) ( $review['source'] ?? '' ),
		];

		$status = ( $privileged && ! $approved ) || $assignment_invalid ? 'warning' : 'success';
		$message = $approved && ! $assignment_invalid
			? __( 'Operator trust state is healthy.', 'core-blueprint' )
			: ( $privileged || $assignment_invalid
				? __( 'Operator trust state requires attention.', 'core-blueprint' )
				: __( 'This user is not a privileged identity.', 'core-blueprint' ) );
		return new Result( $status, $message, $lines, $data );
	}

	public function args_schema(): array {
		return [
			'user' => [
				'type'     => 'user',
				'label'    => __( 'User', 'core-blueprint' ),
				'required' => true,
				'help'     => __( 'Inspect Operator role, base/additional roles, privileged classification, approval and review state.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string { return 'none'; }

	/**
	 * Inspect one user's current Operator/trust state.
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
		if ( 'warning' === $result->status ) { \WP_CLI::warning( $result->message ); }
	}

	private static function resolve_user( string $ref ): ?\WP_User {
		if ( '' === trim( $ref ) ) { return null; }
		if ( ctype_digit( $ref ) ) { $u = get_userdata( (int) $ref ); if ( $u ) { return $u; } }
		if ( false !== strpos( $ref, '@' ) ) { $u = get_user_by( 'email', $ref ); if ( $u ) { return $u; } }
		$u = get_user_by( 'login', $ref );
		return $u ?: null;
	}
}
