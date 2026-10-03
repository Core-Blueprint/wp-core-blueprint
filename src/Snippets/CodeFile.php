<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical on-disk snippet code format.
 *
 * PHP snippets are executable managed files with a direct-access guard.
 * Non-PHP snippets use a guarded __halt_compiler payload so their data can be
 * read without evaluating a generated PHP return expression.
 */
final class CodeFile {
	private const PHP_PREFIX  = "<?php\ndefined( 'ABSPATH' ) || exit;\n\n";
	private const DATA_PREFIX = "<?php\ndefined( 'ABSPATH' ) || exit;\n__halt_compiler();\n";

	public static function build( string $type, string $code ): string {
		if ( 'php' === $type ) {
			return self::PHP_PREFIX . $code;
		}

		return self::DATA_PREFIX . base64_encode( $code );
	}

	/**
	 * Read only the canonical format. Returns null for missing, malformed or
	 * out-of-band modified wrappers instead of executing untrusted wrapper PHP.
	 */
	public static function read( string $type, string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$contents = file_get_contents( $path );
		if ( ! is_string( $contents ) ) {
			return null;
		}

		if ( 'php' === $type ) {
			return str_starts_with( $contents, self::PHP_PREFIX )
				? substr( $contents, strlen( self::PHP_PREFIX ) )
				: null;
		}

		if ( ! str_starts_with( $contents, self::DATA_PREFIX ) ) {
			return null;
		}
		$decoded = base64_decode( substr( $contents, strlen( self::DATA_PREFIX ) ), true );
		return false === $decoded ? null : $decoded;
	}
}
