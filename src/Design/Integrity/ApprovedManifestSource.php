<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic approval comes solely from an independently pinned publisher key
 * and a valid detached signature over the EXACT installed manifest bytes.
 * No wp-config constant, user-entered checksum, TOFU or self-approval.
 *
 * @internal
 */
final class ApprovedManifestSource {
	public static function base(): array {
		$pinned = PublisherSignature::trusted_manifest_digest( CB_CORE_DIR );
		$verified = InstalledVerifier::verify( CB_CORE_DIR, $pinned );
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
					'manifestSha256' => $pinned,
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
