#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$domain = 'core-blueprint';
$locales = [ 'nl_NL', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT' ];

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

function fail_translation_check( string $message ): never {
    fwrite( STDERR, "[translations] ERROR: {$message}\n" );
    exit( 1 );
}

function literal_translation_value( array $tokens ): ?string {
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
        /** @var mixed $value */
        $value = eval( 'return ' . $expression . ';' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- only literal string tokens and concatenation are admitted above.
    } catch ( Throwable ) {
        return null;
    }

    return is_string( $value ) ? $value : null;
}

function translation_placeholders( string $value ): array {
    preg_match_all( "/%(?:\\d+\\$)?[-+0 #'\\.\\d]*[bcdeEfFgGosuxX]/", str_replace( '%%', '', $value ), $matches );
    $items = $matches[0] ?? [];
    sort( $items );
    return $items;
}

function translation_key_list( array $entries ): string {
    if ( [] === $entries ) {
        return '(none)';
    }

    $keys = array_map(
        static fn ( string $key ): string => str_replace( "\4", ' [context] ', $key ),
        array_keys( $entries )
    );
    sort( $keys, SORT_STRING );
    return implode( ' | ', $keys );
}

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
        if ( $file->isFile() && 'php' === strtolower($file->getExtension()) ) {
            $files[] = $file->getPathname();
        }
    }
}
sort( $files );

$source = [];
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

        $required = max( array_values( $spec ) );
        if ( count( $args ) <= $required ) {
            continue;
        }

        $call_domain = literal_translation_value( $args[ $spec['domain'] ] );
        if ( $domain !== $call_domain ) {
            continue;
        }

        $msgid = literal_translation_value( $args[ $spec['msg'] ] );
        if ( null === $msgid || '' === $msgid ) {
            continue;
        }
        $plural = isset( $spec['plural'] ) ? literal_translation_value( $args[ $spec['plural'] ] ) : null;
        $context = isset( $spec['context'] ) ? literal_translation_value( $args[ $spec['context'] ] ) : null;
        if ( isset( $spec['plural'] ) && null === $plural ) {
            continue;
        }
        if ( isset( $spec['context'] ) && null === $context ) {
            continue;
        }

        $key = null !== $context ? $context . "\4" . $msgid : $msgid;
        if ( isset( $source[ $key ] ) && $source[ $key ]['plural'] !== $plural ) {
            // A singular occurrence and plural occurrence may intentionally share
            // the same gettext key. The plural form is the stronger contract.
            if ( null !== $plural ) {
                $source[ $key ]['plural'] = $plural;
            }
        } else {
            $source[ $key ] ??= [ 'msgid' => $msgid, 'plural' => $plural ];
        }
        $i = $j;
    }
}

if ( 3376 !== count( $source ) ) {
    fail_translation_check( 'Expected 3376 canonical source keys, found ' . count( $source ) . '.' );
}

if ( in_array( '--export-source', $argv ?? [], true ) ) {
    echo json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
    exit( 0 );
}

$legacy = glob( $root . '/languages/*.{po,mo,pot}', GLOB_BRACE ) ?: [];
if ( [] !== $legacy ) {
    fail_translation_check( 'Legacy PO/MO/POT runtime artifacts remain: ' . implode( ', ', array_map( 'basename', $legacy ) ) );
}

foreach ( $locales as $locale ) {
    $path = $root . '/languages/core-blueprint-' . $locale . '.l10n.php';
    if ( ! is_file( $path ) ) {
        fail_translation_check( "Missing {$locale} PHP catalog." );
    }
    $catalog = require $path;
    if ( ! is_array( $catalog ) || ! isset( $catalog['messages'] ) || ! is_array( $catalog['messages'] ) ) {
        fail_translation_check( "Invalid {$locale} PHP catalog payload." );
    }
    if ( 'Core Blueprint 1.0.0-rc1' !== ( $catalog['project-id-version'] ?? '' ) ) {
        fail_translation_check( "Unexpected {$locale} project version header." );
    }
    if ( $locale !== ( $catalog['language'] ?? '' ) ) {
        fail_translation_check( "Unexpected {$locale} language header." );
    }
    if ( 'fr_FR' === $locale && ! str_contains( (string) ( $catalog['plural-forms'] ?? '' ), 'n > 1' ) ) {
        fail_translation_check( 'French plural rule must use n > 1.' );
    }

    $messages = [];
    foreach ( $catalog['messages'] as $message_key => $message_value ) {
        $messages[ (string) $message_key ] = $message_value;
    }
    $missing = array_diff_key( $source, $messages );
    $extra = array_diff_key( $messages, $source );
    if ( [] !== $missing || [] !== $extra ) {
        fail_translation_check(
            sprintf(
                '%s catalog/source mismatch: %d missing, %d extra. Missing keys: %s. Extra keys: %s.',
                $locale,
                count( $missing ),
                count( $extra ),
                translation_key_list( $missing ),
                translation_key_list( $extra )
            )
        );
    }

    foreach ( $source as $key => $entry ) {
        $translation = $messages[ $key ];
        if ( ! is_string( $translation ) || '' === $translation ) {
            fail_translation_check( "{$locale} has an empty translation for {$entry['msgid']}." );
        }

        if ( null === $entry['plural'] ) {
            if ( translation_placeholders( $entry['msgid'] ) !== translation_placeholders( $translation ) ) {
                fail_translation_check( "{$locale} placeholder mismatch for {$entry['msgid']}." );
            }
            continue;
        }

        $forms = explode( "\0", $translation );
        if ( 2 !== count( $forms ) ) {
            fail_translation_check( "{$locale} expected 2 plural forms for {$entry['msgid']}." );
        }
        if ( translation_placeholders( $entry['msgid'] ) !== translation_placeholders( $forms[0] ) ) {
            fail_translation_check( "{$locale} singular plural-form placeholder mismatch for {$entry['msgid']}." );
        }
        if ( translation_placeholders( (string) $entry['plural'] ) !== translation_placeholders( $forms[1] ) ) {
            fail_translation_check( "{$locale} plural placeholder mismatch for {$entry['msgid']}." );
        }
    }
}

printf( "PASS: translation source/PHP catalogs aligned (%d messages; %d locales).\n", count( $source ), count( $locales ) );
