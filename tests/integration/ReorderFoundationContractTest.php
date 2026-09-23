<?php
declare(strict_types=1);

use CB\Core\Admin\PageRegistry;
use CB\Core\UI\Assets;

final class CB_Base_Reorder_Foundation_Contract_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( [
			'cb-core-css-reorder',
			'cb-core-css-reorder-native',
			'cb-core-css-tokens',
			'cb-core-css-modals',
			'cb-core-css-token-inputs',
		] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}


	public function test_reorder_is_a_public_semantic_foundation_requirement(): void {
		$normalized = PageRegistry::normalize_semantic_requirements(
			[ 'foundations' => [ 'reorder' ] ],
			'reorder-contract-test'
		);

		self::assertSame(
			[ 'foundations' => [ 'reorder' ], 'components' => [] ],
			$normalized
		);
	}

	public function test_unknown_foundation_requirements_still_fail_closed(): void {
		$this->setExpectedIncorrectUsage( PageRegistry::class . '::diagnostic' );

		self::assertNull(
			PageRegistry::normalize_semantic_requirements(
				[ 'foundations' => [ 'reorder-does-not-exist' ] ],
				'reorder-contract-test'
			)
		);
	}

	public function test_reorder_asset_helper_and_presentation_constants_are_public(): void {
		self::assertTrue( method_exists( Assets::class, 'enqueue_reorder' ) );
		self::assertTrue( defined( Assets::class . '::REORDER_PRESENTATION_CORE' ) );
		self::assertTrue( defined( Assets::class . '::REORDER_PRESENTATION_WP_NATIVE' ) );
		self::assertSame( 'core', Assets::REORDER_PRESENTATION_CORE );
		self::assertSame( 'wp-native', Assets::REORDER_PRESENTATION_WP_NATIVE );
	}

	public function test_wp_native_reorder_enqueue_is_narrow(): void {
		$tokens_before = wp_style_is( 'cb-core-css-tokens', 'enqueued' );

		Assets::enqueue_reorder( Assets::REORDER_PRESENTATION_WP_NATIVE );

		self::assertTrue( wp_style_is( 'cb-core-css-reorder-native', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-reorder', 'enqueued' ) );
		self::assertSame( $tokens_before, wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );

		$styles = wp_styles();
		self::assertArrayHasKey( 'cb-core-css-reorder-native', $styles->registered );
		self::assertSame( [], $styles->registered['cb-core-css-reorder-native']->deps );
	}

	public function test_core_reorder_enqueue_uses_tokens_without_loading_unrelated_foundations(): void {
		Assets::enqueue_reorder( Assets::REORDER_PRESENTATION_CORE );

		self::assertTrue( wp_style_is( 'cb-core-css-reorder', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'cb-core-css-tokens', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-modals', 'enqueued' ) );
		self::assertFalse( wp_style_is( 'cb-core-css-token-inputs', 'enqueued' ) );
	}

	public function test_reorder_source_is_a_domain_neutral_public_runtime(): void {
		$root = dirname( __DIR__, 2 );
		$runtime = file_get_contents( $root . '/assets/js/core/reorder.js' );
		self::assertIsString( $runtime );

		self::assertStringContainsString( 'window.cbCore.reorder', $runtime );
		self::assertStringContainsString( 'pointerdown', $runtime );
		self::assertStringContainsString( 'pointermove', $runtime );
		self::assertStringContainsString( 'pointerup', $runtime );
		self::assertStringContainsString( 'window.scrollBy', $runtime );
		self::assertStringContainsString( 'applyDomSnapshot(root, before)', $runtime );
		self::assertStringContainsString( 'event.altKey', $runtime );
		self::assertStringContainsString( "event.key !== 'ArrowUp'", $runtime );
		self::assertStringContainsString( "event.key !== 'ArrowDown'", $runtime );
		self::assertStringNotContainsString( 'jQuery', $runtime );
		self::assertStringNotContainsString( 'cb_doc', $runtime );
		self::assertStringNotContainsString( 'menu_order', $runtime );
		self::assertStringNotContainsString( 'taxonomy', $runtime );
	}
	public function test_structured_subfields_consume_reorder_without_private_drag_engine(): void {
		$root = dirname( __DIR__, 2 );
		$view = file_get_contents( $root . '/src/ContentModels/Admin/StructuredFieldsView.php' );
		$runtime = file_get_contents( $root . '/assets/js/features/content-models.js' );
		$registry = file_get_contents( $root . '/src/Admin/ScreenAssetRegistry.php' );
		self::assertIsString( $view );
		self::assertIsString( $runtime );
		self::assertIsString( $registry );

		self::assertStringContainsString( 'data-cb-core-reorder', $view );
		self::assertStringContainsString( 'data-cb-core-reorder-list="subfields"', $view );
		self::assertStringContainsString( 'data-cb-core-reorder-item=', $view );
		self::assertStringContainsString( 'data-cb-core-reorder-handle', $view );
		self::assertStringNotContainsString( 'draggable="true"', $view );

		self::assertStringContainsString( 'window.cbCore?.reorder', $runtime );
		self::assertStringContainsString( 'reorderFoundation.enhance', $runtime );
		self::assertStringNotContainsString( 'const bindDrag =', $runtime );
		self::assertStringContainsString( "'foundation.reorder'", $registry );
	}

}
