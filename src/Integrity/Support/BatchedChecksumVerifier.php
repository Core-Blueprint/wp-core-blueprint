<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function array_keys;
use function count;
use function is_array;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function max;
use function microtime;
use function strtolower;
use function wp_normalize_path;

/**
 * Resumable bidirectional checksum-manifest verifier.
 *
 * A complete verification has two phases:
 *  1. expected -> filesystem (missing/modified/unreadable/path safety)
 *  2. filesystem -> expected (unexpected files + traversal coverage)
 *
 * Budgets limit one invocation only. The serialisable state can be persisted
 * between requests and has no total-file ceiling.
 */
final class BatchedChecksumVerifier {
	private const DEFAULT_FILE_BUDGET    = 750;
	private const DEFAULT_SECONDS_BUDGET = 4.0;
	private const MAX_PASSED_CHILDREN    = 200;

	/**
	 * @param array<string,string> $expected Normalised relative path => expected hash.
	 * @param array<string,mixed>  $state    Previous state or [] to start.
	 * @return array{done:bool,state:array<string,mixed>,events:array<int,array<string,mixed>>}
	 */
	public static function verify_batch(
		string $root,
		array $expected,
		array $state = [],
		string $expected_algorithm = 'md5',
		bool $walk_unexpected = true,
		int $file_budget = self::DEFAULT_FILE_BUDGET,
		float $seconds_budget = self::DEFAULT_SECONDS_BUDGET,
		bool $collect_manifest = false
	): array {
		$root           = rtrim( wp_normalize_path( $root ), '/' );
		$file_budget    = max( 1, $file_budget );
		$seconds_budget = max( 0.1, $seconds_budget );
		$started        = microtime( true );
		$events         = [];

		$state = self::normalise_state( $root, $expected, $state, $expected_algorithm, $walk_unexpected, $collect_manifest );
		if ( ! empty( $state['done'] ) ) {
			return [ 'done' => true, 'state' => $state, 'events' => [] ];
		}

		$remaining = $file_budget;

		if ( 'expected' === $state['phase'] ) {
			$keys = array_keys( $expected );
			$total = count( $keys );

			while ( $state['expected_index'] < $total && $remaining > 0 && ( microtime( true ) - $started ) < $seconds_budget ) {
				$relative = (string) $keys[ $state['expected_index'] ];
				$state['expected_index']++;
				$remaining--;
				$expected_hash = self::expected_hash( $expected[ $relative ] ?? '' );
				$absolute = PathGuard::join( $root, $relative );

				if ( null === $absolute ) {
					$state['coverage']['path_escapes']++;
					self::raise_status( $state, 'critical' );
					$events[] = [ 'type' => 'invalid_path', 'severity' => 'critical', 'file' => $relative, 'expected_hash' => $expected_hash, 'filesystem_path' => '' ];
					continue;
				}

				if ( is_link( $absolute ) ) {
					$state['coverage']['symlinks_skipped']++;
					self::raise_status( $state, 'warning' );
					$events[] = [ 'type' => 'symlink_skipped', 'severity' => 'warning', 'file' => $relative, 'expected_hash' => $expected_hash, 'filesystem_path' => wp_normalize_path( $absolute ) ];
					continue;
				}

				if ( ! is_file( $absolute ) ) {
					$state['coverage']['missing_files']++;
					self::raise_status( $state, 'warning' );
					$events[] = [ 'type' => 'missing', 'severity' => 'warning', 'file' => $relative, 'expected_hash' => $expected_hash, 'filesystem_path' => wp_normalize_path( $absolute ) ];
					continue;
				}

				if ( ! PathGuard::existing_path_is_inside( $absolute, $root ) ) {
					$state['coverage']['path_escapes']++;
					self::raise_status( $state, 'critical' );
					$events[] = [ 'type' => 'path_escape', 'severity' => 'critical', 'file' => $relative, 'expected_hash' => $expected_hash, 'filesystem_path' => wp_normalize_path( $absolute ) ];
					continue;
				}

				if ( ! is_readable( $absolute ) ) {
					$state['coverage']['unreadable_files']++;
					self::raise_status( $state, 'warning' );
					$events[] = [ 'type' => 'unreadable', 'severity' => 'warning', 'file' => $relative, 'expected_hash' => $expected_hash, 'filesystem_path' => wp_normalize_path( $absolute ) ];
					continue;
				}

				$algorithm = strtolower( $expected_algorithm );
				$algorithms = 'sha256' === $algorithm ? [ 'sha256' ] : [ $algorithm, 'sha256' ];
				$probe = FileHashProbe::probe( $absolute, $algorithms );
				if ( empty( $probe['ok'] ) ) {
					$reason = (string) ( $probe['reason'] ?? 'hash_failed' );
					if ( str_contains( $reason, 'changed_during_read' ) ) {
						$state['coverage']['unstable_files']++;
						$type = 'file_changed_during_scan';
					} else {
						$state['coverage']['hash_failures']++;
						$type = 'hash_failed';
					}
					self::raise_status( $state, 'warning' );
					$events[] = [
						'type'            => $type,
						'severity'        => 'warning',
						'file'            => $relative,
						'expected_hash'   => $expected_hash,
						'filesystem_path' => wp_normalize_path( $absolute ),
						'size_bytes'      => (int) ( $probe['size'] ?? 0 ),
						'modified_at'     => (int) ( $probe['mtime'] ?? 0 ),
						'reason'          => $reason,
					];
					continue;
				}

				$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
				$actual = (string) ( $hashes[ $algorithm ] ?? '' );
				$sha256 = (string) ( $hashes['sha256'] ?? '' );
				if ( $collect_manifest && '' !== $sha256 ) {
					$state['observed_manifest'][ $relative ] = [
						'hash'  => $sha256,
						'size'  => (int) ( $probe['size'] ?? 0 ),
						'mtime' => (int) ( $probe['mtime'] ?? 0 ),
					];
				}
				if ( strtolower( $actual ) !== strtolower( $expected_hash ) ) {
					$state['coverage']['modified_files']++;
					self::raise_status( $state, 'warning' );
					$events[] = [
						'type'            => 'modified',
						'severity'        => 'warning',
						'file'            => $relative,
						'expected_hash'   => $expected_hash,
						'actual_hash'     => $actual,
						'actual_sha256'   => $sha256,
						'filesystem_path' => wp_normalize_path( $absolute ),
						'size_bytes'      => (int) ( $probe['size'] ?? 0 ),
						'modified_at'     => (int) ( $probe['mtime'] ?? 0 ),
					];
					continue;
				}

				$state['coverage']['verified_files']++;
				if ( count( $state['passed_children'] ) < self::MAX_PASSED_CHILDREN ) {
					$state['passed_children'][] = $relative;
				}
			}

			if ( $state['expected_index'] >= $total ) {
				$state['phase'] = $walk_unexpected ? 'filesystem' : 'done';
				if ( ! $walk_unexpected ) {
					$state['done'] = true;
				}
			}
		}

		if ( 'filesystem' === $state['phase'] && $remaining > 0 && ( microtime( true ) - $started ) < $seconds_budget ) {
			$seconds_left = max( 0.1, $seconds_budget - ( microtime( true ) - $started ) );
			$walker = BatchedFilesystemWalker::walk_batch(
				$root,
				static function ( string $relative, array $meta ) use ( &$events, &$state, $expected, $collect_manifest ): void {
					$normalised = PathGuard::normalise_relative( $relative );
					if ( null === $normalised || isset( $expected[ $normalised ] ) ) {
						return;
					}

					$state['coverage']['unexpected_files']++;
					self::raise_status( $state, 'warning' );
					$path = (string) ( $meta['absolute_path'] ?? '' );
					if ( empty( $meta['readable'] ) ) {
						$state['coverage']['unreadable_files']++;
						$events[] = [ 'type' => 'unreadable_unexpected', 'severity' => 'warning', 'file' => $normalised, 'filesystem_path' => $path ];
						return;
					}

					$probe = FileHashProbe::probe( $path, [ 'sha256' ] );
					if ( empty( $probe['ok'] ) ) {
						$reason = (string) ( $probe['reason'] ?? 'hash_failed' );
						if ( str_contains( $reason, 'changed_during_read' ) ) {
							$state['coverage']['unstable_files']++;
						} else {
							$state['coverage']['hash_failures']++;
						}
						$events[] = [
							'type'            => 'unexpected_unverified',
							'severity'        => 'warning',
							'file'            => $normalised,
							'filesystem_path' => $path,
							'size_bytes'      => (int) ( $probe['size'] ?? ( $meta['size'] ?? 0 ) ),
							'modified_at'     => (int) ( $probe['mtime'] ?? ( $meta['mtime'] ?? 0 ) ),
							'reason'          => $reason,
						];
						return;
					}

					$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
					$sha256 = (string) ( $hashes['sha256'] ?? '' );
					if ( $collect_manifest && '' !== $sha256 ) {
						$state['observed_manifest'][ $normalised ] = [
							'hash'  => $sha256,
							'size'  => (int) ( $probe['size'] ?? 0 ),
							'mtime' => (int) ( $probe['mtime'] ?? 0 ),
						];
					}
					$events[] = [
						'type'            => 'unexpected',
						'severity'        => 'warning',
						'file'            => $normalised,
						'filesystem_path' => $path,
						'size_bytes'      => (int) ( $probe['size'] ?? 0 ),
						'modified_at'     => (int) ( $probe['mtime'] ?? 0 ),
						'actual_sha256'   => $sha256,
					];
				},
				is_array( $state['walker'] ?? null ) ? $state['walker'] : [],
				$remaining,
				$seconds_left
			);

			$state['walker'] = is_array( $walker['state'] ?? null ) ? $walker['state'] : [];
			foreach ( (array) ( $walker['issues'] ?? [] ) as $issue ) {
				if ( ! is_array( $issue ) ) {
					continue;
				}
				$type = (string) ( $issue['type'] ?? '' );
				$file = (string) ( $issue['path'] ?? '' );
				if ( 'unreadable_file' === $type ) {
					continue; // Richer callback event already emitted.
				}
				if ( 'symlink' === $type ) {
					$state['coverage']['symlinks_skipped']++;
					self::raise_status( $state, 'warning' );
					$events[] = [ 'type' => 'symlink_skipped', 'severity' => 'warning', 'file' => $file ];
					continue;
				}
				if ( 'path_escape' === $type || 'invalid_path' === $type ) {
					$state['coverage']['path_escapes']++;
					self::raise_status( $state, 'critical' );
					$events[] = [ 'type' => 'path_escape', 'severity' => 'critical', 'file' => $file ];
					continue;
				}
				if ( 'unreadable_directory' === $type || 'root_unreadable' === $type ) {
					$state['coverage']['filesystem_errors']++;
					self::raise_status( $state, 'warning' );
					$events[] = [ 'type' => 'unreadable_directory', 'severity' => 'warning', 'file' => $file ];
					continue;
				}
				if ( 'filesystem_error' === $type ) {
					$state['coverage']['filesystem_errors']++;
					self::raise_status( $state, 'warning' );
					$events[] = [ 'type' => 'scan_incomplete', 'severity' => 'warning', 'file' => $file ];
				}
			}

			if ( ! empty( $walker['done'] ) ) {
				$walker_state = (array) ( $walker['state'] ?? [] );
				if ( empty( $walker_state['complete'] ) ) {
					$state['coverage']['state'] = 'incomplete';
				}
				$state['phase'] = 'done';
				$state['done'] = true;
			}
		}

		if (
			$state['coverage']['unreadable_files'] > 0
			|| $state['coverage']['hash_failures'] > 0
			|| $state['coverage']['unstable_files'] > 0
			|| $state['coverage']['symlinks_skipped'] > 0
			|| $state['coverage']['path_escapes'] > 0
			|| $state['coverage']['filesystem_errors'] > 0
		) {
			$state['coverage']['state'] = 'incomplete';
		}

		return [ 'done' => ! empty( $state['done'] ), 'state' => $state, 'events' => $events ];
	}

	/** @param array<string,mixed> $expected */
	private static function normalise_state( string $root, array $expected, array $state, string $algorithm, bool $walk_unexpected, bool $collect_manifest ): array {
		$signature_entries = [];
		foreach ( $expected as $relative => $value ) {
			$signature_entries[] = (string) $relative . '=' . self::expected_hash( $value );
		}
		$signature = hash( 'sha256', $root . '|' . $algorithm . '|' . ( $walk_unexpected ? '1' : '0' ) . '|' . ( $collect_manifest ? '1' : '0' ) . '|' . count( $expected ) . '|' . implode( "\n", $signature_entries ) );
		if ( [] === $state || (string) ( $state['signature'] ?? '' ) !== $signature ) {
			return [
				'signature'       => $signature,
				'phase'           => 'expected',
				'expected_index'  => 0,
				'walker'          => [],
				'done'            => false,
				'status'          => 'ok',
				'passed_children'  => [],
				'observed_manifest'=> [],
				'coverage'        => [
					'state'             => 'complete',
					'expected_files'    => count( $expected ),
					'verified_files'    => 0,
					'missing_files'     => 0,
					'modified_files'    => 0,
					'unexpected_files'  => 0,
					'unreadable_files'  => 0,
					'hash_failures'     => 0,
					'unstable_files'    => 0,
					'symlinks_skipped'  => 0,
					'path_escapes'      => 0,
					'filesystem_errors' => 0,
				],
			];
		}

		$state['phase']           = (string) ( $state['phase'] ?? 'expected' );
		$state['expected_index']  = max( 0, (int) ( $state['expected_index'] ?? 0 ) );
		$state['walker']          = is_array( $state['walker'] ?? null ) ? $state['walker'] : [];
		$state['done']            = ! empty( $state['done'] );
		$state['status']          = (string) ( $state['status'] ?? 'ok' );
		$state['passed_children']  = is_array( $state['passed_children'] ?? null ) ? $state['passed_children'] : [];
		$state['observed_manifest']= is_array( $state['observed_manifest'] ?? null ) ? $state['observed_manifest'] : [];
		$state['coverage']        = is_array( $state['coverage'] ?? null ) ? $state['coverage'] : [];
		$state['coverage']['hash_failures']  = max( 0, (int) ( $state['coverage']['hash_failures'] ?? 0 ) );
		$state['coverage']['unstable_files'] = max( 0, (int) ( $state['coverage']['unstable_files'] ?? 0 ) );
		return $state;
	}


	/**
	 * Accept both official checksum manifests (path => hash) and local baseline
	 * manifests (path => ['hash' => ..., 'size' => ..., 'mtime' => ...]).
	 */
	private static function expected_hash( mixed $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['hash'] ?? '' );
		}
		return is_string( $value ) ? $value : (string) $value;
	}

	private static function raise_status( array &$state, string $severity ): void {
		$rank = [ 'ok' => 0, 'warning' => 1, 'critical' => 2, 'failed' => 3 ];
		$current = (string) ( $state['status'] ?? 'ok' );
		if ( ( $rank[ $severity ] ?? 1 ) > ( $rank[ $current ] ?? 0 ) ) {
			$state['status'] = $severity;
		}
	}
}
