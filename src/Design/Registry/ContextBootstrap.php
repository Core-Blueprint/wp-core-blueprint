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
		$context = $evidence['context'] ?? null;
		// ArtifactEvidenceV1 carries its own verified installed package graph.
		// Its raw 64-hex fingerprint is NOT the Registry module-graph digest.
		$registry_hash = $registry->fingerprint();
		if ( null === $profile || ! FrozenRegistry::pure( $identity ) || ! FrozenRegistry::pure( $evidence )
			|| 1 !== ( $evidence['contractVersion'] ?? null ) || 'verified' !== ( $evidence['verificationStatus'] ?? null )
			|| ! is_array( $context ) || ! is_string( $context['registryFingerprint'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $context['registryFingerprint'] )
			|| ! hash_equals( substr( $registry_hash, 7 ), $context['registryFingerprint'] )
			|| ! is_string( $evidence['buildId'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $evidence['buildId'] )
			|| ! is_string( $evidence['moduleGraphFingerprint'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $evidence['moduleGraphFingerprint'] )
			|| ! is_array( $evidence['approvedPackages'] ?? null )
			|| ! isset( $evidence['approvedPackages']['base'], $evidence['approvedPackages']['consumer'] )
			|| ! is_array( $evidence['moduleStamps'] ?? null ) || ! is_array( $evidence['requiredCss'] ?? null )
			|| ! $authorize( $identity, $profile ) ) {
			throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
		}
		// All identities are server-minted. Refuse a cross-actor/resource replay,
		// even when the same trusted package was installed on both sites.
		if ( ! is_int( $identity['siteId'] ?? null ) || $identity['siteId'] < 1
			|| ! is_int( $identity['actorId'] ?? null ) || $identity['actorId'] < 1
			|| ! is_string( $identity['resourceType'] ?? null ) || '' === $identity['resourceType']
			|| ! is_string( $identity['resourceId'] ?? null ) || '' === $identity['resourceId']
			|| ! is_int( $identity['contextEpoch'] ?? null ) || $identity['contextEpoch'] < 0
			|| ( $context['siteBlogId'] ?? null ) !== $identity['siteId']
			|| ( $context['actorId'] ?? null ) !== $identity['actorId']
			|| ( $context['resourceType'] ?? null ) !== $identity['resourceType']
			|| ( $context['resourceKey'] ?? null ) !== $identity['resourceId']
			|| ( $context['profileId'] ?? null ) !== $profile_id ) {
			throw new \DomainException( 'HOST_CONTEXT_REFUSED' );
		}
		// The trusted Registry graph determines module IDs and their exact
		// packagePath keys. Installed evidence alone authorizes package stamps.
		$modules = $registry->modules();
		$allowed = array_fill_keys( array_column( $modules, 'id' ), true );
		if ( ! isset( $allowed[ $profile['moduleId'] ] ) ) {
			throw new \DomainException( 'HOST_MODULE_MISSING' );
		}
		$known_paths = [];
		foreach ( $modules as $module ) {
			$path = $module['packagePath'] ?? null;
			if ( ! is_string( $path ) || ! preg_match( '#^(base|consumer)/[A-Za-z0-9_.@/-]+\.js$#D', $path )
				|| str_contains( $path, '//' ) || preg_match( '#(?:^|/)\.\.?/#', $path )
				|| isset( $known_paths[ $path ] )
				|| ! is_string( $evidence['moduleStamps'][ $path ] ?? null )
				|| ! hash_equals( $module['stamp'], $evidence['moduleStamps'][ $path ] ) ) {
				throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			$known_paths[ $path ] = true;
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
