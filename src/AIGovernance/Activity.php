<?php
declare(strict_types=1);
/**
 * Public AI/agent governance activity write facade.
 *
 * Consumers report completed or otherwise known operations through this
 * one-shot boundary. WordPress Ability lifecycle correlation is owned by the
 * internal AbilityObserver and is deliberately not exposed as public storage API.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class Activity {
	public const OUTCOMES = [ 'unknown', 'succeeded', 'failed', 'denied', 'invalid', 'short-circuited' ];
	public const OPERATION_TYPES = [ 'ability', 'ai-client', 'mcp-request', 'operation' ];
	public const TRANSPORTS = [ 'unknown', 'php', 'rest', 'cli', 'mcp-http', 'mcp-stdio', 'reported' ];

	/**
	 * Record one governance activity using evidence supplied by the caller.
	 *
	 * Required:
	 * - operation: stable human/machine operation identifier.
	 * - outcome: one of OUTCOMES.
	 *
	 * Optional metadata is bounded and metadata-first. Base resolves the current
	 * WordPress actor itself; consumers cannot impersonate an actor through this API.
	 *
	 * @param array<string,mixed> $activity
	 * @return string|false Opaque activity UUID on success, false on rejection/storage failure.
	 */
	public static function record( array $activity ): string|false {
		$operation = isset( $activity['operation'] ) ? trim( (string) $activity['operation'] ) : '';
		$outcome = isset( $activity['outcome'] ) ? sanitize_key( (string) $activity['outcome'] ) : '';
		if ( '' === $operation || ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return false;
		}

		$transport = isset( $activity['transport'] ) ? sanitize_key( (string) $activity['transport'] ) : 'reported';
		if ( ! in_array( $transport, self::TRANSPORTS, true ) ) {
			return false;
		}

		$context = isset( $activity['context'] ) && is_array( $activity['context'] ) ? $activity['context'] : [];
		$evidence = isset( $activity['evidence'] ) && is_array( $activity['evidence'] ) ? $activity['evidence'] : [];
		$evidence['reported_by'] = 'consumer';

		$link = TraceContext::link();
		$row = [
			'activity_id'       => wp_generate_uuid4(),
			'correlation_id'    => $link['correlation_id'],
			'parent_activity_id' => $link['parent_activity_id'],
			'operation_type'    => 'operation',
			'operation'      => $operation,
			'transport'      => $transport,
			'source_id'      => $activity['source_id'] ?? null,
			'source_label'   => $activity['source_label'] ?? null,
			'provider_id'    => $activity['provider_id'] ?? null,
			'provider_label' => $activity['provider_label'] ?? null,
			'model_id'       => $activity['model_id'] ?? null,
			'model_label'    => $activity['model_label'] ?? null,
			'outcome'        => $outcome,
			'capture_state'  => 'reported',
			'target_type'    => $activity['target_type'] ?? null,
			'target_id'      => $activity['target_id'] ?? null,
			'target_label'   => $activity['target_label'] ?? null,
			'duration_ms'    => $activity['duration_ms'] ?? null,
			'error_code'     => $activity['error_code'] ?? null,
			'evidence'       => $evidence,
			'context'        => $context,
			'completed_at'   => gmdate( 'Y-m-d H:i:s' ),
		];

		try {
			return Repository::insert( $row );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}
}
