<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$primary_nav_path = $root . '/src/UI/PrimaryNav.php';
$section_nav_path = $root . '/src/UI/SectionNav.php';
$tab_nav_path = $root . '/src/Admin/TabNav.php';
$tabbed_path = $root . '/src/Admin/Tabbed.php';
$assets_path = $root . '/src/UI/Assets.php';
$css_path = $root . '/assets/css/components/admin-navigation.css';
$page_registry_path = $root . '/src/Admin/PageRegistry.php';
$asset_catalog_path = $root . '/src/Admin/AdminAssetCatalog.php';
$contract_path = $root . '/docs/ADMIN-NAVIGATION-FOUNDATION.md';

function cb_core_admin_navigation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cb_core_admin_navigation_read( string $path ): string {
	$content = file_get_contents( $path );
	if ( false === $content ) {
		throw new RuntimeException( 'Cannot read ' . $path );
	}
	return $content;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
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
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return '/wp-admin/' . ltrim( $path, '/' );
	}
}

require_once $primary_nav_path;
require_once $section_nav_path;
require_once $tab_nav_path;

$assets = cb_core_admin_navigation_read( $assets_path );
$css = cb_core_admin_navigation_read( $css_path );
$page_registry = cb_core_admin_navigation_read( $page_registry_path );
$asset_catalog = cb_core_admin_navigation_read( $asset_catalog_path );
$contract = cb_core_admin_navigation_read( $contract_path );
$tabbed = cb_core_admin_navigation_read( $tabbed_path );

$primary_html = \CoreBlueprint\Core\UI\PrimaryNav::render( [
	'items' => [
		'overview' => [ 'label' => 'Overview', 'href' => '/overview' ],
		'software' => [ 'label' => 'Software', 'href' => '/software' ],
	],
	'active' => 'software',
	'aria_label' => 'Workspace',
] );

cb_core_admin_navigation_assert(
	str_contains( $primary_html, 'class="nav-tab-wrapper cb-core-tab-wrapper"' )
		&& str_contains( $primary_html, 'class="nav-tab nav-tab-active"' )
		&& str_contains( $primary_html, 'aria-current="page"' )
		&& str_contains( $primary_html, 'aria-label="Workspace"' ),
	'PrimaryNav must render canonical Level 1 markup and active accessibility state.'
);
cb_core_admin_navigation_assert(
	'' === \CoreBlueprint\Core\UI\PrimaryNav::render( [ 'items' => [] ] ),
	'PrimaryNav must render nothing when no valid navigation items exist.'
);

$tab_html = \CoreBlueprint\Core\Admin\TabNav::build( 'sample-page', 'general', [
	'overview' => 'Overview',
	'general' => 'General',
] );
cb_core_admin_navigation_assert(
	str_contains( $tab_html, 'cb-core-tab-wrapper' )
		&& str_contains( $tab_html, '/wp-admin/admin.php?page=sample-page' )
		&& str_contains( $tab_html, 'tab=general' )
		&& str_contains( $tab_html, 'nav-tab-active' ),
	'TabNav must delegate same-page tab rendering to the canonical PrimaryNav contract.'
);
cb_core_admin_navigation_assert(
	str_contains( $tabbed, 'return TabNav::build( $page_slug, $active_tab, $tabs );' ),
	'Tabbed must delegate tab markup to TabNav instead of maintaining a second renderer.'
);

$html = \CoreBlueprint\Core\UI\SectionNav::render( [
	'items' => [
		'overview' => [ 'label' => 'Overview', 'href' => '/overview' ],
		'setup' => [ 'label' => 'Setup & Trust', 'href' => '/setup' ],
		'invalid' => [ 'label' => '', 'href' => '/invalid' ],
	],
	'active' => 'overview',
	'aria_label' => 'Distribution workspace',
] );

cb_core_admin_navigation_assert(
	str_contains( $html, 'class="cb-core-section-nav"' )
		&& str_contains( $html, 'cb-core-section-nav__item is-active' )
		&& str_contains( $html, 'aria-current="page"' )
		&& str_contains( $html, 'aria-label="Distribution workspace"' ),
	'SectionNav must render canonical navigation markup and active accessibility state.'
);
cb_core_admin_navigation_assert(
	! str_contains( $html, 'invalid' )
		&& ! str_contains( $html, 'separator' )
		&& ! str_contains( $html, ' | ' ),
	'SectionNav must skip invalid items and keep separators out of consumer markup.'
);
cb_core_admin_navigation_assert(
	'' === \CoreBlueprint\Core\UI\SectionNav::render( [ 'items' => [] ] ),
	'SectionNav must render nothing when no valid navigation items exist.'
);

cb_core_admin_navigation_assert(
	str_contains( $css, '.cb-core-navigation-stack' )
		&& str_contains( $css, 'margin: 0 0 var(--cb-space-5);' )
		&& str_contains( $css, '.cb-core-navigation-stack > .cb-core-section-nav' )
		&& str_contains( $css, 'margin-top: var(--cb-space-3);' ),
	'Navigation Stack must own canonical 24px content rhythm and 12px Level 1 to Level 2 spacing.'
);
cb_core_admin_navigation_assert(
	str_contains( $css, '.cb-core-section-nav__item + .cb-core-section-nav__item::before' )
		&& ! str_contains( $css, 'float:' ),
	'SectionNav separators must be presentation-owned and the primitive must not depend on subsubsub floats.'
);

cb_core_admin_navigation_assert(
	str_contains( $assets, 'public static function enqueue_admin_navigation(): void' )
		&& str_contains( $assets, "'cb-core-css-nav-tabs'" )
		&& str_contains( $assets, "'cb-core-css-admin-navigation'" )
		&& str_contains( $assets, "assets/css/components/admin-navigation.css" ),
	'Public Admin Navigation asset helper is incomplete.'
);
cb_core_admin_navigation_assert(
	str_contains( $assets, "[ 'cb-core-css-tokens', 'cb-core-css-nav-tabs' ]" ),
	'Admin Navigation component must depend on canonical tokens and nav-tabs presentation.'
);

cb_core_admin_navigation_assert(
	str_contains( $page_registry, "'admin-navigation'," )
		&& str_contains( $asset_catalog, "'admin-navigation'   => [ 'component.nav-tabs', 'component.admin-navigation' ]" )
		&& str_contains( $asset_catalog, "'empty-state', 'nav-tabs', 'admin-navigation'," )
		&& ! str_contains( $asset_catalog, "'admin-navigation', 'admin-navigation'" ),
	'PageRegistry and AdminAssetCatalog must expose one complete semantic admin-navigation registration.'
);
cb_core_admin_navigation_assert(
	str_contains( $contract, 'CoreBlueprint\\Core\\UI\\PrimaryNav' )
		&& str_contains( $contract, 'Level 1 - Workspace navigation' )
		&& str_contains( $contract, 'Level 2 - Section navigation' )
		&& str_contains( $contract, 'Level 3 - View or mode selector' )
		&& str_contains( $contract, 'Maximum two navigation levels.' ),
	'Admin Navigation Golden Contract is incomplete.'
);

echo "Core Blueprint Admin Navigation Foundation contract PASS\n";
