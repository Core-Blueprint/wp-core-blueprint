<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

defined( 'ABSPATH' ) || exit;

/**
 * One-time recovery-code generation and consumption.
 *
 * Plaintext codes are returned only at generation time. Persistence contains
 * WordPress password hashes exclusively.
 */
final class RecoveryCodes {

	public const CODE_COUNT = 10;

	private const CODE_BYTES = 10;

	/** @return string[] Plaintext codes shown once to the user. */
	public static function generate_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$material = self::generate_material();
		CredentialStore::store_recovery_hashes( $user_id, $material['hashes'] );
		return $material['codes'];
	}

	/**
	 * Atomically replace the exact recovery-code snapshot currently stored.
	 *
	 * @return string[] Plaintext replacement codes shown once to the user.
	 */
	public static function regenerate_for_user( int $user_id ): array {
		if ( $user_id <= 0 || ! metadata_exists( 'user', $user_id, CredentialStore::META_RECOVERY ) ) {
			return [];
		}

		$previous = CredentialStore::recovery_hashes( $user_id );
		$material = self::generate_material();

		if ( ! CredentialStore::claim_recovery_hashes( $user_id, $previous, $material['hashes'] ) ) {
			return [];
		}

		return $material['codes'];
	}

	public static function consume( int $user_id, string $candidate ): bool {
		$candidate = self::normalize( $candidate );
		if ( 20 !== strlen( $candidate ) || 1 !== preg_match( '/^[A-F0-9]{20}$/', $candidate ) ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$hashes = CredentialStore::recovery_hashes( $user_id );
			foreach ( $hashes as $index => $hash ) {
				if ( ! wp_check_password( $candidate, $hash ) ) {
					continue;
				}

				$next = $hashes;
				unset( $next[ $index ] );
				$next = array_values( $next );

				if ( ! CredentialStore::claim_recovery_hashes( $user_id, $hashes, $next ) ) {
					continue 2;
				}

				Audit::recovery_code_used( $user_id, count( $next ) );
				return true;
			}

			return false;
		}

		return false;
	}

	public static function remaining( int $user_id ): int {
		return count( CredentialStore::recovery_hashes( $user_id ) );
	}

	/** @return array{codes:string[],hashes:string[]} */
	private static function generate_material(): array {
		$codes  = [];
		$hashes = [];

		for ( $i = 0; $i < self::CODE_COUNT; $i++ ) {
			$normalized = strtoupper( bin2hex( random_bytes( self::CODE_BYTES ) ) );
			$codes[]     = implode( '-', str_split( $normalized, 5 ) );
			$hashes[]    = wp_hash_password( $normalized );
		}

		return [
			'codes'  => $codes,
			'hashes' => $hashes,
		];
	}

	private static function normalize( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', trim( $code ) ) ?? '' );
	}
}
