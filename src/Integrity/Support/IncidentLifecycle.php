<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function array_values;
use function is_array;
use function sanitize_key;

/**
 * Compares anomaly findings between two scans without making malware verdicts.
 *
 * Resolution is conservative: a previously observed anomaly is only considered
 * resolved when the current scan completed coverage for that finding's area.
 * If coverage is incomplete, disappearance means "unconfirmed", not "fixed".
 */
final class IncidentLifecycle {
	public function compare( ?array $previous, array $current ): array {
		$previous_map = $this->anomaly_map( $previous );
		$current_map  = $this->anomaly_map( $current );

		$out = [
			'has_previous' => is_array( $previous ),
			'new'          => [],
			'changed'      => [],
			'persistent'   => [],
			'resolved'     => [],
			'unconfirmed'  => [],
		];

		foreach ( $current_map as $id => $finding ) {
			if ( ! isset( $previous_map[ $id ] ) ) {
				$out['new'][] = $this->compact( $finding );
				continue;
			}

			if ( FindingFingerprint::for( $previous_map[ $id ] ) !== FindingFingerprint::for( $finding ) ) {
				$out['changed'][] = $this->compact( $finding );
				continue;
			}

			$out['persistent'][] = $this->compact( $finding );
		}

		foreach ( $previous_map as $id => $finding ) {
			if ( isset( $current_map[ $id ] ) ) {
				continue;
			}

			if ( $this->coverage_complete_for( $finding, $current ) ) {
				$out['resolved'][] = $this->compact( $finding );
			} else {
				$out['unconfirmed'][] = $this->compact( $finding );
			}
		}

		$out['counts'] = [
			'new'         => count( $out['new'] ),
			'changed'     => count( $out['changed'] ),
			'persistent'  => count( $out['persistent'] ),
			'resolved'    => count( $out['resolved'] ),
			'unconfirmed' => count( $out['unconfirmed'] ),
		];
		$out['has_new_incident'] = $out['counts']['new'] > 0 || $out['counts']['changed'] > 0;
		$out['has_resolution']    = $out['counts']['resolved'] > 0;

		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	private function anomaly_map( ?array $result ): array {
		if ( ! is_array( $result ) ) {
			return [];
		}

		$findings = is_array( $result['checks'] ?? null ) ? $result['checks'] : [];
		$out = [];
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}

			$severity = SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) );
			if ( 'ok' === $severity ) {
				continue;
			}

			$id = (string) ( $finding['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$out[ $id ] = $finding;
		}
		return $out;
	}

	private function coverage_complete_for( array $finding, array $current ): bool {
		$type = sanitize_key( (string) ( $finding['type'] ?? '' ) );
		$key = match ( $type ) {
			'plugin'  => 'plugins',
			'theme'   => 'themes',
			'uploads' => 'uploads',
			default   => $type,
		};

		$coverage = is_array( $current['coverage'] ?? null ) ? $current['coverage'] : [];
		$component = is_array( $coverage[ $key ] ?? null ) ? $coverage[ $key ] : [];
		return 'complete' === (string) ( $component['state'] ?? '' );
	}

	private function compact( array $finding ): array {
		$target    = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
		$meta      = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$lifecycle = is_array( $finding['lifecycle'] ?? null ) ? $finding['lifecycle'] : [];

		return [
			'id'        => (string) ( $finding['id'] ?? '' ),
			'type'      => (string) ( $finding['type'] ?? '' ),
			'target'    => $target,
			'status'    => (string) ( $finding['status'] ?? '' ),
			'severity'  => SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) ),
			'meta'      => [ 'filesystem_path' => (string) ( $meta['filesystem_path'] ?? '' ) ],
			'lifecycle' => $lifecycle,
		];
	}
}
