<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Support\DirectoryHasher;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Support\PathGuard;

/**
 * Inspect the actual MU-plugin directory, including nested files, without
 * loading PHP content. Approval is always explicit, never a first-scan trust.
 */
final class MuPluginScanner {
	private const SLUG = 'mu-plugins';
	private const RELATIVE_ROOT = 'wp-content/mu-plugins/';

	/** @return array{done:bool,state:array,status:string,checks:array,coverage:array} */
	public function scan_batch( array $state = [], int $file_budget = 750, float $seconds_budget = 4.0 ): array {
		$root = wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
		$baseline = $this->approved_baseline();
		if ( is_link( $root ) || ( is_dir( $root ) && ! PathGuard::existing_path_is_inside( $root, ABSPATH ) ) ) {
			return $this->failure( $root, 'path_escape', __( 'MU-plugin directory cannot be safely inspected within this WordPress root.', 'core-blueprint' ) );
		}
		if ( ! is_dir( $root ) ) {
			if ( [] !== $baseline ) {
				return $this->failure( $root, 'missing', __( 'Previously approved MU-plugin directory is missing.', 'core-blueprint' ), 'critical' );
			}
			return [
				'done' => true, 'state' => [ 'root' => $root, 'done' => true ],
				'status' => 'ok', 'checks' => [],
				'coverage' => [ 'state' => 'complete', 'files_inspected' => 0, 'signature_checked' => 0, 'verified_files' => 0 ],
			];
		}

		if ( (string) ( $state['root'] ?? '' ) !== $root ) {
			$state = [ 'root' => $root, 'phase' => 'snapshot', 'snapshot_state' => [], 'inspected_index' => 0, 'signature_checked' => 0 ];
		}
		if ( 'snapshot' === (string) ( $state['phase'] ?? 'snapshot' ) ) {
			$batch = DirectoryHasher::snapshot_batch(
				$root,
				is_array( $state['snapshot_state'] ?? null ) ? $state['snapshot_state'] : [],
				$file_budget,
				$seconds_budget
			);
			$state['snapshot_state'] = (array) ( $batch['state'] ?? [] );
			$state['snapshot'] = (array) ( $batch['snapshot'] ?? [] );
			if ( empty( $batch['done'] ) ) {
				return $this->partial( $state );
			}
			$state['phase'] = 'inspect';
		}

		$manifest = (array) ( $state['snapshot']['manifest'] ?? [] );
		$files = array_keys( $manifest );
		$findings = [];
		$started = microtime( true );
		$processed = 0;
		$index = (int) ( $state['inspected_index'] ?? 0 );
		while ( $index < count( $files ) && $processed < $file_budget && microtime( true ) - $started < $seconds_budget ) {
			$relative = (string) $files[ $index++ ];
			$processed++;
			if ( 'php' !== strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			$absolute = PathGuard::join( $root, $relative );
			if ( null === $absolute || is_link( $absolute ) || ! PathGuard::existing_path_is_inside( $absolute, $root ) ) {
				$findings[] = $this->finding( 'warning', 'scan_incomplete', $relative, __( 'MU-plugin PHP file changed location during inspection.', 'core-blueprint' ) );
				$state['inspection_failures'] = (int) ( $state['inspection_failures'] ?? 0 ) + 1;
				continue;
			}
			$source = @file_get_contents( $absolute, false, null, 0, 200000 );
			if ( ! is_string( $source ) ) {
				$findings[] = $this->finding( 'warning', 'unreadable', $relative, __( 'MU-plugin PHP source could not be inspected.', 'core-blueprint' ) );
				$state['inspection_failures'] = (int) ( $state['inspection_failures'] ?? 0 ) + 1;
				continue;
			}
			$state['signature_checked']++;
			// PHP lexer ignores commented-out examples and quoted function names.
			$indicators = LoaderPatternInspector::inspect( $source );
			if ( $indicators['suspected'] ) {
				$findings[] = $this->finding(
					'warning',
					'suspicious_loader_chain',
					$relative,
					__( 'MU-plugin contains an execution, rename and executable-extension chain. Inspect the source; detection is not an execution verdict.', 'core-blueprint' ),
					[
						'actual_sha256' => (string) ( $manifest[ $relative ]['hash'] ?? '' ),
						'filesystem_path' => $absolute,
						'referenced_media' => $indicators['referenced_media'],
						'detection_method' => 'bounded_php_loader_indicators',
					]
				);
			}
		}
		$state['inspected_index'] = $index;
		if ( $index < count( $files ) ) {
			return $this->partial( $state, $findings );
		}
		$state['phase'] = 'done';
		$snapshot = (array) $state['snapshot'];
		$complete = ! empty( $snapshot['complete'] ) && empty( $state['inspection_failures'] );
		$observed = (array) ( $snapshot['manifest'] ?? [] );
		$expected = (array) ( $baseline['manifest'] ?? [] );
		$verified = 0;
		if ( [] !== $baseline ) {
			foreach ( $expected as $name => $entry ) {
				if ( ! array_key_exists( $name, $observed ) ) {
					$findings[] = $this->finding( 'critical', 'missing', (string) $name, __( 'File from the approved MU-plugin baseline is missing.', 'core-blueprint' ) );
				} elseif ( ! hash_equals( (string) ( $entry['hash'] ?? '' ), (string) ( $observed[ $name ]['hash'] ?? '' ) ) ) {
					$findings[] = $this->finding( 'critical', 'modified', (string) $name, __( 'MU-plugin file differs from its approved SHA-256 baseline.', 'core-blueprint' ), [
						'expected_hash' => (string) ( $entry['hash'] ?? '' ),
						'actual_sha256' => (string) ( $observed[ $name ]['hash'] ?? '' ),
					] );
				} else {
					$verified++;
				}
			}
			foreach ( $observed as $name => $entry ) {
				if ( ! array_key_exists( $name, $expected ) ) {
					$findings[] = $this->finding( 'warning', 'unexpected', (string) $name, __( 'New file is not part of the approved MU-plugin baseline.', 'core-blueprint' ), [
						'actual_sha256' => (string) ( $entry['hash'] ?? '' ),
					] );
				}
			}
		}
		// File hashes are authoritative; mtime-only changes are not integrity drift.
		$changed = [] !== $baseline && count( $observed ) !== count( $expected );
		if ( [] !== $baseline && ! $changed ) {
			foreach ( $observed as $name => $entry ) {
				if ( ! isset( $expected[ $name ] ) || ! hash_equals( (string) ( $expected[ $name ]['hash'] ?? '' ), (string) ( $entry['hash'] ?? '' ) ) ) {
					$changed = true;
					break;
				}
			}
		}
		if ( count( $observed ) > 0 && ( [] === $baseline || $changed || ! $complete ) ) {
			$findings[] = $this->finding(
				'warning',
				! $complete ? 'scan_incomplete' : ( $changed ? 'changed' : 'baseline_required' ),
				'',
				! $complete
					? __( 'MU-plugin scan is incomplete; a baseline cannot be approved.', 'core-blueprint' )
					: ( $changed
						? __( 'MU-plugin directory has changed since explicit baseline approval.', 'core-blueprint' )
						: __( 'MU-plugin directory needs an explicitly reviewed local baseline.', 'core-blueprint' ) ),
				[
					'filesystem_root' => $root,
					'fingerprint_hash' => (string) ( $snapshot['hash'] ?? '' ),
					'fingerprint_complete' => $complete,
					'fingerprint_files' => count( $observed ),
					'baseline_manifest' => $observed,
					'baseline_entry_id' => 'mu-plugin-directory-baseline',
					'baseline_comparison_complete' => $complete,
					'fingerprint_unreadable' => (int) ( $snapshot['unreadable'] ?? 0 ),
					'fingerprint_symlinks' => (int) ( $snapshot['symlinks'] ?? 0 ),
					'fingerprint_errors' => (int) ( $snapshot['errors'] ?? 0 ),
				],
				[ 'method' => 'local_baseline', 'source' => 'approved_local_baseline', 'scope' => 'component', 'confidence' => 'medium', 'label' => __( 'Explicit MU-plugin baseline', 'core-blueprint' ) ]
			);
		}
		return [
			'done' => true, 'state' => $state,
			'status' => $this->severity( $findings ),
			'checks' => $findings,
			'coverage' => [
				'state' => ! $complete || ( [] === $baseline && count( $observed ) > 0 ) ? 'incomplete' : 'complete',
				'files_inspected' => count( $observed ),
				'signature_checked' => (int) $state['signature_checked'],
				'verified_files' => $verified,
				'unreadable_files' => (int) ( $snapshot['unreadable'] ?? 0 ) + (int) ( $state['inspection_failures'] ?? 0 ),
				'symlinks_skipped' => (int) ( $snapshot['symlinks'] ?? 0 ),
				'filesystem_errors' => (int) ( $snapshot['errors'] ?? 0 ),
			],
		];
	}

	private function partial( array $state, array $checks = [] ): array {
		$snapshot = (array) ( $state['snapshot'] ?? [] );
		return [
			'done' => false, 'state' => $state, 'status' => 'warning', 'checks' => $checks,
			'coverage' => [
				'state' => 'incomplete',
				'files_inspected' => (int) ( $snapshot['files'] ?? 0 ),
				'signature_checked' => (int) ( $state['signature_checked'] ?? 0 ),
			],
		];
	}

	private function failure( string $root, string $status, string $message, string $severity = 'warning' ): array {
		return [
			'done' => true, 'state' => [ 'root' => $root, 'done' => true ],
			'status' => $severity,
			'checks' => [ $this->finding( $severity, $status, '', $message ) ],
			'coverage' => [ 'state' => 'incomplete', 'files_inspected' => 0 ],
		];
	}

	private function approved_baseline(): array {
		$baseline = ResultRepository::getBaseline();
		foreach ( (array) ( $baseline['entries'] ?? [] ) as $entry ) {
			if ( is_array( $entry ) && 'mu_plugin' === (string) ( $entry['type'] ?? '' )
				&& self::SLUG === (string) ( $entry['target']['slug'] ?? '' ) ) {
				return $entry;
			}
		}
		return [];
	}

	private function finding( string $severity, string $status, string $file, string $message, array $meta = [], array $verification = [] ): array {
		$meta['identity'] = $status;
		return Finding::make( [
			'type' => 'mu_plugin', 'status' => $status, 'severity' => $severity,
			'target' => [ 'slug' => self::SLUG, 'label' => __( 'MU-plugins', 'core-blueprint' ), 'path' => self::RELATIVE_ROOT, 'file' => $file ],
			'message' => $message, 'meta' => $meta, 'verification' => $verification,
		] );
	}

	private function severity( array $findings ): string {
		$severity = 'ok';
		foreach ( $findings as $finding ) {
			if ( 'critical' === (string) ( $finding['severity'] ?? '' ) ) {
				return 'critical';
			}
			if ( 'warning' === (string) ( $finding['severity'] ?? '' ) ) {
				$severity = 'warning';
			}
		}
		return $severity;
	}
}
