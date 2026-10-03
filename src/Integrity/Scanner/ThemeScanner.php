<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Support\BatchedChecksumVerifier;
use CoreBlueprint\Core\Integrity\Support\DirectoryHasher;
use CoreBlueprint\Core\Integrity\Support\FilesystemWalker;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Support\PathGuard;

use function array_intersect_key;
use function array_flip;
use function count;
use function function_exists;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_object;
use function is_readable;
use function is_string;
use function md5_file;
use function method_exists;
use function sanitize_key;
use function sprintf;
use function str_contains;
use function strtolower;
use function wp_get_themes;
use function wp_normalize_path;

use const ABSPATH;
use const WP_CONTENT_DIR;

defined( 'ABSPATH' ) || exit;

final class ThemeScanner {
	private const MAX_PASSED_CHILDREN      = 200;


	/**
	 * Scan one theme component over resumable file-level batches.
	 *
	 * @param array<string,mixed> $state State returned by the previous batch.
	 * @return array{done:bool,state:array<string,mixed>,status:string,checks:array,coverage:array}
	 */
	public function scan_component_batch( string $stylesheet, array $state = [], int $file_budget = 750, float $seconds_budget = 4.0 ): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-includes/theme.php';
		}
		if ( ! function_exists( 'get_theme_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( [] === $state || (string) ( $state['stylesheet'] ?? '' ) !== $stylesheet ) {
			$themes = wp_get_themes();
			$theme = $themes[ $stylesheet ] ?? null;
			$slug = sanitize_key( $stylesheet );
			$version = is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '';
			$name = is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Name' ) : $slug;
			$root = wp_normalize_path( WP_CONTENT_DIR . '/themes/' . $slug );
			$relative_root = 'wp-content/themes/' . $slug . '/';
			$is_wporg = is_object( $theme ) ? $this->is_wordpress_org_theme( $slug, $theme ) : false;
			$checksums = '' !== $slug && '' !== $version && function_exists( 'get_theme_checksums' ) ? get_theme_checksums( $slug, $version ) : false;
			$expected = [];
			$init_findings = [];
			$path_escapes = 0;

			if ( is_array( $checksums ) ) {
				foreach ( $checksums as $relative_path => $expected_hash ) {
					$normalised = PathGuard::normalise_relative( (string) $relative_path );
					if ( null === $normalised ) {
						$path_escapes++;
						$init_findings[] = $this->finding( 'critical', $slug, 'invalid_path', (string) $relative_path, __( 'Theme checksum manifest contained an unsafe path and it was skipped.', 'core-blueprint' ), [ 'name' => $name, 'expected_hash' => (string) $expected_hash ], $relative_root );
						continue;
					}
					$expected[ $normalised ] = (string) $expected_hash;
				}
			}

			$baseline_entry = $this->local_baseline_entry( 'theme', $slug );
			$baseline_manifest = is_array( $baseline_entry['manifest'] ?? null ) ? $baseline_entry['manifest'] : [];
			$mode = is_array( $checksums ) ? 'official' : ( [] !== $baseline_manifest ? 'baseline' : 'snapshot' );
			$status_hint = ! is_array( $checksums ) && $is_wporg ? 'verification_failed' : 'baseline_required';

			$state = [
				'stylesheet'        => $stylesheet,
				'slug'              => $slug,
				'name'              => $name,
				'version'           => $version,
				'root'              => $root,
				'relative_root'     => $relative_root,
				'is_wporg'          => $is_wporg,
				'mode'              => $mode,
				'status_hint'       => $status_hint,
				'expected'          => 'official' === $mode ? $expected : $baseline_manifest,
				'baseline_entry_id' => (string) ( $baseline_entry['id'] ?? '' ),
				'verifier'          => [],
				'snapshot'          => [],
				'init_findings'     => $init_findings,
				'init_emitted'      => false,
				'coverage_seed'     => [
					'state'                 => ! is_array( $checksums ) && $is_wporg ? 'incomplete' : 'complete',
					'components_total'      => 1,
					'official_components'   => is_array( $checksums ) ? 1 : 0,
					'baseline_components'   => is_array( $checksums ) ? 0 : 1,
					'verification_failures' => ! is_array( $checksums ) && $is_wporg ? 1 : 0,
					'path_escapes'          => $path_escapes,
				],
			];
		}

		$findings = [];
		if ( empty( $state['init_emitted'] ) ) {
			$findings = is_array( $state['init_findings'] ?? null ) ? $state['init_findings'] : [];
			$state['init_emitted'] = true;
		}

		$mode = (string) ( $state['mode'] ?? 'snapshot' );
		if ( 'official' === $mode || 'baseline' === $mode ) {
			$batch = BatchedChecksumVerifier::verify_batch(
				(string) $state['root'],
				is_array( $state['expected'] ?? null ) ? $state['expected'] : [],
				is_array( $state['verifier'] ?? null ) ? $state['verifier'] : [],
				'official' === $mode ? 'md5' : 'sha256',
				true,
				$file_budget,
				$seconds_budget,
				'baseline' === $mode
			);
			$state['verifier'] = is_array( $batch['state'] ?? null ) ? $batch['state'] : [];
			foreach ( (array) ( $batch['events'] ?? [] ) as $event ) {
				if ( is_array( $event ) ) {
					$findings[] = $this->batch_event_finding( $event, $state, $mode );
				}
			}
			$coverage = $this->batch_coverage( $state, (array) ( $state['verifier']['coverage'] ?? [] ) );
			$done = ! empty( $batch['done'] );
			if ( $done && [] === $findings && 'ok' === (string) ( $state['verifier']['status'] ?? 'ok' ) ) {
				$findings[] = 'official' === $mode
					? $this->ok_finding( (string) $state['slug'], (string) $state['name'], (string) $state['version'], (int) ( $coverage['verified_files'] ?? 0 ), $this->batch_children( $state, $mode ), (string) $state['relative_root'] )
					: $this->local_baseline_ok_finding( $state, $coverage );
			}
			$status = (string) ( $state['verifier']['status'] ?? 'ok' );
			if ( $done && 'baseline' === $mode && 'ok' !== $status ) {
				$manifest = is_array( $state['verifier']['observed_manifest'] ?? null ) ? $state['verifier']['observed_manifest'] : [];
				$complete = 'complete' === (string) ( $coverage['state'] ?? 'incomplete' );
				$snapshot = DirectoryHasher::snapshot_from_manifest(
					$manifest,
					$complete,
					(int) ( $coverage['unreadable_files'] ?? 0 ),
					(int) ( $coverage['symlinks_skipped'] ?? 0 ),
					(int) ( $coverage['filesystem_errors'] ?? 0 ) + (int) ( $coverage['path_escapes'] ?? 0 )
				);
				$findings[] = Finding::make( [
					'id'             => (string) ( $state['baseline_entry_id'] ?? '' ),
					'type'           => 'theme',
					'target'         => [
						'slug'  => (string) $state['slug'],
						'label' => (string) $state['name'],
						'path'  => (string) $state['relative_root'],
					],
					'status'         => 'changed',
					'severity'       => 'warning',
					'message'        => __( 'Theme differs from the approved local baseline. Review the file-level anomalies before approving a replacement baseline.', 'core-blueprint' ),
					'verification'   => [ 'method' => 'local_baseline', 'source' => 'approved_local_baseline', 'confidence' => 'medium', 'label' => __( 'Compared against approved local baseline', 'core-blueprint' ), 'scope' => 'component' ],
					'meta'           => [
						'filesystem_root'             => wp_normalize_path( (string) ( $state['root'] ?? '' ) ),
						'baseline_entry_id'           => (string) ( $state['baseline_entry_id'] ?? '' ),
						'baseline_comparison_complete'=> $complete,
						'fingerprint_hash'            => (string) ( $snapshot['hash'] ?? '' ),
						'fingerprint_algorithm'       => 'sha256',
						'fingerprint_complete'        => $complete,
						'fingerprint_files'           => (int) ( $snapshot['files'] ?? 0 ),
						'fingerprint_unreadable'      => (int) ( $snapshot['unreadable'] ?? 0 ),
						'fingerprint_symlinks'        => (int) ( $snapshot['symlinks'] ?? 0 ),
						'fingerprint_errors'          => (int) ( $snapshot['errors'] ?? 0 ),
						'baseline_manifest'           => $manifest,
					],
				] );
			}
			return [ 'done' => $done, 'state' => $state, 'status' => $status, 'checks'   => $findings, 'coverage' => $coverage ];
		}

		$snapshot_batch = DirectoryHasher::snapshot_batch(
			(string) ( $state['root'] ?? '' ),
			is_array( $state['snapshot'] ?? null ) ? $state['snapshot'] : [],
			$file_budget,
			$seconds_budget
		);
		$state['snapshot'] = is_array( $snapshot_batch['state'] ?? null ) ? $snapshot_batch['state'] : [];
		$snapshot = is_array( $snapshot_batch['snapshot'] ?? null ) ? $snapshot_batch['snapshot'] : [];
		$coverage = $this->batch_coverage( $state, [
			'state'             => ! empty( $snapshot['complete'] ) ? 'pending_baseline' : 'incomplete',
			'expected_files'    => 0,
			'verified_files'    => 0,
			'snapshot_files_inspected' => (int) ( $snapshot['files'] ?? 0 ),
			'missing_files'     => 0,
			'modified_files'    => 0,
			'unexpected_files'  => 0,
			'unreadable_files'  => (int) ( $snapshot['unreadable'] ?? 0 ),
			'symlinks_skipped'  => (int) ( $snapshot['symlinks'] ?? 0 ),
			'filesystem_errors' => (int) ( $snapshot['errors'] ?? 0 ),
		] );

		if ( empty( $snapshot_batch['done'] ) ) {
			return [ 'done' => false, 'state' => $state, 'status' => 'warning', 'checks'   => $findings, 'coverage' => $coverage ];
		}

		$hint = (string) ( $state['status_hint'] ?? 'baseline_required' );
		$message = 'verification_failed' === $hint
			/* translators: %s: theme name. */
			? sprintf( __( '%s is installed from WordPress.org, but the official checksum could not be verified for this version. A complete local snapshot was captured for investigation or explicit baseline approval.', 'core-blueprint' ), (string) $state['name'] )
			: __( 'Theme needs a local approved baseline because no official WordPress.org checksum is available.', 'core-blueprint' );
		$findings[] = $this->finding( 'warning', (string) $state['slug'], $hint, '', $message, [
			'name'                  => (string) $state['name'],
			'filesystem_root'       => wp_normalize_path( (string) $state['root'] ),
			'fingerprint_hash'      => (string) ( $snapshot['hash'] ?? '' ),
			'fingerprint_algorithm' => (string) ( $snapshot['algorithm'] ?? 'sha256' ),
			'fingerprint_complete'  => ! empty( $snapshot['complete'] ),
			'fingerprint_files'     => (int) ( $snapshot['files'] ?? 0 ),
			'fingerprint_unreadable'=> (int) ( $snapshot['unreadable'] ?? 0 ),
			'fingerprint_symlinks'  => (int) ( $snapshot['symlinks'] ?? 0 ),
			'fingerprint_errors'    => (int) ( $snapshot['errors'] ?? 0 ),
			'baseline_manifest'     => is_array( $snapshot['manifest'] ?? null ) ? $snapshot['manifest'] : [],
		], (string) $state['relative_root'] );

		return [ 'done' => true, 'state' => $state, 'status' => 'warning', 'checks'   => $findings, 'coverage' => $coverage ];
	}

	private function local_baseline_entry( string $type, string $slug ): array {
		$baseline = ResultRepository::getBaseline();
		$entries = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
			if ( sanitize_key( (string) ( $entry['type'] ?? '' ) ) === $type && sanitize_key( (string) ( $target['slug'] ?? '' ) ) === sanitize_key( $slug ) ) {
				return $entry;
			}
		}
		return [];
	}

	private function batch_coverage( array $state, array $dynamic ): array {
		$coverage = is_array( $state['coverage_seed'] ?? null ) ? $state['coverage_seed'] : [];
		foreach ( $dynamic as $key => $value ) {
			if ( 'state' === $key ) {
				if ( 'incomplete' !== (string) ( $coverage['state'] ?? '' ) ) {
					$coverage['state'] = (string) $value;
				}
				continue;
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				$coverage[ $key ] = (int) ( $coverage[ $key ] ?? 0 ) + (int) $value;
			} elseif ( ! isset( $coverage[ $key ] ) ) {
				$coverage[ $key ] = $value;
			}
		}
		if (
			(int) ( $coverage['unreadable_files'] ?? 0 ) > 0
			|| (int) ( $coverage['symlinks_skipped'] ?? 0 ) > 0
			|| (int) ( $coverage['path_escapes'] ?? 0 ) > 0
			|| (int) ( $coverage['filesystem_errors'] ?? 0 ) > 0
		) {
			$coverage['state'] = 'incomplete';
		}
		return $coverage;
	}

	private function batch_children( array $state, string $mode ): array {
		$children = [];
		foreach ( (array) ( $state['verifier']['passed_children'] ?? [] ) as $relative ) {
			$children[] = [
				'status'       => 'ok',
				'severity'     => 'ok',
				'target'       => [
					'path' => (string) $state['relative_root'],
					'file' => (string) $relative,
				],
				'message'      => 'official' === $mode ? __( 'File matched the official checksum.', 'core-blueprint' ) : __( 'File matched the approved local baseline.', 'core-blueprint' ),
				'verification' => 'official' === $mode ? $this->verification_for( 'ok' ) : [ 'method' => 'local_baseline', 'source' => 'approved_local_baseline', 'confidence' => 'medium', 'label' => __( 'Compared against approved local baseline', 'core-blueprint' ), 'scope' => 'file' ],
			];
		}
		return $children;
	}

	private function local_baseline_ok_finding( array $state, array $coverage ): array {
		return Finding::make( [
			'type'         => 'theme',
			'target'       => [
				'slug'  => (string) $state['slug'],
				'label' => (string) $state['name'],
				'path'  => (string) $state['relative_root'],
			],
			'status'         => 'ok',
			'severity'       => 'ok',
			'message'        => __( 'Theme matches the approved local baseline file by file.', 'core-blueprint' ),
			'verification'   => [ 'method' => 'local_baseline', 'source' => 'approved_local_baseline', 'confidence' => 'medium', 'label' => __( 'Compared against approved local baseline', 'core-blueprint' ), 'scope' => 'component' ],
			'children'       => $this->batch_children( $state, 'baseline' ),
			'meta'           => [
				'name'              => (string) $state['name'],
				'filesystem_root'   => wp_normalize_path( (string) $state['root'] ),
				'verified_files'    => (int) ( $coverage['verified_files'] ?? 0 ),
				'baseline_entry_id' => (string) ( $state['baseline_entry_id'] ?? '' ),
			],
		] );
	}

	private function batch_event_finding( array $event, array $state, string $mode ): array {
		$type = (string) ( $event['type'] ?? 'scan_incomplete' );
		$file = (string) ( $event['file'] ?? '' );
		$severity = (string) ( $event['severity'] ?? 'warning' );
		if ( 'baseline' === $mode ) {
			$message = match ( $type ) {
				'missing' => __( 'Theme file is missing compared with the approved local baseline.', 'core-blueprint' ),
				'modified' => __( 'Theme file content differs from the approved local baseline.', 'core-blueprint' ),
				'unexpected' => __( 'Theme file was not present in the approved local baseline.', 'core-blueprint' ),
				'unexpected_unverified' => __( 'Theme file was not present in the approved local baseline, but its hash could not be established reliably. The anomaly is visible, but scan coverage is incomplete.', 'core-blueprint' ),
				'hash_failed' => __( 'Theme file could not be hashed reliably, so local baseline verification is incomplete.', 'core-blueprint' ),
				'file_changed_during_scan' => __( 'Theme file changed while Core Scanner was reading it. No checksum conclusion was made for this file; scan coverage is incomplete.', 'core-blueprint' ),
				'unreadable', 'unreadable_unexpected' => __( 'Theme file could not be read, so local baseline verification is incomplete.', 'core-blueprint' ),
				'symlink_skipped' => __( 'Theme path is a symlink. Core Scanner does not follow symlinks, so local baseline verification is incomplete.', 'core-blueprint' ),
				'path_escape', 'invalid_path' => __( 'Theme path resolved outside its component root and was skipped.', 'core-blueprint' ),
				default => __( 'Theme filesystem traversal was incomplete during local baseline verification.', 'core-blueprint' ),
			};
			$verification = [ 'method' => 'local_baseline', 'source' => 'approved_local_baseline', 'confidence' => 'medium', 'label' => __( 'Compared against approved local baseline', 'core-blueprint' ), 'scope' => 'file' ];
		} else {
			$message = match ( $type ) {
				'missing' => __( 'Theme file from official checksum manifest is missing.', 'core-blueprint' ),
				'modified' => __( 'Theme file checksum does not match the official WordPress.org checksum.', 'core-blueprint' ),
				'unexpected' => __( 'File exists in the theme directory but is not present in the official checksum manifest. This is an anomaly and should be reviewed.', 'core-blueprint' ),
				'unexpected_unverified' => __( 'File exists in the theme directory but is not present in the official checksum manifest. Its hash could not be established reliably, so the anomaly should be reviewed and scan coverage is incomplete.', 'core-blueprint' ),
				'hash_failed' => __( 'Theme file could not be hashed reliably, so checksum verification is incomplete.', 'core-blueprint' ),
				'file_changed_during_scan' => __( 'Theme file changed while Core Scanner was reading it. No checksum conclusion was made for this file; scan coverage is incomplete.', 'core-blueprint' ),
				'unreadable', 'unreadable_unexpected' => __( 'Theme file exists but could not be read for checksum verification.', 'core-blueprint' ),
				'symlink_skipped' => __( 'Theme path is a symlink. Core Scanner does not follow symlinks, so this path was not checksum-verified.', 'core-blueprint' ),
				'path_escape', 'invalid_path' => __( 'Theme checksum path resolved outside its component root and was skipped.', 'core-blueprint' ),
				default => __( 'Theme filesystem traversal was incomplete during checksum verification.', 'core-blueprint' ),
			};
			$verification = $this->verification_for( $type );
		}

		$context = [ 'name' => (string) $state['name'], 'baseline_entry_id' => (string) ( $state['baseline_entry_id'] ?? '' ) ];
		foreach ( [ 'expected_hash', 'actual_hash', 'actual_sha256', 'filesystem_path', 'size_bytes', 'modified_at', 'reason' ] as $key ) {
			if ( isset( $event[ $key ] ) ) {
				$context[ $key ] = $event[ $key ];
			}
		}
		return Finding::make( [
			'type'         => 'theme',
			'target'       => [
				'slug'  => (string) $state['slug'],
				'label' => (string) $state['name'],
				'path'  => (string) $state['relative_root'],
				'file'  => $file,
			],
			'status'         => match ( $type ) {
				'unreadable_unexpected' => 'unreadable',
				'unexpected_unverified' => 'unexpected',
				'hash_failed', 'file_changed_during_scan' => 'unverifiable',
				default => $type,
			},
			'severity'       => $severity,
			'message'        => $message,
			'meta'           => $context,
			'verification'   => $verification,
		] );
	}

	private function is_wordpress_org_theme( string $slug, object $theme ): bool {
		$theme_uri = method_exists( $theme, 'get' ) ? strtolower( (string) $theme->get( 'ThemeURI' ) ) : '';

		// As with plugins, only explicit component provenance counts here. An
		// author's WordPress.org profile is not evidence that this exact installed
		// theme was distributed by WordPress.org. When provenance is uncertain the
		// honest fallback is a local baseline, not a failed official-verification
		// claim.
		return '' !== $slug && str_contains( $theme_uri, 'wordpress.org/themes/' . $slug );
	}

	private function has_severity( array $findings, string $severity ): bool {
		foreach ( $findings as $finding ) {
			if ( $severity === ( $finding['severity'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function ok_finding( string $slug, string $name, string $version, int $verified_count, array $children, string $relative_root ): array {
		/* translators: 1: theme name, 2: theme version. */
		return $this->finding( 'ok', $slug, 'ok', '', sprintf( __( '%1$s matched the official WordPress.org checksum manifest for version %2$s.', 'core-blueprint' ), $name, $version ), [
			'name'                  => $name,
			'version'               => $version,
			'verified_files'        => $verified_count,
			'passed_paths_recorded' => count( $children ),
			'children'              => $children,
		], $relative_root );
	}

	private function finding( string $severity, string $slug, string $type, string $file, string $message, array $context = [], string $component_root = '' ): array {
		$name = (string) ( $context['name'] ?? $slug );
		return Finding::make( [
			'type'         => 'theme',
			'status'       => $type,
			'severity'     => $severity,
			'target'       => [
				'slug'  => $slug,
				'label' => $name,
				'path'  => '' !== $component_root ? $component_root : 'wp-content/themes/' . $slug . '/',
				'file'  => $file,
			],
			'message'      => $message,
			'meta'         => $context,
			'verification' => $this->verification_for( $type ),
			'children'     => is_array( $context['children'] ?? null ) ? $context['children'] : [],
		] );
	}

	private function verification_for( string $type ): array {
		if ( 'baseline_required' === $type || 'verification_failed' === $type || 'new' === $type || 'changed' === $type ) {
			return [
				'method'     => 'local_baseline',
				'source'     => 'approved_local_baseline',
				'confidence' => 'medium',
				'label'      => __( 'Local approved baseline', 'core-blueprint' ),
				'scope'      => 'component',
			];
		}
		return [
			'method'     => 'checksum',
			'source'     => 'wordpress.org_theme_repository',
			'confidence' => 'high',
			'label'      => __( 'Checksum via WordPress.org theme repository', 'core-blueprint' ),
		];
	}

	private function append_walker_findings( array &$findings, array &$coverage, array $walker, string $slug, string $name, string $relative_root ): void {
		$coverage['symlinks_skipped']  += (int) ( $walker['symlink_count'] ?? 0 );
		$coverage['path_escapes']      += (int) ( $walker['outside_count'] ?? 0 );
		$coverage['filesystem_errors'] += (int) ( $walker['error_count'] ?? 0 );

		foreach ( (array) ( $walker['symlink_paths'] ?? [] ) as $path ) {
			$findings[] = $this->finding( 'warning', $slug, 'symlink_skipped', (string) $path, __( 'Symlink detected in theme directory. Core Scanner does not follow symlinks, so this path was not inspected.', 'core-blueprint' ), [ 'name' => $name ], $relative_root );
		}
		foreach ( (array) ( $walker['outside_paths'] ?? [] ) as $path ) {
			$findings[] = $this->finding( 'critical', $slug, 'path_escape', (string) $path, __( 'Theme filesystem entry resolved outside its component root and was skipped.', 'core-blueprint' ), [ 'name' => $name ], $relative_root );
		}
		if ( (int) ( $walker['error_count'] ?? 0 ) > 0 ) {
			/* translators: %d: number of filesystem errors encountered while scanning the theme. */
			$findings[] = $this->finding( 'warning', $slug, 'scan_incomplete', '', sprintf( __( 'Theme traversal encountered %d filesystem error(s). Coverage is incomplete.', 'core-blueprint' ), (int) $walker['error_count'] ), [ 'name' => $name ], $relative_root );
		}
	}
}
