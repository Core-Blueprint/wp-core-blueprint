<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Support;

use function sanitize_key;

defined( 'ABSPATH' ) || exit;

final class SeverityMapper {
	public static function normalize( string $severity ): string {
		$severity = sanitize_key( $severity );

		return match ( $severity ) {
			'critical' => 'critical',
			'warning'  => 'warning',
			default    => 'ok',
		};
	}

	public static function from_status( string $status ): string {
		$status = sanitize_key( $status );

		return match ( $status ) {
			'critical', 'missing_core_file', 'invalid_path', 'path_escape', 'suspicious_pattern', 'error', 'failed' => 'critical',
			'modified', 'missing', 'changed', 'new', 'unexpected', 'unexpected_root_executable', 'unreadable', 'symlink_skipped', 'scan_incomplete', 'baseline_required', 'needs_baseline', 'verification_failed', 'unsupported', 'executable_upload', 'warning' => 'warning',
			default => 'ok',
		};
	}

	public static function highest( string $current, string $candidate ): string {
		$order = [
			'ok'       => 1,
			'warning'  => 2,
			'critical' => 3,
		];

		$current   = self::normalize( $current );
		$candidate = self::normalize( $candidate );

		return ( $order[ $candidate ] ?? 1 ) > ( $order[ $current ] ?? 1 ) ? $candidate : $current;
	}
}
