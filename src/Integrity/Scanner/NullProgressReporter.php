<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * No-op progress reporter.
 *
 * Used by scan paths that do not have a UI to report to:
 *   - WP cron-triggered scheduled scans
 *   - Hub-bound REST scan endpoint (Hub does its own polling)
 *   - Test runs
 *
 * Audit-log entries (`integrity_scan_started`, `integrity_scan_completed`,
 * `integrity_scan_failed`) are written by the engine regardless of the
 * reporter - so cron-triggered scans remain observable through the audit
 * log even though no progress signal is captured.
 *
 * @since   1.0.0
 */
final class NullProgressReporter implements ProgressReporter {
	public function start_phase( string $phase, int $items_total ): void {}
	public function tick( string $phase, int $items_done ): void {}
	public function complete_phase( string $phase ): void {}
	public function complete_scan( string $result_id ): void {}
	public function fail( string $error ): void {}
}
