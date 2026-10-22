<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Checks INSTALLED disk bytes against an independently approved manifest hash.
 * No browser-supplied manifest, cache/version-string or source-tree substitute.
 *
 * @internal
 */
final class InstalledVerifier {
	/**
	 * @return array{valid:bool,code:string,manifest?:array<string,mixed>,manifestDigest?:string}
	 */
	public static function verify( string $installed_root, string $approved_manifest_sha256 ): array {
		$fail = static fn ( string $code ): array => [ 'valid' => false, 'code' => $code ];
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $approved_manifest_sha256 )
			|| ! is_dir( $installed_root ) || is_link( $installed_root ) ) {
			return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$root = realpath( $installed_root );
		if ( false === $root ) {
			return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$manifest_file = $root . '/' . ArtifactManifest::MANIFEST_PATH;
		if ( ! is_file( $manifest_file ) || is_link( $manifest_file ) ) {
			return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		$raw = file_get_contents( $manifest_file );
		if ( ! is_string( $raw ) || ! hash_equals( $approved_manifest_sha256, hash( 'sha256', $raw ) ) ) {
			return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
		try {
			$manifest = json_decode( $raw, true, 128, JSON_THROW_ON_ERROR );
			if ( ! is_array( $manifest ) || ( $manifest['contractVersion'] ?? null ) !== ArtifactManifest::VERSION
				|| ( $manifest['exclusions']['manifest'] ?? null ) !== ArtifactManifest::MANIFEST_PATH
				|| ( $manifest['exclusions']['zipDigest'] ?? null ) !== 'external-only'
				|| ( $manifest['exclusions']['manifestDigest'] ?? null ) !== 'external-only'
				|| ! is_array( $manifest['files'] ?? null )
				|| ! is_array( $manifest['moduleGraph'] ?? null )
				|| ! is_array( $manifest['requiredCss'] ?? null )
				|| ! is_array( $manifest['packageIdentity'] ?? null )
				|| ! is_string( $manifest['buildId'] ?? null )
				|| ! preg_match( '/^[a-f0-9]{64}$/D', $manifest['buildId'] ) ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			if ( ! hash_equals( $raw, ArtifactManifest::json( $manifest ) ) ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}

			$files = [];
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $item ) {
				if ( $item->isLink() || ! $item->isFile() ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$relative = substr( $item->getPathname(), strlen( $root ) + 1 );
				if ( ! ArtifactManifest::valid_path( $relative ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				if ( ArtifactManifest::MANIFEST_PATH === $relative ) {
					continue;
				}
				if ( ! array_key_exists( $relative, $manifest['files'] ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$bytes = file_get_contents( $item->getPathname() );
				if ( ! is_string( $bytes ) || ! is_string( $manifest['files'][$relative] )
					|| ! preg_match( '/^[a-f0-9]{64}$/D', $manifest['files'][$relative] )
					|| ! hash_equals( $manifest['files'][$relative], hash( 'sha256', $bytes ) ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$files[$relative] = $bytes;
			}
			if ( count( $files ) !== count( $manifest['files'] ) ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			$css = array_map(
				static fn ( array $entry ): array => [
					'assetId' => $entry['assetId'],
					'path' => $entry['path'],
					'approvedCssWitness' => $entry['approvedCssWitness'],
					'selector' => $entry['selector'],
				],
				$manifest['requiredCss']
			);
			$recalculated = ArtifactManifest::generate(
				$files, $manifest['moduleGraph'], $css, $manifest['packageIdentity']
			);
			if ( ! hash_equals( $manifest['buildId'], $recalculated['buildId'] )
				|| ! hash_equals( $raw, ArtifactManifest::json( $recalculated ) ) ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			return [
				'valid' => true,
				'code' => 'ARTIFACT_INSTALLED_VERIFIED',
				'manifestDigest' => $approved_manifest_sha256,
				'manifest' => $manifest,
			];
		} catch ( \Throwable $error ) {
			return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
		}
	}

	private function __construct() {}
}
