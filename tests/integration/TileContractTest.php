<?php
declare(strict_types=1);

use CB\Core\UI\Tile;

final class CB_Base_Tile_Contract_Test extends WP_UnitTestCase {

	public function test_default_variant_is_canonical_navigation(): void {
		$html = Tile::render( [
			'title' => 'Default navigation',
			'href'  => 'https://example.test/',
			'state' => 'active',
		] );

		self::assertStringContainsString( 'cb-core-tile--navigation', $html );
		self::assertStringNotContainsString( 'cb-core-tile--status-nav', $html );
		self::assertStringNotContainsString( 'cb-core-tile__dot', $html );
	}

	public function test_unknown_variant_fails_to_canonical_navigation(): void {
		$html = Tile::render( [
			'variant' => 'unknown-variant',
			'title'   => 'Fallback navigation',
		] );

		self::assertStringContainsString( 'cb-core-tile--navigation', $html );
	}

	public function test_status_navigation_retains_explicit_state_dot_contract(): void {
		$html = Tile::render( [
			'variant' => Tile::VARIANT_STATUS_NAV,
			'title'   => 'Status navigation',
			'state'   => 'warning',
		] );

		self::assertStringContainsString( 'cb-core-tile--status-nav', $html );
		self::assertStringContainsString( 'cb-core-tile__dot--warning', $html );
	}

	public function test_quick_variant_is_not_part_of_the_v1_tile_contract(): void {
		self::assertFalse( defined( Tile::class . '::VARIANT_QUICK' ) );

		$source = file_get_contents( CB_CORE_DIR . 'src/UI/Tile.php' );
		$css    = file_get_contents( CB_CORE_DIR . 'assets/css/components/tile-grid.css' );
		self::assertIsString( $source );
		self::assertIsString( $css );
		self::assertStringNotContainsString( 'VARIANT_QUICK', $source );
		self::assertStringNotContainsString( 'render_quick', $source );
		self::assertStringNotContainsString( 'cb-core-tile--quick', $css );
	}
}
