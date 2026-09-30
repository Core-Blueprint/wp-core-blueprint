<?php
declare(strict_types=1);
/**
 * Request-local correlation context for AI Governance activity.
 *
 * Correlation is created only from execution nesting that Base can observe
 * directly. It is never inferred from timestamps, operation names or transport
 * heuristics.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class TraceContext {
	/** @var array<int,array{activity_id:string,correlation_id:string}> */
	private static array $frames = [];

	/** @return array{correlation_id:string,parent_activity_id:?string} */
	public static function link(): array {
		$current = self::current();
		return [
			'correlation_id'    => null !== $current ? $current['correlation_id'] : wp_generate_uuid4(),
			'parent_activity_id' => null !== $current ? $current['activity_id'] : null,
		];
	}

	public static function enter( string $activity_id, string $correlation_id ): void {
		if ( ! wp_is_uuid( $activity_id, 4 ) || ! wp_is_uuid( $correlation_id, 4 ) ) {
			return;
		}
		self::$frames[] = [
			'activity_id'   => $activity_id,
			'correlation_id' => $correlation_id,
		];
	}

	public static function leave( string $activity_id ): void {
		for ( $index = count( self::$frames ) - 1; $index >= 0; --$index ) {
			if ( self::$frames[ $index ]['activity_id'] !== $activity_id ) {
				continue;
			}
			array_splice( self::$frames, $index );
			return;
		}
	}

	public static function current_activity_id(): ?string {
		return self::current()['activity_id'] ?? null;
	}

	public static function current_correlation_id(): ?string {
		return self::current()['correlation_id'] ?? null;
	}

	/** @return array{activity_id:string,correlation_id:string}|null */
	private static function current(): ?array {
		if ( [] === self::$frames ) {
			return null;
		}
		$current = self::$frames[ array_key_last( self::$frames ) ];
		return is_array( $current ) ? $current : null;
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$frames = [];
	}
}
