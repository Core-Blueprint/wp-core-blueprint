<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$assets_path = $root . '/src/UI/Assets.php';
$tile_path = $root . '/src/UI/Tile.php';
$tile_css_path = $root . '/assets/css/components/tile-grid.css';

function cb_core_tile_foundation_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function cb_core_tile_foundation_read( string $path ): string {
    $content = file_get_contents( $path );
    if ( false === $content ) {
        throw new RuntimeException( 'Cannot read ' . $path );
    }
    return $content;
}

$assets = cb_core_tile_foundation_read( $assets_path );
$tile_source = cb_core_tile_foundation_read( $tile_path );
$tile_css = cb_core_tile_foundation_read( $tile_css_path );

cb_core_tile_foundation_assert(
    str_contains( $assets, 'public static function enqueue_tiles(): void' ),
    'Public Tile Foundation asset helper is missing.'
);
cb_core_tile_foundation_assert(
    str_contains( $assets, "'cb-core-css-tokens'" )
        && str_contains( $assets, "'cb-core-css-tile-grid'" )
        && str_contains( $assets, "assets/css/components/tile-grid.css" ),
    'Tile Foundation must enqueue canonical tokens and tile-grid assets.'
);
cb_core_tile_foundation_assert(
    str_contains(
        $assets,
        "CB_CORE_URL . 'assets/css/components/tile-grid.css',\n\t\t\t[ 'cb-core-css-tokens' ]"
    ),
    'Tile Foundation tile-grid must depend on canonical Core tokens.'
);
cb_core_tile_foundation_assert(
    ! str_contains( $assets, "enqueue_tiles(): void {\n\t\tAdminTheme::" )
        && ! str_contains( $assets, "enqueue_tiles(): void {\n\t\twp_enqueue_style(\n\t\t\t'cb-core-css-admin-theme'" ),
    'Tile Foundation helper must not import the broader Core Admin Theme.'
);
cb_core_tile_foundation_assert(
    str_contains( $tile_source, "public const DENSITY_DEFAULT = 'default';" )
        && str_contains( $tile_source, "public const DENSITY_COMPACT = 'compact';" )
        && str_contains( $tile_source, "cb-core-tile--compact" ),
    'Tile Foundation must expose default and compact density contracts.'
);
cb_core_tile_foundation_assert(
    str_contains( $tile_css, '.cb-core-tile--compact' )
        && str_contains( $tile_css, '.cb-core-tile--navigation.cb-core-tile--compact .cb-core-tile__title' )
        && str_contains( $tile_css, '.cb-core-tile--metric.cb-core-tile--compact' ),
    'Compact Tile density CSS must cover shared, navigation and metric geometry.'
);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $value ): string {
        return $value;
    }
}

require_once $tile_path;

$default_tile = \CoreBlueprint\Core\UI\Tile::render( [
    'variant' => \CoreBlueprint\Core\UI\Tile::VARIANT_STATUS_NAV,
    'title' => 'Default',
    'state' => 'active',
] );
$compact_tile = \CoreBlueprint\Core\UI\Tile::render( [
    'variant' => \CoreBlueprint\Core\UI\Tile::VARIANT_STATUS_NAV,
    'density' => \CoreBlueprint\Core\UI\Tile::DENSITY_COMPACT,
    'title' => 'Compact',
    'state' => 'warning',
] );
$unknown_density_tile = \CoreBlueprint\Core\UI\Tile::render( [
    'variant' => \CoreBlueprint\Core\UI\Tile::VARIANT_STATUS_NAV,
    'density' => 'unknown',
    'title' => 'Fallback',
] );
$compact_metric = \CoreBlueprint\Core\UI\Tile::render( [
    'variant' => \CoreBlueprint\Core\UI\Tile::VARIANT_METRIC,
    'density' => \CoreBlueprint\Core\UI\Tile::DENSITY_COMPACT,
    'label' => 'Metric',
    'value' => '1',
] );

cb_core_tile_foundation_assert(
    ! str_contains( $default_tile, 'cb-core-tile--compact' ),
    'Default Tile density must remain backward-compatible.'
);
cb_core_tile_foundation_assert(
    str_contains( $compact_tile, 'cb-core-tile--status-nav cb-core-tile--compact' ),
    'Compact status-navigation Tile must render the canonical compact modifier.'
);
cb_core_tile_foundation_assert(
    ! str_contains( $unknown_density_tile, 'cb-core-tile--compact' ),
    'Unknown Tile density must fail safely to default presentation.'
);
cb_core_tile_foundation_assert(
    str_contains( $compact_metric, 'cb-core-tile--metric' )
        && str_contains( $compact_metric, 'cb-core-tile--compact' ),
    'Compact density must remain independent from the Tile variant.'
);

cb_core_tile_foundation_assert(
    str_contains( $tile_css, '.cb-core-tiles' )
        && str_contains( $tile_css, '.cb-core-tile--status-nav' )
        && str_contains( $tile_css, '.cb-core-tile--metric' ),
    'Canonical Tile Foundation CSS contract is incomplete.'
);

echo "Core Blueprint public Tile Foundation contract PASS\n";
