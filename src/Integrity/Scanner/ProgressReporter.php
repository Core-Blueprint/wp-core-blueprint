<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for reporting scan progress.
 *
 * The ScannerEngine reports progress to a reporter at well-defined
 * lifecycle points. The reporter decides what to do with those signals
 * - write to a transient (UI feedback), drop them entirely (cron
 * runs), or anything else a future consumer might need.
 *
 * Mechanism (this contract) is decoupled from transport (transient,
 * SSE, websocket, audit-log). Lets us swap reporter implementations
 * without touching the engine.
 *
 * Lifecycle (sequential):
 *   start_phase('core', 2104)    → tick('core', 1)…tick('core', 2104) → complete_phase('core')
 *   start_phase('plugins', 25)   → tick('plugins', 1)…tick('plugins', 25) → complete_phase('plugins')
 *   start_phase('themes', 3)     → … → complete_phase('themes')
 *   start_phase('uploads', N)    → … → complete_phase('uploads')
 *   complete_scan('result-id')
 *
 * On failure anywhere:
 *   fail('error message')
 *
 * Implementations must be safe to call in any order - the engine may
 * skip phases (when settings disable them) and may abort early on
 * error. Reporters that track state (TransientProgressReporter)
 * handle out-of-order or skipped lifecycle events gracefully.
 *
 * @since   1.0.0
 */
interface ProgressReporter {
	/**
	 * Mark the start of a named phase.
	 *
	 * @param string $phase       Phase identifier: 'core' | 'plugins' | 'themes' | 'uploads'
	 * @param int    $items_total Best-effort estimate of items to process. May be 0 if unknown.
	 */
	public function start_phase( string $phase, int $items_total ): void;

	/**
	 * Tick: increment progress within the current phase.
	 *
	 * Called frequently (potentially per-item). Implementations are
	 * expected to debounce expensive operations (DB writes) - typical
	 * pattern is "write at most every 500ms".
	 *
	 * @param string $phase      Same phase id as start_phase.
	 * @param int    $items_done Cumulative items completed so far in this phase.
	 */
	public function tick( string $phase, int $items_done ): void;

	/**
	 * Mark a phase as complete. Must be called even if the phase
	 * was skipped (e.g. settings.plugin_checksums = false) so the
	 * timeline is consistent.
	 */
	public function complete_phase( string $phase ): void;

	/**
	 * Mark the entire scan as complete.
	 *
	 * @param string $result_id Identifier of the persisted scan result.
	 */
	public function complete_scan( string $result_id ): void;

	/**
	 * Mark the scan as failed. Reporters should persist the error
	 * message so the UI can surface it without polling indefinitely.
	 */
	public function fail( string $error ): void;
}
