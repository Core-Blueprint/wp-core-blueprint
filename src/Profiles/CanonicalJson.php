<?php
declare(strict_types=1);

namespace CB\Core\Profiles;

defined( 'ABSPATH' ) || exit;

final class CanonicalJson {
	public static function encode( array $value, int $flags = 0 ): string {
		$normalized = self::sort_recursive( $value );
		$json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( __( 'Core Blueprint could not encode the profile document.', 'core-blueprint' ) );
		}
		return $json;
	}

	private static function sort_recursive( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'sort_recursive' ], $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::sort_recursive( $child );
		}
		return $value;
	}
}
