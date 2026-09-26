<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use RuntimeException;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical reset boundary for Base-owned two-factor authentication state.
 *
 * Authorization belongs to the caller. This service guarantees revocation
 * order and exact Base-owned state cleanup so WP-CLI and future user-profile
 * recovery use the same security semantics.
 */
final class RecoveryManager {

	/**
	 * @return array{
	 *   changed:bool,
	 *   was_enrolled:bool,
	 *   credential_records:int,
	 *   recovery_codes:int,
	 *   pending_enrollment:bool,
	 *   challenges_revoked:bool
	 * }
	 */
	public static function reset_user( WP_User $user, string $source ): array {
		$user_id = (int) $user->ID;
		if ( $user_id <= 0 ) {
			throw new RuntimeException( 'Two-factor reset requires a valid WordPress user.' );
		}

		$credential_keys = [
			CredentialStore::META_SECRET,
			CredentialStore::META_RECOVERY,
			CredentialStore::META_ENROLLED_AT,
			CredentialStore::META_LAST_TIMESTEP,
		];

		$credential_records = 0;
		foreach ( $credential_keys as $key ) {
			if ( metadata_exists( 'user', $user_id, $key ) ) {
				$credential_records++;
			}
		}

		$was_enrolled = CredentialStore::is_enrolled( $user_id );
		$recovery_codes = RecoveryCodes::remaining( $user_id );
		$pending = metadata_exists( 'user', $user_id, EnrollmentStore::META_PENDING );
		$generation = CredentialStore::stored_challenge_generation( $user_id );
		$challenges_revoked = null !== $generation;

		// Revoke password-authenticated challenge authority before deleting the
		// underlying factor. Partial cleanup can never leave an old challenge
		// stronger than the remaining credential state.
		if ( $challenges_revoked ) {
			CredentialStore::rotate_challenge_generation( $user_id );
		}

		if ( $pending ) {
			EnrollmentStore::clear( $user_id );
		}

		if ( $credential_records > 0 ) {
			CredentialStore::clear( $user_id );
		}

		foreach ( $credential_keys as $key ) {
			if ( metadata_exists( 'user', $user_id, $key ) ) {
				throw new RuntimeException( 'Could not clear Base two-factor authentication state.' );
			}
		}
		if ( metadata_exists( 'user', $user_id, EnrollmentStore::META_PENDING ) ) {
			throw new RuntimeException( 'Could not clear pending Base two-factor enrollment state.' );
		}

		$stats = [
			'changed'            => $credential_records > 0 || $pending || $challenges_revoked,
			'was_enrolled'       => $was_enrolled,
			'credential_records' => $credential_records,
			'recovery_codes'     => $recovery_codes,
			'pending_enrollment' => $pending,
			'challenges_revoked' => $challenges_revoked,
		];

		Audit::authentication_reset( $user_id, $source, $stats );
		return $stats;
	}
}
