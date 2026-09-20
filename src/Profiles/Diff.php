<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

final class Diff {
	private const MAX_CHANGES = 1000;

	/** @return list<array{path:string,before:mixed,after:mixed}> */
	public static function between( array $before, array $after ): array {
		$changes = [];
		self::walk( $before, $after, '', $changes );
		if ( count( $changes ) > self::MAX_CHANGES ) {
			throw new \RuntimeException( __( 'The profile contains too many individual changes to review safely in one apply.', 'core-blueprint' ) );
		}
		return $changes;
	}

	private static function walk( mixed $before, mixed $after, string $path, array &$changes ): void {
		if ( is_array( $before ) && is_array( $after ) ) {
			$keys = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
			foreach ( $keys as $key ) {
				$child = '' === $path ? (string) $key : $path . '.' . (string) $key;
				$b_has = array_key_exists( $key, $before );
				$a_has = array_key_exists( $key, $after );
				if ( ! $b_has || ! $a_has ) {
					$changes[] = [
						'path'   => $child,
						'before' => $b_has ? self::display_value( $before[ $key ] ) : null,
						'after'  => $a_has ? self::display_value( $after[ $key ] ) : null,
					];
					if ( count( $changes ) > self::MAX_CHANGES ) { return; }
					continue;
				}
				self::walk( $before[ $key ], $after[ $key ], $child, $changes );
				if ( count( $changes ) > self::MAX_CHANGES ) {
					return;
				}
			}
			return;
		}

		if ( $before !== $after ) {
			$changes[] = [
				'path'   => '' === $path ? 'value' : $path,
				'before' => self::display_value( $before ),
				'after'  => self::display_value( $after ),
			];
		}
	}

	private static function display_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return sprintf( 'array(%d)', count( $value ) );
		}
		if ( is_object( $value ) ) {
			return get_class( $value );
		}
		if ( is_string( $value ) && strlen( $value ) > 240 ) {
			return substr( $value, 0, 237 ) . '...';
		}
		return $value;
	}
}
