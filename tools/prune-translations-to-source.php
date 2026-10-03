#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$domain = 'core-blueprint';
$functions = [
	'__' => [ 'msg' => 0, 'domain' => 1 ],
	'_e' => [ 'msg' => 0, 'domain' => 1 ],
	'esc_html__' => [ 'msg' => 0, 'domain' => 1 ],
	'esc_html_e' => [ 'msg' => 0, 'domain' => 1 ],
	'esc_attr__' => [ 'msg' => 0, 'domain' => 1 ],
	'esc_attr_e' => [ 'msg' => 0, 'domain' => 1 ],
	'_x' => [ 'msg' => 0, 'context' => 1, 'domain' => 2 ],
	'_ex' => [ 'msg' => 0, 'context' => 1, 'domain' => 2 ],
	'esc_html_x' => [ 'msg' => 0, 'context' => 1, 'domain' => 2 ],
	'esc_attr_x' => [ 'msg' => 0, 'context' => 1, 'domain' => 2 ],
	'_n' => [ 'msg' => 0, 'plural' => 1, 'domain' => 3 ],
	'_nx' => [ 'msg' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4 ],
	'translate' => [ 'msg' => 0, 'domain' => 1 ],
	'translate_with_gettext_context' => [ 'msg' => 0, 'context' => 1, 'domain' => 2 ],
];

function literal_value( array $tokens ): ?string {
	$expression = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				return null;
			}
			$expression .= $token[1];
			continue;
		}
		if ( '.' === $token ) {
			$expression .= '.';
			continue;
		}
		if ( '' !== trim( (string) $token ) ) {
			return null;
		}
	}
	if ( '' === $expression ) {
		return null;
	}
	try {
		$value = eval( 'return ' . $expression . ';' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- literal-only extraction helper.
	} catch ( Throwable ) {
		return null;
	}
	return is_string( $value ) ? $value : null;
}

function source_keys( string $root, string $domain, array $functions ): array {
	$files = [];
	foreach ( [ 'core-blueprint.php', 'uninstall.php' ] as $relative ) {
		if ( is_file( $root . '/' . $relative ) ) {
			$files[] = $root . '/' . $relative;
		}
	}
	foreach ( [ 'includes', 'src', 'templates' ] as $directory ) {
		$path = $root . '/' . $directory;
		if ( ! is_dir( $path ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}
	sort( $files, SORT_STRING );

	$keys = [];
	foreach ( $files as $file ) {
		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
				continue;
			}
			$spec = $functions[ $token[1] ];
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$j++;
			}
			if ( $j >= $count || '(' !== $tokens[ $j ] ) {
				continue;
			}

			$args = [];
			$current = [];
			$depth = 1;
			for ( $j++; $j < $count; $j++ ) {
				$part = $tokens[ $j ];
				if ( in_array( $part, [ '(', '[', '{' ], true ) ) {
					$depth++;
					$current[] = $part;
					continue;
				}
				if ( in_array( $part, [ ')', ']', '}' ], true ) ) {
					$depth--;
					if ( 0 === $depth ) {
						$args[] = $current;
						break;
					}
					$current[] = $part;
					continue;
				}
				if ( ',' === $part && 1 === $depth ) {
					$args[] = $current;
					$current = [];
					continue;
				}
				$current[] = $part;
			}

			if ( count( $args ) <= max( array_values( $spec ) ) ) {
				continue;
			}
			if ( $domain !== literal_value( $args[ $spec['domain'] ] ) ) {
				continue;
			}
			$msgid = literal_value( $args[ $spec['msg'] ] );
			$context = isset( $spec['context'] ) ? literal_value( $args[ $spec['context'] ] ) : null;
			if ( null === $msgid || '' === $msgid ) {
				continue;
			}
			$key = null !== $context ? $context . "\4" . $msgid : $msgid;
			$keys[ $key ] = true;
			$i = $j;
		}
	}
	ksort( $keys, SORT_STRING );
	return $keys;
}

function export_catalog( array $catalog ): string {
	$export = var_export( $catalog, true );
	$export = preg_replace( '/[ \\t]+$/m', '', $export ) ?? $export;
	return "<?php\ndeclare(strict_types=1);\n\nreturn " . $export . ";\n";
}

$source = source_keys( $root, $domain, $functions );
$language_root = $root . '/languages';
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $language_root, FilesystemIterator::SKIP_DOTS )
);

$files_changed = 0;
$messages_removed = 0;
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$path = $file->getPathname();
	if ( str_ends_with( $path, '.l10n.php' ) ) {
		continue;
	}
	$catalog = require $path;
	if ( ! is_array( $catalog ) || ! is_array( $catalog['messages'] ?? null ) ) {
		continue;
	}
	$before = $catalog['messages'];
	$after = array_intersect_key( $before, $source );
	$catalog['messages'] = $after;

	$canonical = export_catalog( $catalog );
	$current   = (string) file_get_contents( $path );
	if ( $current === $canonical ) {
		continue;
	}

	file_put_contents( $path, $canonical );
	$files_changed++;
	$messages_removed += count( $before ) - count( $after );
}

file_put_contents( $root . '/tools/canonical-i18n-count.txt', (string) count( $source ) . "\n" );

printf(
	"PASS: pruned Base translation layers to %d canonical source keys; %d files changed; %d stale message entries removed.\n",
	count( $source ),
	$files_changed,
	$messages_removed
);
