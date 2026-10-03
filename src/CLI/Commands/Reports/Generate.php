<?php
declare(strict_types=1);
/**
 * Reports\Generate - `wp cb reports generate` and Console "cb reports generate".
 *
 * Generates and persists an immutable maintenance-report data snapshot.
 * The PDF is rendered later, on demand, from the Reports page.
 *
 * Args:
 *   --type=<type>          select; only "maintenance" implemented
 *   --period-start=<date>  HTML5 date input; defaults to first of last month
 *   --period-end=<date>    HTML5 date input; defaults to last of last month
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\CLI\Commands\Reports;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Reports\Generator;
use CoreBlueprint\Core\Reports\State;

defined( 'ABSPATH' ) || exit;

final class Generate implements CommandInterface {

	public function execute( array $args ): Result {
		if ( ! State::is_enabled() ) {
			return Result::error( __( 'Reports is disabled.', 'core-blueprint' ) );
		}

		$type = (string) ( $args['type'] ?? 'maintenance' );

		if ( 'maintenance' !== $type ) {
			return Result::error(
				sprintf(
					/* translators: %s: requested report type */
					__( 'Unknown report type "%s". Supported types: maintenance.', 'core-blueprint' ),
					$type
				)
			);
		}

		[ $period_start, $period_end ] = self::resolve_period( $args );

		try {
			$generator = new Generator();
			$result    = $generator->generate( [
				'period_start' => $period_start,
				'period_end'   => $period_end,
			] );
		} catch ( \InvalidArgumentException $e ) {
			return Result::error(
				/* translators: %s: validation exception message. */
				sprintf( __( 'Invalid input: %s', 'core-blueprint' ), $e->getMessage() )
			);
		} catch ( \Throwable $e ) {
			return Result::error(
				/* translators: %s: exception message from report generation. */
				sprintf( __( 'Unexpected error during generation: %s', 'core-blueprint' ), $e->getMessage() )
			);
		}

		$report_id = (int) ( $result['report_id'] ?? 0 );

		$lines   = [];
		$lines[] = sprintf( 'Maintenance report snapshot generated for %s … %s', $period_start, $period_end );
		$lines[] = sprintf( 'Report ID:   #%d', $report_id );
		$lines[] = __( 'PDF:         Rendered on demand from Core Blueprint > Reports.', 'core-blueprint' );

		return Result::success(
			sprintf(
				/* translators: %d: report ID */
				__( 'Maintenance report #%d generated.', 'core-blueprint' ),
				$report_id
			),
			$lines,
			[
				'report_id'    => $report_id,
				'period_start' => $period_start,
				'period_end'   => $period_end,
			]
		);
	}

	public function args_schema(): array {
		return [
			'type' => [
				'type'    => 'select',
				'label'   => __( 'Report type', 'core-blueprint' ),
				'default' => 'maintenance',
				'options' => [
					'maintenance' => __( 'Maintenance report', 'core-blueprint' ),
				],
				'help'    => __( 'Currently only the maintenance report is supported.', 'core-blueprint' ),
			],
			'period-start' => [
				'type'  => 'date',
				'label' => __( 'Period start', 'core-blueprint' ),
				'help'  => __( 'First day of the report period (YYYY-MM-DD). Defaults to the first day of the previous calendar month.', 'core-blueprint' ),
			],
			'period-end' => [
				'type'  => 'date',
				'label' => __( 'Period end', 'core-blueprint' ),
				'help'  => __( 'Last day of the report period (YYYY-MM-DD). Defaults to the last day of the previous calendar month.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Generate a maintenance report.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Report type. Currently only `maintenance` is supported.
	 *
	 * [--period-start=<date>]
	 * : First day of the period (YYYY-MM-DD). Defaults to first of last month.
	 *
	 * [--period-end=<date>]
	 * : Last day of the period (YYYY-MM-DD). Defaults to last of last month.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb reports generate maintenance
	 *     wp cb reports generate maintenance --period-start=2026-03-01 --period-end=2026-03-31
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$type = (string) ( $args[0] ?? 'maintenance' );

		$normalised = [
			'type'         => $type,
			'period-start' => isset( $assoc_args['period-start'] ) ? (string) $assoc_args['period-start'] : '',
			'period-end'   => isset( $assoc_args['period-end'] )   ? (string) $assoc_args['period-end']   : '',
		];

		\WP_CLI::line( sprintf(
			'Generating %s report for %s … %s',
			$type,
			$normalised['period-start'] ?: '(default)',
			$normalised['period-end']   ?: '(default)'
		) );

		$result = $this->execute( $normalised );

		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
		if ( 'error' === $result->status ) {
			\WP_CLI::error( $result->message );
		}
		\WP_CLI::success( $result->message );
	}

	/**
	 * Resolve the period dates from args, falling back to "previous full
	 * calendar month". Default computed in the WordPress site timezone; explicit dates passed by
	 * the operator are validated by the Reports Generator.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function resolve_period( array $args ): array {
		$now            = current_datetime();
		$first_this     = $now->modify( 'first day of this month' )->setTime( 0, 0 );
		$first_previous = $first_this->modify( '-1 month' );
		$last_previous  = $first_this->modify( '-1 day' );
		$default_start  = $first_previous->format( 'Y-m-d' );
		$default_end    = $last_previous->format( 'Y-m-d' );

		$start = (string) ( $args['period-start'] ?? '' );
		$end   = (string) ( $args['period-end']   ?? '' );

		return [
			'' === trim( $start ) ? $default_start : $start,
			'' === trim( $end )   ? $default_end   : $end,
		];
	}
}
