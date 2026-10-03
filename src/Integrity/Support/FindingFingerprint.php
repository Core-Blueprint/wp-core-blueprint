<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function hash;
use function is_array;
use function sanitize_key;
use function wp_json_encode;

/**
 * Stable material-state fingerprint for one integrity finding.
 *
 * Lifecycle consumers use this to distinguish an unchanged persistent anomaly
 * from the same path whose observed state materially changed between scans.
 * Presentation-only fields (message text, timestamps, lifecycle counters) are
 * intentionally excluded.
 */
final class FindingFingerprint {
	public static function for( array $finding ): string {
		$meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];

		$signal = [
			'id'               => (string) ( $finding['id'] ?? '' ),
			'status'           => sanitize_key( (string) ( $finding['status'] ?? '' ) ),
			'severity'         => SeverityMapper::normalize( (string) ( $finding['severity'] ?? 'ok' ) ),
			'actual_sha256'    => (string) ( $meta['actual_sha256'] ?? $meta['sha256'] ?? '' ),
			'actual_hash'      => (string) ( $meta['actual_hash'] ?? '' ),
			'expected_hash'    => (string) ( $meta['expected_hash'] ?? '' ),
			'fingerprint_hash' => (string) ( $meta['fingerprint_hash'] ?? '' ),
		];

		return hash( 'sha256', (string) wp_json_encode( $signal ) );
	}
}
