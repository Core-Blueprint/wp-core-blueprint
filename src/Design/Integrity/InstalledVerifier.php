<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Checks only Designer-owned JS/CSS against its packaged manifest.
 * Never scans the complete Base installation on a normal editor open.
 * This detects stale Designer assets, not independent publisher authenticity.
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
		// One installed-asset verification per PHP request, not once per caller.
		// Cache only successful evidence after the manifest digest is checked;
		// never persist it across requests or cache an installation failure.
		static $verified_in_request = [];
		$cache_key = $root . "\0" . $approved_manifest_sha256;
		if ( isset( $verified_in_request[ $cache_key ] ) ) {
			return $verified_in_request[ $cache_key ];
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

			// Verify the assets this internal Designer actually uses, not every
			// unrelated PHP/plugin file on each REST context request. Base and
			// WordPress already own execution authorization and deployment.
			$files = $manifest['files'];
			if ( [] === $files ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			$build_input = '';
			ksort( $files, SORT_STRING );
			foreach ( $files as $relative => $digest ) {
				if ( ! is_string( $relative ) || ! ArtifactManifest::valid_path( $relative )
					|| ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$build_input .= pack( 'N', strlen( $relative ) ) . $relative
					. pack( 'N', strlen( $digest ) ) . $digest;
			}
			if ( ! hash_equals( $manifest['buildId'], hash( 'sha256', $build_input ) ) ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			$required = [];
			foreach ( $manifest['moduleGraph'] as $relative => $imports ) {
				if ( ! is_string( $relative ) || ! str_ends_with( $relative, '.js' )
					|| ! isset( $files[$relative] ) || ! is_array( $imports ) || ! array_is_list( $imports ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$required[$relative] = true;
				foreach ( $imports as $import ) {
					if ( ! is_string( $import ) || ! str_ends_with( $import, '.js' )
						|| ! isset( $files[$import] ) || ! isset( $manifest['moduleGraph'][$import] ) ) {
						return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
					}
					$required[$import] = true;
				}
			}
			foreach ( $manifest['requiredCss'] as $css ) {
				if ( ! is_array( $css ) || ! is_string( $css['path'] ?? null )
					|| ! str_ends_with( $css['path'], '.css' ) || ! isset( $files[$css['path']] )
					|| ! is_string( $css['packagedDigest'] ?? null )
					|| ! hash_equals( $files[$css['path']], $css['packagedDigest'] )
					|| ! is_string( $css['approvedCssWitness'] ?? null )
					|| ! is_string( $css['selector'] ?? null ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$required[$css['path']] = true;
			}
			$module_stamps = $manifest['packageIdentity']['moduleStamps'] ?? null;
			if ( ! is_array( $module_stamps ) ) {
				return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
			}
			foreach ( $module_stamps as $relative => $stamp ) {
				if ( ! is_string( $relative ) || ! isset( $manifest['moduleGraph'][$relative] )
					|| ! is_string( $stamp ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$required[$relative] = true;
			}
			foreach ( array_keys( $required ) as $relative ) {
				$path = $root . '/' . $relative;
				if ( ! is_file( $path ) || is_link( $path ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				$bytes = file_get_contents( $path );
				if ( ! is_string( $bytes ) || ! hash_equals( $files[$relative], hash( 'sha256', $bytes ) ) ) {
					return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
				}
				foreach ( $manifest['requiredCss'] as $css ) {
					if ( $css['path'] !== $relative ) continue;
					if ( substr_count( $bytes, ArtifactManifest::css_witness_rule(
						$css['selector'], $css['approvedCssWitness']
					) ) !== 1 ) {
						return $fail( 'ARTIFACT_RUNTIME_MISMATCH' );
					}
				}
			}
			return $verified_in_request[ $cache_key ] = [
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
