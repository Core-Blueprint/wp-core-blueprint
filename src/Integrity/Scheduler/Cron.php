<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scheduler;

use CoreBlueprint\Core\Integrity\Scanner\ScanJobRunner;
use CoreBlueprint\Core\Integrity\Scanner\ScanLockedException;
use CoreBlueprint\Core\Integrity\State;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Support\Audit;

use function bin2hex;
use function random_bytes;
use function time;
use function wp_clear_scheduled_hook;
use function wp_schedule_event;

defined( 'ABSPATH' ) || exit;

final class Cron {
	public const HOOK = 'cb_core_integrity_scan_run';

	public static function sync_schedule(): void {
		$settings = ResultRepository::settings();
		$schedule = (string) ( $settings['schedule'] ?? 'disabled' );

		// Converge first: a disabled Scanner must never retain stale scheduled
		// workload, even when sync_schedule() is called from settings maintenance.
		wp_clear_scheduled_hook( self::HOOK );

		if ( ! State::is_enabled() ) {
			return;
		}

		if ( 'daily' === $schedule || 'weekly' === $schedule ) {
			wp_schedule_event( time() + 300, $schedule, self::HOOK );
		}
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run_scheduled_scan(): void {
		if ( ! State::is_enabled() ) {
			return;
		}

		$job_id = 'cron_' . bin2hex( random_bytes( 8 ) );
		try {
			ScanJobRunner::start( 'cron', $job_id, 0, false );
			ScanJobRunner::run_slice( $job_id );
		} catch ( ScanLockedException $exception ) {
			Audit::log( 'integrity_scan_skipped_locked', 'notice', [ 'source' => 'cron', 'lock' => $exception->lock() ] );
		} catch ( \Throwable $throwable ) {
			Audit::log( 'integrity_scan_failed', 'critical', [ 'source' => 'cron', 'error' => $throwable->getMessage() ] );
		}
	}
}
