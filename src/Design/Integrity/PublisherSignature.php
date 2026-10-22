<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Offline publisher signature over the exact generated artifact manifest.
 * Trust roots are release-controlled, NOT read from the plugin ZIP, manifest,
 * WordPress options or an installation-time TOFU record. A root is not yet
 * provisioned; unsigned/untrusted packages remain fail-closed until a separate
 * product-owner key and independent distribution-bootstrap approval.
 *
 * @internal
 */
final class PublisherSignature {
	public const SIGNATURE_PATH = 'artifact-manifest.sig';
	public const MESSAGE_PREFIX = "CoreBlueprint/Base/ArtifactManifest/Ed25519/v1\0";
	public const TRUSTED_PUBLIC_KEY_BASE64 = '';

	/**
	 * Verify an independently pinned publisher key before trusting manifest bytes.
	 * The detached signature is excluded from the manifest's file inventory;
	 * its authenticity is protected by the signature itself.
	 */
	public static function trusted_manifest_digest( string $installed_root ): string {
		$public_key = base64_decode( self::TRUSTED_PUBLIC_KEY_BASE64, true );
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' )
			|| ! is_string( $public_key ) || strlen( $public_key ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
			throw new \DomainException( 'ARTIFACT_TRUST_ANCHOR_MISSING' );
		}
		if ( ! is_dir( $installed_root ) || is_link( $installed_root ) ) {
			throw new \DomainException( 'ARTIFACT_SIGNATURE_INVALID' );
		}
		$root = realpath( $installed_root );
		if ( false === $root ) {
			throw new \DomainException( 'ARTIFACT_SIGNATURE_INVALID' );
		}
		$manifest_path = $root . '/' . ArtifactManifest::MANIFEST_PATH;
		$signature_path = $root . '/' . self::SIGNATURE_PATH;
		if ( ! is_file( $manifest_path ) || is_link( $manifest_path )
			|| ! is_file( $signature_path ) || is_link( $signature_path ) ) {
			throw new \DomainException( 'ARTIFACT_SIGNATURE_INVALID' );
		}
		$manifest = file_get_contents( $manifest_path );
		$encoded = file_get_contents( $signature_path );
		$signature = is_string( $encoded ) && preg_match( '/^[A-Za-z0-9+\/]{86}==\n$/D', $encoded )
			? base64_decode( $encoded, true ) : false;
		if ( ! is_string( $manifest ) || ! is_string( $signature )
			|| strlen( $signature ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			throw new \DomainException( 'ARTIFACT_SIGNATURE_INVALID' );
		}
		$digest = hash( 'sha256', $manifest );
		if ( ! sodium_crypto_sign_verify_detached(
			$signature, self::MESSAGE_PREFIX . hex2bin( $digest ), $public_key
		) ) {
			throw new \DomainException( 'ARTIFACT_SIGNATURE_INVALID' );
		}
		return $digest;
	}

	private function __construct() {}
}
