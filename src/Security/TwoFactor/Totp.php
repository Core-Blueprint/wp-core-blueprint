<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 6238 TOTP implementation using the RFC-mandated HOTP construction.
 *
 * Production verification uses 6 digits and a 30-second period. The code()
 * helper also supports 8 digits so the implementation can be verified against
 * the published RFC 6238 SHA-1 test vectors.
 */
final class Totp {

	public const PERIOD = 30;
	public const DIGITS = 6;

	private const SECRET_BYTES = 20;
	private const ALPHABET     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public static function generate_secret(): string {
		return self::base32_encode( random_bytes( self::SECRET_BYTES ) );
	}

	public static function code( string $secret, int $timestamp, int $digits = self::DIGITS ): string {
		if ( $timestamp < 0 || ! in_array( $digits, [ 6, 8 ], true ) ) {
			throw new InvalidArgumentException( 'Invalid TOTP code parameters.' );
		}

		$key     = self::base32_decode( $secret );
		$counter = intdiv( $timestamp, self::PERIOD );
		return self::counter_code( $key, $counter, $digits );
	}

	/**
	 * Verify a six-digit code and return the accepted timestep.
	 *
	 * @return int|null Accepted timestep, or null on failure.
	 */
	public static function verify(
		string $secret,
		string $candidate,
		int $timestamp,
		int $window = 1,
		int $last_timestep = -1
	): ?int {
		$candidate = trim( $candidate );
		if (
			$timestamp < 0
			|| $window < 0
			|| $window > 2
			|| 1 !== preg_match( '/^\d{6}$/', $candidate )
		) {
			return null;
		}

		try {
			$key = self::base32_decode( $secret );
		} catch ( InvalidArgumentException ) {
			return null;
		}

		$current = intdiv( $timestamp, self::PERIOD );
		$offsets = [ 0 ];
		for ( $i = 1; $i <= $window; $i++ ) {
			$offsets[] = -$i;
			$offsets[] = $i;
		}

		foreach ( $offsets as $offset ) {
			$timestep = $current + $offset;
			if ( $timestep < 0 || $timestep <= $last_timestep ) {
				continue;
			}
			if ( hash_equals( self::counter_code( $key, $timestep, self::DIGITS ), $candidate ) ) {
				return $timestep;
			}
		}

		return null;
	}

	private static function counter_code( string $key, int $counter, int $digits ): string {
		$high = intdiv( $counter, 0x100000000 );
		$low  = $counter % 0x100000000;

		$counter_bytes = pack( 'N2', $high, $low );
		$hash          = hash_hmac( 'sha1', $counter_bytes, $key, true );
		$offset        = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0f;

		$binary = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xff );

		$modulus = 10 ** $digits;
		return str_pad( (string) ( $binary % $modulus ), $digits, '0', STR_PAD_LEFT );
	}

	private static function base32_encode( string $binary ): string {
		if ( '' === $binary ) {
			throw new InvalidArgumentException( 'TOTP secret material cannot be empty.' );
		}

		$output = '';
		$buffer = 0;
		$bits   = 0;

		foreach ( str_split( $binary ) as $byte ) {
			$buffer = ( $buffer << 8 ) | ord( $byte );
			$bits  += 8;

			while ( $bits >= 5 ) {
				$bits -= 5;
				$output .= self::ALPHABET[ ( $buffer >> $bits ) & 0x1f ];
				$buffer = $bits > 0 ? $buffer & ( ( 1 << $bits ) - 1 ) : 0;
			}
		}

		if ( $bits > 0 ) {
			$output .= self::ALPHABET[ ( $buffer << ( 5 - $bits ) ) & 0x1f ];
		}

		return $output;
	}

	private static function base32_decode( string $secret ): string {
		$secret = strtoupper( preg_replace( '/\s+/', '', trim( $secret ) ) ?? '' );
		$secret = rtrim( $secret, '=' );
		if ( '' === $secret || 1 !== preg_match( '/^[A-Z2-7]+$/', $secret ) ) {
			throw new InvalidArgumentException( 'Invalid Base32 TOTP secret.' );
		}

		$output = '';
		$buffer = 0;
		$bits   = 0;

		foreach ( str_split( $secret ) as $character ) {
			$value = strpos( self::ALPHABET, $character );
			if ( false === $value ) {
				throw new InvalidArgumentException( 'Invalid Base32 TOTP secret.' );
			}

			$buffer = ( $buffer << 5 ) | $value;
			$bits  += 5;

			while ( $bits >= 8 ) {
				$bits -= 8;
				$output .= chr( ( $buffer >> $bits ) & 0xff );
				$buffer = $bits > 0 ? $buffer & ( ( 1 << $bits ) - 1 ) : 0;
			}
		}

		if ( '' === $output ) {
			throw new InvalidArgumentException( 'Invalid Base32 TOTP secret.' );
		}

		return $output;
	}
}
