<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Storage\StorageSchema;
use CoreBlueprint\Core\Integrity\Support\Audit;
use CoreBlueprint\Core\Integrity\Support\DiffService;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Support\FindingLifecycle;
use CoreBlueprint\Core\Integrity\Support\IncidentLifecycle;
use CoreBlueprint\Core\Integrity\Support\SeverityMapper;

use function array_keys;
use function array_slice;
use function array_merge;
use function current_time;
use function in_array;
use function is_array;
use function microtime;
use function round;
use function sanitize_key;
use function sprintf;

use const CB_CORE_VERSION;

defined( 'ABSPATH' ) || exit;

final class ScannerEngine {
	/**
	 * Finalize and persist a resumable scan job assembled over multiple requests.
	 *
	 * The job runner owns execution/batching and the long-lived lock; this method
	 * intentionally does not acquire or release ScannerLock. It applies the same
	 * baseline, coverage, diff, persistence and audit semantics as run().
	 *
	 * @param array<string,mixed>   $job      Persisted resumable job state.
	 * @param ProgressReporter|null $reporter Optional progress reporter.
	 */
	public function finalize_job_result( array $job, ?ProgressReporter $reporter = null ): array {
		$reporter        = $reporter ?? new NullProgressReporter();
		$source          = sanitize_key( (string) ( $job['source'] ?? 'manual' ) );
		$started_at      = (string) ( $job['started_at'] ?? current_time( 'mysql' ) );
		$started_micro   = (float) ( $job['started_at_micro'] ?? microtime( true ) );
		$findings        = is_array( $job['checks'] ?? null ) ? $job['checks'] : [];
		$components      = is_array( $job['components'] ?? null ) ? $job['components'] : [];
		$coverage        = is_array( $job['coverage'] ?? null ) ? $job['coverage'] : [];
		$phase_durations = is_array( $job['phase_durations'] ?? null ) ? $job['phase_durations'] : [];
		$previous        = ResultRepository::getLatest();

		$findings   = $this->normalize_findings( $findings );
		$findings   = $this->apply_baseline( $findings, $components );
		$completed_at = current_time( 'mysql' );
		$findings   = ( new FindingLifecycle() )->enrich( $previous, $findings, $completed_at );
		$coverage   = $this->finalize_coverage( $coverage, $findings );
		$total_files = 0;
		foreach ( [ 'core', 'plugins', 'themes', 'uploads' ] as $coverage_key ) {
			if ( is_array( $coverage[ $coverage_key ] ?? null ) ) {
				$total_files += $this->coverage_file_count( $coverage[ $coverage_key ] );
			}
		}

		$summary    = $this->summarize( $findings );
		$status     = $this->status_from_summary( $summary );
		$components = $this->components_from_findings( $components, $findings );
		$duration_seconds = round( max( 0.0, microtime( true ) - $started_micro ), 3 );

		$result = [
			'storage_schema'      => StorageSchema::VERSION,
			'plugin_version'      => CB_CORE_VERSION,
			'job_id'              => (string) ( $job['job_id'] ?? '' ),
			'source'              => $source,
			'scan_type'           => $source,
			'timestamp'           => $completed_at,
			'status'              => $status,
			'started_at'          => $started_at,
			'completed_at'        => $completed_at,
			'duration_seconds'    => $duration_seconds,
			'phase_durations'     => $phase_durations,
			'total_files_scanned' => $total_files,
			'total_files_checked' => $total_files,
			'completion'          => (string) ( $coverage['state'] ?? 'incomplete' ),
			'coverage'            => $coverage,
			'summary'             => $summary,
			'components'          => $components,
			'checks'              => $findings,
		];

		$result['anomaly'] = $this->detect_duration_anomaly( $previous, $duration_seconds );
		$result['diff']    = ( new DiffService() )->compare( $previous, $result );
		$result['incident_lifecycle'] = ( new IncidentLifecycle() )->compare( $previous, $result );

		ResultRepository::saveLatest( $result );
		$this->audit_incident_lifecycle( $result['incident_lifecycle'], $source );

		$result_id = (string) ( $result['timestamp'] ?? $started_at );
		$reporter->complete_scan( $result_id );

		$this->audit(
			'integrity_scan_completed',
			'notice',
			[
				'source'           => $source,
				'status'           => $status,
				'summary'          => $summary,
				'components'       => $components,
				'duration_seconds' => $duration_seconds,
				'coverage_state'   => (string) ( $coverage['state'] ?? 'incomplete' ),
			]
		);

		return $result;
	}


	private function coverage_file_count( array $coverage ): int {
		if ( isset( $coverage['files_inspected'] ) ) {
			return max( 0, (int) $coverage['files_inspected'] );
		}

		return max( 0, (int) ( $coverage['verified_files'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['modified_files'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['unexpected_files'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['local_baseline_files_checked'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['snapshot_files_inspected'] ?? 0 ) );
	}

	/**
	 * Finalise scan coverage after local-baseline resolution. Findings are the
	 * authoritative signal for whether a component could actually be verified.
	 */
	private function finalize_coverage( array $coverage, array $findings ): array {
		foreach ( [ 'plugins' => 'plugin', 'themes' => 'theme' ] as $key => $type ) {
			if ( ! isset( $coverage[ $key ] ) || 'skipped' === (string) ( $coverage[ $key ]['state'] ?? '' ) ) {
				continue;
			}

			$unverified = 0;
			$local_baseline_files_checked = 0;
			foreach ( $findings as $finding ) {
				if ( $type !== (string) ( $finding['type'] ?? '' ) ) {
					continue;
				}

				$status = (string) ( $finding['status'] ?? '' );
				if ( in_array( $status, [ 'baseline_required', 'verification_failed', 'scan_incomplete', 'symlink_skipped', 'unreadable', 'path_escape', 'invalid_path' ], true ) ) {
					$unverified++;
				}

				$meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
				$verification = is_array( $finding['verification'] ?? null ) ? $finding['verification'] : [];
				if ( 'local_baseline' === (string) ( $verification['method'] ?? '' ) && isset( $meta['baseline_snapshot_files'] ) ) {
					$local_baseline_files_checked += max( 0, (int) $meta['baseline_snapshot_files'] );
				}
			}

			$coverage[ $key ]['unverified_findings'] = $unverified;
			$coverage[ $key ]['local_baseline_files_checked'] = $local_baseline_files_checked;
			$structural_incomplete = (int) ( $coverage[ $key ]['unreadable_files'] ?? 0 ) > 0
				|| (int) ( $coverage[ $key ]['symlinks_skipped'] ?? 0 ) > 0
				|| (int) ( $coverage[ $key ]['path_escapes'] ?? 0 ) > 0
				|| (int) ( $coverage[ $key ]['filesystem_errors'] ?? 0 ) > 0;

			$coverage[ $key ]['state'] = ( $structural_incomplete || $unverified > 0 ) ? 'incomplete' : 'complete';
		}

		$state = 'complete';
		$enabled_components = 0;
		$incomplete_components = [];
		foreach ( [ 'core', 'plugins', 'themes', 'uploads' ] as $key ) {
			$component_state = (string) ( $coverage[ $key ]['state'] ?? 'incomplete' );
			if ( 'skipped' === $component_state ) {
				continue;
			}

			$enabled_components++;
			if ( 'complete' !== $component_state ) {
				$state = 'incomplete';
				$incomplete_components[] = $key;
			}
		}

		$coverage['state'] = 0 === $enabled_components ? 'incomplete' : $state;
		$coverage['incomplete_components'] = $incomplete_components;
		$coverage['enabled_components'] = $enabled_components;

		return $coverage;
	}

	/**
	 * Detect duration anomaly by comparing to the previous scan.
	 *
	 * Returns null if no comparison is possible (no previous, or
	 * previous lacks duration_seconds). Otherwise
	 * returns a structure describing the anomaly (or its absence).
	 *
	 * Threshold v1: ratio > 3.0 = signal. Hardcoded; configurable
	 * threshold is parking lot for a later release.
	 */
	private function detect_duration_anomaly( ?array $previous, float $current_seconds ): ?array {
		if ( ! is_array( $previous ) ) {
			return null;
		}

		$previous_seconds = (float) ( $previous['duration_seconds'] ?? 0.0 );
		if ( $previous_seconds <= 0.0 ) {
			return null;
		}

		$ratio = $current_seconds / $previous_seconds;

		if ( $ratio > 3.0 ) {
			return [
				'type'             => 'slower',
				'ratio'            => round( $ratio, 2 ),
				'previous_seconds' => $previous_seconds,
				'current_seconds'  => $current_seconds,
			];
		}

		return null;
	}

	private function normalize_findings( array $findings ): array {
		$normalized = [];

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || ! isset( $finding['type'], $finding['target'], $finding['status'] ) ) {
				continue;
			}

			$normalized[] = Finding::make( $finding );
		}

		return $normalized;
	}

	private function apply_baseline( array $findings, array $components ): array {
		$baseline = ResultRepository::getBaseline();
		if ( ! is_array( $baseline ) ) {
			return $findings;
		}

		$raw_entries = is_array( $baseline['entries'] ?? null ) ? $baseline['entries'] : [];
		$entries     = [];
		foreach ( $raw_entries as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// Only per-file manifests are accepted as proof. Aggregate-only baselines
			// cannot localise drift and are therefore not trusted.
			$manifest = is_array( $entry['manifest'] ?? null ) ? $entry['manifest'] : [];
			$id       = (string) ( $entry['id'] ?? $key );
			if ( '' === $id || [] === $manifest ) {
				continue;
			}
			$entries[ $id ] = $entry;
		}

		$seen     = [];
		$resolved = [];

		foreach ( $findings as $finding ) {
			$id = (string) ( $finding['id'] ?? '' );
			if ( '' !== $id ) {
				$seen[ $id ] = true;
			}

			if ( '' === $id || ! $this->is_baseline_candidate( $finding ) ) {
				$resolved[] = $finding;
				continue;
			}

			$meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
			if ( array_key_exists( 'baseline_comparison_complete', $meta ) ) {
				// The resumable plugin/theme scanner already compared every file against
				// the approved manifest. Finalization must never re-read that tree.
				$resolved[] = $finding;
				continue;
			}

			if ( ! isset( $entries[ $id ] ) ) {
				$finding['status']       = 'baseline_required';
				$finding['severity']     = 'warning';
				$finding['message']      = __( 'This component is not part of an approved per-file local baseline yet.', 'core-blueprint' );
				$finding['verification'] = $this->local_baseline_verification();
				$resolved[] = $finding;
				continue;
			}

			// A manifest exists but the scanner did not provide a file-level comparison.
			// Treat that as incomplete rather than silently falling back to synchronous
			// hashing or declaring the component unchanged from an aggregate fingerprint.
			$finding['status']       = 'scan_incomplete';
			$finding['severity']     = 'warning';
			$finding['message']      = __( 'An approved local baseline exists, but this scan did not produce a complete file-level comparison.', 'core-blueprint' );
			$finding['verification'] = $this->local_baseline_verification();
			$resolved[] = $finding;
		}

		foreach ( $entries as $id => $entry ) {
			if ( isset( $seen[ $id ] ) || ! is_array( $entry ) ) {
				continue;
			}

			$type = (string) ( $entry['type'] ?? '' );
			if ( ! $this->component_is_enabled( $type, $components ) ) {
				continue;
			}

			$target = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
			$resolved[] = Finding::make( [
				'id'           => (string) $id,
				'type'         => $type,
				'target'       => $target,
				'status'       => 'missing',
				'severity'     => 'critical',
				'message'      => __( 'This baseline-approved component was not found in the current scan.', 'core-blueprint' ),
				'meta'         => is_array( $entry['meta'] ?? null ) ? $entry['meta'] : [],
				'verification' => $this->local_baseline_verification(),
			] );
		}

		return $resolved;
	}

	private function local_baseline_verification(): array {
		return [
			'method'     => 'local_baseline',
			'source'     => 'approved_local_baseline',
			'confidence' => 'medium',
			'label'      => __( 'Compared against approved local baseline', 'core-blueprint' ),
			'scope'      => 'component',
		];
	}

	private function is_baseline_candidate( array $finding ): bool {
		$verification = is_array( $finding['verification'] ?? null ) ? $finding['verification'] : [];

		return in_array( (string) ( $finding['status'] ?? '' ), [ 'baseline_required', 'verification_failed', 'new', 'changed' ], true )
			&& in_array( (string) ( $finding['type'] ?? '' ), [ 'plugin', 'theme' ], true )
			&& 'local_baseline' === (string) ( $verification['method'] ?? '' )
			&& 'component' === (string) ( $verification['scope'] ?? '' );
	}

	private function fingerprint_hash_from_finding( array $finding ): string {
		$meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		return (string) ( $meta['fingerprint_hash'] ?? '' );
	}

	private function component_is_enabled( string $type, array $components ): bool {
		$key = match ( $type ) {
			'plugin' => 'plugins',
			'theme'  => 'themes',
			'uploads' => 'uploads',
			default  => $type,
		};

		return 'skipped' !== (string) ( $components[ $key ] ?? '' );
	}

	private function components_from_findings( array $components, array $findings ): array {
		foreach ( array_keys( $components ) as $component ) {
			if ( 'skipped' === $components[ $component ] ) {
				continue;
			}

			$components[ $component ] = 'ok';
		}

		foreach ( $findings as $finding ) {
			$type = (string) ( $finding['type'] ?? '' );
			$key  = match ( $type ) {
				'plugin' => 'plugins',
				'theme'  => 'themes',
				'uploads' => 'uploads',
				default  => $type,
			};

			if ( ! isset( $components[ $key ] ) || 'skipped' === $components[ $key ] ) {
				continue;
			}

			$severity = SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) );
			$components[ $key ] = SeverityMapper::highest( (string) $components[ $key ], $severity );
		}

		return $components;
	}

	private function summarize( array $findings ): array {
		$summary = [
			'total'    => 0,
			'ok'       => 0,
			'warning'  => 0,
			'critical' => 0,
		];

		foreach ( $findings as $finding ) {
			$severity = SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) );
			$summary[ $severity ]++;
			$summary['total']++;
		}

		return $summary;
	}

	private function status_from_summary( array $summary ): string {
		if ( (int) ( $summary['critical'] ?? 0 ) > 0 ) {
			return 'critical';
		}
		if ( (int) ( $summary['warning'] ?? 0 ) > 0 ) {
			return 'warning';
		}
		return 'ok';
	}


	private function audit_incident_lifecycle( array $lifecycle, string $source ): void {
		$new         = is_array( $lifecycle['new'] ?? null ) ? $lifecycle['new'] : [];
		$changed     = is_array( $lifecycle['changed'] ?? null ) ? $lifecycle['changed'] : [];
		$resolved    = is_array( $lifecycle['resolved'] ?? null ) ? $lifecycle['resolved'] : [];
		$unconfirmed = is_array( $lifecycle['unconfirmed'] ?? null ) ? $lifecycle['unconfirmed'] : [];

		// Keep critical and warning incidents on separate audit/notification
		// events. Their Preferences toggles are independent; a critical finding
		// in the same scan must not swallow warning-only notifications (or vice
		// versa) by collapsing both severities into one umbrella event.
		$incidents = array_merge( $new, $changed );
		$critical  = [];
		$warnings  = [];
		foreach ( $incidents as $finding ) {
			if ( 'critical' === SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'warning' ) ) ) {
				$critical[] = $finding;
			} else {
				$warnings[] = $finding;
			}
		}

		$this->audit_incident_group( 'integrity_scan_critical_anomalies_detected', 'critical', $critical, $new, $changed, $source );
		$this->audit_incident_group( 'integrity_scan_warning_anomalies_detected', 'warning', $warnings, $new, $changed, $source );

		if ( [] !== $resolved ) {
			$resolved_ids = array_values( array_filter( array_map( static fn ( array $finding ): string => (string) ( $finding['id'] ?? '' ), $resolved ) ) );
			$this->audit( 'integrity_scan_anomalies_resolved', 'notice', [
				'source'         => sanitize_key( $source ),
				'resolved_count' => count( $resolved ),
				'incident_key'   => hash( 'sha256', (string) wp_json_encode( $resolved_ids ) ),
				'paths'          => array_values( array_filter( array_slice( array_map( [ $this, 'finding_path' ], $resolved ), 0, 10 ) ) ),
			] );
		}

		if ( [] !== $unconfirmed ) {
			$this->audit( 'integrity_scan_anomaly_resolution_unconfirmed', 'notice', [
				'source'            => sanitize_key( $source ),
				'unconfirmed_count' => count( $unconfirmed ),
			] );
		}
	}

	/** @param array<int,array<string,mixed>> $incidents */
	private function audit_incident_group( string $event_type, string $severity, array $incidents, array $new, array $changed, string $source ): void {
		if ( [] === $incidents ) {
			return;
		}

		$ids = array_values( array_filter( array_map( static fn ( array $finding ): string => (string) ( $finding['id'] ?? '' ), $incidents ) ) );
		$new_ids = array_fill_keys( array_values( array_filter( array_map( static fn ( array $finding ): string => (string) ( $finding['id'] ?? '' ), $new ) ) ), true );
		$changed_ids = array_fill_keys( array_values( array_filter( array_map( static fn ( array $finding ): string => (string) ( $finding['id'] ?? '' ), $changed ) ) ), true );
		$new_count = 0;
		$changed_count = 0;
		foreach ( $ids as $id ) {
			$new_count += isset( $new_ids[ $id ] ) ? 1 : 0;
			$changed_count += isset( $changed_ids[ $id ] ) ? 1 : 0;
		}

		$this->audit( $event_type, $severity, [
			'source'        => sanitize_key( $source ),
			'new_count'     => $new_count,
			'changed_count' => $changed_count,
			'incident_key'  => hash( 'sha256', (string) wp_json_encode( $ids ) ),
			'paths'         => array_values( array_filter( array_slice( array_map( [ $this, 'finding_path' ], $incidents ), 0, 10 ) ) ),
		] );
	}

	private function finding_path( array $finding ): string {
		$target = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
		$root   = rtrim( (string) ( $target['path'] ?? '' ), '/' );
		$file   = ltrim( (string) ( $target['file'] ?? '' ), '/' );

		if ( '' === $file ) {
			return $root;
		}

		return '' === $root || '.' === $root ? $file : $root . '/' . $file;
	}

	private function audit( string $event_type, string $severity, array $context = [] ): void {
		Audit::log( $event_type, $severity, $context );
	}
}
