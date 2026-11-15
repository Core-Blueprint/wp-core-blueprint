#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DP-NAV-02C / GATE 1: read-only standalone Dompdf frame-to-page proof.
 *
 * Run: php tools/probe-flow-pagination.php
 * No WordPress boot, site data, DB, network, persistent PDF or vendor patches.
 * This does NOT implement interactive A4 page surfaces.
 */

const CB_DP_PROBE_MAX_CALLBACKS = 20000;
const CB_DP_PROBE_MAX_FRAGMENTS = 5000;
const CB_DP_PROBE_REGION = '/^r(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){0,7}$/D';

function cb_dp_probe_temp_directory(): string {
    $root = rtrim( sys_get_temp_dir(), '/\\' );
    $dir = $root . '/cb-dpnav02c-' . bin2hex( random_bytes( 12 ) );
    if ( ! mkdir( $dir, 0700 ) ) {
        throw new RuntimeException( 'Cannot create isolated probe font cache.' );
    }
    return $dir;
}

function cb_dp_probe_remove_temp_directory( string $path ): void {
    // Generated private flat cache; never recurse or follow symlinks.
    foreach ( glob( $path . '/*' ) ?: [] as $item ) {
        if ( is_file( $item ) || is_link( $item ) ) {
            unlink( $item );
        }
    }
    if ( is_dir( $path ) ) {
        rmdir( $path );
    }
}

function cb_dp_probe_html( string $body ): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>'
        . '@page{size:210mm 297mm;margin:15mm 18mm;}'
        . 'body{margin:0;font-family:DejaVu Sans,sans-serif;font-size:10pt;line-height:1.45;}'
        . 'p{margin:0 0 6pt;}table{width:100%;border-collapse:collapse;}'
        . 'td,th{border:0.5pt solid #777;padding:5pt;text-align:left;}'
        . 'thead{display:table-header-group;}tr{page-break-inside:avoid;}'
        . '</style></head><body>' . $body . '</body></html>';
}

/** @return array<string,string> */
function cb_dp_probe_cases(): array {
    $sentence = 'A semantic document component continues across an actual A4 page boundary. ';
    $table_rows = '';
    for ( $i = 0; $i < 90; $i++ ) {
        $table_rows .= '<tr data-cb-flow-region="r1.' . $i . '"><td>Row ' . $i
            . '</td><td>Physical page spill proof with bounded semantic row identity.</td></tr>';
    }
    return [
        'single' => cb_dp_probe_html( '<p data-cb-flow-region="r0">One short report paragraph.</p>' ),
        'paragraph_split' => cb_dp_probe_html(
            '<p data-cb-flow-region="r0">' . str_repeat( $sentence, 180 ) . '</p>'
        ),
        'table_spill' => cb_dp_probe_html(
            '<table data-cb-flow-region="r1"><thead><tr><th>ID</th><th>Text</th></tr></thead>'
            . '<tbody>' . $table_rows . '</tbody></table>'
        ),
        'nested_split' => cb_dp_probe_html(
            '<div data-cb-flow-region="r1"><div data-cb-flow-region="r1.0">'
            . '<p>' . str_repeat( $sentence, 180 ) . '</p></div></div>'
        ),
        'explicit_break' => cb_dp_probe_html(
            '<p data-cb-flow-region="r0">Before the forced break.</p>'
            . '<p style="page-break-before:always" data-cb-flow-region="r2">After the forced break.</p>'
        ),
    ];
}

/**
 * @return array{page_count:int,rendered_pages:list<int>,regions:array<string,list<int>>,
 *  fragments:int,invalid:list<string>,pdf_valid:bool,callback_count:int}
 */
function cb_dp_probe_render( string $html, string $cache ): array {
    $options = new \Dompdf\Options();
    $options->setIsRemoteEnabled( false );
    $options->setIsPhpEnabled( false );
    $options->setIsJavascriptEnabled( false );
    $options->setDefaultFont( 'DejaVu Sans' );
    $options->setFontCache( $cache );
    $options->setFontDir( $cache );
    $options->setTempDir( $cache );

    $dompdf = new \Dompdf\Dompdf( $options );
    $dompdf->setPaper( 'a4', 'portrait' );
    $dompdf->loadHtml( $html );

    $rendered_pages = [];
    $regions = [];
    $invalid = [];
    $fragments = 0;
    $callback_count = 0;
    $dompdf->setCallbacks( [
        [
            'event' => 'begin_page_render',
            'f' => static function ( $frame, $canvas ) use ( &$rendered_pages, &$callback_count ): void {
                $callback_count++;
                if ( $callback_count > CB_DP_PROBE_MAX_CALLBACKS ) {
                    throw new RuntimeException( 'Callback count exceeded safety bound.' );
                }
                $page = $canvas->get_page_number();
                if ( ! is_int( $page ) || $page < 1 || $page > 500 ) {
                    throw new RuntimeException( 'Renderer provided an invalid page number.' );
                }
                $rendered_pages[ $page ] = true;
            },
        ],
        [
            'event' => 'end_frame',
            'f' => static function ( $frame, $canvas ) use ( &$regions, &$invalid, &$fragments, &$callback_count ): void {
                $callback_count++;
                if ( $callback_count > CB_DP_PROBE_MAX_CALLBACKS ) {
                    throw new RuntimeException( 'Callback count exceeded safety bound.' );
                }
                $node = $frame->get_node();
                if ( ! $node instanceof \DOMElement || ! $node->hasAttribute( 'data-cb-flow-region' ) ) {
                    return;
                }
                if ( ++$fragments > CB_DP_PROBE_MAX_FRAGMENTS ) {
                    throw new RuntimeException( 'Frame fragment count exceeded safety bound.' );
                }
                $id = $node->getAttribute( 'data-cb-flow-region' );
                if ( 1 !== preg_match( CB_DP_PROBE_REGION, $id ) ) {
                    $invalid[] = 'invalid semantic region identifier';
                    return;
                }
                $page = $canvas->get_page_number();
                $box = $frame->get_border_box();
                if ( ! is_int( $page ) || $page < 1 || $page > 500
                    || ! is_array( $box ) || count( $box ) !== 4 ) {
                    $invalid[] = 'invalid physical page or frame geometry';
                    return;
                }
                foreach ( $box as $number ) {
                    if ( ! is_numeric( $number ) || ! is_finite( (float) $number ) ) {
                        $invalid[] = 'non-finite frame coordinate';
                        return;
                    }
                }
                [ $x, $y, $width, $height ] = array_map( 'floatval', array_values( $box ) );
                if ( $x < -1 || $y < -1 || $width <= 0 || $height <= 0
                    || $x > 2000 || $y > 2000 || $width > 2000 || $height > 2000 ) {
                    $invalid[] = 'out-of-range frame geometry';
                    return;
                }
                $regions[ $id ][ $page ] = true;
            },
        ],
    ] );
    $dompdf->render();
    $count = $dompdf->getCanvas()->get_page_count();
    $pdf = $dompdf->output();
    ksort( $rendered_pages, SORT_NUMERIC );
    ksort( $regions, SORT_STRING );
    $region_pages = [];
    foreach ( $regions as $id => $pages ) {
        ksort( $pages, SORT_NUMERIC );
        $region_pages[ $id ] = array_map( 'intval', array_keys( $pages ) );
    }
    return [
        'page_count' => $count,
        'rendered_pages' => array_map( 'intval', array_keys( $rendered_pages ) ),
        'regions' => $region_pages,
        'fragments' => $fragments,
        'invalid' => array_values( array_unique( $invalid ) ),
        'pdf_valid' => is_string( $pdf ) && str_starts_with( $pdf, '%PDF-' ),
        'callback_count' => $callback_count,
    ];
}

function cb_dp_probe_verified( string $name, array $data ): bool {
    if ( ! $data['pdf_valid'] || $data['invalid'] !== []
        || count( $data['rendered_pages'] ) !== $data['page_count']
        || $data['rendered_pages'] !== range( 1, $data['page_count'] ) ) {
        return false;
    }
    $region = static fn ( string $id ): array => $data['regions'][ $id ] ?? [];
    return match ( $name ) {
        'single' => $data['page_count'] === 1 && $region( 'r0' ) === [ 1 ],
        'paragraph_split' => $data['page_count'] >= 2 && count( $region( 'r0' ) ) >= 2,
        'table_spill' => $data['page_count'] >= 2 && count( $region( 'r1' ) ) >= 2,
        'nested_split' => $data['page_count'] >= 2 && count( $region( 'r1.0' ) ) >= 2,
        'explicit_break' => $data['page_count'] >= 2 && $region( 'r0' ) === [ 1 ]
            && $region( 'r2' ) === [ 2 ],
        default => false,
    };
}

function cb_dp_probe_main(): int {
    $root = dirname( __DIR__ );
    $autoload = $root . '/src/PDF/lib/dompdf/vendor/autoload.php';
    $version_file = $root . '/src/PDF/lib/dompdf/vendor/dompdf/dompdf/VERSION';
    if ( ! is_file( $autoload ) || trim( (string) @file_get_contents( $version_file ) ) !== '3.1.6' ) {
        fwrite( STDERR, "DP-NAV-02C: bundled Dompdf 3.1.6 is required.\n" );
        return 2;
    }
    require $autoload;
    if ( ! extension_loaded( 'dom' ) || ! extension_loaded( 'mbstring' ) ) {
        fwrite( STDERR, "DP-NAV-02C: PHP dom and mbstring extensions are required.\n" );
        return 2;
    }

    $cache = cb_dp_probe_temp_directory();
    $result = [
        'probe' => 'DP-NAV-02C standalone Dompdf 3.1.6 frame/page authority',
        'scope' => 'engineering evidence only; not an interactive paged Designer',
        'cases' => [],
    ];
    $passed = true;
    try {
        foreach ( cb_dp_probe_cases() as $name => $html ) {
            $data = cb_dp_probe_render( $html, $cache );
            $ok = cb_dp_probe_verified( $name, $data );
            $result['cases'][ $name ] = [ 'proof' => $ok ? 'PASS' : 'UNPROVEN', ...$data ];
            $passed = $passed && $ok;
        }
    } catch ( \Throwable $error ) {
        $result['failure'] = $error::class;
        $passed = false;
    } finally {
        cb_dp_probe_remove_temp_directory( $cache );
    }
    $result['status'] = $passed ? 'PROOF_CANDIDATE' : 'HOLD';
    echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
    return $passed ? 0 : 1;
}

exit( cb_dp_probe_main() );
