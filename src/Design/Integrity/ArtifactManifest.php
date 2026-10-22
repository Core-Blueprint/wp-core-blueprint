<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Integrity;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic manifest over FINAL transformed package bytes, not source files.
 * Build-script integration and ZIP creation remain exclusively Luna 1 owned.
 *
 * @internal
 */
final class ArtifactManifest {
	public const VERSION = 1;
	public const MANIFEST_PATH = 'artifact-manifest.json';

	/** Canonical length-prefixed bytes avoid path/digest concatenation ambiguity. */
	private static function length_prefix( string $data ): string {
		return pack( 'N', strlen( $data ) ) . $data;
	}

	public static function valid_path( string $path ): bool {
		return '' !== $path && strlen( $path ) <= 512
			&& ! str_contains( $path, '\\' )
			&& ! str_starts_with( $path, '/' )
			&& ! str_contains( $path, "\0" )
			&& ! preg_match( '#(?:^|/)\.{1,2}(?:/|$)|//#', $path )
			&& 1 === preg_match( '#^[A-Za-z0-9_.@/-]+$#D', $path );
	}

	/**
	 * File values are the actual packaged byte strings, AFTER import transform
	 * and CSS witness insertion. The manifest itself MUST NOT be included.
	 *
	 * @param array<string,string> $files
	 * @param array<string,list<string>> $module_graph
	 * @param list<array{assetId:string,path:string,approvedCssWitness:string,selector:string}> $required_css
	 * @param array{packageId:string,sourceSha:string,buildGeneration:string,capabilities?:list<string>,schemas?:list<string>,partner?:string} $identity
	 * @return array<string,mixed>
	 */
	public static function generate( array $files, array $module_graph, array $required_css, array $identity ): array {
		foreach ( [ 'packageId', 'sourceSha', 'buildGeneration' ] as $key ) {
			if ( ! is_string( $identity[$key] ?? null ) || '' === $identity[$key] ) {
				throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: incomplete approved identity.' );
			}
		}
		if ( ! preg_match( '/^[a-f0-9]{40}$/D', $identity['sourceSha'] ) ) {
			throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: source identity.' );
		}
		if ( array_key_exists( self::MANIFEST_PATH, $files ) ) {
			throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: recursive manifest content.' );
		}
		$file_hashes = [];
		foreach ( $files as $path => $bytes ) {
			if ( ! is_string( $path ) || ! self::valid_path( $path ) || ! is_string( $bytes )
				|| str_ends_with( $path, '.zip' ) ) {
				throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: invalid packaged file.' );
			}
			$file_hashes[$path] = hash( 'sha256', $bytes );
		}
		if ( [] === $file_hashes ) {
			throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: empty package.' );
		}
		ksort( $file_hashes, SORT_STRING );
		$build_input = '';
		foreach ( $file_hashes as $path => $digest ) {
			$build_input .= self::length_prefix( $path ) . self::length_prefix( $digest );
		}
		$build_id = hash( 'sha256', $build_input );

		$graph = [];
		foreach ( $module_graph as $path => $imports ) {
			if ( ! isset( $file_hashes[$path] ) || ! str_ends_with( $path, '.js' )
				|| ! is_array( $imports ) || ! array_is_list( $imports ) ) {
				throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: invalid module graph.' );
			}
			foreach ( $imports as $import ) {
				if ( ! is_string( $import ) || ! isset( $file_hashes[$import] )
					|| ! str_ends_with( $import, '.js' ) ) {
					throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: untracked module import.' );
				}
			}
			$imports = array_values( array_unique( $imports ) );
			sort( $imports, SORT_STRING );
			$graph[$path] = $imports;
		}
		ksort( $graph, SORT_STRING );

		$css = [];
		$ids = [];
		foreach ( $required_css as $entry ) {
			$path = $entry['path'] ?? null;
			$asset_id = $entry['assetId'] ?? null;
			$witness = $entry['approvedCssWitness'] ?? null;
			$selector = $entry['selector'] ?? null;
			if ( ! is_string( $path ) || ! str_ends_with( $path, '.css' )
				|| ! isset( $file_hashes[$path] ) || ! is_string( $asset_id )
				|| ! preg_match( '/^[a-z][a-z0-9-]{0,63}$/D', $asset_id )
				|| isset( $ids[$asset_id] )
				|| ! is_string( $witness ) || ! preg_match( '/^[a-f0-9]{64}$/D', $witness )
				|| ! is_string( $selector ) || ! preg_match( '/^\[data-cb-artifact-[a-z0-9-]+\]$/D', $selector )
				|| substr_count( $files[$path], $witness ) !== 1 ) {
				throw new \InvalidArgumentException( 'CSS_RUNTIME_STALE: missing/ambiguous final CSS witness.' );
			}
			$ids[$asset_id] = true;
			$css[] = [
				'assetId' => $asset_id,
				'path' => $path,
				'packagedDigest' => $file_hashes[$path],
				'approvedCssWitness' => $witness,
				'selector' => $selector,
			];
		}
		usort( $css, static fn ( array $a, array $b ): int => strcmp( $a['assetId'], $b['assetId'] ) );
		return [
			'contractVersion' => self::VERSION,
			'buildId' => $build_id,
			'packageIdentity' => $identity,
			'files' => $file_hashes,
			'moduleGraph' => $graph,
			'requiredCss' => $css,
			'exclusions' => [
				'manifest' => self::MANIFEST_PATH,
				'zipDigest' => 'external-only',
				'manifestDigest' => 'external-only',
			],
		];
	}

	/**
	 * Witness derives from transformed-input CSS *without* the generated rule.
	 * packageId/buildGeneration cannot themselves depend on final buildId.
	 * This function never alters assets or inserts a witness into the package.
	 */
	public static function css_witness(
		string $package_id,
		string $build_generation,
		string $path,
		string $css_without_witness
	): string {
		if ( ! self::valid_path( $path ) || ! str_ends_with( $path, '.css' )
			|| '' === $package_id || '' === $build_generation ) {
			throw new \InvalidArgumentException( 'CSS_RUNTIME_STALE: invalid witness inputs.' );
		}
		return hash( 'sha256',
			self::length_prefix( 'cb-css-witness-v1' )
			. self::length_prefix( $package_id )
			. self::length_prefix( $build_generation )
			. self::length_prefix( $path )
			. self::length_prefix( hash( 'sha256', $css_without_witness ) )
		);
	}

	/** Deterministic manifest JSON; its SHA is maintained OUTSIDE its content. */
	public static function json( array $manifest ): string {
		return self::encode_sorted( $manifest );
	}

	private static function encode_sorted( mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return '[' . implode( ',', array_map( [ self::class, 'encode_sorted' ], $value ) ) . ']';
			}
			ksort( $value, SORT_STRING );
			$pairs = [];
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) ) {
					throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: invalid JSON key.' );
				}
				$pairs[] = json_encode( $key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
					. ':' . self::encode_sorted( $item );
			}
			return '{' . implode( ',', $pairs ) . '}';
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new \InvalidArgumentException( 'ARTIFACT_RUNTIME_MISMATCH: invalid manifest value.' );
		}
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private function __construct() {}
}
