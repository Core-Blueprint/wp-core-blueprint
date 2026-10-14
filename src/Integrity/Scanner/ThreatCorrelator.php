<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Support\Finding;

/**
 * Correlate independent, passive filesystem observations. A link is only
 * reported when the loader source literally references the flagged filename.
 * This never asserts that a payload was run, nor does it move files.
 */
final class ThreatCorrelator {
	public static function correlate( array $findings ): array {
		$payloads = [];
		$loaders = [];
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$status = (string) ( $finding['status'] ?? '' );
			$type = (string) ( $finding['type'] ?? '' );
			$target = (array) ( $finding['target'] ?? [] );
			if ( 'uploads' === $type && 'disguised_executable' === $status ) {
				$name = strtolower( basename( (string) ( $target['file'] ?? '' ) ) );
				if ( '' !== $name ) {
					$payloads[ $name ][] = $finding;
				}
			} elseif ( 'mu_plugin' === $type && 'suspicious_loader_chain' === $status ) {
				$loaders[] = $finding;
			}
		}
		foreach ( $loaders as $loader ) {
			$meta = (array) ( $loader['meta'] ?? [] );
			foreach ( (array) ( $meta['referenced_media'] ?? [] ) as $referenced ) {
				$name = strtolower( basename( (string) $referenced ) );
				// Same-name files in different upload folders cannot be linked reliably.
				if ( count( (array) ( $payloads[ $name ] ?? [] ) ) !== 1 ) {
					continue;
				}
				foreach ( (array) ( $payloads[ $name ] ?? [] ) as $payload ) {
					$loader_target = (array) ( $loader['target'] ?? [] );
					$payload_target = (array) ( $payload['target'] ?? [] );
					$findings[] = Finding::make( [
						'type' => 'mu_plugin',
						'status' => 'linked_execution_chain',
						'severity' => 'critical',
						'target' => $loader_target,
						'meta' => [
							'identity' => 'linked-execution-' . hash( 'sha256', (string) ( $payload['id'] ?? '' ) ),
							'loader_path' => (string) ( $loader_target['file'] ?? '' ),
							'payload_path' => (string) ( $payload_target['path'] ?? '' ) . (string) ( $payload_target['file'] ?? '' ),
							'payload_sha256' => (string) ( $payload['meta']['actual_sha256'] ?? '' ),
							'detection_method' => 'literal_file_reference_correlation',
						],
						'verification' => [
							'method' => 'correlation',
							'source' => 'local_filesystem',
							'confidence' => 'high',
							'label' => __( 'Correlated loader and executable-format evidence', 'core-blueprint' ),
						],
						'message' => __( 'MU-plugin loader references an upload with a confirmed executable signature. This is a correlated execution risk, not proof that it ran.', 'core-blueprint' ),
					] );
				}
			}
		}
		return $findings;
	}
}
