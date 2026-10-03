<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles;

defined( 'ABSPATH' ) || exit;

final class SchemaGuard {
	/** @param list<string> $expected */
	public static function exact_keys( array $value, array $expected, string $context ): void {
		$actual = array_map( 'strval', array_keys( $value ) );
		$expected = array_values( array_map( 'strval', $expected ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \InvalidArgumentException( sprintf(
				/* translators: %s: profile configuration domain */
				__( 'The profile contains an incomplete or unsupported %s payload.', 'core-blueprint' ),
				$context
			) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	public static function bool( mixed $value, string $context ): bool {
		if ( ! is_bool( $value ) ) {
			self::invalid_type( $context );
		}
		return $value;
	}

	public static function int( mixed $value, string $context ): int {
		if ( ! is_int( $value ) ) {
			self::invalid_type( $context );
		}
		return $value;
	}

	public static function string( mixed $value, string $context ): string {
		if ( ! is_string( $value ) ) {
			self::invalid_type( $context );
		}
		return $value;
	}

	/** @return array<string,mixed> */
	public static function object( mixed $value, string $context ): array {
		if ( ! is_array( $value ) || ( [] !== $value && array_is_list( $value ) ) ) {
			self::invalid_type( $context );
		}
		return $value;
	}

	public static function invalid_value( string $context ): never {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
		throw new \InvalidArgumentException( sprintf(
			/* translators: %s: profile field or configuration domain */
			__( 'The profile contains an invalid value for %s.', 'core-blueprint' ),
			$context
		) );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	private static function invalid_type( string $context ): never {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
		throw new \InvalidArgumentException( sprintf(
			/* translators: %s: profile field or configuration domain */
			__( 'The profile contains an invalid value type for %s.', 'core-blueprint' ),
			$context
		) );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	private function __construct() {}
}
