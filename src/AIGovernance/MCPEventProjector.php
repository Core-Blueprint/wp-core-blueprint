<?php
declare(strict_types=1);
/**
 * Projects official WordPress MCP Adapter observability into AI Governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class MCPEventProjector {
	/** @param array<string,mixed> $tags */
	public static function record( string $event, array $tags = [], ?float $duration_ms = null ): string|false {
		if ( 'mcp.request' !== $event ) {
			return false;
		}

		$status = isset( $tags['status'] ) ? sanitize_key( (string) $tags['status'] ) : '';
		$failure_reason = self::machine_identifier( $tags['failure_reason'] ?? null );
		$outcome = 'success' === $status ? 'succeeded' : 'failed';
		if ( 'permission_denied' === $failure_reason ) {
			$outcome = 'denied';
		}

		$method = isset( $tags['method'] ) ? sanitize_text_field( (string) $tags['method'] ) : 'unknown';
		$transport = self::transport( $tags['transport'] ?? null );
		$target = self::target( $tags );
		$link = TraceContext::link();

		$error_code = null;
		if ( isset( $tags['error_code'] ) && is_scalar( $tags['error_code'] ) ) {
			$error_code = 'mcp_jsonrpc_' . (string) $tags['error_code'];
		}

		$evidence = [
			'capture' => [
				'platform' => 'wordpress-mcp-adapter',
			],
			'mcp' => self::evidence( $tags ),
		];

		return Repository::insert( [
			'activity_id'       => wp_generate_uuid4(),
			'correlation_id'    => $link['correlation_id'],
			'parent_activity_id' => $link['parent_activity_id'],
			'operation_type'    => 'mcp-request',
			'operation'         => 'mcp/' . ltrim( $method, '/' ),
			'transport'         => $transport,
			'source_id'         => 'wordpress-mcp-adapter',
			'source_label'      => 'WordPress MCP Adapter',
			'outcome'           => $outcome,
			'capture_state'     => 'completed',
			'target_type'       => $target['type'],
			'target_id'         => $target['id'],
			'target_label'      => $target['label'],
			'duration_ms'       => null === $duration_ms ? null : max( 0, (int) round( $duration_ms ) ),
			'error_code'        => $error_code,
			'evidence'          => $evidence,
			'completed_at'      => gmdate( 'Y-m-d H:i:s' ),
		] );
	}

	private static function transport( mixed $transport ): string {
		$transport = sanitize_key( is_scalar( $transport ) ? (string) $transport : '' );
		return match ( $transport ) {
			'http'  => 'mcp-http',
			'stdio' => 'mcp-stdio',
			default => 'unknown',
		};
	}

	/** @param array<string,mixed> $tags @return array{type:?string,id:?string,label:?string} */
	private static function target( array $tags ): array {
		if ( ! empty( $tags['ability_name'] ) ) {
			$value = substr( sanitize_text_field( (string) $tags['ability_name'] ), 0, 190 );
			return [ 'type' => 'ability', 'id' => $value, 'label' => $value ];
		}

		$type = isset( $tags['component_type'] ) ? sanitize_key( (string) $tags['component_type'] ) : '';
		$key = match ( $type ) {
			'tool'     => 'tool_name',
			'prompt'   => 'prompt_name',
			'resource' => 'resource_uri',
			default    => '',
		};
		if ( '' === $key || empty( $tags[ $key ] ) ) {
			return [ 'type' => null, 'id' => null, 'label' => null ];
		}
		$value = substr( sanitize_text_field( (string) $tags[ $key ] ), 0, 190 );
		return [ 'type' => $type, 'id' => $value, 'label' => $value ];
	}

	/** @param array<string,mixed> $tags @return array<string,mixed> */
	private static function evidence( array $tags ): array {
		$allowed = [
			'method',
			'server_id',
			'revision',
			'component_type',
			'tool_name',
			'ability_name',
			'prompt_name',
			'resource_uri',
			'error_category',
			'params',
		];
		$out = [];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $tags ) ) {
				continue;
			}
			$out[ $key ] = $tags[ $key ];
		}
		if ( array_key_exists( 'request_id', $tags ) && is_scalar( $tags['request_id'] ) ) {
			$request_id = (string) $tags['request_id'];
			if ( 1 === preg_match( '/^[A-Za-z0-9._:-]{1,100}$/D', $request_id ) ) {
				$out['request_id'] = $request_id;
			} elseif ( '' !== $request_id ) {
				$out['request_id_fingerprint'] = self::fingerprint( $request_id );
			}
		}
		if ( array_key_exists( 'failure_reason', $tags ) && is_scalar( $tags['failure_reason'] ) ) {
			$reason = self::machine_identifier( $tags['failure_reason'] );
			if ( null !== $reason ) {
				$out['failure_reason'] = $reason;
			} else {
				$out['failure_reason_summary'] = Privacy::summarize( (string) $tags['failure_reason'] );
			}
		}
		foreach ( [ 'session_id' => 'session_fingerprint', 'new_session_id' => 'new_session_fingerprint' ] as $source => $target ) {
			if ( ! isset( $tags[ $source ] ) || ! is_scalar( $tags[ $source ] ) || '' === (string) $tags[ $source ] ) {
				continue;
			}
			$out[ $target ] = self::fingerprint( (string) $tags[ $source ] );
		}
		return $out;
	}

	private static function machine_identifier( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_.:-]{0,99}$/D', $value ) ? $value : null;
	}

	private static function fingerprint( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}
}
