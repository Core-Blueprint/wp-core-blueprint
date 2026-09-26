<?php
declare(strict_types=1);

use CB\Core\Admin\PageRegistry;
use CB\Core\UI\Assets;

final class CB_Base_Interactive_Grid_Foundation_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( [
			'cb-core-css-interactive-grid',
			'cb-core-css-interactive-grid-native',
			'cb-core-css-tokens',
		] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	public function test_interactive_grid_is_a_public_semantic_foundation_requirement(): void {
		$normalized = PageRegistry::normalize_semantic_requirements(
			[ 'foundations' => [ 'interactive-grid' ] ],
			'interactive-grid-contract-test'
		);

		self::assertSame(
			[ 'foundations' => [ 'interactive-grid' ], 'components' => [] ],
			$normalized
		);
	}

	public function test_interactive_grid_asset_helper_and_presentation_constants_are_public(): void {
		self::assertTrue( method_exists( Assets::class, 'enqueue_interactive_grid' ) );
		self::assertTrue( defined( Assets::class . '::INTERACTIVE_GRID_PRESENTATION_CORE' ) );
		self::assertTrue( defined( Assets::class . '::INTERACTIVE_GRID_PRESENTATION_WP_NATIVE' ) );
		self::assertSame( 'core', Assets::INTERACTIVE_GRID_PRESENTATION_CORE );
		self::assertSame( 'wp-native', Assets::INTERACTIVE_GRID_PRESENTATION_WP_NATIVE );
	}

	public function test_wp_native_interactive_grid_enqueue_is_narrow(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		Assets::enqueue_interactive_grid( Assets::INTERACTIVE_GRID_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-interactive-grid-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-interactive-grid', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );

		$styles = wp_styles();
		self::assertArrayHasKey( 'cb-core-css-interactive-grid-native', $styles->registered );
		self::assertSame( [], $styles->registered['cb-core-css-interactive-grid-native']->deps );
	}

	public function test_core_interactive_grid_enqueue_uses_tokens_only(): void {
		Assets::enqueue_interactive_grid( Assets::INTERACTIVE_GRID_PRESENTATION_CORE );

		self::assertTrue( wp_style_is( 'cb-core-css-interactive-grid', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-interactive-grid-native', 'enqueued' ) );
	}

	public function test_interactive_grid_contract_is_domain_neutral_and_uses_real_controls(): void {
		$root = dirname( __DIR__, 2 );
		$core_css = file_get_contents( $root . '/assets/css/components/interactive-grid.css' );
		$native_css = file_get_contents( $root . '/assets/css/components/interactive-grid-native.css' );
		$doc = file_get_contents( $root . '/docs/INTERACTIVE-GRID-FOUNDATION.md' );

		self::assertIsString( $core_css );
		self::assertIsString( $native_css );
		self::assertIsString( $doc );

		foreach ( [ $core_css, $native_css ] as $css ) {
			self::assertStringContainsString( '.cb-core-interactive-grid__action', $css );
			self::assertStringContainsString( ':focus-visible', $css );
			self::assertStringContainsString( 'pointer-events: none', $css );
			self::assertStringNotContainsString( 'booking', strtolower( $css ) );
			self::assertStringNotContainsString( 'calendar', strtolower( $css ) );
		}

		self::assertStringContainsString( 'stretched action and nested controls are siblings', $doc );
		self::assertStringContainsString( 'must never nest', $doc );
		self::assertStringContainsString( 'real focusable control', $doc );
	}
}
