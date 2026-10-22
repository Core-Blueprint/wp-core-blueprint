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
		$artifact_mode = $evidence['packageMode'] ?? null;
		$package_names = 'base-only' === $artifact_mode ? [ 'base' ]
			: ( 'base-consumer' === $artifact_mode ? [ 'base', 'consumer' ] : [] );
		$context = $evidence['context'] ?? null;
		// Registry and installed artifact module graphs retain different identities.
		// The server-attested Registry graph MUST match this exact frozen graph.
		$registry_hash = $registry->fingerprint();
		$module_graph_hash = FrozenRegistry::digest( $registry->modules(), 'ModuleGraphV1' );
		if ( null === $profile || ! FrozenRegistry::pure( $identity ) || ! FrozenRegistry::pure( $evidence )
			|| 1 !== ( $evidence['contractVersion'] ?? null ) || 'verified' !== ( $evidence['verificationStatus'] ?? null )
			|| ! is_array( $context ) || ! is_string( $context['registryFingerprint'] ?? null )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $context['registryFingerprint'] )
			|| ! hash_equals( $registry_hash, $context['registryFingerprint'] )
			|| ! is_string( $evidence['buildId'] ?? null )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $evidence['buildId'] )
			|| ! is_string( $evidence['moduleGraphFingerprint'] ?? null )
			|| ! hash_equals( $module_graph_hash, $evidence['moduleGraphFingerprint'] )
			|| ! is_string( $evidence['artifactModuleGraphFingerprint'] ?? null )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $evidence['artifactModuleGraphFingerprint'] )
			|| [] === $package_names || ! is_array( $evidence['approvedPackages'] ?? null )
			|| array_keys( $evidence['approvedPackages'] ) !== $package_names
			|| ! is_array( $evidence['moduleStamps'] ?? null )
			|| ! is_array( $evidence['moduleFileBindings'] ?? null )
			|| ! is_array( $evidence['requiredCss'] ?? null )
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
		// The same frozen Registry binds each module ID to exactly one verified
		// installed package file. Never index stamps by packagePath.
		$modules = $registry->modules();
		$allowed = array_fill_keys( array_column( $modules, 'id' ), true );
		if ( ! isset( $allowed[ $profile['moduleId'] ] ) ) {
			throw new \DomainException( 'HOST_MODULE_MISSING' );
		}
		if ( count( $modules ) !== count( $evidence['moduleStamps'] )
			|| count( $modules ) !== count( $evidence['moduleFileBindings'] ) ) {
			throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$known_paths = [];
		foreach ( $modules as $module ) {
			$id = $module['id'] ?? null;
			$path = $module['packagePath'] ?? null;
			$binding = is_string( $id ) ? ( $evidence['moduleFileBindings'][ $id ] ?? null ) : null;
			if ( ! is_string( $id ) || ! isset( $allowed[ $id ] )
				|| ! is_string( $path ) || ! preg_match( '#^(base|consumer)/[A-Za-z0-9_.@/-]+\.js$#D', $path )
				|| ( 'base-only' === $artifact_mode && ! str_starts_with( $path, 'base/' ) )
				|| str_contains( $path, '//' ) || preg_match( '#(?:^|/)\.\.?/#', $path )
				|| isset( $known_paths[ $path ] )
				|| ! is_string( $evidence['moduleStamps'][ $id ] ?? null )
				|| ! hash_equals( $module['stamp'], $evidence['moduleStamps'][ $id ] )
				|| ! is_array( $binding ) || count( $binding ) !== 2
				|| ! is_string( $binding['packagePath'] ?? null )
				|| ! hash_equals( $path, $binding['packagePath'] )
				|| ! is_string( $binding['packagedDigest'] ?? null )
				|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $binding['packagedDigest'] ) ) {
				throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			$known_paths[ $path ] = true;
		}
		// Package-file graph digests and packaged-file hashes were verified by
		// ArtifactEvidence/InstalledVerifier, not recomputed from Registry IDs.
		foreach ( $package_names as $package ) {
			$approved = $evidence['approvedPackages'][ $package ] ?? null;
			if ( ! is_array( $approved )
				|| ! is_string( $approved['manifestDigest'] ?? null )
				|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $approved['manifestDigest'] )
				|| ! is_string( $approved['buildId'] ?? null )
				|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $approved['buildId'] ) ) {
				throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
		}
		foreach ( $evidence['requiredCss'] as $css ) {
			if ( ! is_array( $css ) || ! is_string( $css['packagedDigest'] ?? null )
				|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $css['packagedDigest'] ) ) {
				throw new \DomainException( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
		}
		return [
			'contractVersion' => 1,
			'registryFingerprint' => $registry->fingerprint(),
			'profileId' => $profile_id,
			'identity' => $identity,
			'profile' => $profile,
			'schemaSnapshot' => $registry->project_schema_snapshot( $profile_id ),
			'persistedFieldFacet' => $registry->persisted_field_facet( $profile_id ),
			// These are projections of the SAME immutable registry graph. They
			// are not local/DOM-provided registrations or executable callbacks.
			'components' => array_values( array_filter( $registry->graph()['components'],
				static fn ( array $entry ): bool => $entry['profileId'] === $profile_id ) ),
			'controls' => array_values( array_filter( $registry->graph()['controls'],
				static fn ( array $entry ): bool => $entry['profileId'] === $profile_id ) ),
			'modules' => $modules,
			'evidence' => $evidence,
		];
	}
}
