<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use InvalidArgumentException;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user two-factor credential persistence.
 *
 * This store owns authentication material only. Site-wide two-factor policy
 * remains in Settings and Core Profiles never reads these user-meta records.
 */
final class CredentialStore {

	public const META_SECRET        = '_cb_core_two_factor_totp_secret';
	public const META_RECOVERY      = '_cb_core_two_factor_recovery_hashes';
	public const META_ENROLLED_AT   = '_cb_core_two_factor_enrolled_at';
	public const META_LAST_TIMESTEP = '_cb_core_two_factor_last_timestep';

	public static function store_totp_secret( int $user_id, string $secret ): void {
		if ( $user_id <= 0 ) {
			throw new InvalidArgumentException( 'Invalid two-factor user.' );
		}

		$secret = self::normalize_secret( $secret );
		$stored = CredentialCipher::encrypt( $secret, $user_id );

		$previous = [];
		foreach ( [ self::META_SECRET, self::META_ENROLLED_AT, self::META_LAST_TIMESTEP ] as $key ) {
			$previous[ $key ] = [
				'exists' => metadata_exists( 'user', $user_id, $key ),
				'value'  => get_user_meta( $user_id, $key, true ),
			];
		}

		try {
			self::persist_meta( $user_id, self::META_SECRET, $stored );
			self::persist_meta( $user_id, self::META_ENROLLED_AT, time() );
			self::persist_meta( $user_id, self::META_LAST_TIMESTEP, -1 );
		} catch ( RuntimeException $error ) {
			foreach ( $previous as $key => $state ) {
				self::restore_meta( $user_id, (string) $key, (bool) $state['exists'], $state['value'] );
			}
			throw $error;
		}
	}

	public static function totp_secret( int $user_id ): ?string {
		$stored = get_user_meta( $user_id, self::META_SECRET, true );
		if ( ! is_array( $stored ) || [] === $stored ) {
			return null;
		}
		return CredentialCipher::decrypt( $stored, $user_id );
	}

	public static function is_enrolled( int $user_id ): bool {
		if ( (int) get_user_meta( $user_id, self::META_ENROLLED_AT, true ) <= 0 ) {
			return false;
		}
		try {
			return null !== self::totp_secret( $user_id );
		} catch ( RuntimeException ) {
			return false;
		}
	}

	public static function enrolled_at( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_ENROLLED_AT, true ) );
	}

	public static function last_timestep( int $user_id ): int {
		$stored = get_user_meta( $user_id, self::META_LAST_TIMESTEP, true );
		if ( '' === $stored || null === $stored ) {
			return -1;
		}
		return max( -1, (int) $stored );
	}

	public static function set_last_timestep( int $user_id, int $timestep ): void {
		if ( $user_id <= 0 || $timestep < -1 ) {
			throw new InvalidArgumentException( 'Invalid two-factor timestep.' );
		}
		self::persist_meta( $user_id, self::META_LAST_TIMESTEP, $timestep );
	}

	/**
	 * Atomically claim a newer TOTP timestep.
	 *
	 * update_user_meta() receives the exact previous value, giving concurrent
	 * requests compare-and-swap semantics. A second request using the same code
	 * cannot overwrite the already-advanced timestep.
	 */
	public static function claim_timestep( int $user_id, int $timestep ): bool {
		if ( $user_id <= 0 || $timestep < 0 || ! metadata_exists( 'user', $user_id, self::META_LAST_TIMESTEP ) ) {
			return false;
		}

		$previous_raw = get_user_meta( $user_id, self::META_LAST_TIMESTEP, true );
		$previous     = (int) $previous_raw;
		if ( $timestep <= $previous ) {
			return false;
		}

		$updated = update_user_meta(
			$user_id,
			self::META_LAST_TIMESTEP,
			$timestep,
			$previous_raw
		);
		if ( false === $updated ) {
			return false;
		}

		return $timestep === self::last_timestep( $user_id );
	}

	/** @return string[] */
	public static function recovery_hashes( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_RECOVERY, true );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		return array_values( array_filter(
			array_map( 'strval', $stored ),
			static fn( string $hash ): bool => '' !== $hash
		) );
	}

	/** @param string[] $hashes */
	public static function store_recovery_hashes( int $user_id, array $hashes ): void {
		if ( $user_id <= 0 ) {
			throw new InvalidArgumentException( 'Invalid two-factor user.' );
		}
		$hashes = array_values( array_filter(
			array_map( 'strval', $hashes ),
			static fn( string $hash ): bool => '' !== $hash
		) );
		self::persist_meta( $user_id, self::META_RECOVERY, $hashes );
	}

	public static function clear( int $user_id ): void {
		foreach ( [
			self::META_SECRET,
			self::META_RECOVERY,
			self::META_ENROLLED_AT,
			self::META_LAST_TIMESTEP,
		] as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	private static function normalize_secret( string $secret ): string {
		$secret = strtoupper( preg_replace( '/\s+/', '', trim( $secret ) ) ?? '' );
		if (
			strlen( $secret ) < 16
			|| strlen( $secret ) > 128
			|| 1 !== preg_match( '/^[A-Z2-7]+$/', $secret )
		) {
			throw new InvalidArgumentException( 'Invalid TOTP secret.' );
		}
		return $secret;
	}

	private static function persist_meta( int $user_id, string $key, mixed $value ): void {
		$result = update_user_meta( $user_id, $key, $value );
		if ( false !== $result ) {
			return;
		}

		$current = get_user_meta( $user_id, $key, true );
		$same = is_array( $value )
			? $current === $value
			: (string) $current === (string) $value;
		if ( ! $same ) {
			throw new RuntimeException( 'Could not persist two-factor credential state.' );
		}
	}

	private static function restore_meta( int $user_id, string $key, bool $existed, mixed $value ): void {
		if ( ! $existed ) {
			delete_user_meta( $user_id, $key );
			return;
		}
		update_user_meta( $user_id, $key, $value );
	}
}
