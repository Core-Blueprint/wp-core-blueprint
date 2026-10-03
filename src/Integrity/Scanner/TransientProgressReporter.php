<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use function delete_transient;
use function get_transient;
use function in_array;
use function is_array;
use function microtime;
use function set_transient;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed progress reporter.
 *
 * Persists scan progress to the `cb_core_integrity_scan_progress_{job_id}`
 * transient so the admin UI can poll for updates without an open
 * connection to the running scan process.
 *
 * Write-debouncing
 * ----------------
 *
 * Per-tick writes would hit `wp_options` on every iteration of every
 * scanner phase - for ~665 checks across all phases that is 665 row
 * updates. Most of those updates are noise (the percentage barely
 * moves between adjacent ticks).
 *
 * Strategy: tick() updates an in-memory snapshot, but only flushes
 * to the transient when one of these is true:
 *   - it's been ≥ DEBOUNCE_MS since the last flush
 *   - the phase changes
 *   - it's the first or last tick of a phase
 *
 * start_phase() and complete_phase() always flush immediately - they
 * are state transitions the UI must see. complete_scan() and fail()
 * also always flush.
 *
 * TTL
 * ---
 *
 * Transient TTL is 15 minutes. This is only a presentation cache - the
 * persisted ScanJobRepository state + long-lived ScannerLock remain the
 * authoritative lifecycle. A long scan therefore survives transient expiry,
 * while abandoned UI state cleans itself up quickly.
 *
 * @since   1.0.0
 */
final class TransientProgressReporter implements ProgressReporter {
	public const TRANSIENT_PREFIX = 'cb_core_integrity_scan_progress_';
	public const TTL_SECONDS      = 900;     // 15 min
	public const DEBOUNCE_MS      = 500;

	private const PHASES = [ 'core', 'plugins', 'themes', 'uploads' ];

	private string $job_id;
	private float  $started_at;
	private int    $started_by_user_id;

	/**
	 * In-memory state snapshot. Kept here so tick() can update without
	 * a transient round-trip, and only flushes happen on debounce.
	 *
	 * @var array<string,mixed>
	 */
	private array $state;

	/** Last flush time in microseconds since epoch. */
	private float $last_flush_ms = 0.0;

	public function __construct( string $job_id, int $started_by_user_id = 0 ) {
		$this->job_id             = $job_id;
		$this->started_by_user_id = $started_by_user_id;

		// Resumable scan jobs construct a reporter on every execution slice.
		// Rehydrate an existing progress state instead of resetting the UI to
		// pending at every cron callback.
		$existing = self::read( $job_id );
		if ( is_array( $existing ) ) {
			$this->state      = $existing;
			$this->started_at = (float) ( $existing['started_at'] ?? microtime( true ) );
			return;
		}

		$this->started_at = microtime( true );
		$this->state = [
			'job_id'             => $job_id,
			'started_at'         => $this->started_at,
			'started_by_user_id' => $started_by_user_id,
			'status'             => 'pending',
			'current_phase'      => '',
			'phases'             => $this->initial_phases(),
			'result_id'          => null,
			'error'              => null,
		];

		$this->flush();
	}

	public function start_phase( string $phase, int $items_total ): void {
		if ( ! $this->is_known_phase( $phase ) ) {
			return;
		}

		$this->state['status']        = 'running';
		$this->state['current_phase'] = $phase;
		$this->state['phases'][ $phase ]['status']      = 'running';
		$this->state['phases'][ $phase ]['started_at']  = microtime( true );
		$this->state['phases'][ $phase ]['items_total'] = $items_total;
		$this->state['phases'][ $phase ]['items_done']  = 0;

		$this->flush();
	}

	public function tick( string $phase, int $items_done ): void {
		if ( ! $this->is_known_phase( $phase ) ) {
			return;
		}

		$this->state['phases'][ $phase ]['items_done'] = $items_done;

		$now_ms = microtime( true ) * 1000;
		if ( $now_ms - $this->last_flush_ms < self::DEBOUNCE_MS ) {
			return;
		}

		$this->flush();
	}

	public function complete_phase( string $phase ): void {
		if ( ! $this->is_known_phase( $phase ) ) {
			return;
		}

		$this->state['phases'][ $phase ]['status']       = 'done';
		$this->state['phases'][ $phase ]['completed_at'] = microtime( true );

		// Mirror items_done to items_total when complete so the bar
		// shows 100% for this phase even if the scanner ticked fewer
		// items than originally estimated (e.g. uploads count was an
		// estimate, actual count differs).
		$total = (int) ( $this->state['phases'][ $phase ]['items_total'] ?? 0 );
		if ( $total > 0 ) {
			$this->state['phases'][ $phase ]['items_done'] = $total;
		}

		$this->flush();
	}

	public function complete_scan( string $result_id ): void {
		$this->state['status']        = 'done';
		$this->state['current_phase'] = '';
		$this->state['result_id']     = $result_id;
		$this->state['completed_at']  = microtime( true );

		$this->flush();
	}

	public function fail( string $error ): void {
		$this->state['status'] = 'error';
		$this->state['error']  = $error;
		$this->state['failed_at'] = microtime( true );

		$this->flush();
	}

	/** Read the current state from the transient (for the REST polling endpoint). */
	public static function read( string $job_id ): ?array {
		$value = get_transient( self::TRANSIENT_PREFIX . $job_id );
		return is_array( $value ) ? $value : null;
	}

	/** Delete the transient (called when the operator clears results, or when stale). */
	public static function clear( string $job_id ): void {
		delete_transient( self::TRANSIENT_PREFIX . $job_id );
	}

	private function flush(): void {
		set_transient( self::TRANSIENT_PREFIX . $this->job_id, $this->state, self::TTL_SECONDS );
		$this->last_flush_ms = microtime( true ) * 1000;
	}

	private function initial_phases(): array {
		$phases = [];
		foreach ( self::PHASES as $name ) {
			$phases[ $name ] = [
				'status'       => 'pending',
				'started_at'   => null,
				'completed_at' => null,
				'items_total'  => 0,
				'items_done'   => 0,
			];
		}
		return $phases;
	}

	private function is_known_phase( string $phase ): bool {
		return in_array( $phase, self::PHASES, true );
	}
}
