<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Snippets\Validation;

defined( 'ABSPATH' ) || exit;

final class PhpValidator {
	/**
	 * Parse PHP without executing it. TOKEN_PARSE invokes PHP's parser and throws
	 * ParseError for invalid syntax, avoiding eval() and all validation side effects.
	 */
	public static function validate( string $code ): ?string {
		try {
			$tokens = token_get_all( "<?php\n" . $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return sprintf(
				/* translators: %s: PHP parser error */
				__( 'PHP syntax error: %s', 'core-blueprint' ),
				$e->getMessage()
			);
		}

		$seen_prefix_open_tag = false;
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$id = (int) $token[0];
			if ( T_OPEN_TAG === $id && ! $seen_prefix_open_tag ) {
				$seen_prefix_open_tag = true;
				continue;
			}

			if ( in_array( $id, [ T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG ], true ) ) {
				return __( 'Do not include PHP opening or closing tags in a PHP snippet.', 'core-blueprint' );
			}
			if ( T_NAMESPACE === $id || T_HALT_COMPILER === $id ) {
				return __( 'Namespace declarations and __halt_compiler are not supported in managed snippets.', 'core-blueprint' );
			}
		}

		return null;
	}
}
