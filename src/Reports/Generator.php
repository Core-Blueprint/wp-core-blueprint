<?php
declare(strict_types=1);
/**
 * Reports Generator
 *
 * Creates an immutable maintenance-report snapshot and persists that snapshot
 * to the Reports table. PDF rendering is deliberately not part of generation:
 * a PDF is rendered on demand when an authorised user views or downloads a
 * stored report.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Reports;

use CoreBlueprint\Core\Log\AuditLog;

use DateTimeImmutable;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Generator {

	/**
	 * Generate and persist a maintenance-report snapshot.
	 *
	 * @param array $args {
	 *     @type string $period_start 'Y-m-d' (required).
	 *     @type string $period_end   'Y-m-d' (required, >= period_start).
	 *     @type int    $generated_by Optional WP user ID; defaults to current user.
	 * }
	 * @return array{report_id:int,period_start:string,period_end:string}
	 * @throws \InvalidArgumentException When period args are missing or malformed.
	 * @throws \RuntimeException When collection or persistence fails.
	 */
	public function generate( array $args ): array {
		[ $period_start, $period_end, $start_ts, $end_ts ] = $this->validate_period( $args );

		$generated_by = isset( $args['generated_by'] )
			? max( 0, (int) $args['generated_by'] )
			: get_current_user_id();

		try {
			$report_data = MaintenanceAggregator::collect( $start_ts, $end_ts );

			$report_id = Storage::save( [
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'generated_by' => $generated_by,
				'report_data'  => $report_data,
				'status'       => 'generated',
			] );

			if ( $report_id <= 0 ) {
				throw new \RuntimeException( 'Maintenance report snapshot could not be persisted.' );
			}
		} catch ( Throwable $e ) {
			AuditLog::log( 'reports.generation_failed', 'warning', [
				'period' => [ $period_start, $period_end ],
				'reason' => $e->getMessage(),
			] );

			/**
			 * Fires when maintenance-report collection or persistence fails.
			 *
			 * @param array     $args  Original generate() arguments.
			 * @param Throwable $error Failure that aborted generation.
			 */
			do_action( 'core_blueprint_maintenance_report_failed', $args, $e );

			throw $e;
		}

		AuditLog::log( 'reports.maintenance_generated', 'notice', [
			'report_id' => $report_id,
			'period'    => [ $period_start, $period_end ],
		] );

		/**
		 * Fires after an immutable maintenance-report snapshot is persisted.
		 *
		 * @param int   $report_id Row ID in cb_maintenance_reports.
		 * @param array $args      Original generate() arguments.
		 */
		do_action( 'core_blueprint_maintenance_report_generated', $report_id, $args );

		return [
			'report_id'    => $report_id,
			'period_start' => $period_start,
			'period_end'   => $period_end,
		];
	}

	/**
	 * Validate a report period in the WordPress site timezone and convert its
	 * inclusive local-day boundaries to Unix timestamps. Audit-log queries can
	 * then convert those timestamps to UTC without shifting the requested days.
	 *
	 * @return array{0:string,1:string,2:int,3:int}
	 */
	private function validate_period( array $args ): array {
		$start = isset( $args['period_start'] ) ? trim( (string) $args['period_start'] ) : '';
		$end   = isset( $args['period_end'] ) ? trim( (string) $args['period_end'] ) : '';

		if ( '' === $start || '' === $end ) {
			throw new \InvalidArgumentException(
				'Generator::generate() requires period_start and period_end.'
			);
		}

		$timezone  = wp_timezone();
		$start_day = DateTimeImmutable::createFromFormat( '!Y-m-d', $start, $timezone );
		$start_err = DateTimeImmutable::getLastErrors();
		$end_day   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end, $timezone );
		$end_err   = DateTimeImmutable::getLastErrors();

		if (
			false === $start_day
			|| false === $end_day
			|| ( is_array( $start_err ) && ( $start_err['warning_count'] > 0 || $start_err['error_count'] > 0 ) )
			|| ( is_array( $end_err ) && ( $end_err['warning_count'] > 0 || $end_err['error_count'] > 0 ) )
			|| $start_day->format( 'Y-m-d' ) !== $start
			|| $end_day->format( 'Y-m-d' ) !== $end
		) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException(
				sprintf( 'Invalid period: %s -> %s', $start, $end )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( $start_day > $end_day ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException(
				sprintf( 'period_start must be <= period_end (got %s -> %s).', $start, $end )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$start_boundary = $start_day->setTime( 0, 0, 0 );
		$end_boundary   = $end_day->setTime( 23, 59, 59 );

		return [
			$start_day->format( 'Y-m-d' ),
			$end_day->format( 'Y-m-d' ),
			$start_boundary->getTimestamp(),
			$end_boundary->getTimestamp(),
		];
	}
}
