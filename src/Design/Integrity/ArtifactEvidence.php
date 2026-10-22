<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Server-minted ArtifactEvidenceV1 for ONE approved installed Base+consumer
 * pair. The Host still must independently prove applied CSS/ESM before READY.
 *
 * @internal
 */
final class ArtifactEvidence {
	/**
	 * Inputs are trusted server configuration, never request/project JSON.
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
		array $server_scope
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
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $server_scope['registryFingerprint'] ) ) {
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
			$expected_capabilities = $identity['capabilities'] ?? [];
			$actual_capabilities = $registered_capabilities[$name];
			if ( ! is_array( $expected_capabilities ) || ! array_is_list( $expected_capabilities ) ) {
				return $fail;
			}
			sort( $expected_capabilities, SORT_STRING );
			sort( $actual_capabilities, SORT_STRING );
			if ( $expected_capabilities !== $actual_capabilities ) {
				return $fail;
			}
			$verified[$name] = $manifest;
		}
		if ( $verified['base']['packageIdentity']['packageId']
			=== $verified['consumer']['packageIdentity']['packageId'] ) {
			return $fail;
		}
		$required_css = [];
		$module_stamps = [];
		$modules = [];
		$packages = [];
		$asset_ids = [];
		foreach ( [ 'base', 'consumer' ] as $name ) {
			$manifest = $verified[$name];
			$identity = $manifest['packageIdentity'];
			$packages[$name] = [
				'packageId' => $identity['packageId'],
				'sourceSha' => $identity['sourceSha'],
				'manifestDigest' => $approved[$name]['manifestSha256'],
				'buildId' => $manifest['buildId'],
			];
			$modules[$name] = $manifest['moduleGraph'];
			$stamps = $identity['moduleStamps'] ?? null;
			if ( ! is_array( $stamps ) || count( $stamps ) !== count( $modules[$name] ) ) {
				return $fail; // Unstamped ESM is never claimed compatible.
			}
			foreach ( $modules[$name] as $path => $_imports ) {
				if ( ! is_string( $stamps[$path] ?? null ) || '' === $stamps[$path] ) {
					return $fail;
				}
				$module_stamps[$name . '/' . $path] = $stamps[$path];
			}
			foreach ( $manifest['requiredCss'] as $css ) {
				if ( isset( $asset_ids[$css['assetId']] ) ) {
					return $fail; // Host CSS IDs must be globally unambiguous.
				}
				$asset_ids[$css['assetId']] = true;
				// Exact signed Host ABI. Package-relative path stays private.
				$required_css[] = [
					'assetId' => $css['assetId'],
					'approvedCssWitness' => $css['approvedCssWitness'],
					'packagedDigest' => $css['packagedDigest'],
					'selector' => $css['selector'],
				];
			}
		}
		usort( $required_css, static fn ( array $a, array $b ): int => strcmp( $a['assetId'], $b['assetId'] ) );
		ksort( $module_stamps, SORT_STRING );
		$graph_digest = hash( 'sha256', ArtifactManifest::json( $modules ) );
		$pair = hash( 'sha256', self::frame( $packages['base']['buildId'] )
			. self::frame( $packages['consumer']['buildId'] )
			. self::frame( $packages['base']['manifestDigest'] )
			. self::frame( $packages['consumer']['manifestDigest'] ) );
		return [
			'valid' => true,
			'code' => 'ARTIFACT_INSTALLED_VERIFIED',
			'evidence' => [
				'contractVersion' => 1,
				'buildId' => $pair,
				'moduleGraphFingerprint' => $graph_digest,
				'moduleStamps' => $module_stamps,
				'requiredCss' => $required_css,
				'verificationStatus' => 'verified',
				'context' => [
					'siteBlogId' => $server_scope['siteBlogId'],
					'actorId' => $server_scope['actorId'],
					'resourceType' => $server_scope['resourceType'],
					'resourceKey' => $server_scope['resourceKey'],
					'profileId' => $server_scope['profileId'],
					'registryFingerprint' => $server_scope['registryFingerprint'],
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
