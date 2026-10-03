<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Api;

use CoreBlueprint\Core\Integrity\Scanner\ScanJobDispatcher;
use CoreBlueprint\Core\Integrity\Scanner\ScanJobRepository;
use CoreBlueprint\Core\Integrity\Scanner\ScanJobStatus;
use CoreBlueprint\Core\Integrity\Scanner\ScanLockedException;
use CoreBlueprint\Core\Integrity\Scanner\TransientProgressReporter;
use CoreBlueprint\Core\Integrity\State;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Support\Audit as IntegrityAudit;
use CoreBlueprint\Core\Integrity\Support\Finding;
use CoreBlueprint\Core\Integrity\Support\ResultFormatter;
use Throwable;
use WP_Error;

use function function_exists;
use function get_current_user_id;
use function in_array;
use function is_array;
use function max;
use function min;
use function sanitize_key;
use function strlen;
use function trim;

/**
 * Public v1 service boundary for Core Scanner integrations.
 *
 * Scan identifiers are opaque. Callers must keep the scan_id returned by
 * request_scan() and use it for subsequent status/summary/findings requests.
 * Base currently retains one full completed result; older scan IDs are not a
 * promise of historical finding retention.
 */
final class IntegrityApi {
	/** Queue one resumable Core Scanner scan. */
	public static function request_scan( string $source = 'api' ): array|WP_Error {
		if ( ! State::is_enabled() ) {
			return self::error(
				'cb_integrity_subsystem_disabled',
				__( 'Core Scanner is disabled.', 'core-blueprint' ),
				403
			);
		}

		$active = ScanJobStatus::active_job();
		if ( is_array( $active ) ) {
			$status = ScanJobStatus::progress_from_job( $active );
			return self::error(
				'cb_integrity_scan_locked',
				__( 'A scan is already running.', 'core-blueprint' ),
				409,
				[
					'scan_id'    => (string) ( $status['job_id'] ?? '' ),
					'scan_status'=> (string) ( $status['status'] ?? 'running' ),
					'started_at' => (float) ( $status['started_at'] ?? 0.0 ),
				]
			);
		}

		$source = sanitize_key( $source );
		if ( '' === $source ) {
			$source = 'api';
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		try {
			$queued = ScanJobDispatcher::dispatch( $source, $user_id, true );
		} catch ( ScanLockedException $locked ) {
			$active = ScanJobStatus::active_job();
			$status = is_array( $active ) ? ScanJobStatus::progress_from_job( $active ) : [];
			return self::error(
				'cb_integrity_scan_locked',
				$locked->getMessage(),
				409,
				[
					'scan_id'     => (string) ( $status['job_id'] ?? '' ),
					'scan_status' => (string) ( $status['status'] ?? 'running' ),
					'started_at'  => (float) ( $status['started_at'] ?? 0.0 ),
				]
			);
		} catch ( Throwable $throwable ) {
			IntegrityAudit::log( 'integrity_scan_failed', 'critical', [ 'message' => $throwable->getMessage() ] );
			return self::error(
				'cb_integrity_scan_dispatch_failed',
				__( 'Core Scanner could not queue the scan.', 'core-blueprint' ),
				503
			);
		}

		return [
			'scan_id'    => (string) ( $queued['job_id'] ?? '' ),
			'status'     => 'queued',
			'started_at' => (float) ( $queued['started_at'] ?? 0.0 ),
		];
	}

	/** Return status for the active scan or the currently retained completed scan. */
	public static function scan_status( string $scan_id ): array|WP_Error {
		$scan_id = self::scan_id( $scan_id );
		if ( '' === $scan_id ) {
			return self::invalid_scan_id();
		}

		$state = TransientProgressReporter::read( $scan_id );
		$job   = ScanJobRepository::get_by_id( $scan_id );

		if ( is_array( $state ) ) {
			$state_status = (string) ( $state['status'] ?? '' );
			if ( in_array( $state_status, [ 'pending', 'running' ], true ) ) {
				if ( ! is_array( $job ) || ! ScanJobStatus::is_active_job( $job ) ) {
					TransientProgressReporter::clear( $scan_id );
					$state = null;
				}
			} elseif ( 'error' === $state_status ) {
				return self::status_projection( $scan_id, $state );
			} elseif ( 'done' === $state_status ) {
				$result = self::completed_result( $scan_id );
				if ( is_array( $result ) ) {
					return [
						'scan_id'          => $scan_id,
						'status'           => 'done',
						'current_phase'    => '',
						'result_available' => true,
						'summary'          => self::summary_projection( $scan_id, $result ),
					];
				}
			}
		}

		if ( is_array( $job ) && ScanJobStatus::is_active_job( $job ) ) {
			return self::status_projection( $scan_id, is_array( $state ) ? $state : ScanJobStatus::progress_from_job( $job ) );
		}

		$result = self::completed_result( $scan_id );
		if ( is_array( $result ) ) {
			return [
				'scan_id'          => $scan_id,
				'status'           => 'done',
				'current_phase'    => '',
				'result_available' => true,
				'summary'          => self::summary_projection( $scan_id, $result ),
			];
		}

		return self::not_found( $scan_id );
	}

	/** Return the canonical summary for the currently retained completed scan. */
	public static function summary( string $scan_id ): array|WP_Error {
		$scan_id = self::scan_id( $scan_id );
		if ( '' === $scan_id ) {
			return self::invalid_scan_id();
		}

		$result = self::completed_result( $scan_id );
		if ( ! is_array( $result ) ) {
			if ( is_array( ScanJobRepository::get_by_id( $scan_id ) ) ) {
				return self::error(
					'cb_integrity_scan_result_pending',
					__( 'The requested Core Scanner scan is not complete yet.', 'core-blueprint' ),
					409,
					[ 'scan_id' => $scan_id ]
				);
			}
			return self::not_found( $scan_id );
		}

		return self::summary_projection( $scan_id, $result );
	}

	/**
	 * Return paginated canonical Finding Schema 1 anomalies for a completed scan.
	 */
	public static function findings(
		string $scan_id,
		int $offset = 0,
		int $limit = 50,
		string $component = ''
	): array|WP_Error {
		$scan_id = self::scan_id( $scan_id );
		if ( '' === $scan_id ) {
			return self::invalid_scan_id();
		}

		$result = self::completed_result( $scan_id );
		if ( ! is_array( $result ) ) {
			if ( is_array( ScanJobRepository::get_by_id( $scan_id ) ) ) {
				return self::error(
					'cb_integrity_scan_result_pending',
					__( 'The requested Core Scanner scan is not complete yet.', 'core-blueprint' ),
					409,
					[ 'scan_id' => $scan_id ]
				);
			}
			return self::not_found( $scan_id );
		}

		$offset    = max( 0, $offset );
		$limit     = max( 1, min( 250, $limit ) );
		$component = sanitize_key( $component );
		$page      = ResultFormatter::findings_page( $result, $offset, $limit, $component );

		return [
			'scan_id'        => $scan_id,
			'finding_schema' => Finding::SCHEMA_VERSION,
			'summary'        => self::summary_projection( $scan_id, $result ),
			'findings'       => $page['items'],
			'pagination'     => [
				'total'       => $page['total'],
				'offset'      => $page['offset'],
				'limit'       => $page['limit'],
				'has_more'    => $page['has_more'],
				'next_offset' => $page['next_offset'],
			],
			'component'      => $component,
		];
	}

	private static function status_projection( string $scan_id, array $state ): array {
		return [
			'scan_id'          => $scan_id,
			'status'           => (string) ( $state['status'] ?? 'running' ),
			'started_at'       => (float) ( $state['started_at'] ?? 0.0 ),
			'current_phase'    => (string) ( $state['current_phase'] ?? '' ),
			'phases'           => is_array( $state['phases'] ?? null ) ? $state['phases'] : [],
			'result_available' => false,
			'error'            => isset( $state['error'] ) ? (string) $state['error'] : null,
		];
	}

	private static function summary_projection( string $scan_id, array $result ): array {
		$summary = ResultFormatter::summary( $result );
		return [
			'scan_id'        => $scan_id,
			'finding_schema' => Finding::SCHEMA_VERSION,
			'status'         => (string) ( $summary['status'] ?? 'idle' ),
			'completed_at'   => (string) ( $summary['last_scan'] ?? '' ),
			'source'         => (string) ( $summary['source'] ?? '' ),
			'findings'       => is_array( $summary['summary'] ?? null ) ? $summary['summary'] : [],
			'components'     => is_array( $summary['components'] ?? null ) ? $summary['components'] : [],
			'completion'     => (string) ( $summary['completion'] ?? 'unknown' ),
			'coverage'       => is_array( $summary['coverage'] ?? null ) ? $summary['coverage'] : [],
			'baseline'       => is_array( $summary['baseline'] ?? null ) ? $summary['baseline'] : [],
			'diff'           => is_array( $summary['diff'] ?? null ) ? $summary['diff'] : [],
		];
	}

	private static function completed_result( string $scan_id ): ?array {
		$result = ResultRepository::getLatest();
		if ( ! is_array( $result ) || $scan_id !== (string) ( $result['job_id'] ?? '' ) ) {
			return null;
		}
		return $result;
	}

	private static function scan_id( string $scan_id ): string {
		$scan_id = trim( $scan_id );
		if ( '' === $scan_id || 80 < strlen( $scan_id ) || sanitize_key( $scan_id ) !== $scan_id ) {
			return '';
		}
		return $scan_id;
	}

	private static function invalid_scan_id(): WP_Error {
		return self::error(
			'cb_integrity_invalid_scan_id',
			__( 'A valid Core Scanner scan ID is required.', 'core-blueprint' ),
			400
		);
	}

	private static function not_found( string $scan_id ): WP_Error {
		return self::error(
			'cb_integrity_scan_not_found',
			__( 'The requested Core Scanner scan is not available.', 'core-blueprint' ),
			404,
			[ 'scan_id' => $scan_id ]
		);
	}

	private static function error( string $code, string $message, int $status, array $data = [] ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] + $data );
	}
}
