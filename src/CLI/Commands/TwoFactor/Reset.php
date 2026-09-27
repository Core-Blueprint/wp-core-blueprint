<?php
declare(strict_types=1);

namespace CB\Core\CLI\Commands\TwoFactor;

use CB\Core\Console\CommandInterface;
use CB\Core\Console\Result;
use CB\Core\Security\TwoFactor\ProviderDetector;
use CB\Core\Security\TwoFactor\RecoveryManager;

defined( 'ABSPATH' ) || exit;

final class Reset implements CommandInterface {

	public function execute( array $args ): Result {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return Result::error( __( 'Two-factor reset is available only through trusted server-side WP-CLI.', 'core-blueprint' ) );
		}

		$ref = (string) ( $args['user'] ?? '' );
		$user = self::resolve_user( $ref );
		if ( null === $user ) {
			return Result::error( sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $ref ) );
		}

		try {
			$providers = ProviderDetector::providers_for_user( $user );
			$stats = RecoveryManager::reset_user( $user, 'cli' );
		} catch ( \Throwable ) {
			return Result::error( __( 'Base two-factor authentication reset failed.', 'core-blueprint' ) );
		}

		$lines = [
			sprintf( '%s (#%d)', $user->user_login, (int) $user->ID ),
			'Base enrolled before:    ' . ( $stats['was_enrolled'] ? 'YES' : 'no' ),
			'Recovery codes removed:  ' . (int) $stats['recovery_codes'],
			'Pending enrollment:      ' . ( $stats['pending_enrollment'] ? 'cleared' : 'none' ),
			'Challenges revoked:      ' . ( $stats['challenges_revoked'] ? 'YES' : 'none issued' ),
			'External providers:      ' . ( [] !== $providers ? implode( ', ', $providers ) . ' (unchanged)' : '-' ),
		];

		$data = [
			'user_id'            => (int) $user->ID,
			'changed'            => (bool) $stats['changed'],
			'was_enrolled'       => (bool) $stats['was_enrolled'],
			'recovery_codes'     => (int) $stats['recovery_codes'],
			'pending_enrollment' => (bool) $stats['pending_enrollment'],
			'challenges_revoked' => (bool) $stats['challenges_revoked'],
			'external_providers' => $providers,
		];

		return $stats['changed']
			? Result::success( __( 'Base two-factor authentication reset completed.', 'core-blueprint' ), $lines, $data )
			: Result::warning( __( 'No Base two-factor authentication state required a reset.', 'core-blueprint' ), $lines, $data );
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

	public function side_effects(): string { return 'destructive'; }

	/**
	 * Reset Base-owned 2FA for a known user through trusted server-side CLI.
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
