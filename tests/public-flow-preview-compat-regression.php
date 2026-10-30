<?php
declare(strict_types=1);

/**
 * DF-FP: bounded Flow-preview interoperability, no WordPress bootstrap or DB.
 * Exercises the real Renderer path validation and generated preview bridge.
 */
define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__ );
foreach ( [
    'src/Design/Profile/Document/Flow/Contract.php',
    'src/Design/Profile/Document/Flow/Layout.php',
    'src/Design/Profile/Document/Flow/RenderBlock.php',
    'src/Design/Profile/Document/Flow/Presentation.php',
    'src/Design/Profile/Document/Flow/HtmlRenderer.php',
    'src/Design/Editor/Assets.php',
] as $file ) {
    require_once $root . '/' . $file;
}

use CoreBlueprint\Core\Design\Profile\Document\Flow\RenderBlock;
use CoreBlueprint\Core\Design\Profile\Document\Flow\HtmlRenderer;
use CoreBlueprint\Core\Design\Editor\Assets;

function cb_flow_assert( bool $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( 'Flow-preview compatibility: ' . $message ); }
}

function cb_flow_reject( callable $run, string $message ): void {
    try { $run(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException( 'Flow-preview compatibility: expected rejection: ' . $message );
}

$renderer = new HtmlRenderer();
$reflect = new ReflectionClass( HtmlRenderer::class );
$regionsMethod = $reflect->getMethod( 'preview_region_paths' );
$blocks = array_fill( 0, 501, RenderBlock::text( 'Sample' ) );
$layout = [
    'mode' => 'flow',
    'units' => 'mm',
    'page' => [ 'width' => 210, 'height' => 297 ],
    'margins' => [ 'top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12 ],
];
$ids = static function ( int $count ): array {
    $regions = [];
    for ( $i = 0; $i < $count; $i++ ) { $regions['r' . $i] = [ $i ]; }
    return $regions;
};
foreach ( [ 1, 100, 101, 500 ] as $count ) {
    $regions = $ids( $count );
    $start = hrtime( true );
    $mapped = $regionsMethod->invoke( $renderer, $regions, $blocks );
    cb_flow_assert( count( $mapped ) === $count, $count . ' region mapping is complete' );
    $html = $renderer->render_preview( $layout, array_slice( $blocks, 0, $count ), 'en', null, $regions );
    cb_flow_assert( substr_count( $html, ' data-cb-flow-preview-region=' ) === $count, $count . ' attributes survive preview rendering' );
    cb_flow_assert( str_contains( $html, 'cb-core-flow-preview-sizing' ), 'original CSP-hashed bridge remains present' );
    $millis = ( hrtime( true ) - $start ) / 1000000;
    echo "Flow preview PHP {$count} regions: PASS (" . round( $millis, 2 ) . " ms)\n";
}
cb_flow_reject( static fn () => $regionsMethod->invoke( $renderer, $ids( 501 ), $blocks ), '501 regions' );

$leaf = RenderBlock::text( 'nested' );
for ( $depth = 1; $depth < 9; $depth++ ) { $leaf = RenderBlock::container( [ $leaf ] ); }
$nestedBlocks = [ $leaf ];
$eight = 'r0.0.0.0.0.0.0.0';
$path = array_fill( 0, 8, 0 );
$nested = $regionsMethod->invoke( $renderer, [ $eight => [ $path ] ], $nestedBlocks );
cb_flow_assert( count( $nested ) === 1 && $nested['0.0.0.0.0.0.0.0'] === $eight, 'eight-level region accepted' );
foreach ( [ 'r0.0.0.0.0.0.0.0.0', 'r01', 'r0..1', 'r0.01', 'r9007199254740992', 'r0.1.2.3.4.5.6.7.8' ] as $bad ) {
    cb_flow_reject( static fn () => $regionsMethod->invoke( $renderer, [ $bad => [ $path ] ], $nestedBlocks ), 'malformed or overdepth ID ' . $bad );
}
cb_flow_reject(
    static fn () => $regionsMethod->invoke( $renderer, [ 'r0' => [ 0 ], 'r1' => [ 0 ] ], $blocks ),
    'different region IDs cannot alias one render path'
);
cb_flow_assert(
    count( $regionsMethod->invoke( $renderer, [ 'heading:main' => [ 0 ] ], $blocks ) ) === 1,
    'legacy bounded semantic ID remains render-compatible'
);

$bridge = $reflect->getReflectionConstant( 'PREVIEW_SIZING_BRIDGE' )->getValue();
cb_flow_assert( ! str_contains( $bridge, '.slice(0,100)' ), 'no silent region truncation' );
cb_flow_assert( str_contains( $bridge, 'regionBounds.size>500' ), 'bridge has the matching bounded region count' );
cb_flow_assert( str_contains( $bridge, 'regions:null' ), 'bridge explicitly rejects invalid full sets' );
cb_flow_assert( str_contains( $bridge, 'window.parent.postMessage' ), 'original sandboxed bridge communication remains' );
cb_flow_assert( Assets::FLOW_PREVIEW_NESTED_REGIONS_API === 1, 'public same-build capability advertised' );
echo "Flow preview PHP nested/invalid/capability contract: PASS\n";
