<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use CoreBlueprint\Core\Integrity\Bootstrap;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Storage\StorageSchema;
use CoreBlueprint\Core\Integrity\Support\Audit;
use CoreBlueprint\Core\Integrity\Support\Finding;
use Throwable;

use function array_merge;
use function count;
use function current_time;
use function function_exists;
use function get_plugins;
use function is_array;
use function max;
use function microtime;
use function round;
use function sanitize_key;
use function sprintf;
use function time;
use function wp_get_themes;
use function wp_clear_scheduled_hook;
use function wp_next_scheduled;
use function wp_schedule_single_event;

use const ABSPATH;

/**
 * Resumable Core Scanner job orchestrator.
 *
 * A complete scan may span many PHP requests. Work budgets limit one execution
 * slice, never the total number of files/components that may be inspected.
 * ScannerLock ownership is retained for the entire job and refreshed per slice.
 */
final class ScanJobRunner {
	private const SLICE_SECONDS       = 8.0;
	private const UPLOAD_FILE_BUDGET  = 1000;
	private const UPLOAD_TIME_BUDGET  = 4.0;

	private static bool $shutdown_kick_registered = false;

	/** Create a persisted scan job and acquire the long-lived scan lock. */
	public static function start( string $source, string $job_id, int $started_by_user_id = 0, bool $progress_enabled = false ): array {
		$source = sanitize_key( $source );
		$job_id = sanitize_key( $job_id );
		if ( '' === $job_id ) {
			throw new \InvalidArgumentException( 'Missing integrity scan job id.' );
		}

		$lock_token = ScannerLock::acquire( $source, $job_id );

		try {
			$settings = ResultRepository::settings();
			$job = [
				'storage_schema'     => StorageSchema::VERSION,
				'job_id'             => $job_id,
				'source'             => '' !== $source ? $source : 'manual',
				'started_by_user_id' => $started_by_user_id,
				'progress_enabled'   => $progress_enabled,
				'lock_token'         => $lock_token,
				'status'             => 'running',
				'phase'              => 'core',
				'started_at'         => current_time( 'mysql' ),
				'started_at_micro'   => microtime( true ),
				'updated_at_micro'   => microtime( true ),
				'phase_started_micro'=> microtime( true ),
				'settings'           => $settings,
				'core_state'         => [],
				'plugin_files'       => self::plugin_files(),
				'plugin_index'       => 0,
				'plugin_state'       => [],
				'theme_stylesheets'  => self::theme_stylesheets(),
				'theme_index'        => 0,
				'theme_state'        => [],
				'uploads_state'      => [],
				'checks'             => [],
				'components'         => [],
				'coverage'           => [],
				'phase_durations'    => [],
			];

			ScanJobRepository::save( $job );
			Audit::log( 'integrity_scan_started', 'notice', [ 'source' => $source, 'job_id' => $job_id ] );
			return $job;
		} catch ( Throwable $throwable ) {
			ScannerLock::release( $lock_token );
			throw $throwable;
		}
	}

	/** Cancel a persisted job and release its lock. */
	public static function cancel( string $job_id, string $message = '' ): void {
		$job = ScanJobRepository::get_by_id( sanitize_key( $job_id ) );
		if ( ! is_array( $job ) ) {
			return;
		}
		if ( '' !== $message ) {
			self::reporter( $job )->fail( $message );
		} elseif ( ! empty( $job['progress_enabled'] ) ) {
			// Silent cancellation (for example plugin deactivation) must not
			// leave a running progress transient that can reappear after reload.
			TransientProgressReporter::clear( (string) ( $job['job_id'] ?? '' ) );
		}
		self::clear_pending_continuations( $job );
		ScannerLock::release( (string) ( $job['lock_token'] ?? '' ) );
		ScanJobRepository::clear( (string) ( $job['job_id'] ?? '' ) );
	}

	/** Cancel whichever resumable scan is currently persisted, if any. */
	public static function cancel_active( string $message = '' ): void {
		$job = ScanJobRepository::get();
		if ( ! is_array( $job ) ) {
			return;
		}
		self::cancel( (string) ( $job['job_id'] ?? '' ), $message );
	}

	/**
	 * Run one bounded execution slice. Returns true when the job completed.
	 */
	public static function run_slice( string $job_id, bool $schedule_continuation = true ): bool {
		$job = ScanJobRepository::get_by_id( sanitize_key( $job_id ) );
		if ( ! is_array( $job ) ) {
			return true;
		}

		$job_id = (string) ( $job['job_id'] ?? '' );
		$slice_token = ScanSliceLock::acquire( $job_id );
		if ( null === $slice_token ) {
			// A duplicate/overlapping worker is already processing this exact job.
			// Do not touch its cursor. Leave a watchdog behind so a crashed owner
			// cannot consume the only continuation and strand the scan forever.
			if ( $schedule_continuation ) {
				self::ensure_watchdog( $job );
			}
			return false;
		}

		try {
			$token = (string) ( $job['lock_token'] ?? '' );
			if ( ! ScannerLock::is_owned_by( $token ) ) {
				self::fail( $job, __( 'Core Scanner lost ownership of its scan lock. The scan was stopped to avoid inconsistent results.', 'core-blueprint' ) );
				return true;
			}

			if ( $schedule_continuation && ! self::ensure_watchdog( $job ) ) {
				self::fail( $job, __( 'Core Scanner could not schedule its recovery watchdog. The partial scan was not published as a completed result.', 'core-blueprint' ) );
				return true;
			}

			ScannerLock::refresh( $token );
			$reporter      = self::reporter( $job );
			$slice_started = microtime( true );

			try {
				while ( microtime( true ) - $slice_started < self::SLICE_SECONDS ) {
					$phase = (string) ( $job['phase'] ?? 'core' );

					if ( 'core' === $phase ) {
						if ( self::run_core_step( $job, $reporter ) ) {
							self::save_and_refresh( $job );
							continue;
						}
						self::save_and_refresh( $job );
						break;
					}

					if ( 'plugins' === $phase ) {
						if ( self::run_plugin_step( $job, $reporter ) ) {
							self::save_and_refresh( $job );
							continue;
						}
						self::save_and_refresh( $job );
						break;
					}

					if ( 'themes' === $phase ) {
						if ( self::run_theme_step( $job, $reporter ) ) {
							self::save_and_refresh( $job );
							continue;
						}
						self::save_and_refresh( $job );
						break;
					}

					if ( 'uploads' === $phase ) {
						$done = self::run_uploads_step( $job, $reporter );
						self::save_and_refresh( $job );
						if ( $done ) {
							continue;
						}
						break;
					}

					if ( 'finalize' === $phase ) {
						self::assert_job_current( $job );
						if ( class_exists( \CoreBlueprint\Core\Integrity\State::class ) && ! \CoreBlueprint\Core\Integrity\State::is_enabled() ) {
							throw new \RuntimeException( __( 'Core Scanner was disabled before finalization. No completed result was published.', 'core-blueprint' ) );
						}
						$result = ( new ScannerEngine() )->finalize_job_result( $job, $reporter );
						$job['status']    = 'done';
						$job['result_id'] = (string) ( $result['timestamp'] ?? '' );
						self::clear_pending_continuations( $job );
						ScanJobRepository::clear( (string) $job['job_id'] );
						ScannerLock::release( $token );
						return true;
					}

					throw new \RuntimeException( sprintf( 'Unknown Core Scanner job phase: %s', $phase ) );
				}
			} catch ( Throwable $throwable ) {
				self::fail( $job, $throwable->getMessage() );
				return true;
			}

			if ( ! $schedule_continuation ) {
				return false;
			}

			if ( ! self::schedule_continuation( $job ) ) {
				self::fail( $job, __( 'Core Scanner could not schedule the next scan batch. The partial scan was not published as a completed result.', 'core-blueprint' ) );
				return true;
			}
			return false;
		} finally {
			ScanSliceLock::release( $slice_token );
		}
	}

	/**
	 * Drive a persisted job to completion in the current process without cron.
	 *
	 * This is primarily for WP-CLI --wait. It preserves the exact same resumable
	 * phase/cursor model as async jobs while avoiding a dependency on loopback or
	 * external cron execution.
	 */
	public static function run_to_completion( string $job_id ): void {
		while ( ! self::run_slice( $job_id, false ) ) {
			// State is persisted between slices; continue immediately in CLI.
		}
	}

	/** Return true when caller may continue within this same execution slice. */
	private static function run_core_step( array &$job, ProgressReporter $reporter ): bool {
		if ( empty( $job['core_state'] ) ) {
			$reporter->start_phase( 'core', 0 );
			$job['phase_started_micro'] = microtime( true );
		}

		try {
			$result = ( new CoreScanner() )->scan_batch(
				is_array( $job['core_state'] ?? null ) ? $job['core_state'] : [],
				750,
				4.0
			);
			$job['core_state'] = is_array( $result['state'] ?? null ) ? $result['state'] : [];
			$job['coverage']['core'] = is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [ 'state' => 'incomplete', 'reason' => 'coverage_missing' ];
			$job['checks'] = array_merge( (array) $job['checks'], (array) ( $result['checks'] ?? [] ) );
			$job['components']['core'] = (string) ( $result['status'] ?? 'failed' );
			$reporter->tick( 'core', self::core_progress_count( (array) $job['coverage']['core'] ) );

			if ( empty( $result['done'] ) ) {
				return false;
			}
		} catch ( Throwable $throwable ) {
			$job['components']['core'] = 'failed';
			$job['coverage']['core']   = [ 'state' => 'failed', 'reason' => 'scanner_exception' ];
			/* translators: %s: exception message from the core checksum scan. */
			$job['checks'][] = self::error_finding( 'core', 'wordpress-core', __( 'WordPress Core', 'core-blueprint' ), './', sprintf( __( 'Core checksum scan failed: %s', 'core-blueprint' ), $throwable->getMessage() ) );
		}

		$job['phase_durations']['core'] = round( max( 0.0, microtime( true ) - (float) ( $job['phase_started_micro'] ?? microtime( true ) ) ), 3 );
		$reporter->complete_phase( 'core' );
		self::advance_phase( $job, 'plugins' );
		return true;
	}

	private static function core_progress_count( array $coverage ): int {
		return max( 0, (int) ( $coverage['verified_files'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['modified_files'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['missing_files'] ?? 0 ) )
			+ max( 0, (int) ( $coverage['unexpected_files'] ?? 0 ) );
	}

	/** Return true when caller may continue within this same execution slice. */
	private static function run_plugin_step( array &$job, ProgressReporter $reporter ): bool {
		$settings = is_array( $job['settings'] ?? null ) ? $job['settings'] : [];
		$files    = is_array( $job['plugin_files'] ?? null ) ? $job['plugin_files'] : [];
		$index    = max( 0, (int) ( $job['plugin_index'] ?? 0 ) );

		if ( empty( $settings['plugin_checksums'] ) ) {
			$reporter->start_phase( 'plugins', 0 );
			$job['components']['plugins'] = 'skipped';
			$job['coverage']['plugins']   = [ 'state' => 'skipped' ];
			$job['phase_durations']['plugins'] = 0.0;
			$reporter->complete_phase( 'plugins' );
			self::advance_phase( $job, 'themes' );
			return true;
		}

		if ( 0 === $index && empty( $job['plugin_state'] ) ) {
			$reporter->start_phase( 'plugins', count( $files ) );
			$job['phase_started_micro'] = microtime( true );
		}

		if ( $index >= count( $files ) ) {
			self::finish_component_phase( $job, $reporter, 'plugins', 'themes' );
			return true;
		}

		$plugin_file = (string) $files[ $index ];
		try {
			$result = ( new PluginScanner() )->scan_component_batch(
				$plugin_file,
				is_array( $job['plugin_state'] ?? null ) ? $job['plugin_state'] : [],
				750,
				4.0
			);
			$job['plugin_state'] = is_array( $result['state'] ?? null ) ? $result['state'] : [];
			$job['checks'] = array_merge( (array) $job['checks'], (array) ( $result['checks'] ?? [] ) );

			if ( empty( $result['done'] ) ) {
				return false;
			}

			$job['coverage']['plugins'] = self::merge_coverage(
				(array) ( $job['coverage']['plugins'] ?? [] ),
				is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [ 'state' => 'incomplete', 'reason' => 'coverage_missing' ]
			);
			$job['components']['plugins'] = self::merge_status(
				(string) ( $job['components']['plugins'] ?? 'ok' ),
				(string) ( $result['status'] ?? 'failed' )
			);
		} catch ( Throwable $throwable ) {
			/* translators: 1: plugin file, 2: exception message from the checksum scan. */
			$job['checks'][] = self::error_finding( 'plugin', $plugin_file, $plugin_file, 'wp-content/plugins/', sprintf( __( 'Plugin checksum scan failed for %1$s: %2$s', 'core-blueprint' ), $plugin_file, $throwable->getMessage() ) );
			$job['coverage']['plugins'] = self::merge_coverage( (array) ( $job['coverage']['plugins'] ?? [] ), [ 'state' => 'incomplete', 'filesystem_errors' => 1 ] );
		}

		$job['plugin_state'] = [];
		$job['plugin_index'] = $index + 1;
		$reporter->tick( 'plugins', $index + 1 );

		if ( $index + 1 >= count( $files ) ) {
			self::finish_component_phase( $job, $reporter, 'plugins', 'themes' );
		}
		return true;
	}

	private static function run_theme_step( array &$job, ProgressReporter $reporter ): bool {
		$settings    = is_array( $job['settings'] ?? null ) ? $job['settings'] : [];
		$stylesheets = is_array( $job['theme_stylesheets'] ?? null ) ? $job['theme_stylesheets'] : [];
		$index       = max( 0, (int) ( $job['theme_index'] ?? 0 ) );

		if ( empty( $settings['theme_checksums'] ) ) {
			$reporter->start_phase( 'themes', 0 );
			$job['components']['themes'] = 'skipped';
			$job['coverage']['themes']   = [ 'state' => 'skipped' ];
			$job['phase_durations']['themes'] = 0.0;
			$reporter->complete_phase( 'themes' );
			self::advance_phase( $job, 'uploads' );
			return true;
		}

		if ( 0 === $index && empty( $job['theme_state'] ) ) {
			$reporter->start_phase( 'themes', count( $stylesheets ) );
			$job['phase_started_micro'] = microtime( true );
		}

		if ( $index >= count( $stylesheets ) ) {
			self::finish_component_phase( $job, $reporter, 'themes', 'uploads' );
			return true;
		}

		$stylesheet = (string) $stylesheets[ $index ];
		try {
			$result = ( new ThemeScanner() )->scan_component_batch(
				$stylesheet,
				is_array( $job['theme_state'] ?? null ) ? $job['theme_state'] : [],
				750,
				4.0
			);
			$job['theme_state'] = is_array( $result['state'] ?? null ) ? $result['state'] : [];
			$job['checks'] = array_merge( (array) $job['checks'], (array) ( $result['checks'] ?? [] ) );

			if ( empty( $result['done'] ) ) {
				return false;
			}

			$job['coverage']['themes'] = self::merge_coverage(
				(array) ( $job['coverage']['themes'] ?? [] ),
				is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [ 'state' => 'incomplete', 'reason' => 'coverage_missing' ]
			);
			$job['components']['themes'] = self::merge_status(
				(string) ( $job['components']['themes'] ?? 'ok' ),
				(string) ( $result['status'] ?? 'failed' )
			);
		} catch ( Throwable $throwable ) {
			/* translators: 1: theme stylesheet, 2: exception message from the checksum scan. */
			$job['checks'][] = self::error_finding( 'theme', $stylesheet, $stylesheet, 'wp-content/themes/', sprintf( __( 'Theme checksum scan failed for %1$s: %2$s', 'core-blueprint' ), $stylesheet, $throwable->getMessage() ) );
			$job['coverage']['themes'] = self::merge_coverage( (array) ( $job['coverage']['themes'] ?? [] ), [ 'state' => 'incomplete', 'filesystem_errors' => 1 ] );
		}

		$job['theme_state'] = [];
		$job['theme_index'] = $index + 1;
		$reporter->tick( 'themes', $index + 1 );

		if ( $index + 1 >= count( $stylesheets ) ) {
			self::finish_component_phase( $job, $reporter, 'themes', 'uploads' );
		}
		return true;
	}

	private static function run_uploads_step( array &$job, ProgressReporter $reporter ): bool {
		$settings = is_array( $job['settings'] ?? null ) ? $job['settings'] : [];
		if ( empty( $settings['uploads_scan'] ) ) {
			$reporter->start_phase( 'uploads', 0 );
			$job['components']['uploads'] = 'skipped';
			$job['coverage']['uploads']   = [ 'state' => 'skipped' ];
			$job['phase_durations']['uploads'] = 0.0;
			$reporter->complete_phase( 'uploads' );
			self::advance_phase( $job, 'finalize' );
			return true;
		}

		if ( empty( $job['uploads_state'] ) ) {
			$reporter->start_phase( 'uploads', 0 );
			$job['phase_started_micro'] = microtime( true );
		}

		$batch = ( new UploadsScanner() )->scan_batch(
			is_array( $job['uploads_state'] ?? null ) ? $job['uploads_state'] : [],
			self::UPLOAD_FILE_BUDGET,
			self::UPLOAD_TIME_BUDGET
		);

		$job['uploads_state'] = is_array( $batch['state'] ?? null ) ? $batch['state'] : [];
		$job['coverage']['uploads'] = is_array( $batch['coverage'] ?? null ) ? $batch['coverage'] : [ 'state' => 'incomplete', 'reason' => 'coverage_missing' ];
		$job['checks'] = array_merge( (array) $job['checks'], (array) ( $batch['checks'] ?? [] ) );
		$files_done = (int) ( $job['coverage']['uploads']['files_inspected'] ?? 0 );
		$reporter->tick( 'uploads', $files_done );

		if ( empty( $batch['done'] ) ) {
			return false;
		}

		$job['components']['uploads'] = (string) ( $batch['status'] ?? 'ok' );
		$job['phase_durations']['uploads'] = round( max( 0.0, microtime( true ) - (float) ( $job['phase_started_micro'] ?? microtime( true ) ) ), 3 );
		$reporter->complete_phase( 'uploads' );
		self::advance_phase( $job, 'finalize' );
		return true;
	}

	private static function merge_component_result( array &$job, string $component, array $result ): void {
		$job['checks'] = array_merge( (array) $job['checks'], (array) ( $result['checks'] ?? [] ) );
		$job['coverage'][ $component ] = self::merge_coverage(
			(array) ( $job['coverage'][ $component ] ?? [] ),
			is_array( $result['coverage'] ?? null ) ? $result['coverage'] : [ 'state' => 'incomplete', 'reason' => 'coverage_missing' ]
		);
		$job['components'][ $component ] = self::merge_status(
			(string) ( $job['components'][ $component ] ?? 'ok' ),
			(string) ( $result['status'] ?? 'failed' )
		);
	}

	private static function merge_coverage( array $current, array $next ): array {
		if ( [] === $current ) {
			return $next;
		}

		$out = $current;
		foreach ( $next as $key => $value ) {
			if ( 'state' === $key ) {
				$out['state'] = self::merge_coverage_state( (string) ( $out['state'] ?? 'complete' ), (string) $value );
				continue;
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = (int) ( $out[ $key ] ?? 0 ) + (int) $value;
				continue;
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private static function merge_coverage_state( string $a, string $b ): string {
		$rank = [ 'skipped' => 0, 'complete' => 1, 'pending_baseline' => 2, 'incomplete' => 3, 'failed' => 4 ];
		return ( $rank[ $b ] ?? 3 ) > ( $rank[ $a ] ?? 3 ) ? $b : $a;
	}

	private static function merge_status( string $a, string $b ): string {
		$rank = [ 'skipped' => 0, 'ok' => 1, 'warning' => 2, 'critical' => 3, 'failed' => 4 ];
		return ( $rank[ $b ] ?? 4 ) > ( $rank[ $a ] ?? 4 ) ? $b : $a;
	}

	private static function finish_component_phase( array &$job, ProgressReporter $reporter, string $phase, string $next ): void {
		$job['phase_durations'][ $phase ] = round( max( 0.0, microtime( true ) - (float) ( $job['phase_started_micro'] ?? microtime( true ) ) ), 3 );
		if ( ! isset( $job['components'][ $phase ] ) ) {
			$job['components'][ $phase ] = 'ok';
		}
		if ( ! isset( $job['coverage'][ $phase ] ) ) {
			$job['coverage'][ $phase ] = [ 'state' => 'complete', 'components_total' => 0 ];
		}
		$reporter->complete_phase( $phase );
		self::advance_phase( $job, $next );
	}

	private static function advance_phase( array &$job, string $next ): void {
		$job['phase'] = $next;
		$job['phase_started_micro'] = microtime( true );
	}

	private static function save_and_refresh( array &$job ): void {
		self::assert_job_current( $job );
		$job['updated_at_micro'] = microtime( true );
		ScanJobRepository::save( $job );
		if ( ! ScannerLock::refresh( (string) ( $job['lock_token'] ?? '' ) ) ) {
			throw new \RuntimeException( 'Core Scanner could not refresh ownership of its scan lock.' );
		}
	}

	/** Ensure a delayed continuation exists before a slice starts doing work. */
	private static function ensure_watchdog( array $job ): bool {
		$args = self::continuation_args( $job );
		if ( false !== wp_next_scheduled( Bootstrap::ASYNC_SCAN_HOOK, $args ) ) {
			return true;
		}

		return true === wp_schedule_single_event( time() + 30, Bootstrap::ASYNC_SCAN_HOOK, $args );
	}

	private static function schedule_continuation( array $job ): bool {
		$args = self::continuation_args( $job );

		// Replace the delayed watchdog with an immediate continuation. If the
		// clear fails but an event still exists, that watchdog remains a valid
		// recovery path and the job must not be failed merely for running later.
		wp_clear_scheduled_hook( Bootstrap::ASYNC_SCAN_HOOK, $args );
		if ( true !== wp_schedule_single_event( time(), Bootstrap::ASYNC_SCAN_HOOK, $args ) ) {
			if ( false === wp_next_scheduled( Bootstrap::ASYNC_SCAN_HOOK, $args ) ) {
				return false;
			}
		}

		// WP-Cron normally needs a later page request to notice a newly queued
		// continuation. Chained scan jobs must also progress on low-traffic sites,
		// so kick cron again at shutdown - after wp-cron.php has released its own
		// doing_cron lock. The call is non-blocking inside spawn_cron().
		if ( ! self::$shutdown_kick_registered && function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			self::$shutdown_kick_registered = true;
			add_action( 'shutdown', [ self::class, 'kick_continuation_cron' ], PHP_INT_MAX );
		}
		return true;
	}

	private static function clear_pending_continuations( array $job ): void {
		wp_clear_scheduled_hook( Bootstrap::ASYNC_SCAN_HOOK, self::continuation_args( $job ) );
	}

	/** @return array{0:string,1:int} */
	private static function continuation_args( array $job ): array {
		return [
			(string) ( $job['job_id'] ?? '' ),
			(int) ( $job['started_by_user_id'] ?? 0 ),
		];
	}

	private static function assert_job_current( array $job ): void {
		$job_id = (string) ( $job['job_id'] ?? '' );
		$current = ScanJobRepository::get_by_id( $job_id );
		if (
			! is_array( $current )
			|| (string) ( $current['lock_token'] ?? '' ) !== (string) ( $job['lock_token'] ?? '' )
			|| ! ScannerLock::is_owned_by( (string) ( $job['lock_token'] ?? '' ) )
		) {
			throw new \RuntimeException( 'Core Scanner job is no longer authoritative and cannot publish state.' );
		}
	}

	public static function kick_continuation_cron(): void {
		if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}

	private static function fail( array $job, string $message ): void {
		$reporter = self::reporter( $job );
		$reporter->fail( $message );
		Audit::log( 'integrity_scan_failed', 'critical', [
			'source' => (string) ( $job['source'] ?? 'manual' ),
			'job_id' => (string) ( $job['job_id'] ?? '' ),
			'error'  => $message,
		] );
		self::clear_pending_continuations( $job );
		ScannerLock::release( (string) ( $job['lock_token'] ?? '' ) );
		ScanJobRepository::clear( (string) ( $job['job_id'] ?? '' ) );
	}

	private static function reporter( array $job ): ProgressReporter {
		if ( ! empty( $job['progress_enabled'] ) ) {
			return new TransientProgressReporter( (string) $job['job_id'], (int) ( $job['started_by_user_id'] ?? 0 ) );
		}
		return new NullProgressReporter();
	}

	private static function error_finding( string $component, string $slug, string $label, string $root, string $message ): array {
		return Finding::make( [
			'type'     => $component,
			'status'   => 'failed',
			'severity' => 'warning',
			'target'   => [
				'slug'  => $slug,
				'label' => $label,
				'path'  => $root,
			],
			'message'  => $message,
		] );
	}

	/** @return list<string> */
	private static function plugin_files(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_keys( get_plugins() );
	}

	/** @return list<string> */
	private static function theme_stylesheets(): array {
		$themes = wp_get_themes();
		return array_keys( is_array( $themes ) ? $themes : [] );
	}
}
