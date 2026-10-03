<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function is_array;
use function max;

/**
 * Adds human-investigation lifecycle metadata to current anomaly findings.
 *
 * This is deliberately per incident streak, not a permanent malware registry:
 * once a finding is confirmed resolved, a later reappearance starts a fresh
 * first-detected timestamp. That keeps the metadata honest about what the
 * current scanner history can actually prove.
 */
final class FindingLifecycle {
	/**
	 * @param array<string,mixed>|null              $previous Previous completed scan.
	 * @param array<int,array<string,mixed>>        $findings Current normalized findings.
	 * @return array<int,array<string,mixed>>
	 */
	public function enrich( ?array $previous, array $findings, string $detected_at ): array {
		$previous_map = $this->anomaly_map( $previous );
		$previous_scan_time = is_array( $previous )
			? (string) ( $previous['completed_at'] ?? $previous['timestamp'] ?? '' )
			: '';

		foreach ( $findings as $index => $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}

			$severity = SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) );
			if ( 'ok' === $severity ) {
				unset( $finding['lifecycle'] );
				$findings[ $index ] = $finding;
				continue;
			}

			$id       = (string) ( $finding['id'] ?? '' );
			$previous_finding = '' !== $id && isset( $previous_map[ $id ] ) ? $previous_map[ $id ] : null;
			$previous_lifecycle = is_array( $previous_finding ) && is_array( $previous_finding['lifecycle'] ?? null )
				? $previous_finding['lifecycle']
				: [];

			if ( is_array( $previous_finding ) ) {
				$first = (string) ( $previous_lifecycle['first_detected_at'] ?? $previous_scan_time );
				if ( '' === $first ) {
					$first = $detected_at;
				}

				$changed = FindingFingerprint::for( $previous_finding ) !== FindingFingerprint::for( $finding );
				$last_changed = $changed
					? $detected_at
					: (string) ( $previous_lifecycle['last_changed_at'] ?? $first );

				$finding['lifecycle'] = [
					'first_detected_at' => $first,
					'last_detected_at'  => $detected_at,
					'last_changed_at'   => '' !== $last_changed ? $last_changed : $detected_at,
					'observations'      => max( 1, (int) ( $previous_lifecycle['observations'] ?? 1 ) ) + 1,
					'state'             => $changed ? 'changed' : 'persistent',
				];
			} else {
				$finding['lifecycle'] = [
					'first_detected_at' => $detected_at,
					'last_detected_at'  => $detected_at,
					'last_changed_at'   => $detected_at,
					'observations'      => 1,
					'state'             => 'new',
				];
			}

			$findings[ $index ] = $finding;
		}

		return $findings;
	}

	/** @return array<string,array<string,mixed>> */
	private function anomaly_map( ?array $result ): array {
		if ( ! is_array( $result ) ) {
			return [];
		}

		$raw = is_array( $result['checks'] ?? null ) ? $result['checks'] : [];
		$out = [];

		foreach ( $raw as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			if ( 'ok' === SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) ) ) {
				continue;
			}
			$id = (string) ( $finding['id'] ?? '' );
			if ( '' !== $id ) {
				$out[ $id ] = $finding;
			}
		}

		return $out;
	}
}
