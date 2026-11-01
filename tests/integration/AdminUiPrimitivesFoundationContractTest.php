<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\UI\Assets;

final class CB_Admin_UI_Primitives_Foundation_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( [
			'cb-core-css-buttons',
			'cb-core-css-buttons-native',
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

	public function test_optional_field_fixture_uses_public_asset_helpers(): void {
		$fixture = file_get_contents( dirname( __DIR__, 2 ) . '/tests/fixtures/base-ui-p53-field.php' );
		self::assertIsString( $fixture );
		self::assertStringContainsString( 'Assets::enqueue_buttons( $presentation )', $fixture );
		self::assertStringContainsString( 'Assets::enqueue_icon_controls( $presentation )', $fixture );
		self::assertStringContainsString( 'cb-core-details__chevron', $fixture );
		self::assertStringContainsString( 'aria-hidden="true"', $fixture );
	}

	public function test_buttons_have_public_presentation_api_and_existing_registry_semantics(): void {
		self::assertTrue( method_exists( Assets::class, 'enqueue_buttons' ) );
		self::assertSame( 'core', Assets::BUTTON_PRESENTATION_CORE );
		self::assertSame( 'wp-native', Assets::BUTTON_PRESENTATION_WP_NATIVE );
		self::assertSame(
			[ 'foundations' => [], 'components' => [ 'buttons' ] ],
			PageRegistry::normalize_semantic_requirements(
				[ 'components' => [ 'buttons' ] ],
				'button-foundation-contract-test'
			)
		);
	}

	public function test_wordpress_native_buttons_are_opt_in_and_token_independent(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		Assets::enqueue_buttons( Assets::BUTTON_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-buttons-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-buttons', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
		self::assertSame( [], wp_styles()->registered['cb-core-css-buttons-native']->deps );
	}

	public function test_core_buttons_keep_token_backed_presentation(): void {
		Assets::enqueue_buttons( Assets::BUTTON_PRESENTATION_CORE );

		self::assertTrue( wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-buttons', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-buttons-native', 'enqueued' ) );
		self::assertSame( [ 'cb-core-css-tokens' ], wp_styles()->registered['cb-core-css-buttons']->deps );
	}

	public function test_button_native_css_only_targets_explicit_component_classes(): void {
		$native = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/components/buttons-native.css' );
		$core = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/components/buttons.css' );
		self::assertIsString( $native );
		self::assertIsString( $core );

		foreach ( [ '--primary', '--secondary', '--remediation', '--danger', '--compact', '--icon-only' ] as $variant ) {
			self::assertStringContainsString( '.cb-core-button' . $variant, $native );
		}
		foreach ( [ ':hover', ':focus-visible', ':disabled', '[aria-disabled="true"]', '[aria-busy="true"]' ] as $state ) {
			self::assertStringContainsString( $state, $native );
		}
		self::assertStringContainsString( 'text-decoration: none;', $native );
		self::assertStringContainsString( ':not(dialog.cb-core-modal .cb-core-button)', $native );
		self::assertStringContainsString( '.cb-core-button.cb-core-button--danger', $native );
		self::assertDoesNotMatchRegularExpression( '/body\\.wp-admin\\s+\\.cb-core-button--/', $native );
		self::assertStringContainsString( 'var(--cb-danger, #b32d2e)', $native );
		self::assertStringContainsString( '.cb-core-button--danger', $core );
		self::assertStringContainsString( '.cb-core-button--compact.cb-core-button--icon-only', $core );
		self::assertStringNotContainsString( '@import', $native );
		self::assertDoesNotMatchRegularExpression( '/(?m)^\\s*(?:body\\.wp-admin\\s+)?\\.button(?:[\\s:{.#]|$)/', $native );
	}

	public function test_outlined_icon_and_native_details_are_opt_in_and_motion_safe(): void {
		foreach ( [ 'icon-controls.css', 'icon-controls-native.css' ] as $name ) {
			$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/components/' . $name );
			self::assertIsString( $css );
			self::assertStringContainsString( '.cb-core-icon-control--outlined', $css );
			self::assertStringContainsString( 'width: 30px;', $css );
			self::assertStringContainsString( '.cb-core-details > summary:focus-visible', $css );
			self::assertStringContainsString( '.cb-core-details__chevron::before', $css );
			self::assertStringContainsString( '.cb-core-details[open] > summary .cb-core-details__chevron', $css );
			self::assertStringContainsString( 'rotate(180deg)', $css );
			self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
			self::assertStringContainsString( '.cb-core-disclosure-toggle[aria-expanded="true"]', $css );
		}

		$assets = file_get_contents( dirname( __DIR__, 2 ) . '/src/UI/Assets.php' );
		self::assertIsString( $assets );
		self::assertStringNotContainsString( 'enqueue_details_script', $assets );
		self::assertStringContainsString( 'enqueue_icon_controls', $assets );
	}

}
