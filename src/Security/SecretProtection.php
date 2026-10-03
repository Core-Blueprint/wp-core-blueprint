<?php
declare(strict_types=1);
/**
 * Public authenticated secret-protection service for Core Blueprint extensions.
 *
 * Base owns cryptographic protection only. Consumers own credential semantics,
 * authorization, persistence, lifecycle and reconnect UX.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

defined( 'ABSPATH' ) || exit;

final class SecretProtection {

	/** Public contract generation for the opaque protected payload format. */
	public const CONTRACT_VERSION = '1';

	/** Maximum plaintext secret size accepted by the public v1 contract. */
	public const MAX_SECRET_BYTES = 65536;

	private const PREFIX            = 'cbsp1:';
	private const KDF_DOMAIN        = 'core-blueprint-secret-protection-v1';
	private const MAX_PAYLOAD_BYTES = 90000;
	private const MAX_PURPOSE_BYTES = 128;
	private const MAX_SUBJECT_BYTES = 191;

	/**
	 * Whether the authenticated encryption primitive is available.
	 *
	 * Base normally requires Sodium at bootstrap. The explicit check keeps this
	 * public boundary fail-closed when invoked in an incomplete/test runtime.
	 */
	public static function available(): bool {
		return function_exists( 'wp_salt' )
			&& function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_MACBYTES' );
	}

	/**
	 * Protect one extension-owned secret for storage.
	 *
	 * The returned string is opaque. Consumers must persist it unchanged and
	 * must supply the exact same purpose and subject when opening it.
	 *
	 * @return string|\WP_Error
	 */
	public static function seal( #[\SensitiveParameter] string $plaintext, string $purpose, string $subject ): string|\WP_Error {
		$context_error = self::validate_context( $purpose, $subject );
		if ( $context_error instanceof \WP_Error ) {
			return $context_error;
		}
		if ( '' === $plaintext || strlen( $plaintext ) > self::MAX_SECRET_BYTES ) {
			return self::error( 'cb_core_secret_protection_invalid_secret', 'Secret input is invalid.' );
		}
		if ( ! self::available() ) {
			return self::error( 'cb_core_secret_protection_unavailable', 'Authenticated secret protection is unavailable.' );
		}

		$key = '';
		try {
			$key        = self::derive_key( $purpose, $subject );
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::PREFIX . base64_encode( $nonce . $ciphertext );
		} catch ( \Throwable ) {
			return self::error( 'cb_core_secret_protection_failed', 'Secret protection failed.' );
		} finally {
			self::wipe( $key );
		}
	}

	/**
	 * Open one previously protected secret.
	 *
	 * @return string|\WP_Error
	 */
	public static function open( #[\SensitiveParameter] string $payload, string $purpose, string $subject ): string|\WP_Error {
		$context_error = self::validate_context( $purpose, $subject );
		if ( $context_error instanceof \WP_Error ) {
			return $context_error;
		}
		if ( '' === $payload || strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
			return self::error( 'cb_core_secret_protection_invalid_payload', 'Protected secret payload is invalid.' );
		}
		if ( ! str_starts_with( $payload, self::PREFIX ) ) {
			return self::error( 'cb_core_secret_protection_unsupported_payload', 'Protected secret payload version is unsupported.' );
		}
		if ( ! self::available() ) {
			return self::error( 'cb_core_secret_protection_unavailable', 'Authenticated secret protection is unavailable.' );
		}

		$decoded = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $decoded ) ) {
			return self::error( 'cb_core_secret_protection_invalid_payload', 'Protected secret payload is invalid.' );
		}

		$minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;
		$maximum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + self::MAX_SECRET_BYTES;
		$length  = strlen( $decoded );
		if ( $length < $minimum || $length > $maximum ) {
			return self::error( 'cb_core_secret_protection_invalid_payload', 'Protected secret payload is invalid.' );
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key        = '';

		try {
			$key       = self::derive_key( $purpose, $subject );
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} catch ( \Throwable ) {
			return self::error( 'cb_core_secret_protection_failed', 'Secret opening failed.' );
		} finally {
			self::wipe( $key );
		}

		if ( false === $plaintext || '' === $plaintext || strlen( $plaintext ) > self::MAX_SECRET_BYTES ) {
			return self::error( 'cb_core_secret_protection_authentication_failed', 'Protected secret authentication failed.' );
		}

		return $plaintext;
	}

	/** @return true|\WP_Error */
	private static function validate_context( string $purpose, string $subject ): true|\WP_Error {
		if (
			'' === $purpose
			|| strlen( $purpose ) > self::MAX_PURPOSE_BYTES
			|| 1 !== preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $purpose )
		) {
			return self::error( 'cb_core_secret_protection_invalid_context', 'Secret protection context is invalid.' );
		}

		if (
			'' === $subject
			|| strlen( $subject ) > self::MAX_SUBJECT_BYTES
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $subject )
		) {
			return self::error( 'cb_core_secret_protection_invalid_context', 'Secret protection context is invalid.' );
		}

		return true;
	}

	private static function derive_key( string $purpose, string $subject ): string {
		$auth        = (string) wp_salt( 'auth' );
		$secure_auth = (string) wp_salt( 'secure_auth' );
		if ( '' === $auth || '' === $secure_auth ) {
			throw new \RuntimeException( 'WordPress secret material is unavailable.' );
		}

		$salt_material = $auth . "\0" . $secure_auth;
		$key = hash_hmac(
			'sha256',
			self::KDF_DOMAIN . "\0" . $purpose . "\0" . $subject,
			$salt_material,
			true
		);
		self::wipe( $auth );
		self::wipe( $secure_auth );
		self::wipe( $salt_material );

		if ( strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			self::wipe( $key );
			throw new \RuntimeException( 'Secret-protection key derivation failed.' );
		}

		return $key;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	private static function wipe( string &$value ): void {
		if ( '' === $value ) {
			return;
		}
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
			return;
		}
		$value = '';
	}

	private function __construct() {}
}
