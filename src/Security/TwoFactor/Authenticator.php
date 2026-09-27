<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Authentication-material verifier.
 *
 * Login orchestration deliberately lives elsewhere. This class only verifies
 * a presented second factor and commits replay-prevention state.
 */
final class Authenticator {

	public static function verify_totp( int $user_id, string $code, ?int $timestamp = null ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		try {
			$secret = CredentialStore::totp_secret( $user_id );
		} catch ( RuntimeException ) {
			return false;
		}

		if ( null === $secret ) {
			return false;
		}

		$timestep = Totp::verify(
			$secret,
			$code,
			$timestamp ?? time(),
			1,
			CredentialStore::last_timestep( $user_id )
		);
		if ( null === $timestep ) {
			return false;
		}

		return CredentialStore::claim_timestep( $user_id, $timestep );
	}

	public static function verify_recovery_code( int $user_id, string $code ): bool {
		return $user_id > 0 && RecoveryCodes::consume( $user_id, $code );
	}
}
