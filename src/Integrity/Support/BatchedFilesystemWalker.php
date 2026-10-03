<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function array_shift;
use function count;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function microtime;
use function rtrim;
use function scandir;
use function wp_normalize_path;

/**
 * Resumable filesystem traversal for scan jobs.
 *
 * The walker processes a bounded amount of work per invocation while its
 * serialisable state retains the directory queue and current directory offset.
 * The file budget is a per-request budget, never a total-scan ceiling.
 * Symlinks are reported and never followed.
 */
final class BatchedFilesystemWalker {
	private const DEFAULT_FILE_BUDGET    = 1000;
	private const DEFAULT_SECONDS_BUDGET = 5.0;

	/**
	 * @param callable(string,array):void $on_file Called for each regular file.
	 * @param array<string,mixed>         $state   State returned by the previous batch, or [] to start.
	 *
	 * @return array{state:array<string,mixed>,issues:array<int,array{type:string,path:string}>,batch_files:int,done:bool}
	 */
	public static function walk_batch(
		string $root,
		callable $on_file,
		array $state = [],
		int $file_budget = self::DEFAULT_FILE_BUDGET,
		float $seconds_budget = self::DEFAULT_SECONDS_BUDGET
	): array {
		$root           = rtrim( wp_normalize_path( $root ), '/' );
		$file_budget    = max( 1, $file_budget );
		$seconds_budget = max( 0.1, $seconds_budget );
		$started        = microtime( true );
		$issues         = [];
		$batch_files    = 0;
		$batch_work     = 0;

		$state = self::normalise_state( $root, $state );
		if ( ! empty( $state['done'] ) ) {
			return [ 'state' => $state, 'issues' => [], 'batch_files' => 0, 'done' => true ];
		}

		if ( '' === $root || ! is_dir( $root ) || ! is_readable( $root ) || ! PathGuard::existing_path_is_inside( $root, $root ) ) {
			$state['complete'] = false;
			$state['done']     = true;
			$state['error_count']++;
			self::record_issue( $issues, 'root_unreadable', '' );
			return [ 'state' => $state, 'issues' => $issues, 'batch_files' => 0, 'done' => true ];
		}

		while ( true ) {
			if ( $batch_work >= $file_budget || ( microtime( true ) - $started ) >= $seconds_budget ) {
				break;
			}

			if ( null === $state['active_dir'] ) {
				if ( [] === $state['queue'] ) {
					$state['done'] = true;
					break;
				}
				$state['active_dir'] = (string) array_shift( $state['queue'] );
				$state['offset']     = 0;
			}

			$relative_dir = (string) $state['active_dir'];
			$directory    = '' === $relative_dir ? $root : PathGuard::join( $root, $relative_dir );
			if ( null === $directory || is_link( $directory ) || ! PathGuard::existing_path_is_inside( $directory, $root ) ) {
				$state['complete'] = false;
				$state['outside_count']++;
				self::record_issue( $issues, 'path_escape', $relative_dir );
				$state['active_dir'] = null;
				$state['offset'] = 0;
				continue;
			}

			if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
				$state['complete'] = false;
				$state['unreadable_count']++;
				self::record_issue( $issues, 'unreadable_directory', $relative_dir );
				$state['active_dir'] = null;
				$state['offset'] = 0;
				continue;
			}

			$entries = scandir( $directory, SCANDIR_SORT_ASCENDING );
			if ( ! is_array( $entries ) ) {
				$state['complete'] = false;
				$state['error_count']++;
				self::record_issue( $issues, 'filesystem_error', $relative_dir );
				$state['active_dir'] = null;
				$state['offset'] = 0;
				continue;
			}

			$entry_count = count( $entries );
			for ( $i = (int) $state['offset']; $i < $entry_count; $i++ ) {
				if ( $batch_work >= $file_budget || ( microtime( true ) - $started ) >= $seconds_budget ) {
					break 2;
				}

				$name = (string) $entries[ $i ];
				$state['offset'] = $i + 1;
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$batch_work++;

				$relative = '' === $relative_dir ? $name : $relative_dir . '/' . $name;
				$path = PathGuard::join( $root, $relative );
				if ( null === $path ) {
					$state['complete'] = false;
					$state['outside_count']++;
					self::record_issue( $issues, 'invalid_path', $relative );
					continue;
				}

				if ( is_link( $path ) ) {
					$state['complete'] = false;
					$state['symlink_count']++;
					self::record_issue( $issues, 'symlink', $relative );
					continue;
				}

				if ( ! PathGuard::existing_path_is_inside( $path, $root ) ) {
					$state['complete'] = false;
					$state['outside_count']++;
					self::record_issue( $issues, 'path_escape', $relative );
					continue;
				}

				if ( is_dir( $path ) ) {
					if ( ! is_readable( $path ) ) {
						$state['complete'] = false;
						$state['unreadable_count']++;
						self::record_issue( $issues, 'unreadable_directory', $relative );
					} else {
						$state['queue'][] = $relative;
					}
					continue;
				}

				if ( ! is_file( $path ) ) {
					continue;
				}

				$state['files_encountered']++;
				$readable = is_readable( $path );
				if ( ! $readable ) {
					$state['complete'] = false;
					$state['unreadable_count']++;
					self::record_issue( $issues, 'unreadable_file', $relative );
				}

				$on_file(
					$relative,
					[
						'absolute_path' => wp_normalize_path( $path ),
						'readable'      => $readable,
						'size'          => (int) @filesize( $path ),
						'mtime'         => (int) @filemtime( $path ),
					]
				);
				$state['files_processed']++;
				$batch_files++;

				if ( $batch_work >= $file_budget || ( microtime( true ) - $started ) >= $seconds_budget ) {
					break 2;
				}
			}

			if ( (int) $state['offset'] >= $entry_count ) {
				$state['active_dir'] = null;
				$state['offset'] = 0;
			}
		}

		return [
			'state'       => $state,
			'issues'      => $issues,
			'batch_files' => $batch_files,
			'done'        => ! empty( $state['done'] ),
		];
	}

	private static function normalise_state( string $root, array $state ): array {
		if ( [] === $state || (string) ( $state['root'] ?? '' ) !== $root ) {
			return [
				'root'              => $root,
				'queue'             => [ '' ],
				'active_dir'        => null,
				'offset'            => 0,
				'done'              => false,
				'complete'          => true,
				'files_encountered' => 0,
				'files_processed'   => 0,
				'unreadable_count'  => 0,
				'symlink_count'     => 0,
				'outside_count'     => 0,
				'error_count'       => 0,
			];
		}

		$state['queue']             = is_array( $state['queue'] ?? null ) ? $state['queue'] : [];
		$state['active_dir']        = null === ( $state['active_dir'] ?? null ) ? null : (string) $state['active_dir'];
		$state['offset']            = max( 0, (int) ( $state['offset'] ?? 0 ) );
		$state['done']              = ! empty( $state['done'] );
		$state['complete']          = ! array_key_exists( 'complete', $state ) || ! empty( $state['complete'] );
		$state['files_encountered'] = max( 0, (int) ( $state['files_encountered'] ?? 0 ) );
		$state['files_processed']   = max( 0, (int) ( $state['files_processed'] ?? 0 ) );
		$state['unreadable_count']  = max( 0, (int) ( $state['unreadable_count'] ?? 0 ) );
		$state['symlink_count']     = max( 0, (int) ( $state['symlink_count'] ?? 0 ) );
		$state['outside_count']     = max( 0, (int) ( $state['outside_count'] ?? 0 ) );
		$state['error_count']       = max( 0, (int) ( $state['error_count'] ?? 0 ) );
		return $state;
	}

	private static function record_issue( array &$issues, string $type, string $path ): void {
		$issues[] = [ 'type' => $type, 'path' => $path ];
	}
}
