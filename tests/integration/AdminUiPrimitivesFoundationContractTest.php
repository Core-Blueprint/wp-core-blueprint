<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\UI\Assets;

final class CB_Admin_UI_Primitives_Foundation_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( [
			'cb-core-css-icon-controls',
			'cb-core-css-icon-controls-native',
			'cb-core-css-status-indicators',
			'cb-core-css-status-indicators-native',
			'cb-core-css-segmented-control',
			'cb-core-css-segmented-control-native',
			'cb-core-css-reorder',
			'cb-core-css-reorder-native',
			'cb-core-css-tokens',
		] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	public function test_icon_control_and_segmented_control_are_public_semantic_foundations(): void {
		$normalized = PageRegistry::normalize_semantic_requirements(
			[ 'foundations' => [ 'icon-control', 'segmented-control' ] ],
			'admin-ui-primitives-contract-test'
		);

		self::assertSame(
			[ 'foundations' => [ 'icon-control', 'segmented-control' ], 'components' => [] ],
			$normalized
		);
	}

	public function test_public_asset_helpers_and_presentation_constants_exist(): void {
		self::assertTrue( method_exists( Assets::class, 'enqueue_icon_controls' ) );
		self::assertTrue( method_exists( Assets::class, 'enqueue_status' ) );
		self::assertTrue( method_exists( Assets::class, 'enqueue_segmented_control' ) );

		self::assertSame( 'core', Assets::ICON_CONTROL_PRESENTATION_CORE );
		self::assertSame( 'wp-native', Assets::ICON_CONTROL_PRESENTATION_WP_NATIVE );
		self::assertSame( 'core', Assets::STATUS_PRESENTATION_CORE );
		self::assertSame( 'wp-native', Assets::STATUS_PRESENTATION_WP_NATIVE );
		self::assertSame( 'core', Assets::SEGMENTED_CONTROL_PRESENTATION_CORE );
		self::assertSame( 'wp-native', Assets::SEGMENTED_CONTROL_PRESENTATION_WP_NATIVE );
	}

	public function test_wp_native_icon_controls_do_not_import_core_tokens(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		Assets::enqueue_icon_controls( Assets::ICON_CONTROL_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-icon-controls-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-icon-controls', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
	}

	public function test_reorder_composes_the_icon_control_presentation(): void {
		Assets::enqueue_reorder( Assets::REORDER_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-icon-controls-native', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-reorder-native', 'enqueued' ) );

		$styles = wp_styles();
		self::assertSame(
			[ 'cb-core-css-icon-controls-native' ],
			$styles->registered['cb-core-css-reorder-native']->deps
		);
	}

	public function test_wp_native_status_presentation_is_standalone_safe(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		Assets::enqueue_status( Assets::STATUS_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-status-indicators-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-status-indicators', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
	}

	public function test_wp_native_segmented_control_is_standalone_safe(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		Assets::enqueue_segmented_control( Assets::SEGMENTED_CONTROL_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-segmented-control-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-segmented-control', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
	}

	public function test_base_consumers_use_canonical_reorder_handle_presentation(): void {
		$root = dirname( __DIR__, 2 );
		$navigation = file_get_contents( $root . '/templates/preferences-admin-navigation.php' );
		$columns = file_get_contents( $root . '/src/AdminColumns/Admin/ScreenSettings.php' );
		$navigation_css = file_get_contents( $root . '/assets/css/pages/preferences.css' );
		$columns_css = file_get_contents( $root . '/assets/css/features/admin-columns.css' );

		self::assertIsString( $navigation );
		self::assertIsString( $columns );
		self::assertIsString( $navigation_css );
		self::assertIsString( $columns_css );

		self::assertStringContainsString( 'cb-core-icon-control cb-core-reorder-handle cb-core-admin-navigation-row__drag', $navigation );
		self::assertStringContainsString( 'cb-core-icon-control cb-core-reorder-handle cb-admin-columns-governance__handle', $columns );
		self::assertStringNotContainsString( '.cb-core-admin-navigation-row__drag {', $navigation_css );
		self::assertStringNotContainsString( '.cb-admin-columns-governance__handle {', $columns_css );
	}

	public function test_icon_and_segmented_css_are_domain_neutral(): void {
		$root = dirname( __DIR__, 2 );
		$icon_css = file_get_contents( $root . '/assets/css/components/icon-controls.css' );
		$segmented_css = file_get_contents( $root . '/assets/css/components/segmented-control.css' );

		self::assertIsString( $icon_css );
		self::assertIsString( $segmented_css );
		self::assertStringContainsString( '.cb-core-icon-control', $icon_css );
		self::assertStringContainsString( '.cb-core-disclosure-toggle[aria-expanded="true"]', $icon_css );
		self::assertStringContainsString( '.cb-core-segmented-control__option.is-active', $segmented_css );
		self::assertStringNotContainsString( 'booking', strtolower( $segmented_css ) );
		self::assertStringNotContainsString( 'docs', strtolower( $icon_css ) );
		self::assertStringNotContainsString( 'lms', strtolower( $icon_css ) );
	}
}
