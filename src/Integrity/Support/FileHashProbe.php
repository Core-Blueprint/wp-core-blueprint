<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use Throwable;

use function array_values;
use function clearstatcache;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fstat;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_array;
use function is_resource;
use function is_string;
use function max;
use function stat;
use function strtolower;
use function wp_normalize_path;

/**
 * Hash a file through one stable open handle and report observable read races.
 *
 * The scanner cannot make an absolute TOCTOU guarantee on a live filesystem,
 * but it must never treat an obvious read failure or observable file mutation
 * as a trustworthy checksum comparison. Multiple algorithms are calculated in
 * one pass so official MD5 verification and investigation SHA-256 metadata are
 * derived from the same bytes.
 */
final class FileHashProbe {
	private const READ_CHUNK_BYTES = 1048576;

	/**
	 * @param array<int,string> $algorithms
	 * @return array{ok:bool,stable:bool,reason:string,hashes:array<string,string>,size:int,mtime:int}
	 */
	public static function probe( string $path, array $algorithms ): array {
		$path = wp_normalize_path( $path );
		$algorithms = array_values( array_unique( array_map( static fn( mixed $algorithm ): string => strtolower( (string) $algorithm ), $algorithms ) ) );
		$algorithms = array_values( array_filter( $algorithms, static fn( string $algorithm ): bool => '' !== $algorithm ) );
		if ( [] === $algorithms ) {
			return self::failure( 'no_algorithm' );
		}

		$handle = @fopen( $path, 'rb' );
		if ( ! is_resource( $handle ) ) {
			return self::failure( 'open_failed' );
		}

		$before = @fstat( $handle );
		if ( ! is_array( $before ) ) {
			@fclose( $handle );
			return self::failure( 'stat_failed' );
		}

		$contexts = [];
		try {
			foreach ( $algorithms as $algorithm ) {
				$contexts[ $algorithm ] = hash_init( $algorithm );
			}
		} catch ( Throwable ) {
			@fclose( $handle );
			return self::failure( 'algorithm_unavailable' );
		}

		while ( ! feof( $handle ) ) {
			$chunk = @fread( $handle, self::READ_CHUNK_BYTES );
			if ( false === $chunk ) {
				@fclose( $handle );
				return self::failure( 'read_failed', $before );
			}
			if ( '' === $chunk ) {
				if ( feof( $handle ) ) {
					break;
				}
				@fclose( $handle );
				return self::failure( 'read_stalled', $before );
			}
			foreach ( $contexts as $context ) {
				hash_update( $context, $chunk );
			}
		}

		$after = @fstat( $handle );
		@fclose( $handle );
		if ( ! is_array( $after ) ) {
			return self::failure( 'stat_failed_after_read', $before );
		}

		clearstatcache( true, $path );
		$path_after = @stat( $path );
		if ( ! is_array( $path_after ) ) {
			return self::failure( 'path_changed_during_read', $after );
		}

		if ( ! self::same_observable_file_state( $before, $after ) || ! self::same_observable_file_state( $after, $path_after ) ) {
			return self::failure( 'file_changed_during_read', $path_after );
		}

		$hashes = [];
		foreach ( $contexts as $algorithm => $context ) {
			$hash = hash_final( $context );
			if ( ! is_string( $hash ) || '' === $hash ) {
				return self::failure( 'hash_failed', $path_after );
			}
			$hashes[ $algorithm ] = $hash;
		}

		return [
			'ok'     => true,
			'stable' => true,
			'reason' => 'ok',
			'hashes' => $hashes,
			'size'   => max( 0, (int) ( $path_after['size'] ?? 0 ) ),
			'mtime'  => max( 0, (int) ( $path_after['mtime'] ?? 0 ) ),
		];
	}

	/** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
	private static function same_observable_file_state( array $left, array $right ): bool {
		foreach ( [ 'dev', 'ino', 'size', 'mtime', 'ctime' ] as $key ) {
			if ( isset( $left[ $key ], $right[ $key ] ) && (string) $left[ $key ] !== (string) $right[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string|int,mixed> $stat */
	private static function failure( string $reason, array $stat = [] ): array {
		return [
			'ok'     => false,
			'stable' => ! str_contains( $reason, 'changed_during_read' ),
			'reason' => $reason,
			'hashes' => [],
			'size'   => max( 0, (int) ( $stat['size'] ?? 0 ) ),
			'mtime'  => max( 0, (int) ( $stat['mtime'] ?? 0 ) ),
		];
	}
}
