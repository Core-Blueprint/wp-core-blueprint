<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Security\TwoFactor;

use RuntimeException;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived enrollment state for a not-yet-enrolled user.
 *
 * Pending TOTP material is authenticated-encrypted and user-bound. Successful
 * confirmation promotes the pending secret into the canonical credential store,
 * consumes the enrollment timestep to prevent immediate replay, issues recovery
 * codes once, then removes the pending state.
 */
final class EnrollmentStore {

	public const META_PENDING = '_cb_core_two_factor_pending_enrollment';
	public const TTL_SECONDS  = 600;

	private const VERSION = 1;

	/**
	 * Start or resume enrollment for a user that has no canonical 2FA credential.
	 *
	 * @return string Plaintext Base32 secret for immediate local provisioning.
	 */
	public static function start( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! ( $user instanceof WP_User ) ) {
			throw new RuntimeException( 'Two-factor enrollment requires a valid WordPress user.' );
		}
		return CredentialStore::with_enrollment_lock( $user_id, static function () use ( $user_id ): string {
			if ( CredentialStore::is_enrolled( $user_id ) ) {
				throw new RuntimeException( 'This user is already enrolled in two-factor authentication.' );
			}
			$existing = self::pending_secret( $user_id );
			if ( null !== $existing ) {
				return $existing;
			}
			$secret = Totp::generate_secret();
			$now = time();
			$state = [
				'version'    => self::VERSION,
				'secret'     => CredentialCipher::encrypt( $secret, $user_id ),
				'created_at' => $now,
				'expires_at' => $now + self::TTL_SECONDS,
			];
			self::persist( $user_id, $state );
			Audit::enrollment_started( $user_id );
			return $secret;
		} );
	}

	public static function has_pending( int $user_id ): bool {
		$state = get_user_meta( $user_id, self::META_PENDING, true );
		if ( ! is_array( $state ) || [] === $state ) {
			return false;
		}

		if ( null === self::normalize_state( $state ) ) {
			self::delete_pending( $user_id, $state );
			return false;
		}

		return true;
	}

	public static function pending_secret( int $user_id ): ?string {
		$state = get_user_meta( $user_id, self::META_PENDING, true );
		if ( ! is_array( $state ) || [] === $state ) {
			return null;
		}

		$normalized = self::normalize_state( $state );
		if ( null === $normalized ) {
			self::delete_pending( $user_id, $state );
			return null;
		}

		try {
			return CredentialCipher::decrypt( $normalized['secret'], $user_id );
		} catch ( RuntimeException ) {
			self::delete_pending( $user_id, $state );
			return null;
		}
	}

	/**
	 * Confirm pending enrollment and return one-time plaintext recovery codes.
	 *
	 * @return string[]|null Recovery codes on success, null for an invalid TOTP.
	 */
	public static function confirm( int $user_id, string $code, ?int $timestamp = null ): ?array {
		return CredentialStore::with_enrollment_lock( $user_id, static function () use ( $user_id, $code, $timestamp ): ?array {
			if ( CredentialStore::is_enrolled( $user_id ) ) {
				return null;
			}
			$state = get_user_meta( $user_id, self::META_PENDING, true );
			$secret = self::pending_secret( $user_id );
			if ( null === $secret ) {
				return null;
			}
			$timestep = Totp::verify( $secret, $code, $timestamp ?? time(), 1, -1 );
			if ( null === $timestep ) {
				return null;
			}
			$material = RecoveryCodes::new_material();
			if ( RecoveryCodes::CODE_COUNT !== count( $material['codes'] ) ) {
				throw new RuntimeException( 'Could not issue two-factor recovery codes.' );
			}
			self::delete_pending( $user_id, $state );
			if ( metadata_exists( 'user', $user_id, self::META_PENDING ) ) {
				throw new RuntimeException( 'Could not consume pending two-factor enrollment.' );
			}
			CredentialStore::create_enrollment( $user_id, $secret, $timestep, $material['hashes'] );
			Audit::enrollment_completed( $user_id );
			return $material['codes'];
		} );
	}

	public static function clear( int $user_id ): void {
		CredentialStore::with_enrollment_lock( $user_id, static function () use ( $user_id ): void {
			self::delete_pending( $user_id );
			if ( metadata_exists( 'user', $user_id, self::META_PENDING ) ) {
				throw new RuntimeException( 'Could not clear pending two-factor enrollment state.' );
			}
		} );
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array{version:int,secret:array<string,mixed>,created_at:int,expires_at:int}|null
	 */
	private static function normalize_state( array $state ): ?array {
		$secret     = $state['secret'] ?? null;
		$created_at = (int) ( $state['created_at'] ?? 0 );
		$expires_at = (int) ( $state['expires_at'] ?? 0 );

		if (
			self::VERSION !== (int) ( $state['version'] ?? 0 )
			|| ! is_array( $secret )
			|| $created_at <= 0
			|| $expires_at <= $created_at
			|| $expires_at <= time()
		) {
			return null;
		}

		return [
			'version'    => self::VERSION,
			'secret'     => $secret,
			'created_at' => $created_at,
			'expires_at' => $expires_at,
		];
	}

	/** @param array<string,mixed> $state */
	/** The caller holds the enrollment lock. */
	private static function persist( int $user_id, array $state ): void {
		if ( metadata_exists( 'user', $user_id, self::META_PENDING ) ) {
			self::delete_pending( $user_id, get_user_meta( $user_id, self::META_PENDING, true ) );
		}
		if (
			metadata_exists( 'user', $user_id, self::META_PENDING )
			|| ! add_user_meta( $user_id, self::META_PENDING, $state, true )
			|| get_user_meta( $user_id, self::META_PENDING, true ) !== $state
		) {
			throw new RuntimeException( 'Could not persist pending two-factor enrollment state.' );
		}
	}

	private static function delete_pending( int $user_id, mixed $expected = null ): void {
		if ( null === $expected ) {
			delete_user_meta( $user_id, self::META_PENDING );
			return;
		}
		delete_user_meta( $user_id, self::META_PENDING, $expected );
	}
}
