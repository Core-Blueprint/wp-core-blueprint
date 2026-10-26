<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\CLI\Commands\TwoFactor;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side-only manual abandoned-lock recovery. This is not a 2FA reset,
 * credential writer, or browser Console command.
 */
final class Unlock implements CommandInterface {
	public function execute( array $args ): Result {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return Result::error( 'Two-factor lock recovery requires trusted WP-CLI.' );
		}
		$ref = (string) ( $args['user'] ?? '' );
		if ( ! ctype_digit( $ref ) || (int) $ref <= 0 ) {
			return Result::error( 'A positive WordPress user ID is required.' );
		}
		$user_id = (int) $ref;
		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return Result::error( 'The selected WordPress user does not exist.' );
		}

		$evidence = CredentialStore::enrollment_lock_evidence( $user_id );
		if ( null === $evidence ) {
			return Result::warning( 'No supported Base enrollment lock is available for recovery.' );
		}
		if ( ! empty( $args['inspect'] ) ) {
			return Result::success( 'Enrollment lock evidence (read-only).', [
				'User ID: ' . $user_id,
				'Expected SHA-256: ' . $evidence['sha256'],
				'Created at (Unix): ' . ( $evidence['legacy'] ? 'unknown (legacy)' : $evidence['created_at'] ),
				'Legacy format: ' . ( $evidence['legacy'] ? 'YES' : 'no' ),
			], $evidence );
		}
		if ( ( $args['confirm-inactive'] ?? '' ) !== 'I_VERIFIED_PROCESS_STOPPED' ) {
			return Result::error( 'Confirm the originating process is stopped using --confirm-inactive=I_VERIFIED_PROCESS_STOPPED.' );
		}
		if ( $evidence['legacy'] && ( $args['legacy-lock'] ?? '' ) !== 'YES' ) {
			return Result::error( 'A timestamp-free legacy lock additionally requires --legacy-lock=YES.' );
		}
		try {
			$recovered = CredentialStore::recover_enrollment_lock(
				$user_id,
				(string) ( $args['expected-sha256'] ?? '' ),
				true,
				$evidence['legacy'] && ( $args['legacy-lock'] ?? '' ) === 'YES'
			);
		} catch ( \Throwable ) {
			return Result::error( 'Lock recovery refused or failed. No credential or approval was reset.' );
		}
		return $recovered
			? Result::success( 'Exact abandoned enrollment lock released.', [ 'User ID: ' . $user_id ] )
			: Result::error( 'Lock identity changed; no lock was removed.' );
	}

	public function args_schema(): array {
		return [
			'user' => [ 'type' => 'user', 'label' => 'WordPress user ID', 'required' => true ],
		];
	}
	public function side_effects(): string { return 'destructive'; }

	/**
	 * Inspect: wp cb two-factor unlock <user-id> --inspect
	 * Recover: wp cb two-factor unlock <user-id> --expected-sha256=<digest> --confirm-inactive=I_VERIFIED_PROCESS_STOPPED
	 * Legacy:  add --legacy-lock=YES after independently verifying termination.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( array_merge( $assoc_args, [ 'user' => (string) ( $args[0] ?? '' ) ] ) );
		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
		if ( 'error' === $result->status ) {
			\WP_CLI::error( $result->message );
		}
		if ( 'warning' === $result->status ) {
			\WP_CLI::warning( $result->message );
			return;
		}
		\WP_CLI::success( $result->message );
	}
}
