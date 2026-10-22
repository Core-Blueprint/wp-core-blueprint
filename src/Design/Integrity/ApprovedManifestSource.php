<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Read the canonical manifest of this installed Base package for local file
 * and module consistency. Publisher authenticity belongs to the WordPress
 * installation/update channel, not to first-party Designer startup.
 * No wp-config setting, external signature, or manual approval is required.
 *
 * @internal
 */
final class ApprovedManifestSource {
	public static function base(): array {
		$manifest_path = rtrim( CB_CORE_DIR, '/\\' ) . '/' . ArtifactManifest::MANIFEST_PATH;
		if ( ! is_file( $manifest_path ) || is_link( $manifest_path ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$manifest_bytes = file_get_contents( $manifest_path );
		if ( ! is_string( $manifest_bytes ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$manifest_digest = hash( 'sha256', $manifest_bytes );
		$verified = InstalledVerifier::verify( CB_CORE_DIR, $manifest_digest );
		if ( true !== ( $verified['valid'] ?? false ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$identity = $verified['manifest']['packageIdentity'] ?? null;
		if ( ! is_array( $identity ) || ( $identity['packageId'] ?? null ) !== 'core-blueprint/base'
			|| ! is_string( $identity['sourceSha'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{40}$/D', $identity['sourceSha'] )
			|| ! is_array( $identity['moduleStamps'] ?? null )
			|| ! is_array( $identity['capabilities'] ?? null )
			|| ! array_is_list( $identity['capabilities'] ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		return [
			'approved' => [
				'base' => [
					'manifestSha256' => $manifest_digest,
					'packageId' => 'core-blueprint/base',
					'sourceSha' => $identity['sourceSha'],
				],
			],
			'capabilities' => [ 'base' => $identity['capabilities'] ],
			'manifest' => $verified['manifest'],
		];
	}

	/** Return only a stamp attested to the EXACT installed transformed file. */
	public static function module_stamp( array $verified, string $relative ): string {
		if ( ! ArtifactManifest::valid_path( $relative )
			|| ! isset( $verified['manifest']['moduleGraph'][ $relative ] )
			|| ! is_string( $verified['manifest']['packageIdentity']['moduleStamps'][ $relative ] ?? null ) ) {
			throw new \DomainException( 'HOST_MODULE_MISSING' );
		}
		return $verified['manifest']['packageIdentity']['moduleStamps'][ $relative ];
	}

	private function __construct() {}
}
