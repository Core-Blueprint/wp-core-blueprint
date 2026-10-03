<?php
declare(strict_types=1);
/**
 * Deterministic hashing for Core Setup review evidence.
 *
 * Fingerprints are derived from an explicitly bounded, non-secret evidence
 * payload. They are used only to detect whether the canonical configuration
 * reviewed by an administrator still matches the current configuration.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup;
defined( 'ABSPATH' ) || exit;

final class Fingerprint {

	public static function hash( array $data ): string {
		$canonical = self::canonicalize( $data );
		$json = json_encode(
			$canonical,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
		);

		return hash( 'sha256', $json );
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return array_map( [ self::class, 'canonicalize' ], $value );
			}

			$normalized = [];
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ! is_string( $key ) ) {
					throw new \InvalidArgumentException( 'Core Setup fingerprint keys must be scalar.' );
				}
				$normalized[ (string) $key ] = self::canonicalize( $item );
			}
			ksort( $normalized, SORT_STRING );
			return $normalized;
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return $value;
		}

		throw new \InvalidArgumentException( 'Core Setup fingerprints accept only arrays and scalar values.' );
	}

	private function __construct() {}
}
