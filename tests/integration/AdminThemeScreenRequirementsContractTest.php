<?php
declare(strict_types=1);

use CB\Core\Admin\PageRegistry;
use CB\Core\UI\AdminTheme;

final class CB_Admin_Theme_Screen_Requirements_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( [
			'cb-core-css-buttons',
			'cb-core-css-field',
			'cb-core-css-form-controls',
		] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	public function test_native_screen_requirements_use_public_semantic_vocabulary(): void {
		$normalized = PageRegistry::normalize_semantic_requirements(
			[
				'components' => [ 'buttons', 'fields', 'form-controls' ],
			],
			'native-screen-contract-test'
		);

		self::assertSame(
			[
				'foundations' => [],
				'components'  => [ 'buttons', 'fields', 'form-controls' ],
			],
			$normalized
		);
	}

	public function test_registered_native_screen_enqueues_declared_shared_components(): void {
		$hook = 'cb-resource-post.php';

		AdminTheme::register_screen(
			$hook,
			[
				'components' => [ 'buttons', 'fields', 'form-controls' ],
			]
		);

		self::assertTrue( AdminTheme::is_registered_screen( $hook ) );

		AdminTheme::enqueue_registered_screen_requirements( $hook );

		self::assertTrue( wp_style_is( 'cb-core-css-buttons', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-field', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) );
	}

	public function test_repeated_registration_unions_requirements_for_a_shared_hook(): void {
		$hook = 'shared-native-screen.php';

		AdminTheme::register_screen( $hook, [ 'components' => [ 'fields' ] ] );
		AdminTheme::register_screen( $hook, [ 'components' => [ 'buttons', 'form-controls' ] ] );

		AdminTheme::enqueue_registered_screen_requirements( $hook );

		self::assertTrue( wp_style_is( 'cb-core-css-buttons', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-field', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-form-controls', 'enqueued' ) );
	}

	public function test_formal_button_variants_are_safe_on_native_registered_screens(): void {
		$css = file_get_contents( CB_CORE_DIR . 'assets/css/components/buttons.css' );

		self::assertIsString( $css );
		self::assertStringContainsString( '.button.cb-core-button--primary,', $css );
		self::assertStringContainsString( '.button.cb-core-button--secondary,', $css );
		self::assertStringContainsString( '.cb-core-button--danger {', $css );
	}

	public function test_gutenberg_dark_adapter_uses_semantic_core_selectors(): void {
		$css = file_get_contents( CB_CORE_DIR . 'assets/css/admin-theme/gutenberg.css' );

		self::assertIsString( $css );
		self::assertStringContainsString( 'body.cb-admin-theme .editor-post-publish-panel,', $css );
		self::assertStringContainsString( 'body.cb-admin-theme .editor-post-summary {', $css );
		self::assertStringContainsString( 'body.cb-admin-theme .editor-post-summary [data-wp-component="Text"] {', $css );
		self::assertStringContainsString( 'body.cb-admin-theme .block-editor-block-inspector__no-blocks {', $css );
		self::assertStringNotContainsString( '.css-w4lcwg', $css );
		self::assertStringNotContainsString( '.d232bf8e2132288d__is-line-clamp', $css );
	}

	public function test_existing_zero_requirement_registration_remains_supported(): void {
		$hook = 'legacy-compatible-screen.php';

		AdminTheme::register_screen( $hook );

		self::assertTrue( AdminTheme::is_registered_screen( $hook ) );
	}
}
