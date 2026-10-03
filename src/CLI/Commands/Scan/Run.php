<?php
declare(strict_types=1);
/**
 * Scan\Run - `wp cb scan run` (write-action).
 *
 * Triggers an async Core Scanner integrity scan via the same
 * ASYNC_SCAN_HOOK the REST endpoint uses, optionally blocking until
 * completion when --wait is passed.
 *
 * execute() (Console) schedules the shared resumable job and returns an
 * async-poll Result. WP-CLI does the same by default; --wait drives those same
 * persisted scan slices in the foreground without depending on cron.
 * Browser Console records its authenticated WordPress operator. Trusted
 * server-side WP-CLI records user id 0 rather than accepting a caller-selected
 * WordPress identity as scan provenance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Scan;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Integrity\Scanner\ScanJobDispatcher;
use CoreBlueprint\Core\Integrity\Scanner\ScanJobRunner;
use CoreBlueprint\Core\Integrity\Scanner\ScanJobStatus;
use CoreBlueprint\Core\Integrity\Scanner\ScanLockedException;
use CoreBlueprint\Core\Integrity\State as IntegrityState;

defined( 'ABSPATH' ) || exit;

final class Run implements CommandInterface {

	public function execute( array $args ): Result {
		if ( ! class_exists( IntegrityState::class ) ) {
			return Result::error(
				__( 'Core Scanner subsystem not available on this site.', 'core-blueprint' )
			);
		}

		if ( ! IntegrityState::is_enabled() ) {
			return Result::error(
				__( 'Core Scanner is disabled. Enable it in Safeguards › Core Scanner first.', 'core-blueprint' )
			);
		}

		// Check for concurrent scans - refuse to start a second one.
		$active_job = ScanJobStatus::active_job();
		if ( null !== $active_job ) {
			$active = ScanJobStatus::progress_from_job( $active_job );
			return Result::warning(
				sprintf(
					/* translators: 1: job id, 2: status */
					__( 'A scan is already running (job %1$s, status %2$s). Wait for it to finish or refresh the page to track its progress.', 'core-blueprint' ),
					(string) ( $active['job_id'] ?? '?' ),
					(string) ( $active['status'] ?? 'running' )
				),
				[ 'A scan is already in progress. Refresh to track its progress.' ],
				[ 'concurrent' => true, 'active_job' => $active ]
			);
		}

		// Browser Console records its authenticated, explicitly selected operator.
		$user_ref = (string) ( $args['user'] ?? '' );
		$user     = self::resolve_user( $user_ref );
		if ( null === $user ) {
			return Result::error(
				/* translators: %s: user reference entered on the command line. */
				sprintf( __( 'No user matches "%s" (tried ID, email, login).', 'core-blueprint' ), $user_ref )
			);
		}

		try {
			$queued = ScanJobDispatcher::dispatch( 'manual', (int) $user->ID, true );
		} catch ( ScanLockedException $locked ) {
			return Result::warning(
				__( 'A Core Scanner job is already running.', 'core-blueprint' ),
				[ $locked->getMessage() ],
				[ 'concurrent' => true, 'lock' => $locked->lock() ]
			);
		} catch ( \Throwable $throwable ) {
			return Result::error(
				__( 'Core Scanner could not queue the scan.', 'core-blueprint' ),
				[ $throwable->getMessage() ]
			);
		}

		$job_id = (string) $queued['job_id'];

		// Return Result with async flag - the Console JS detects this
		// and switches to polling-mode. The lines render immediately
		// in the output panel as the initial "scan scheduled" state.
		return Result::success(
			sprintf(
				/* translators: %s: user login */
				__( 'Scan scheduled (operator: %s). Polling for progress…', 'core-blueprint' ),
				$user->user_login
			),
			[
				sprintf( 'Scan job: %s', $job_id ),
				sprintf( 'Operator: %s (#%d)', $user->user_login, $user->ID ),
				'',
				'Polling progress every 1 second…',
			],
			[
				'async'      => true,
				'job_id'     => $job_id,
				'user_id'    => (int) $user->ID,
				'user_login' => $user->user_login,
			]
		);
	}

	public function args_schema(): array {
		return [
			'user' => [
				'type'     => 'user',
				'label'    => __( 'Operator', 'core-blueprint' ),
				'required' => true,
				'help'     => __( 'Search by login, email, or display name. Recorded as the scan operator in the audit log.', 'core-blueprint' ),
			],
			'wait' => [
				'type'    => 'boolean',
				'label'   => __( 'Block until completion (CLI only)', 'core-blueprint' ),
				'default' => false,
				'help'    => __( 'CLI-only flag. The Console always tracks progress live - this checkbox has no effect there.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Trigger a Core Scanner scan from trusted server-side WP-CLI.
	 *
	 * The terminal itself is the trusted execution context, so the persisted
	 * Scanner actor is user id 0. A WordPress user may not be supplied as
	 * attribution for this command.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Block until the scan completes (or fails).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb scan run
	 *     wp cb scan run --wait
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>     $args
	 * @param array<string, string>  $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! class_exists( IntegrityState::class ) ) {
			\WP_CLI::error( 'Core Scanner subsystem not available on this site.' );
		}
		if ( ! IntegrityState::is_enabled() ) {
			\WP_CLI::error( 'Core Scanner is disabled. Enable it in Safeguards › Core Scanner first.' );
		}

		$wait = ! empty( $assoc_args['wait'] );

		$active_job = ScanJobStatus::active_job();
		if ( null !== $active_job ) {
			$active = ScanJobStatus::progress_from_job( $active_job );
			\WP_CLI::warning( sprintf(
				'A scan is already running (job %s, status %s).',
				(string) ( $active['job_id'] ?? '?' ),
				(string) ( $active['status'] ?? 'running' )
			) );
			return;
		}

		if ( $wait ) {
			try {
				$job = ScanJobDispatcher::start_foreground( 'manual', 0, false );
				$job_id = (string) ( $job['job_id'] ?? '' );
				\WP_CLI::line( sprintf( 'Running resumable scan job %s in the foreground (operator: server CLI).', $job_id ) );
				ScanJobRunner::run_to_completion( $job_id );
			} catch ( ScanLockedException $locked ) {
				\WP_CLI::warning( $locked->getMessage() );
				return;
			} catch ( \Throwable $throwable ) {
				\WP_CLI::error( 'Scan failed: ' . $throwable->getMessage() );
			}

			\WP_CLI::success( 'Scan completed. Run `wp cb scan latest` for the result.' );
			return;
		}

		try {
			$queued = ScanJobDispatcher::dispatch( 'manual', 0, true );
		} catch ( ScanLockedException $locked ) {
			\WP_CLI::warning( $locked->getMessage() );
			return;
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( 'Could not queue Core Scanner: ' . $throwable->getMessage() );
		}

		$job_id = (string) $queued['job_id'];
		\WP_CLI::line( sprintf( 'Scheduled scan job %s (operator: server CLI).', $job_id ) );
		\WP_CLI::success( 'Scan scheduled. Run `wp cb scan latest` once it completes.' );
	}

	/**
	 * Resolve a user reference to a WP_User. Returns null when no match -
	 * Console-friendly.
	 *
	 * Precedence: numeric → ID; with @ → email; fall through → login.
	 */
	private static function resolve_user( string $ref ): ?\WP_User {
		if ( '' === trim( $ref ) ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			$u = get_userdata( (int) $ref );
			if ( $u ) {
				return $u;
			}
		}
		if ( false !== strpos( $ref, '@' ) ) {
			$u = get_user_by( 'email', $ref );
			if ( $u ) {
				return $u;
			}
		}
		$u = get_user_by( 'login', $ref );
		return $u ?: null;
	}
}
