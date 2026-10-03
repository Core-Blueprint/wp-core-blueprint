<?php
declare(strict_types=1);
/** @package Core_Blueprint @since 1.0.0 */
namespace CoreBlueprint\Core\Governance;

defined( 'ABSPATH' ) || exit;

final class ContextSanitizer {
	private const MAX_DEPTH = 5;
	private const MAX_ITEMS = 100;
	private const MAX_STRING_BYTES = 2048;
	private const MAX_TOTAL_STRING_BYTES = 16384;
	private const SECRET_KEY_PATTERN = '/(?:pass(?:word)?|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|nonce|credential|(?:^|[_-])key$)/i';

	/** @param array<string|int,mixed> $context @return array<string|int,mixed> */
	public static function sanitize( array $context ): array {
		$items = 0;
		$budget = self::MAX_TOTAL_STRING_BYTES;
		$value = self::walk( $context, 0, $items, $budget );
		return is_array( $value ) ? $value : [];
	}

	private static function walk( mixed $value, int $depth, int &$items, int &$budget ): mixed {
		if ( $depth > self::MAX_DEPTH || $items >= self::MAX_ITEMS ) {
			return '[truncated]';
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $child ) {
				if ( $items >= self::MAX_ITEMS ) {
					$out['_truncated'] = true;
					break;
				}
				++$items;
				$key_string = is_int( $key ) ? (string) $key : $key;
				if ( ! is_int( $key ) && 1 === preg_match( self::SECRET_KEY_PATTERN, $key_string ) ) {
					$out[ $key ] = '[redacted]';
					continue;
				}
				$out[ $key ] = self::walk( $child, $depth + 1, $items, $budget );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			if ( $budget <= 0 ) {
				return '[truncated]';
			}
			$limit = min( self::MAX_STRING_BYTES, $budget );
			$out = strlen( $value ) > $limit ? substr( $value, 0, $limit ) : $value;
			$budget -= strlen( $out );
			return $out;
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		return null;
	}
}
