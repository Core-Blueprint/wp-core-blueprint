<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use function count;
use function is_array;
use function max;
use function microtime;

/**
 * Read-only status projection for the single persisted Core Scanner job.
 *
 * The persisted job + its long-lived lock are the authoritative source of
 * truth for whether a scan is active. UI progress transients are deliberately
 * treated as an optional presentation cache: they may expire without changing
 * the underlying scan lifecycle, and scheduled/API scans may not create them
 * at all.
 */
final class ScanJobStatus {
	private const PHASES = [ 'core', 'plugins', 'themes', 'uploads' ];

	/** Return the authoritative active job, or null when no valid job owns the lock. */
	public static function active_job(): ?array {
		$job = ScanJobRepository::get();
		return is_array( $job ) && self::is_active_job( $job ) ? $job : null;
	}

	/** Verify that this persisted job is currently the owner of the scan lock. */
	public static function is_active_job( array $job ): bool {
		if ( 'running' !== (string) ( $job['status'] ?? '' ) ) {
			return false;
		}

		$job_id = (string) ( $job['job_id'] ?? '' );
		$token  = (string) ( $job['lock_token'] ?? '' );
		return '' !== $job_id && '' !== $token && ScannerLock::is_owned_by( $token );
	}

	/**
	 * Build the public progress shape directly from persisted job state.
	 *
	 * This is intentionally approximate for file totals that are not known until
	 * traversal completes. It is a resilience fallback, not a second source of
	 * truth for scan execution.
	 */
	public static function progress_from_job( array $job ): array {
		$current = (string) ( $job['phase'] ?? 'core' );
		$phases  = self::initial_phases();
		$order   = array_flip( self::PHASES );
		$current_rank = 'finalize' === $current ? count( self::PHASES ) : (int) ( $order[ $current ] ?? 0 );

		foreach ( self::PHASES as $name ) {
			$rank = (int) ( $order[ $name ] ?? 0 );
			if ( $rank < $current_rank ) {
				$phases[ $name ]['status'] = 'done';
			} elseif ( $rank === $current_rank && 'finalize' !== $current ) {
				$phases[ $name ]['status'] = 'running';
			}
		}

		$plugin_total = count( is_array( $job['plugin_files'] ?? null ) ? $job['plugin_files'] : [] );
		$plugin_done  = max( 0, (int) ( $job['plugin_index'] ?? 0 ) );
		$phases['plugins']['items_total'] = $plugin_total;
		$phases['plugins']['items_done']  = 'done' === $phases['plugins']['status'] ? $plugin_total : min( $plugin_total, $plugin_done );

		$theme_total = count( is_array( $job['theme_stylesheets'] ?? null ) ? $job['theme_stylesheets'] : [] );
		$theme_done  = max( 0, (int) ( $job['theme_index'] ?? 0 ) );
		$phases['themes']['items_total'] = $theme_total;
		$phases['themes']['items_done']  = 'done' === $phases['themes']['status'] ? $theme_total : min( $theme_total, $theme_done );

		$core_coverage = is_array( $job['coverage']['core'] ?? null ) ? $job['coverage']['core'] : [];
		$core_done = max( 0, (int) ( $core_coverage['verified_files'] ?? 0 ) )
			+ max( 0, (int) ( $core_coverage['modified_files'] ?? 0 ) )
			+ max( 0, (int) ( $core_coverage['missing_files'] ?? 0 ) )
			+ max( 0, (int) ( $core_coverage['unexpected_files'] ?? 0 ) );
		$core_total = max( $core_done, (int) ( $core_coverage['expected_files'] ?? 0 ) );
		$phases['core']['items_total'] = $core_total;
		$phases['core']['items_done']  = 'done' === $phases['core']['status'] ? $core_total : min( $core_total, $core_done );

		$uploads_coverage = is_array( $job['coverage']['uploads'] ?? null ) ? $job['coverage']['uploads'] : [];
		$uploads_done = max( 0, (int) ( $uploads_coverage['files_inspected'] ?? 0 ) );
		// Traversal does not know the final uploads total up front. Keep total=0
		// while running; the UI renders the phase without inventing a percentage.
		$phases['uploads']['items_total'] = 'done' === $phases['uploads']['status'] ? $uploads_done : 0;
		$phases['uploads']['items_done']  = $uploads_done;

		return [
			'job_id'             => (string) ( $job['job_id'] ?? '' ),
			'started_at'         => (float) ( $job['started_at_micro'] ?? microtime( true ) ),
			'started_by_user_id' => (int) ( $job['started_by_user_id'] ?? 0 ),
			'status'             => 'running',
			'current_phase'      => 'finalize' === $current ? '' : $current,
			'phases'             => $phases,
			'result_id'          => null,
			'error'              => null,
			'progress_source'    => 'persisted_job',
		];
	}

	private static function initial_phases(): array {
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
}
