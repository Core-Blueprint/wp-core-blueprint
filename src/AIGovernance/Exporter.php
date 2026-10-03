<?php
declare(strict_types=1);
/**
 * AI Activity export projection.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\AIGovernance;

use CoreBlueprint\Core\Log\LogExporter;

defined( 'ABSPATH' ) || exit;

final class Exporter {
	/** @return array<string,string> */
	public static function columns(): array {
		return [
			'activity_id'        => __( 'Activity ID', 'core-blueprint' ),
			'correlation_id'     => __( 'Correlation ID', 'core-blueprint' ),
			'parent_activity_id' => __( 'Parent activity ID', 'core-blueprint' ),
			'created_at'       => __( 'Observed at', 'core-blueprint' ),
			'completed_at'     => __( 'Completed at', 'core-blueprint' ),
			'actor_user_id'    => __( 'Actor user ID', 'core-blueprint' ),
			'actor_user_login' => __( 'Actor user login', 'core-blueprint' ),
			'operation_type'   => __( 'Operation type', 'core-blueprint' ),
			'operation'        => __( 'Operation', 'core-blueprint' ),
			'transport'        => __( 'Transport', 'core-blueprint' ),
			'source_id'        => __( 'Source ID', 'core-blueprint' ),
			'source_label'       => __( 'Source', 'core-blueprint' ),
			'provider_id'        => __( 'Provider ID', 'core-blueprint' ),
			'provider_label'     => __( 'Provider', 'core-blueprint' ),
			'model_id'           => __( 'Model ID', 'core-blueprint' ),
			'model_label'        => __( 'Model', 'core-blueprint' ),
			'outcome'          => __( 'Outcome', 'core-blueprint' ),
			'capture_state'    => __( 'Capture state', 'core-blueprint' ),
			'target_type'      => __( 'Target type', 'core-blueprint' ),
			'target_id'        => __( 'Target ID', 'core-blueprint' ),
			'target_label'     => __( 'Target', 'core-blueprint' ),
			'duration_ms'      => __( 'Duration (ms)', 'core-blueprint' ),
			'error_code'       => __( 'Error code', 'core-blueprint' ),
			'evidence'         => __( 'Evidence', 'core-blueprint' ),
			'context'          => __( 'Context', 'core-blueprint' ),
		];
	}

	/** @param resource $handle */
	public static function write( string $format, $handle, array $filters ): int {
		if ( ! in_array( $format, [ 'csv', 'json' ], true ) ) {
			return 0;
		}
		$meta = LogExporter::base_meta( 'ai_activity', $filters );
		$meta['evidence_model'] = 'metadata-first';
		return LogExporter::dispatch( $format, $handle, Repository::rows_iterator( $filters ), self::columns(), $meta );
	}
}
