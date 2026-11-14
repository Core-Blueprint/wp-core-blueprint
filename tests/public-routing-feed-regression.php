<?php
declare(strict_types=1);

/**
 * Routing feed regex regression — source-only, no WordPress or database.
 *
 * Execute the actual concatenated PCRE literals in Routing/Runtime.php.
 * PHP lint cannot detect a missing regex delimiter, and the previous
 * WordPress matrix did not execute every alternate/legacy feed branch.
 */

$source = file_get_contents( dirname( __DIR__ ) . '/src/Routing/Runtime.php' );
if ( ! is_string( $source ) || '' === $source ) {
    fwrite( STDERR, "FAIL: routing runtime source unavailable\n" );
    exit( 1 );
}

$extract = <<<'PCRE'
~preg_match\(\s*'([^'\r\n]+)'\s*\.\s*\$(quoted|legacy_quoted)\s*\.\s*'([^'\r\n]+)'~
PCRE;
preg_match_all( $extract, $source, $found, PREG_SET_ORDER );

$cases = [];
foreach ( $found as $match ) {
    [ , $prefix, $source_name, $suffix ] = $match;
    if ( ! str_contains( $suffix, '(feed|rdf|rss|rss2|atom)' ) ) {
        continue;
    }

    // This requires four real, independently compiled feed variants from the
    // production method, not a copied or independently repaired test pattern.
    if ( '#(?:^|/)' !== $prefix ) {
        fwrite( STDERR, "FAIL: changed feed route prefix\n" );
        exit( 1 );
    }
    $key = $source_name . ':' . ( str_starts_with( $suffix, '/feed/' ) ? 'extended' : 'short' );
    if ( isset( $cases[ $key ] ) ) {
        fwrite( STDERR, "FAIL: duplicate feed route expression\n" );
        exit( 1 );
    }
    $cases[ $key ] = [ $prefix, $source_name, $suffix ];
}

$expected = [
    'quoted:extended'        => 'fr/news/feed/atom',
    'quoted:short'           => 'fr/news/rss2',
    'legacy_quoted:extended' => 'fr/category/news/feed/rdf',
    'legacy_quoted:short'    => 'fr/category/news/rss',
];
if ( count( $cases ) !== 4 || array_diff_key( $expected, $cases ) || array_diff_key( $cases, $expected ) ) {
    fwrite( STDERR, 'FAIL: expected exactly four canonical/legacy feed alternatives, got '
        . implode( ', ', array_keys( $cases ) ) . "\n" );
    exit( 1 );
}

set_error_handler(
    static function ( int $severity, string $message ): never {
        throw new ErrorException( $message, 0, $severity );
    },
    E_WARNING
);
try {
    foreach ( $expected as $key => $positive ) {
        [ $prefix, $source_name, $suffix ] = $cases[ $key ];
        $route = 'legacy_quoted' === $source_name ? 'category/news' : 'news';
        $regex = $prefix . preg_quote( $route, '#' ) . $suffix;

        if ( 1 !== preg_match( $regex, $positive, $captured ) ) {
            throw new RuntimeException( "Missing or invalid match: {$key} {$positive}" );
        }
        $expected_feed = match ( $key ) {
            'quoted:extended'        => 'atom',
            'quoted:short'           => 'rss2',
            'legacy_quoted:extended' => 'rdf',
            'legacy_quoted:short'    => 'rss',
        };
        if ( ( $captured[1] ?? null ) !== $expected_feed ) {
            throw new RuntimeException( "Feed group changed: {$key}" );
        }
        foreach ( [ 'fr/news/page/2', $positive . '/unexpected', 'fr/another/newsroom/feed/atom' ] as $negative ) {
            if ( 0 !== preg_match( $regex, $negative ) ) {
                throw new RuntimeException( "Feed route overmatched: {$key} {$negative}" );
            }
        }
        // The same template must safely quote category paths containing a #.
        $special_route = 'legacy_quoted' === $source_name ? 'category/news#2026' : 'news#2026';
        $special = $prefix . preg_quote( $special_route, '#' ) . $suffix;
        $positive_special = str_replace( $route, $special_route, $positive );
        if ( 1 !== preg_match( $special, $positive_special ) ) {
            throw new RuntimeException( "Feed route quoting failed: {$key}" );
        }
    }
} catch ( Throwable $error ) {
    fwrite( STDERR, 'FAIL: routing feed PCRE: ' . $error->getMessage() . "\n" );
    exit( 1 );
} finally {
    restore_error_handler();
}

echo "Base Routing canonical/legacy feed PCRE runtime regression: PASS (4 patterns, positive/negative and quoted paths)\n";
