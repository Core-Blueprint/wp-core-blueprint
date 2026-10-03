<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Profile\Document\Flow;

defined( 'ABSPATH' ) || exit;

final class Hints {
	private const MAX_SPACING_MM = 2000.0;

	/**
	 * @param array<string,mixed> $hints
	 * @return array{space_before:float,space_after:float,break_before:bool,break_after:bool,keep_together:bool}|null
	 */
	public static function normalize( array $hints ): ?array {
		foreach ( array_keys( $hints ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, Contract::FLOW_KEYS, true ) ) {
				return null;
			}
		}

		$before = self::spacing( $hints['space_before'] ?? 0 );
		$after = self::spacing( $hints['space_after'] ?? 0 );
		if ( null === $before || null === $after ) {
			return null;
		}
		foreach ( [ 'break_before', 'break_after', 'keep_together' ] as $key ) {
			if ( array_key_exists( $key, $hints ) && ! is_bool( $hints[ $key ] ) ) {
				return null;
			}
		}

		return [
			'space_before' => $before,
			'space_after' => $after,
			'break_before' => $hints['break_before'] ?? false,
			'break_after' => $hints['break_after'] ?? false,
			'keep_together' => $hints['keep_together'] ?? false,
		];
	}

	private static function spacing( mixed $value ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$value = (float) $value;
		return is_finite( $value ) && $value >= 0.0 && $value <= self::MAX_SPACING_MM ? $value : null;
	}

	private function __construct() {}
}
