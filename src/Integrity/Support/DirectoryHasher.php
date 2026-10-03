<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function basename;
use function hash;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function ksort;
use function sprintf;
use function wp_normalize_path;

/**
 * Deterministic SHA-256 snapshot for private, premium, or custom components.
 *
 * A snapshot contains both an aggregate fingerprint and a per-file manifest.
 * The aggregate remains useful for quick equality checks; the manifest lets the
 * scanner tell an operator exactly which local-baseline file changed, vanished,
 * or appeared unexpectedly.
 *
 * Symlinks are never followed. A snapshot is trustworthy only when `complete`
 * is true.
 */
final class DirectoryHasher {
	public static function hash( string $path ): string {
		$fingerprint = self::fingerprint( $path );
		return (string) ( $fingerprint['hash'] ?? '' );
	}

	/**
	 * Return compact fingerprint metadata without the potentially large manifest.
	 */
	public static function fingerprint( string $path ): array {
		$snapshot = self::snapshot( $path );
		unset( $snapshot['manifest'] );
		return $snapshot;
	}

	/**
	 * Capture a file or directory as a deterministic SHA-256 manifest.
	 *
	 * @return array{hash:string,algorithm:string,complete:bool,files:int,unreadable:int,symlinks:int,errors:int,manifest:array<string,array{hash:string,size:int,mtime:int}>}
	 */
	public static function snapshot( string $path ): array {
		$path = wp_normalize_path( $path );

		if ( is_link( $path ) ) {
			return self::empty_snapshot( false, 0, 1, 0 );
		}

		if ( is_file( $path ) ) {
			if ( ! is_readable( $path ) ) {
				return self::empty_snapshot( false, 1, 0, 0 );
			}

			$probe = FileHashProbe::probe( $path, [ 'sha256' ] );
			if ( empty( $probe['ok'] ) ) {
				return self::empty_snapshot( false, 0, 0, 1 );
			}

			$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
			$file_hash = (string) ( $hashes['sha256'] ?? '' );
			if ( '' === $file_hash ) {
				return self::empty_snapshot( false, 0, 0, 1 );
			}

			$relative = basename( $path );
			$manifest = [
				$relative => [
					'hash'  => $file_hash,
					'size'  => (int) ( $probe['size'] ?? 0 ),
					'mtime' => (int) ( $probe['mtime'] ?? 0 ),
				],
			];

			return self::from_manifest( $manifest, true, 0, 0, 0 );
		}

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			return self::empty_snapshot( false, 1, 0, 0 );
		}

		$manifest = [];
		$hash_errors = 0;
		$walker = FilesystemWalker::walk(
			$path,
			static function ( string $relative, array $meta ) use ( &$manifest, &$hash_errors ): void {
				if ( empty( $meta['readable'] ) ) {
					return;
				}

				$absolute = (string) ( $meta['absolute_path'] ?? '' );
				$probe = FileHashProbe::probe( $absolute, [ 'sha256' ] );
				if ( empty( $probe['ok'] ) ) {
					$hash_errors++;
					return;
				}
				$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
				$file_hash = (string) ( $hashes['sha256'] ?? '' );
				if ( '' === $file_hash ) {
					$hash_errors++;
					return;
				}

				$manifest[ $relative ] = [
					'hash'  => $file_hash,
					'size'  => (int) ( $probe['size'] ?? 0 ),
					'mtime' => (int) ( $probe['mtime'] ?? 0 ),
				];
			}
		);

		$errors = (int) ( $walker['error_count'] ?? 0 ) + $hash_errors;
		$complete = ! empty( $walker['complete'] ) && 0 === $hash_errors;

		return self::from_manifest(
			$manifest,
			$complete,
			(int) ( $walker['unreadable_count'] ?? 0 ),
			(int) ( $walker['symlink_count'] ?? 0 ),
			$errors
		);
	}


	/**
	 * Capture a resumable SHA-256 snapshot.
	 *
	 * The returned state contains the manifest accumulated so far and may be
	 * persisted between requests. Budgets are per invocation, never total caps.
	 *
	 * @param array<string,mixed> $state Previous state or [] to start.
	 * @return array{done:bool,state:array<string,mixed>,snapshot:array<string,mixed>}
	 */
	public static function snapshot_batch( string $path, array $state = [], int $file_budget = 750, float $seconds_budget = 4.0 ): array {
		$path = wp_normalize_path( $path );

		if ( is_link( $path ) ) {
			$snapshot = self::empty_snapshot( false, 0, 1, 0 );
			return [ 'done' => true, 'state' => [ 'root' => $path, 'done' => true ], 'snapshot' => $snapshot ];
		}

		if ( is_file( $path ) ) {
			$snapshot = self::snapshot( $path );
			return [ 'done' => true, 'state' => [ 'root' => $path, 'done' => true ], 'snapshot' => $snapshot ];
		}

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			$snapshot = self::empty_snapshot( false, 1, 0, 0 );
			return [ 'done' => true, 'state' => [ 'root' => $path, 'done' => true ], 'snapshot' => $snapshot ];
		}

		if ( (string) ( $state['root'] ?? '' ) !== $path ) {
			$state = [
				'root'        => $path,
				'walker'      => [],
				'manifest'    => [],
				'hash_errors' => 0,
				'done'        => false,
			];
		}

		$manifest = is_array( $state['manifest'] ?? null ) ? $state['manifest'] : [];
		$hash_errors = (int) ( $state['hash_errors'] ?? 0 );
		$walker = BatchedFilesystemWalker::walk_batch(
			$path,
			static function ( string $relative, array $meta ) use ( &$manifest, &$hash_errors ): void {
				if ( empty( $meta['readable'] ) ) {
					return;
				}
				$absolute = (string) ( $meta['absolute_path'] ?? '' );
				$probe = FileHashProbe::probe( $absolute, [ 'sha256' ] );
				if ( empty( $probe['ok'] ) ) {
					$hash_errors++;
					return;
				}
				$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
				$file_hash = (string) ( $hashes['sha256'] ?? '' );
				if ( '' === $file_hash ) {
					$hash_errors++;
					return;
				}
				$manifest[ $relative ] = [
					'hash'  => $file_hash,
					'size'  => (int) ( $probe['size'] ?? 0 ),
					'mtime' => (int) ( $probe['mtime'] ?? 0 ),
				];
			},
			is_array( $state['walker'] ?? null ) ? $state['walker'] : [],
			$file_budget,
			$seconds_budget
		);

		$walker_state = is_array( $walker['state'] ?? null ) ? $walker['state'] : [];
		$state['walker']      = $walker_state;
		$state['manifest']    = $manifest;
		$state['hash_errors'] = $hash_errors;
		$state['done']        = ! empty( $walker['done'] );

		$errors   = (int) ( $walker_state['error_count'] ?? 0 ) + $hash_errors;
		$complete = ! empty( $walker_state['done'] ) && ! empty( $walker_state['complete'] ) && 0 === $hash_errors;
		$snapshot = self::from_manifest(
			$manifest,
			$complete,
			(int) ( $walker_state['unreadable_count'] ?? 0 ),
			(int) ( $walker_state['symlink_count'] ?? 0 ),
			$errors
		);

		return [ 'done' => ! empty( $walker['done'] ), 'state' => $state, 'snapshot' => $snapshot ];
	}


	/**
	 * Build the canonical snapshot shape from a manifest already collected by a
	 * resumable verifier. This avoids re-reading a large component during baseline
	 * approval and lets the operator approve the exact filesystem evidence that
	 * was reviewed in the completed scan.
	 */
	public static function snapshot_from_manifest( array $manifest, bool $complete = true, int $unreadable = 0, int $symlinks = 0, int $errors = 0 ): array {
		return self::from_manifest( $manifest, $complete, $unreadable, $symlinks, $errors );
	}

	private static function from_manifest( array $manifest, bool $complete, int $unreadable, int $symlinks, int $errors ): array {
		ksort( $manifest, SORT_STRING );
		$entries = [];

		foreach ( $manifest as $relative => $meta ) {
			$entries[] = sprintf(
				'%s|%d|%s',
				(string) $relative,
				(int) ( $meta['size'] ?? 0 ),
				(string) ( $meta['hash'] ?? '' )
			);
		}

		return [
			'hash'       => hash( 'sha256', implode( "\n", $entries ) ),
			'algorithm'  => 'sha256',
			'complete'   => $complete,
			'files'      => count( $manifest ),
			'unreadable' => $unreadable,
			'symlinks'   => $symlinks,
			'errors'     => $errors,
			'manifest'   => $manifest,
		];
	}

	private static function empty_snapshot( bool $complete, int $unreadable, int $symlinks, int $errors ): array {
		return self::from_manifest( [], $complete, $unreadable, $symlinks, $errors );
	}
}
