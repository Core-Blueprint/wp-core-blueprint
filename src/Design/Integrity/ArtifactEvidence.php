<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side trusted ArtifactEvidenceV1 for ONE installed Base+consumer pair.
 * No unsigned browser-selected manifest, no claim of ZIP/browser verification.
 *
 * @internal
 */
final class ArtifactEvidence {
	/**
	 * The $approved pair must come from trusted server configuration, never
	 * request JSON. Runtime capabilities must come from registered PHP modules.
	 *
	 * @param array{base:array{manifestSha256:string,packageId:string,sourceSha:string},consumer:array{manifestSha256:string,packageId:string,sourceSha:string}} $approved
	 * @param array{base:list<string>,consumer:list<string>} $registered_capabilities
	 * @return array{valid:bool,code:string,evidence?:array<string,mixed>}
	 */
	public static function collect(
		string $base_installed_root,
		string $consumer_installed_root,
		array $approved,
		array $registered_capabilities
	): array {
		$fail = [ 'valid' => false, 'code' => 'ARTIFACT_RUNTIME_MISMATCH' ];
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
			if ( ! is_array( $expected_capabilities )
				|| ! array_is_list( $expected_capabilities ) ) {
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
		$modules = [];
		$packages = [];
		foreach ( [ 'base', 'consumer' ] as $name ) {
			$manifest = $verified[$name];
			$packages[$name] = [
				'packageId' => $manifest['packageIdentity']['packageId'],
				'sourceSha' => $manifest['packageIdentity']['sourceSha'],
				'manifestDigest' => $approved[$name]['manifestSha256'],
				'buildId' => $manifest['buildId'],
				'capabilities' => $registered_capabilities[$name],
				'schemas' => $manifest['packageIdentity']['schemas'] ?? [],
			];
			foreach ( $manifest['requiredCss'] as $css ) {
				$required_css[] = [
					'package' => $name,
					'assetId' => $css['assetId'],
					'path' => $css['path'],
					'selector' => $css['selector'],
					'approvedCssWitness' => $css['approvedCssWitness'],
					'packagedDigest' => $css['packagedDigest'],
				];
			}
			$modules[$name] = $manifest['moduleGraph'];
		}
		usort( $required_css, static function ( array $a, array $b ): int {
			return strcmp( $a['package'] . '/' . $a['assetId'], $b['package'] . '/' . $b['assetId'] );
		} );
		// Stable pair identity is NOT an assertion of downloaded browser bytes.
		$pair = hash( 'sha256', self::frame( $packages['base']['buildId'] )
			. self::frame( $packages['consumer']['buildId'] )
			. self::frame( $packages['base']['manifestDigest'] )
			. self::frame( $packages['consumer']['manifestDigest'] ) );
		return [
			'valid' => true,
			'code' => 'ARTIFACT_INSTALLED_VERIFIED',
			'evidence' => [
				'contractVersion' => 1,
				'pairId' => $pair,
				'packages' => $packages,
				'moduleGraph' => $modules,
				'requiredCss' => $required_css,
				'proofScope' => 'installed-server-bytes',
			],
		];
	}

	private static function frame( string $value ): string {
		return pack( 'N', strlen( $value ) ) . $value;
	}

	private function __construct() {}
}
