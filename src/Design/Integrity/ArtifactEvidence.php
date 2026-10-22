<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

use CoreBlueprint\Core\Design\Registry\FrozenRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Server-minted ArtifactEvidenceV1 for ONE approved installed Base+consumer
 * pair. The Host must still independently prove applied CSS/ESM before READY.
 *
 * The registry module graph (trusted module IDs/stamps/packagePath) and each
 * final installed artifact module graph (transitive JS file paths) are NOT
 * interchangeable. They have separate, domain-specific digests.
 *
 * @internal
 */
final class ArtifactEvidence {
	/**
	 * Inputs are trusted server configuration and the SAME authoritative
	 * FrozenRegistry selected by ContextBootstrap, never request/project JSON.
	 * This method does not independently prove an unavailable ZIP or browser
	 * download; external approval artifacts remain an integration dependency.
	 *
	 * @param array<string,array<string,string>> $approved
	 * @param array{base:list<string>,consumer:list<string>} $registered_capabilities
	 * @param array<string,mixed> $server_scope
	 * @return array{valid:bool,code:string,evidence?:array<string,mixed>}
	 */
	public static function collect(
		string $base_installed_root,
		string $consumer_installed_root,
		array $approved,
		array $registered_capabilities,
		array $server_scope,
		FrozenRegistry $registry
	): array {
		$fail = [ 'valid' => false, 'code' => 'ARTIFACT_RUNTIME_MISMATCH' ];
		if ( ! function_exists( 'get_current_blog_id' ) || ! function_exists( 'get_current_user_id' )
			|| ( $server_scope['authorized'] ?? null ) !== true
			|| ! is_int( $server_scope['siteBlogId'] ?? null )
			|| ! is_int( $server_scope['actorId'] ?? null )
			|| $server_scope['siteBlogId'] < 1 || $server_scope['actorId'] < 1
			|| (int) get_current_blog_id() !== $server_scope['siteBlogId']
			|| (int) get_current_user_id() !== $server_scope['actorId']
			|| ! is_string( $server_scope['resourceType'] ?? null )
			|| ! is_string( $server_scope['resourceKey'] ?? null )
			|| ! is_string( $server_scope['profileId'] ?? null )
			|| ! is_string( $server_scope['registryFingerprint'] ?? null )
			|| ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $server_scope['registryFingerprint'] )
			|| ! hash_equals( $registry->fingerprint(), $server_scope['registryFingerprint'] )
			|| null === $registry->profile( $server_scope['profileId'] ) ) {
			return $fail;
		}
		$verified = [];
		foreach ( [ 'base' => $base_installed_root, 'consumer' => $consumer_installed_root ] as $name => $root ) {
			$config = $approved[$name] ?? null;
			if ( ! is_array( $config )
				|| ! is_string( $config['manifestSha256'] ?? null )
				|| ! is_string( $config['packageId'] ?? null )
				|| ! is_string( $config['sourceSha'] ?? null )
				|| ! is_array( $registered_capabilities[$name] ?? null )
				|| ! array_is_list( $registered_capabilities[$name] ) ) {
				return $fail;
			}
			$result = InstalledVerifier::verify( $root, $config['manifestSha256'] );
			if ( $result['valid'] !== true ) {
				return $fail;
			}
			$manifest = $result['manifest'];
			$identity = $manifest['packageIdentity'] ?? [];
			if ( ( $identity['packageId'] ?? null ) !== $config['packageId']
				|| ( $identity['sourceSha'] ?? null ) !== $config['sourceSha'] ) {
				return $fail;
			}
			$expected = $identity['capabilities'] ?? [];
			$actual = $registered_capabilities[$name];
			if ( ! is_array( $expected ) || ! array_is_list( $expected ) ) {
				return $fail;
			}
			sort( $expected, SORT_STRING );
			sort( $actual, SORT_STRING );
			if ( $expected !== $actual ) {
				return $fail;
			}
			$verified[$name] = $manifest;
		}
		if ( $verified['base']['packageIdentity']['packageId']
			=== $verified['consumer']['packageIdentity']['packageId'] ) {
			return $fail;
		}

		// Authoritative Registry descriptor packagePath is "base/path.js" or
		// "consumer/path.js"; it is NOT a user-supplied file map. Each ID must
		// resolve to exactly one approved file in the matching installed graph.
		$registry_modules = $registry->modules();
		$module_stamps = [];
		$module_files = [];
		$used_paths = [];
		foreach ( $registry_modules as $module ) {
			$id = $module['id'] ?? null;
			$path = $module['packagePath'] ?? null;
			$stamp = $module['stamp'] ?? null;
			if ( ! is_string( $id ) || ! preg_match( '~^@[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$~D', $id )
				|| isset( $module_stamps[$id] )
				|| ! is_string( $path )
				|| ! preg_match( '#^(base|consumer)/[A-Za-z0-9_.@/-]+\.js$#D', $path )
				|| ! is_string( $stamp ) || ! preg_match( '/^[a-z0-9._:-]{1,128}$/D', $stamp )
				|| isset( $used_paths[$path] ) ) {
				return $fail;
			}
			[ $package, $relative ] = explode( '/', $path, 2 );
			if ( ! ArtifactManifest::valid_path( $relative ) || str_contains( $relative, '//' )
				|| ! isset( $verified[$package]['moduleGraph'][$relative] )
				|| ! isset( $verified[$package]['files'][$relative] )
				|| ! is_string( $verified[$package]['packageIdentity']['moduleStamps'][$relative] ?? null )
				|| ! hash_equals( $stamp, $verified[$package]['packageIdentity']['moduleStamps'][$relative] ) ) {
				return $fail;
			}
			$used_paths[$path] = true;
			$module_stamps[$id] = $stamp; // EXACT registry-ID namespace for Luna 3.
			$module_files[$id] = [
				'packagePath' => $path,
				'packagedDigest' => 'sha256:' . $verified[$package]['files'][$relative],
			];
		}
		if ( [] === $registry_modules ) {
			return $fail; // No supported editable module to attest.
		}
		ksort( $module_stamps, SORT_STRING );
		ksort( $module_files, SORT_STRING );
		$packages = [];
		$required_css = [];
		$seen_css = [];
		$artifact_graphs = [];
		foreach ( [ 'base', 'consumer' ] as $name ) {
			$manifest = $verified[$name];
			$identity = $manifest['packageIdentity'];
			$packages[$name] = [
				'packageId' => $identity['packageId'],
				'sourceSha' => $identity['sourceSha'],
				'manifestDigest' => 'sha256:' . $approved[$name]['manifestSha256'],
				'buildId' => 'sha256:' . $manifest['buildId'],
			];
			$artifact_graphs[$name] = $manifest['moduleGraph'];
			foreach ( $manifest['requiredCss'] as $css ) {
				if ( isset( $seen_css[$css['assetId']] ) ) {
					return $fail; // Every Host probe ID is globally unique.
				}
				$seen_css[$css['assetId']] = true;
				// Exact signed Host descriptor; file path stays in the
				// installed manifest. Full prefixed digest is external ABI.
				$required_css[] = [
					'assetId' => $css['assetId'],
					'approvedCssWitness' => $css['approvedCssWitness'],
					'packagedDigest' => 'sha256:' . $css['packagedDigest'],
					'selector' => $css['selector'],
				];
			}
		}
		usort( $required_css, static fn ( array $a, array $b ): int => strcmp( $a['assetId'], $b['assetId'] ) );
		// This is the ONLY Host moduleGraphFingerprint authority:
		// exactly the same canonical graph as ContextBootstrap and preflight.
		$registry_graph_digest = FrozenRegistry::digest( $registry_modules, 'ModuleGraphV1' );
		// Distinct, domain-separated fingerprint of installed file-import paths.
		// This MUST NOT replace the above registry graph digest.
		$artifact_graph_digest = 'sha256:' . hash(
			'sha256', 'ArtifactPackageModuleGraphV1' . "\0" . ArtifactManifest::json( $artifact_graphs )
		);
		$pair = hash( 'sha256', self::frame( $verified['base']['buildId'] )
			. self::frame( $verified['consumer']['buildId'] )
			. self::frame( $approved['base']['manifestSha256'] )
			. self::frame( $approved['consumer']['manifestSha256'] ) );
		return [
			'valid' => true,
			'code' => 'ARTIFACT_INSTALLED_VERIFIED',
			'evidence' => [
				'contractVersion' => 1,
				'buildId' => 'sha256:' . $pair,
				'moduleGraphFingerprint' => $registry_graph_digest,
				'artifactModuleGraphFingerprint' => $artifact_graph_digest,
				'moduleStamps' => $module_stamps,
				'moduleFileBindings' => $module_files,
				'requiredCss' => $required_css,
				'verificationStatus' => 'verified',
				'context' => [
					'siteBlogId' => $server_scope['siteBlogId'],
					'actorId' => $server_scope['actorId'],
					'resourceType' => $server_scope['resourceType'],
					'resourceKey' => $server_scope['resourceKey'],
					'profileId' => $server_scope['profileId'],
					'registryFingerprint' => $registry->fingerprint(),
				],
				'approvedPackages' => $packages,
			],
		];
	}

	private static function frame( string $value ): string {
		return pack( 'N', strlen( $value ) ) . $value;
	}

	private function __construct() {}
}
