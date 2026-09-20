<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

/** Internal rollback-state guard for exact Profile sections. */
final class StateGuard {
	public static function is_between( mixed $current, mixed $before, mixed $after ): bool {
		if ( $current === $before || $current === $after ) {
			return true;
		}
		if ( ! is_array( $current ) || ! is_array( $before ) || ! is_array( $after ) ) {
			return false;
		}
		$current_keys = array_keys( $current );
		$before_keys  = array_keys( $before );
		$after_keys   = array_keys( $after );
		sort( $current_keys, SORT_STRING );
		sort( $before_keys, SORT_STRING );
		sort( $after_keys, SORT_STRING );
		if ( $current_keys !== $before_keys || $current_keys !== $after_keys ) {
			return false;
		}
		foreach ( $current as $key => $value ) {
			if ( ! self::is_between( $value, $before[ $key ], $after[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	private function __construct() {}
}
