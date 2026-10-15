<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

/**
 * Inspect a bounded PHP source sample using PHP's lexer. Comments and quoted
 * examples must not be mistaken for real function calls. No code is executed.
 */
final class LoaderPatternInspector {
	/** @return array{suspected:bool,referenced_media:list<string>} */
	public static function inspect( string $source ): array {
		$tokens = token_get_all( $source );
		$exec = false;
		$rename = false;
		$exe_string = false;
		$referenced = [];
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}
			[ $id, $value ] = $token;
			if ( in_array( $id, [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ], true ) ) {
				$exe_string = $exe_string || stripos( $value, '.exe' ) !== false;
				preg_match_all( '/[A-Za-z0-9_.-]+\.(?:png|jpe?g|gif|webp)/i', $value, $matches );
				foreach ( (array) ( $matches[0] ?? [] ) as $name ) {
					$referenced[ strtolower( $name ) ] = $name;
				}
			}
			if ( T_STRING !== $id ) {
				continue;
			}
			$name = strtolower( $value );
			if ( ! in_array( $name, [ 'rename', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'com' ], true ) ) {
				continue;
			}
			// Method names, static calls and declarations are not the
			// global execution/rename functions this heuristic models.
			$previous = $i - 1;
			while ( $previous >= 0 && is_array( $tokens[ $previous ] )
				&& in_array( $tokens[ $previous ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$previous--;
			}
			if ( $previous >= 0 && is_array( $tokens[ $previous ] )
				&& in_array( $tokens[ $previous ][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true ) ) {
				continue;
			}
			$next = $i + 1;
			while ( $next < $count && is_array( $tokens[ $next ] )
				&& in_array( $tokens[ $next ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$next++;
			}
			if ( '(' !== ( $tokens[ $next ] ?? null ) ) {
				continue;
			}
			if ( 'rename' === $name ) {
				$rename = true;
			} elseif ( 'com' === $name ) {
				$previous = $i - 1;
				while ( $previous >= 0 && is_array( $tokens[ $previous ] )
					&& T_WHITESPACE === $tokens[ $previous ][0] ) {
					$previous--;
				}
				if ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_NEW === $tokens[ $previous ][0] ) {
					$exec = true;
				}
			} else {
				$exec = true;
			}
		}
		return [ 'suspected' => $exec && $rename && $exe_string, 'referenced_media' => array_values( $referenced ) ];
	}
}
