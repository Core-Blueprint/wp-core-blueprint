<?php
declare(strict_types=1);

namespace CB\Core\CLI\Commands\TwoFactor;

use CB\Core\Console\CommandInterface;
use CB\Core\Console\Result;
use CB\Core\Permissions\PrivilegedAccessPolicy;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\ProviderDetector;
use CB\Core\Security\TwoFactor\RecoveryCodes;

defined( 'ABSPATH' ) || exit;

final class Status implements CommandInterface {

	public function execute( array $args ): Result {
		$ref = (string) ( $args['user'] ?? '' );
		$user = self::resolve_user( $ref );
		if ( null === $user ) {
			return Result::error( sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $ref ) );
		}

		$privileged = PrivilegedAccessPolicy::is_privileged( $user );
		$enrolled = CredentialStore::is_enrolled( (int) $user->ID );
		$recovery = RecoveryCodes::remaining( (int) $user->ID );
		$providers = ProviderDetector::providers_for_user( $user );
		$requires_enrollment = Policy::MODE_ENFORCE === Policy::mode()
			&& $privileged
			&& [] === $providers
			&& ! $enrolled;

		$lines = [
			'',
			'Core Blueprint - Two-Factor Status',
			str_repeat( '─', 48 ),
			'User:                    ' . $user->user_login . ' (#' . (int) $user->ID . ')',
			'Policy mode:             ' . Policy::mode(),
			'Privileged:              ' . ( $privileged ? 'YES' : 'no' ),
			'Base 2FA enrolled:       ' . ( $enrolled ? 'YES' : 'no' ),
			'Recovery codes:          ' . $recovery,
			'External providers:      ' . ( [] !== $providers ? implode( ', ', $providers ) : '-' ),
			'Enrollment required:     ' . ( $requires_enrollment ? 'YES' : 'no' ),
			'',
		];

		$data = [
			'user_id'             => (int) $user->ID,
			'user_login'          => (string) $user->user_login,
			'policy_mode'         => Policy::mode(),
			'privileged'          => $privileged,
			'base_enrolled'       => $enrolled,
			'recovery_codes'      => $recovery,
			'external_providers'  => $providers,
			'enrollment_required' => $requires_enrollment,
		];

		return $requires_enrollment
			? Result::warning( __( 'Base two-factor enrollment is required for this user.', 'core-blueprint' ), $lines, $data )
			: Result::success( '', $lines, $data );
	}

	public function args_schema(): array {
		return [
			'user' => [
				'type'     => 'user',
				'label'    => __( 'User', 'core-blueprint' ),
				'required' => true,
			],
		];
	}

	public function side_effects(): string { return 'none'; }

	/**
	 * Inspect one user's Base two-factor state.
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
