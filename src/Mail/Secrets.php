<?php
declare(strict_types=1);
/**
 * Mail secret encryption using libsodium.
 *
 * Secrets are encrypted at rest with a key derived from the site's WordPress
 * salts. The raw Brevo API key and SMTP password are never written to logs or
 * returned by the settings UI.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Mail;

defined( 'ABSPATH' ) || exit;

final class Secrets {

	private const PREFIX = 'cbm1:';

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key   = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		$encoded = base64_encode( $nonce . $cipher );

		sodium_memzero( $key );

		return self::PREFIX . $encoded;
	}

	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		// No plaintext fallback by design. Credentials written by this module
		// must always carry the versioned encrypted prefix.
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return '';
		}

		$decoded = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = self::key();
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

		sodium_memzero( $key );

		return false === $plain ? '' : $plain;
	}

	private static function key(): string {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|core-blueprint-mail';
		return hash( 'sha256', $material, true );
	}
}
