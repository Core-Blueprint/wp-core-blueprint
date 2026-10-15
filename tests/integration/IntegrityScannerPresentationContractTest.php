<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\AdminAssetCatalog;
use CoreBlueprint\Core\Admin\ScreenAssetRegistry;
use CoreBlueprint\Core\Admin\ScreenContext;

/**
 * Scanner UI must consume the canonical Base disclosure/interactive row styles.
 * These are asset/presentation checks, not a security scan or database mutation.
 */
final class IntegrityScannerPresentationContractTest extends WP_UnitTestCase {

	public function test_scanner_requires_shared_interactive_surfaces_without_loading_it_on_other_safeguards_tabs(): void {
		$method = new ReflectionMethod( ScreenAssetRegistry::class, 'safeguards_requirements' );
		$scanner = $method->invoke( null, 'core-scanner' );
		$failsafe = $method->invoke( null, 'failsafe' );

		self::assertContains( 'page.safeguards-core-scanner', $scanner );
		self::assertContains( 'component.interactive-surfaces', $scanner );
		self::assertNotContains( 'component.interactive-surfaces', $failsafe );
	}

	public function test_shared_interactive_surfaces_asset_resolves_to_canonical_stylesheet(): void {
		$handle = 'cb-core-css-interactive-surfaces';
		wp_dequeue_style( $handle );

		try {
			$context = ScreenContext::from_request( 'toplevel_page_' . CB_CORE_PARENT_MENU );
			AdminAssetCatalog::enqueue( 'component.interactive-surfaces', $context );

			self::assertTrue( wp_style_is( $handle, 'enqueued' ) );
			self::assertArrayHasKey( $handle, wp_styles()->registered );
			self::assertStringEndsWith(
				'assets/css/components/interactive-surfaces.css',
				(string) wp_styles()->registered[ $handle ]->src
			);
		} finally {
			wp_dequeue_style( $handle );
		}
	}
}
