<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Support\BatchedChecksumVerifier;
use CoreBlueprint\Core\Integrity\Support\BatchedFilesystemWalker;
use CoreBlueprint\Core\Integrity\Support\FilesystemWalker;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Support\FileHashProbe;
use CoreBlueprint\Core\Integrity\Support\PathGuard;
use CoreBlueprint\Core\Settings;

use function current_time;
use function do_action;
use function function_exists;
use function get_core_checksums;
use function get_locale;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function ltrim;
use function pathinfo;
use function md5_file;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function wp_normalize_path;

use const ABSPATH;

defined( 'ABSPATH' ) || exit;

/**
 * Core file integrity scanner.
 *
 * Distribution-locale handling (1.3.12-dev): the WP.org checksums API
 * returns a different expected hash for `wp-includes/version.php` per
 * official locale distribution (en_US, nl_NL, de_DE, …). UI-locale
 * (`get_locale()`) does not always match the distribution-locale -
 * a site can be installed as en_US and later switched to nl_NL via
 * Settings → General. WordPress does NOT re-download core files on
 * a UI-locale switch, so the on-disk distribution stays en_US while
 * `get_locale()` returns 'nl_NL'. Naively asking the API for the
 * UI-locale's checksums then produces a false-positive critical
 * finding on `version.php`.
 *
 * The scanner now resolves an "effective locale" before fetching
 * checksums: a settings-pinned auto-detected value (preferred), an
 * operator-chosen override (Settings → Distribution locale), or
 * `get_locale()` as the fallback. When a checksum mismatch occurs
 * with the fallback path, lazy detection runs to pin the correct
 * locale and the scan retries against the pinned payload.
 *
 * The {@see LocaleDetector} runs an integrity cross-check on
 * locale-agnostic core files (`load.php`, `wp-settings.php`) to
 * distinguish real distribution-drift (only `version.php` differs)
 * from tampering (other core files also differ). Drift is reported
 * with category `distribution_drift` at `info` severity; tampering
 * keeps the existing `critical` treatment.
 */
final class CoreScanner {
	/**
	 * Scan WordPress Core over resumable file-level batches.
	 *
	 * The batch state is serialisable and intentionally separates official-file
	 * verification from reverse traversal. wp-content is never traversed as Core.
	 * Budgets limit one invocation only and are never total scan ceilings.
	 *
	 * @param array<string,mixed> $state State returned by the previous batch.
	 * @return array{done:bool,state:array<string,mixed>,status:string,checks:array,coverage:array}
	 */
	public function scan_batch( array $state = [], int $file_budget = 750, float $seconds_budget = 4.0 ): array {
		$file_budget    = max( 1, $file_budget );
		$seconds_budget = max( 0.1, $seconds_budget );

		if ( [] === $state || empty( $state['initialized'] ) ) {
			if ( ! function_exists( 'get_core_checksums' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}

			global $wp_version;
			$version = (string) $wp_version;
			[ $effective_locale, $locale_source ] = $this->resolve_effective_locale( $version );
			$checksums = get_core_checksums( $version, $effective_locale );

			if ( is_array( $checksums ) && 'fallback' === $locale_source && $this->discriminator_mismatched( $checksums ) ) {
				$detection = ( new LocaleDetector() )->detect( $version );
				$this->persist_detection( $detection );
				if ( null !== $detection['detected'] && 'failed' !== $detection['cross_check'] ) {
					$effective_locale = $detection['detected'];
					$locale_source    = 'auto_detected';
					$checksums        = get_core_checksums( $version, $effective_locale );
				}
			}

			if ( ! is_array( $checksums ) ) {
				$finding = $this->finding(
					'warning',
					'unsupported',
					'',
					__( 'Could not retrieve official WordPress core checksums for this version or locale.', 'core-blueprint' )
				);
				return [
					'done'     => true,
					'state'    => [ 'initialized' => true, 'done' => true, 'phase' => 'done' ],
					'status'   => 'warning',
					'checks'   => [ $finding ],
					'coverage' => [ 'state' => 'unavailable', 'reason' => 'official_checksums_unavailable' ],
				];
			}

			$expected      = [];
			$init_findings = [];
			$path_escapes  = 0;
			foreach ( $checksums as $relative_path => $expected_hash ) {
				$relative_path = (string) $relative_path;
				if ( $this->is_wp_content_component_path( $relative_path ) ) {
					continue;
				}
				$normalised = PathGuard::normalise_relative( $relative_path );
				if ( null === $normalised ) {
					$path_escapes++;
					$init_findings[] = $this->finding(
						'critical',
						'invalid_path',
						$relative_path,
						__( 'Official WordPress checksum manifest contained an unsafe path and it was skipped.', 'core-blueprint' ),
						[ 'expected_hash' => (string) $expected_hash ]
					);
					continue;
				}
				$expected[ $normalised ] = (string) $expected_hash;
			}

			$state = [
				'initialized'       => true,
				'done'              => false,
				'phase'             => 'expected',
				'wp_version'        => $version,
				'effective_locale'  => $effective_locale,
				'locale_source'     => $locale_source,
				'expected'          => $expected,
				'verifier'          => [],
				'core_dir_index'    => 0,
				'core_dir_walker'   => [],
				'core_dir_totals'   => [ 'unreadable_count' => 0, 'symlink_count' => 0, 'outside_count' => 0, 'error_count' => 0 ],
				'root_offset'       => 0,
				'extra_coverage'    => [
					'unexpected_files'     => 0,
					'unreadable_files'     => 0,
					'hash_failures'        => 0,
					'unstable_files'       => 0,
					'symlinks_skipped'     => 0,
					'path_escapes'         => $path_escapes,
					'filesystem_errors'    => 0,
					'unmanaged_root_files' => 0,
				],
				'init_findings'     => $init_findings,
				'init_emitted'      => false,
				'status'            => [] === $init_findings ? 'ok' : 'critical',
			];
		}

		$findings = [];
		if ( empty( $state['init_emitted'] ) ) {
			$findings = is_array( $state['init_findings'] ?? null ) ? $state['init_findings'] : [];
			$state['init_emitted'] = true;
		}

		$phase = (string) ( $state['phase'] ?? 'expected' );
		if ( 'expected' === $phase ) {
			$batch = BatchedChecksumVerifier::verify_batch(
				ABSPATH,
				is_array( $state['expected'] ?? null ) ? $state['expected'] : [],
				is_array( $state['verifier'] ?? null ) ? $state['verifier'] : [],
				'md5',
				false,
				$file_budget,
				$seconds_budget
			);
			$state['verifier'] = is_array( $batch['state'] ?? null ) ? $batch['state'] : [];
			foreach ( (array) ( $batch['events'] ?? [] ) as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$finding = $this->core_batch_event_finding( $event, $state );
				$findings[] = $finding;
				$this->raise_batch_status( $state, (string) ( $finding['severity'] ?? 'warning' ), (string) ( $finding['category'] ?? 'tampering' ) );
			}

			if ( ! empty( $batch['done'] ) ) {
				$state['phase'] = 'core_dirs';
			}
			return $this->core_batch_response( $state, $findings );
		}

		if ( 'core_dirs' === $phase ) {
			$directories = [ 'wp-admin', 'wp-includes' ];
			$index = max( 0, (int) ( $state['core_dir_index'] ?? 0 ) );
			if ( $index >= count( $directories ) ) {
				$state['phase'] = 'root';
				return $this->core_batch_response( $state, $findings );
			}

			$directory = $directories[ $index ];
			$root = wp_normalize_path( ABSPATH . $directory );
			if ( ! is_dir( $root ) ) {
				$state['core_dir_index'] = $index + 1;
				$state['core_dir_walker'] = [];
				return $this->core_batch_response( $state, $findings );
			}
			if ( is_link( $root ) ) {
				$state['extra_coverage']['symlinks_skipped'] = (int) ( $state['extra_coverage']['symlinks_skipped'] ?? 0 ) + 1;
				$finding = $this->finding( 'warning', 'symlink_skipped', $directory . '/', __( 'Core directory is a symlink. Core Scanner does not follow symlinked core directories.', 'core-blueprint' ), [ 'filesystem_path' => $root ] );
				$findings[] = $finding;
				$this->raise_batch_status( $state, 'warning' );
				$state['core_dir_index'] = $index + 1;
				$state['core_dir_walker'] = [];
				return $this->core_batch_response( $state, $findings );
			}

			$expected = is_array( $state['expected'] ?? null ) ? $state['expected'] : [];
			$batch = BatchedFilesystemWalker::walk_batch(
				$root,
				function ( string $relative, array $meta ) use ( &$findings, &$state, $expected, $directory ): void {
					$full_relative = $directory . '/' . $relative;
					if ( isset( $expected[ $full_relative ] ) ) {
						return;
					}
					$state['extra_coverage']['unexpected_files'] = (int) ( $state['extra_coverage']['unexpected_files'] ?? 0 ) + 1;
					$path = (string) ( $meta['absolute_path'] ?? '' );
					if ( empty( $meta['readable'] ) ) {
						$findings[] = $this->finding( 'warning', 'unreadable', $full_relative, __( 'Unexpected file in a WordPress core directory could not be read. Review this path manually.', 'core-blueprint' ), [ 'filesystem_path' => $path ] );
						$this->raise_batch_status( $state, 'warning' );
						return;
					}
					$probe = FileHashProbe::probe( $path, [ 'sha256' ] );
					$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
					$sha256 = (string) ( $hashes['sha256'] ?? '' );
					$message = __( 'File exists in a WordPress core directory but is not present in the official checksum manifest. This is an anomaly and should be reviewed.', 'core-blueprint' );
					$context = [
						'filesystem_path' => $path,
						'size_bytes'      => (int) ( $probe['size'] ?? ( $meta['size'] ?? 0 ) ),
						'modified_at'     => (int) ( $probe['mtime'] ?? ( $meta['mtime'] ?? 0 ) ),
						'actual_sha256'   => $sha256,
					];
					if ( empty( $probe['ok'] ) ) {
						$reason = (string) ( $probe['reason'] ?? 'hash_failed' );
						$context['hash_unavailable_reason'] = $reason;
						if ( str_contains( $reason, 'changed_during_read' ) ) {
							$state['extra_coverage']['unstable_files'] = (int) ( $state['extra_coverage']['unstable_files'] ?? 0 ) + 1;
						} else {
							$state['extra_coverage']['hash_failures'] = (int) ( $state['extra_coverage']['hash_failures'] ?? 0 ) + 1;
						}
						$message = __( 'File exists in a WordPress core directory but is not present in the official checksum manifest. Its hash could not be established reliably, so the anomaly should be reviewed and scan coverage is incomplete.', 'core-blueprint' );
					}
					$findings[] = $this->finding( 'warning', 'unexpected', $full_relative, $message, $context );
					$this->raise_batch_status( $state, 'warning' );
				},
				is_array( $state['core_dir_walker'] ?? null ) ? $state['core_dir_walker'] : [],
				$file_budget,
				$seconds_budget
			);
			$state['core_dir_walker'] = is_array( $batch['state'] ?? null ) ? $batch['state'] : [];
			foreach ( (array) ( $batch['issues'] ?? [] ) as $issue ) {
				if ( ! is_array( $issue ) ) {
					continue;
				}
				$type = (string) ( $issue['type'] ?? '' );
				$path = (string) ( $issue['path'] ?? '' );
				if ( 'unreadable_file' === $type ) {
					continue;
				}
				if ( 'symlink' === $type ) {
					$findings[] = $this->finding( 'warning', 'symlink_skipped', $directory . '/' . $path, __( 'Symlink detected in a WordPress core directory. It was not followed or inspected.', 'core-blueprint' ) );
					$this->raise_batch_status( $state, 'warning' );
					continue;
				}
				if ( 'path_escape' === $type || 'invalid_path' === $type ) {
					$findings[] = $this->finding( 'critical', 'path_escape', $directory . '/' . $path, __( 'Core filesystem entry resolved outside its scan root and was skipped.', 'core-blueprint' ) );
					$this->raise_batch_status( $state, 'critical' );
					continue;
				}
				if ( 'unreadable_directory' === $type || 'root_unreadable' === $type || 'filesystem_error' === $type ) {
					$findings[] = $this->finding( 'warning', 'scan_incomplete', $directory . '/' . $path, __( 'Core directory traversal encountered a filesystem error. Coverage is incomplete.', 'core-blueprint' ) );
					$this->raise_batch_status( $state, 'warning' );
				}
			}

			if ( ! empty( $batch['done'] ) ) {
				$walker = is_array( $state['core_dir_walker'] ?? null ) ? $state['core_dir_walker'] : [];
				foreach ( [ 'unreadable_count', 'symlink_count', 'outside_count', 'error_count' ] as $counter ) {
					$state['core_dir_totals'][ $counter ] = (int) ( $state['core_dir_totals'][ $counter ] ?? 0 ) + (int) ( $walker[ $counter ] ?? 0 );
				}
				$state['core_dir_index'] = $index + 1;
				$state['core_dir_walker'] = [];
				if ( $index + 1 >= count( $directories ) ) {
					$state['phase'] = 'root';
				}
			}
			return $this->core_batch_response( $state, $findings );
		}

		if ( 'root' === $phase ) {
			$entries = scandir( ABSPATH, SCANDIR_SORT_ASCENDING );
			if ( ! is_array( $entries ) ) {
				$state['extra_coverage']['filesystem_errors'] = (int) ( $state['extra_coverage']['filesystem_errors'] ?? 0 ) + 1;
				$findings[] = $this->finding( 'warning', 'scan_incomplete', './', __( 'WordPress root traversal failed. Coverage is incomplete.', 'core-blueprint' ) );
				$this->raise_batch_status( $state, 'warning' );
				$state['phase'] = 'done';
				$state['done'] = true;
				return $this->core_batch_response( $state, $findings );
			}

			$offset  = max( 0, (int) ( $state['root_offset'] ?? 0 ) );
			$started = microtime( true );
			$handled = 0;
			$expected = is_array( $state['expected'] ?? null ) ? $state['expected'] : [];
			$total = count( $entries );
			for ( $i = $offset; $i < $total; $i++ ) {

				$state['root_offset'] = $i + 1;
				$name = (string) $entries[ $i ];
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$handled++;
				$path = PathGuard::join( ABSPATH, $name );
				if ( null === $path ) {
					$state['extra_coverage']['path_escapes'] = (int) ( $state['extra_coverage']['path_escapes'] ?? 0 ) + 1;
					$findings[] = $this->finding( 'critical', 'path_escape', $name, __( 'WordPress root entry could not be resolved safely and was skipped.', 'core-blueprint' ) );
					$this->raise_batch_status( $state, 'critical' );
					continue;
				}
				if ( is_dir( $path ) || isset( $expected[ $name ] ) || 'wp-config.php' === $name ) {
					continue;
				}
				if ( is_link( $path ) ) {
					$state['extra_coverage']['symlinks_skipped'] = (int) ( $state['extra_coverage']['symlinks_skipped'] ?? 0 ) + 1;
					$findings[] = $this->finding( 'warning', 'symlink_skipped', $name, __( 'Symlink detected in the WordPress root. It was not followed or inspected.', 'core-blueprint' ), [ 'filesystem_path' => wp_normalize_path( $path ) ] );
					$this->raise_batch_status( $state, 'warning' );
					continue;
				}
				if ( ! is_file( $path ) ) {
					continue;
				}

				$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				if ( in_array( $extension, [ 'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh' ], true ) ) {
					$state['extra_coverage']['unexpected_files'] = (int) ( $state['extra_coverage']['unexpected_files'] ?? 0 ) + 1;
					$readable = is_readable( $path );
					if ( ! $readable ) {
						$state['extra_coverage']['unreadable_files'] = (int) ( $state['extra_coverage']['unreadable_files'] ?? 0 ) + 1;
					}
					$probe = $readable ? FileHashProbe::probe( $path, [ 'sha256' ] ) : [ 'ok' => false, 'reason' => 'unreadable', 'hashes' => [], 'size' => (int) @filesize( $path ), 'mtime' => (int) @filemtime( $path ) ];
					$hashes = is_array( $probe['hashes'] ?? null ) ? $probe['hashes'] : [];
					$message = __( 'Executable/script file exists in the WordPress root but is not part of the official Core checksum manifest. This may be legitimate, but should be reviewed.', 'core-blueprint' );
					$context = [
						'filesystem_path' => wp_normalize_path( $path ),
						'size_bytes'      => (int) ( $probe['size'] ?? 0 ),
						'modified_at'     => (int) ( $probe['mtime'] ?? 0 ),
						'actual_sha256'   => (string) ( $hashes['sha256'] ?? '' ),
					];
					if ( $readable && empty( $probe['ok'] ) ) {
						$reason = (string) ( $probe['reason'] ?? 'hash_failed' );
						$context['hash_unavailable_reason'] = $reason;
						if ( str_contains( $reason, 'changed_during_read' ) ) {
							$state['extra_coverage']['unstable_files'] = (int) ( $state['extra_coverage']['unstable_files'] ?? 0 ) + 1;
						} else {
							$state['extra_coverage']['hash_failures'] = (int) ( $state['extra_coverage']['hash_failures'] ?? 0 ) + 1;
						}
						$message = __( 'Executable/script file exists in the WordPress root but is not part of the official Core checksum manifest. Its hash could not be established reliably, so the anomaly should be reviewed and scan coverage is incomplete.', 'core-blueprint' );
					}
					$findings[] = $this->finding( 'warning', 'unexpected_root_executable', $name, $message, $context );
					$this->raise_batch_status( $state, 'warning' );
				} else {
					$state['extra_coverage']['unmanaged_root_files'] = (int) ( $state['extra_coverage']['unmanaged_root_files'] ?? 0 ) + 1;
				}

				if ( $handled >= $file_budget || ( microtime( true ) - $started ) >= $seconds_budget ) {
					break;
				}
			}

			if ( (int) ( $state['root_offset'] ?? 0 ) >= $total ) {
				$state['phase'] = 'done';
				$state['done'] = true;
			}
			return $this->core_batch_response( $state, $findings );
		}

		$state['done'] = true;
		$state['phase'] = 'done';
		return $this->core_batch_response( $state, $findings );
	}

	private function core_batch_event_finding( array $event, array $state ): array {
		$type = (string) ( $event['type'] ?? 'scan_incomplete' );
		$file = (string) ( $event['file'] ?? '' );
		$context = [];
		foreach ( [ 'expected_hash', 'actual_hash', 'actual_sha256', 'filesystem_path', 'size_bytes', 'modified_at', 'reason' ] as $key ) {
			if ( isset( $event[ $key ] ) ) {
				$context[ $key ] = $event[ $key ];
			}
		}

		if ( 'modified' === $type ) {
			$finding = $this->classify_mismatch(
				$file,
				(string) ( $state['effective_locale'] ?? get_locale() ),
				(string) ( $state['wp_version'] ?? '' ),
				(string) ( $event['actual_hash'] ?? '' )
			);
			$meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
			$finding['meta'] = $context + $meta;
			return $finding;
		}

		return match ( $type ) {
			'missing' => $this->finding( 'critical', 'missing', $file, __( 'Official WordPress core file is missing.', 'core-blueprint' ), $context ),
			'hash_failed' => $this->finding( 'warning', 'unverifiable', $file, __( 'Core file could not be hashed reliably, so no checksum conclusion was made for this file and scan coverage is incomplete.', 'core-blueprint' ), $context ),
			'file_changed_during_scan' => $this->finding( 'warning', 'unverifiable', $file, __( 'Core file changed while Core Scanner was reading it. No checksum conclusion was made for this file; scan coverage is incomplete.', 'core-blueprint' ), $context ),
			'unreadable', 'unreadable_unexpected' => $this->finding( 'critical', 'unreadable', $file, __( 'Core file exists but could not be read for checksum verification.', 'core-blueprint' ), $context ),
			'symlink_skipped' => $this->finding( 'warning', 'symlink_skipped', $file, __( 'Core file is a symlink. Core Scanner does not follow symlinks, so this file was not checksum-verified.', 'core-blueprint' ), $context ),
			'path_escape', 'invalid_path' => $this->finding( 'critical', 'path_escape', $file, __( 'Core checksum path could not be resolved safely and was skipped.', 'core-blueprint' ), $context ),
			default => $this->finding( 'warning', 'scan_incomplete', $file, __( 'Core checksum verification was incomplete for this path.', 'core-blueprint' ), $context ),
		};
	}

	private function core_batch_response( array $state, array $findings ): array {
		$coverage = $this->core_batch_coverage( $state );
		$done     = ! empty( $state['done'] );
		$status   = (string) ( $state['status'] ?? 'ok' );

		// A completely clean Core scan used to publish no positive finding at all,
		// which made the overview show "Verified: 0" even though thousands of
		// official checksums had just matched. Emit one compact component-level
		// passed check on the final batch. Individual paths remain sampled in the
		// verifier state so the result stays small.
		if ( $done && 'ok' === $status && [] === $findings ) {
			$children = [];
			foreach ( (array) ( $state['verifier']['passed_children'] ?? [] ) as $relative ) {
				$children[] = [
					'status'       => 'ok',
					'severity'     => 'ok',
					'target'       => [
						'path' => './',
						'file' => (string) $relative,
					],
					'message'      => __( 'File matched the official checksum.', 'core-blueprint' ),
					'verification' => [ 'method' => 'checksum', 'source' => 'wordpress.org_core_checksums', 'confidence' => 'high', 'label' => __( 'Checksum via WordPress.org', 'core-blueprint' ) ],
				];
			}

			$findings[] = Finding::make( [
				'type'         => 'core',
				'target'       => [
					'slug'  => 'wordpress-core',
					'label' => __( 'WordPress Core', 'core-blueprint' ),
					'path'  => './',
				],
				'status'       => 'ok',
				'severity'     => 'ok',
				/* translators: 1: component name (WordPress Core), 2: WordPress version. */
				'message'      => sprintf( __( '%1$s matched the official WordPress.org checksum manifest for version %2$s.', 'core-blueprint' ), __( 'WordPress Core', 'core-blueprint' ), (string) ( $state['wp_version'] ?? '' ) ),
				'verification' => [ 'method' => 'checksum', 'source' => 'wordpress.org_core_checksums', 'confidence' => 'high', 'label' => __( 'Checksum via WordPress.org', 'core-blueprint' ) ],
				'children'     => $children,
				'meta'         => [
					'version'               => (string) ( $state['wp_version'] ?? '' ),
					'effective_locale'      => (string) ( $state['effective_locale'] ?? '' ),
					'verified_files'        => (int) ( $coverage['verified_files'] ?? 0 ),
					'passed_paths_recorded' => count( $children ),
				],
			] );
		}

		return [
			'done'     => $done,
			'state'    => $state,
			'status'   => $status,
			'checks'   => $findings,
			'coverage' => $coverage,
		];
	}

	private function core_batch_coverage( array $state ): array {
		$verifier = is_array( $state['verifier']['coverage'] ?? null ) ? $state['verifier']['coverage'] : [];
		$extra    = is_array( $state['extra_coverage'] ?? null ) ? $state['extra_coverage'] : [];
		$totals   = is_array( $state['core_dir_totals'] ?? null ) ? $state['core_dir_totals'] : [];
		$current  = is_array( $state['core_dir_walker'] ?? null ) ? $state['core_dir_walker'] : [];

		$coverage = [
			'state'                => 'complete',
			'expected_files'       => (int) ( $verifier['expected_files'] ?? count( (array) ( $state['expected'] ?? [] ) ) ),
			'verified_files'       => (int) ( $verifier['verified_files'] ?? 0 ),
			'missing_files'        => (int) ( $verifier['missing_files'] ?? 0 ),
			'modified_files'       => (int) ( $verifier['modified_files'] ?? 0 ),
			'unexpected_files'     => (int) ( $extra['unexpected_files'] ?? 0 ),
			'unreadable_files'     => (int) ( $verifier['unreadable_files'] ?? 0 ) + (int) ( $extra['unreadable_files'] ?? 0 ) + (int) ( $totals['unreadable_count'] ?? 0 ) + (int) ( $current['unreadable_count'] ?? 0 ),
			'hash_failures'        => (int) ( $verifier['hash_failures'] ?? 0 ) + (int) ( $extra['hash_failures'] ?? 0 ),
			'unstable_files'       => (int) ( $verifier['unstable_files'] ?? 0 ) + (int) ( $extra['unstable_files'] ?? 0 ),
			'symlinks_skipped'     => (int) ( $verifier['symlinks_skipped'] ?? 0 ) + (int) ( $extra['symlinks_skipped'] ?? 0 ) + (int) ( $totals['symlink_count'] ?? 0 ) + (int) ( $current['symlink_count'] ?? 0 ),
			'path_escapes'         => (int) ( $verifier['path_escapes'] ?? 0 ) + (int) ( $extra['path_escapes'] ?? 0 ) + (int) ( $totals['outside_count'] ?? 0 ) + (int) ( $current['outside_count'] ?? 0 ),
			'filesystem_errors'    => (int) ( $verifier['filesystem_errors'] ?? 0 ) + (int) ( $extra['filesystem_errors'] ?? 0 ) + (int) ( $totals['error_count'] ?? 0 ) + (int) ( $current['error_count'] ?? 0 ),
			'unmanaged_root_files' => (int) ( $extra['unmanaged_root_files'] ?? 0 ),
		];

		if ( $coverage['unreadable_files'] > 0 || $coverage['hash_failures'] > 0 || $coverage['unstable_files'] > 0 || $coverage['symlinks_skipped'] > 0 || $coverage['path_escapes'] > 0 || $coverage['filesystem_errors'] > 0 ) {
			$coverage['state'] = 'incomplete';
		}
		return $coverage;
	}

	private function raise_batch_status( array &$state, string $severity, string $category = 'tampering' ): void {
		if ( 'distribution_drift' === $category ) {
			return;
		}
		$rank = [ 'ok' => 0, 'info' => 0, 'warning' => 1, 'critical' => 2, 'failed' => 3 ];
		$current = (string) ( $state['status'] ?? 'ok' );
		if ( ( $rank[ $severity ] ?? 1 ) > ( $rank[ $current ] ?? 0 ) ) {
			$state['status'] = $severity;
		}
	}

	/**
	 * Resolve the locale used for the WP.org checksum lookup.
	 *
	 * Resolution order:
	 *   1. Operator override   (mode === 'override' + non-empty override)
	 *   2. Auto-detected pin   (mode === 'auto'     + non-empty detected)
	 *   3. UI-locale fallback  (legacy behaviour)
	 *
	 * @return array{0: string, 1: 'override'|'auto_detected'|'fallback'}
	 *   Tuple of [resolved-locale, source-tag]. The source-tag is used
	 *   downstream to decide whether lazy detection should fire on a
	 *   mismatch (only meaningful for the fallback path).
	 */
	private function resolve_effective_locale( string $wp_version ): array {
		$settings  = Settings::get()['integrity'] ?? [];
		$mode      = (string) ( $settings['distribution_locale_mode']     ?? 'fallback' );
		$detected  = (string) ( $settings['distribution_locale_detected'] ?? '' );
		$override  = (string) ( $settings['distribution_locale_override'] ?? '' );

		if ( 'override' === $mode && '' !== $override ) {
			return [ $override, 'override' ];
		}

		if ( 'auto' === $mode && '' !== $detected ) {
			return [ $detected, 'auto_detected' ];
		}

		return [ (string) get_locale(), 'fallback' ];
	}

	/**
	 * Quick test: does the on-disk discriminator file mismatch the
	 * supplied checksum payload? Used as the trigger for lazy
	 * detection on the fallback path.
	 */
	private function discriminator_mismatched( array $checksums ): bool {
		$expected = $checksums[ LocaleDetector::DISCRIMINATOR_FILE ] ?? null;
		if ( ! is_string( $expected ) ) {
			return false;
		}

		$path = wp_normalize_path( ABSPATH . LocaleDetector::DISCRIMINATOR_FILE );
		if ( ! is_file( $path ) ) {
			return false;
		}

		$actual = md5_file( $path );
		return is_string( $actual ) && strtolower( $actual ) !== strtolower( $expected );
	}

	/**
	 * Persist a detection result to the integrity settings.
	 *
	 * On a successful detection with a clean cross-check, mode is set
	 * to 'auto' so subsequent scans use the pinned locale. On an
	 * inconclusive detection (no match, or cross-check failed) the
	 * meta is still recorded for operator-visibility, but mode stays
	 * 'fallback' so we don't pin a wrong value. The Settings UI
	 * surfaces both states explicitly.
	 */
	private function persist_detection( array $detection ): void {
		$now = current_time( 'mysql' );

		$integrity = (array) ( Settings::get()['integrity'] ?? [] );

		$integrity['distribution_locale_meta'] = [
			'last_detected_at' => $now,
			'tried'            => $detection['tried'],
			'matched_file'     => $detection['matched_file'],
			'cross_check'      => $detection['cross_check'],
		];

		if ( null !== $detection['detected'] && 'failed' !== $detection['cross_check'] ) {
			$integrity['distribution_locale_detected'] = $detection['detected'];
			$integrity['distribution_locale_mode']     = 'auto';

			Settings::set_key( 'integrity', $integrity, 'integrity_scan' );
			do_action( 'core_blueprint_integrity_locale_detected', $detection['detected'], $detection );
			return;
		}

		Settings::set_key( 'integrity', $integrity, 'integrity_scan' );
		do_action( 'core_blueprint_integrity_locale_detection_inconclusive', $detection );
	}

	/**
	 * Classify a checksum mismatch as drift or tampering.
	 *
	 * The discriminator file (`wp-includes/version.php`) is the only
	 * core file whose hash legitimately differs across distributions.
	 * If it mismatches the effective locale's payload, run a
	 * confirmation: does it match the en_US distribution instead, AND
	 * does the cross-check pass against that en_US payload? If both,
	 * this is distribution_drift (info severity, not a security event).
	 * Anything else stays as tampering (critical, current behaviour).
	 *
	 * Mismatches on locale-agnostic files are always tampering - those
	 * files have the same hash in every distribution, so a mismatch is
	 * not explainable by locale-drift.
	 */
	private function classify_mismatch( string $relative_path, string $effective_locale, string $wp_version, string $observed_md5 = '' ): array {
		if ( LocaleDetector::DISCRIMINATOR_FILE !== $relative_path ) {
			return $this->finding( 'critical', 'modified', $relative_path, __( 'Core file checksum does not match the official WordPress checksum.', 'core-blueprint' ) );
		}

		if ( 'en_US' === $effective_locale ) {
			return $this->finding(
				'critical',
				'modified',
				$relative_path,
				__( 'Core file checksum does not match the official WordPress checksum.', 'core-blueprint' )
			);
		}

		$en_us_checksums = get_core_checksums( $wp_version, 'en_US' );
		if ( ! is_array( $en_us_checksums ) ) {
			return $this->finding( 'critical', 'modified', $relative_path, __( 'Core file checksum does not match the official WordPress checksum.', 'core-blueprint' ) );
		}

		$en_us_expected = $en_us_checksums[ $relative_path ] ?? null;
		if ( ! is_string( $en_us_expected ) ) {
			return $this->finding( 'critical', 'modified', $relative_path, __( 'Core file checksum does not match the official WordPress checksum.', 'core-blueprint' ) );
		}

		// Reuse the stable MD5 captured by BatchedChecksumVerifier. Re-reading the
		// discriminator here would reopen a TOCTOU window and could classify a
		// different on-disk state than the mismatch that triggered this finding.
		if ( '' === $observed_md5 || strtolower( $observed_md5 ) !== strtolower( $en_us_expected ) ) {
			return $this->finding( 'critical', 'modified', $relative_path, __( 'Core file checksum does not match the official WordPress checksum.', 'core-blueprint' ) );
		}

		// Discriminator matches en_US distribution. Cross-check the
		// other locale-agnostic files against the en_US payload too.
		// If they all match, this is a confirmed distribution_drift.
		// If any mismatch, we have a stranger situation and treat it
		// as tampering - the safer default.
		$cross_check_failed = false;
		foreach ( LocaleDetector::CROSS_CHECK_FILES as $cross_relative ) {
			$cross_expected = $en_us_checksums[ $cross_relative ] ?? null;
			if ( ! is_string( $cross_expected ) ) {
				continue;
			}
			$cross_path = wp_normalize_path( ABSPATH . $cross_relative );
			if ( ! is_file( $cross_path ) ) {
				continue;
			}
			$cross_actual = md5_file( $cross_path );
			if ( ! is_string( $cross_actual ) || strtolower( $cross_actual ) !== strtolower( $cross_expected ) ) {
				$cross_check_failed = true;
				break;
			}
		}

		if ( $cross_check_failed ) {
			return $this->finding( 'critical', 'modified', $relative_path, __( 'Core file checksum does not match the official WordPress checksum.', 'core-blueprint' ) );
		}

		return Finding::make( [
			'type'     => 'core',
			'category' => 'distribution_drift',
			'status'   => 'distribution_drift',
			'severity' => 'info',
			'target'   => [
				'slug'  => 'wordpress-core',
				'label' => __( 'WordPress Core', 'core-blueprint' ),
				'path'  => './',
				'file'  => $relative_path,
			],
			'message'  => sprintf(
				/* translators: 1: configured locale, 2: detected matching locale */
				__( 'Core files match a different official WordPress distribution (%2$s) than the configured locale (%1$s). This usually happens after switching site language. No action required if expected.', 'core-blueprint' ),
				$effective_locale,
				'en_US'
			),
			'meta'           => [
				'configured_locale' => $effective_locale,
				'matched_locale'    => 'en_US',
			],
		] );
	}

	/**
	 * Aggregate status from finding list.
	 *
	 * Drift-only findings keep the scan status at 'ok' - a drift is
	 * not a security event. Tampering at any severity escalates the
	 * scan status appropriately.
	 */
	private function aggregate_status( array $findings ): string {
		if ( [] === $findings ) {
			return 'ok';
		}

		$has_critical = false;
		$has_warning  = false;

		foreach ( $findings as $finding ) {
			$category = (string) ( $finding['category'] ?? 'tampering' );
			$severity = (string) ( $finding['severity'] ?? 'info' );

			if ( 'distribution_drift' === $category ) {
				continue;
			}

			if ( 'critical' === $severity ) {
				$has_critical = true;
			} elseif ( 'warning' === $severity ) {
				$has_warning = true;
			}
		}

		if ( $has_critical ) {
			return 'critical';
		}
		if ( $has_warning ) {
			return 'warning';
		}
		return 'ok';
	}

	/**
	 * Should this WP.org checksum path be skipped by the Core scanner?
	 *
	 * The checksums API returns the full file list for a given version
	 * + locale, including paths under `wp-content/` that are not core's
	 * responsibility:
	 *
	 *   - `wp-content/themes/twenty*` - bundled default themes. Tracked
	 *     separately by ThemeScanner. Skip.
	 *   - `wp-content/plugins/akismet` - bundled default plugin. Tracked
	 *     separately by PluginScanner. Skip.
	 *   - `wp-content/languages/themes/{slug}-{locale}.{po,mo}` - these
	 *     are translation files for the bundled default themes. They
	 *     ride along with the WP.org checksum payload, but on a site
	 *     where the corresponding theme is not installed (e.g. Bricks-
	 *     only setup) the translation files are also absent - and that
	 *     absence is correct, not a core integrity issue. Skip.
	 *   - `wp-content/languages/plugins/{slug}-{locale}.{po,mo}` - same
	 *     situation for bundled plugin translations. Skip.
	 *
	 * Path NOT skipped: `wp-content/languages/{locale}.{po,mo}` directly
	 * - those are WordPress core translations themselves, and absence
	 * there IS a real integrity concern. The leading-component check
	 * handles this naturally because those paths don't sit under
	 * `themes/` or `plugins/` subfolders.
	 */
	private function is_wp_content_component_path( string $relative_path ): bool {
		$normalized = str_replace( '\\', '/', ltrim( $relative_path, '/' ) );

		return str_starts_with( $normalized, 'wp-content/themes/' )
			|| str_starts_with( $normalized, 'wp-content/plugins/' )
			|| str_starts_with( $normalized, 'wp-content/languages/themes/' )
			|| str_starts_with( $normalized, 'wp-content/languages/plugins/' );
	}

	private function finding( string $severity, string $status, string $file, string $message, array $meta = [] ): array {
		return Finding::make( [
			'type'     => 'core',
			'status'   => $status,
			'severity' => $severity,
			'target'   => [
				'slug'  => 'wordpress-core',
				'label' => __( 'WordPress Core', 'core-blueprint' ),
				'path'  => './',
				'file'  => $file,
			],
			'message'  => $message,
			'meta'     => $meta,
		] );
	}
}
