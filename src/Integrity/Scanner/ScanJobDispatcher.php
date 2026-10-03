<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Bootstrap;
use RuntimeException;

use function bin2hex;
use function defined;
use function function_exists;
use function microtime;
use function random_bytes;
use function spawn_cron;
use function time;
use function wp_schedule_single_event;

/**
 * Canonical entry point for dispatching resumable Core Scanner jobs.
 *
 * Browser, Console and integration callers all use the same lock, persistent
 * job state and scheduling semantics. DISABLE_WP_CRON is not interpreted as
 * "cron unavailable": hosts may intentionally disable loopback cron while a
 * real system cron executes due events. It only suppresses the opportunistic
 * spawn_cron() kick.
 */
final class ScanJobDispatcher {
	/**
	 * Queue one resumable job.
	 *
	 * @return array{async:true,job_id:string,started_at:float}
	 * @throws ScanLockedException When another scan owns the global scan lock.
	 * @throws RuntimeException    When the initial continuation cannot be queued.
	 */
	public static function dispatch( string $source, int $user_id = 0, bool $progress_enabled = true ): array {
		$job_id     = 'scan_' . bin2hex( random_bytes( 8 ) );
		$started_at = microtime( true );

		ScanJobRunner::start( $source, $job_id, $user_id, $progress_enabled );
		if ( $progress_enabled ) {
			new TransientProgressReporter( $job_id, $user_id );
		}

		$scheduled = wp_schedule_single_event(
			time(),
			Bootstrap::ASYNC_SCAN_HOOK,
			[ $job_id, $user_id ]
		);

		if ( true !== $scheduled ) {
			ScanJobRunner::cancel( $job_id );
			if ( $progress_enabled ) {
				TransientProgressReporter::clear( $job_id );
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException(
				__( 'Core Scanner could not schedule its first scan batch. No partial result was published. Verify WordPress cron or the server cron runner and try again.', 'core-blueprint' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		self::kick_cron_if_allowed();

		return [
			'async'      => true,
			'job_id'     => $job_id,
			'started_at' => $started_at,
		];
	}

	/**
	 * Start a resumable job without cron dispatch, intended for a foreground CLI.
	 * The caller may then use ScanJobRunner::run_to_completion().
	 */
	public static function start_foreground( string $source, int $user_id = 0, bool $progress_enabled = false ): array {
		$job_id = 'scan_' . bin2hex( random_bytes( 8 ) );
		$job    = ScanJobRunner::start( $source, $job_id, $user_id, $progress_enabled );
		if ( $progress_enabled ) {
			new TransientProgressReporter( $job_id, $user_id );
		}
		return $job;
	}

	public static function kick_cron_if_allowed(): void {
		if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}
}
