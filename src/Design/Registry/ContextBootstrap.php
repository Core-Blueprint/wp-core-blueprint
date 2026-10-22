<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Trusted server bootstrap assembler; called only after WordPress authorization
 * and installed ArtifactEvidence verification. Never mint evidence from a
 * browser profile, saved DesignProject or other untrusted transport.
 */
final class ContextBootstrap {
	/**
	 * @param array<string,mixed> $identity Server-resolved actor/site/resource/context.
	 * @param array<string,mixed> $evidence Server-verified installed ArtifactEvidenceV1.
	 * @param callable(array<string,mixed>,array<string,mixed>):bool $authorize Trusted WordPress authorization callback.
	 * @return array<string,mixed>
	 */
	public function mint(
		FrozenRegistry $registry,
		string $profile_id,
		array $identity,
		array $evidence,
		callable $authorize
	): array {
		$profile = $registry->profile( $profile_id );
		if ( null === $profile || ! FrozenRegistry::pure( $identity ) || ! FrozenRegistry::pure( $evidence )
			|| 1 !== ( $evidence['contractVersion'] ?? null ) || 'verified' !== ( $evidence['verificationStatus'] ?? null )
			|| ! hash_equals( $registry->fingerprint(), (string) ( $evidence['registryFingerprint'] ?? '' ) )
			|| ! hash_equals( FrozenRegistry::digest( $registry->modules(), 'ModuleGraphV1' ), (string) ( $evidence['moduleGraphFingerprint'] ?? '' ) )
			|| ! $authorize( $identity, $profile ) ) {
			throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
		}
		foreach ( [ 'siteId', 'actorId', 'resourceId', 'contextEpoch' ] as $field ) {
			if ( ! array_key_exists( $field, $identity ) || ! is_string( $identity[ $field ] ) && ! is_int( $identity[ $field ] ) ) {
				throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
			}
		}
		$modules = $registry->modules();
		$allowed = array_fill_keys( array_column( $modules, 'id' ), true );
		if ( ! isset( $allowed[ $profile['moduleId'] ] ) ) {
			throw new \DomainException( 'HOST_MODULE_MISSING' );
		}
		return [
			'contractVersion' => 1,
			'registryFingerprint' => $registry->fingerprint(),
			'profileId' => $profile_id,
			'identity' => $identity,
			'profile' => $profile,
			'schemaSnapshot' => $registry->project_schema_snapshot( $profile_id ),
			'persistedFieldFacet' => $registry->persisted_field_facet( $profile_id ),
			'modules' => $modules,
			'evidence' => $evidence,
		];
	}
}
