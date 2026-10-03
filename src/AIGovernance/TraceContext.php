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
namespace CoreBlueprint\Core\AIGovernance;

defined( 'ABSPATH' ) || exit;

final class TraceContext {
	/** @var array<int,array{activity_id:string,correlation_id:string,scope:string,ability_depth:int}> */
	private static array $frames = [];

	/** @return array{correlation_id:string,parent_activity_id:?string} */
	public static function link(): array {
		$current = self::current();
		return [
			'correlation_id'    => null !== $current ? $current['correlation_id'] : wp_generate_uuid4(),
			'parent_activity_id' => null !== $current ? $current['activity_id'] : null,
		];
	}

	public static function prepare_ability_open(): void {
		$depth = self::ability_execution_depth();
		self::$frames = array_values( array_filter(
			self::$frames,
			static fn( array $frame ): bool => 'ability' !== $frame['scope'] || $frame['ability_depth'] < $depth
		) );
	}

	public static function enter( string $activity_id, string $correlation_id, string $scope = 'generic' ): void {
		if ( ! wp_is_uuid( $activity_id, 4 ) || ! wp_is_uuid( $correlation_id, 4 ) ) {
			return;
		}
		$scope = sanitize_key( $scope ) ?: 'generic';
		self::$frames[] = [
			'activity_id'    => $activity_id,
			'correlation_id' => $correlation_id,
			'scope'          => $scope,
			'ability_depth'  => 'ability' === $scope ? self::ability_execution_depth() : 0,
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

	/** @return array{activity_id:string,correlation_id:string,scope:string,ability_depth:int}|null */
	private static function current(): ?array {
		$ability_depth = self::ability_execution_depth();
		while ( [] !== self::$frames ) {
			$current = self::$frames[ array_key_last( self::$frames ) ];
			if ( ! is_array( $current ) ) {
				array_pop( self::$frames );
				continue;
			}
			if (
				'ability' === $current['scope']
				&& ( $ability_depth < 1 || $current['ability_depth'] > $ability_depth )
			) {
				array_pop( self::$frames );
				continue;
			}
			return $current;
		}
		return null;
	}

	private static function ability_execution_depth(): int {
		$depth = 0;
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 64 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- bounded call-stack inspection is the execution-depth mechanism, not debug output.
			if ( 'WP_Ability' === ( $frame['class'] ?? null ) && 'execute' === ( $frame['function'] ?? null ) ) {
				++$depth;
			}
		}
		return $depth;
	}

	/** @internal */
	public static function reset_for_tests(): void {
		self::$frames = [];
	}
}
