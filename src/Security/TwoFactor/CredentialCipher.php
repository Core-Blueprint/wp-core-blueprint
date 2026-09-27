<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption for TOTP secrets.
 *
 * The encryption key is derived from the site's auth salt and the exact user
 * identity. Copying a ciphertext record to another user cannot turn it into a
 * usable credential. No plaintext persistence fallback exists.
 */
final class CredentialCipher {

	private const VERSION   = 1;
	private const ALGORITHM = 'sodium_secretbox';
	private const PURPOSE   = 'core-blueprint-two-factor-totp-v1';

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * @return array{version:int,algorithm:string,nonce:string,ciphertext:string}
	 */
	public static function encrypt( string $plaintext, int $user_id ): array {
		if ( '' === $plaintext || $user_id <= 0 ) {
			throw new RuntimeException( 'Two-factor credential encryption received invalid input.' );
		}
		if ( ! self::available() ) {
			throw new RuntimeException( 'Authenticated credential encryption is unavailable.' );
		}

		$key   = self::key_for_user( $user_id );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} finally {
			self::wipe( $key );
		}

		return [
			'version'    => self::VERSION,
			'algorithm'  => self::ALGORITHM,
			'nonce'      => base64_encode( $nonce ),
			'ciphertext' => base64_encode( $ciphertext ),
		];
	}

	public static function decrypt( array $payload, int $user_id ): string {
		if ( $user_id <= 0 || ! self::available() ) {
			throw new RuntimeException( 'Two-factor credential decryption is unavailable.' );
		}
		if (
			self::VERSION !== (int) ( $payload['version'] ?? 0 )
			|| self::ALGORITHM !== (string) ( $payload['algorithm'] ?? '' )
		) {
			throw new RuntimeException( 'Unsupported two-factor credential payload.' );
		}

		$nonce      = base64_decode( (string) ( $payload['nonce'] ?? '' ), true );
		$ciphertext = base64_decode( (string) ( $payload['ciphertext'] ?? '' ), true );
		if (
			! is_string( $nonce )
			|| strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
			|| ! is_string( $ciphertext )
			|| strlen( $ciphertext ) <= SODIUM_CRYPTO_SECRETBOX_MACBYTES
		) {
			throw new RuntimeException( 'Invalid two-factor credential payload.' );
		}

		$key = self::key_for_user( $user_id );
		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} finally {
			self::wipe( $key );
		}

		if ( false === $plaintext ) {
			throw new RuntimeException( 'Two-factor credential authentication failed.' );
		}

		return $plaintext;
	}

	private static function key_for_user( int $user_id ): string {
		$salt = wp_salt( 'auth' );
		if ( '' === $salt ) {
			throw new RuntimeException( 'WordPress authentication salt is unavailable.' );
		}

		$key = hash_hmac(
			'sha256',
			self::PURPOSE . '|user:' . $user_id,
			$salt,
			true
		);

		if ( strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new RuntimeException( 'Could not derive a two-factor credential key.' );
		}

		return $key;
	}

	private static function wipe( string &$value ): void {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
			return;
		}
		$value = '';
	}
}
