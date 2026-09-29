<?php
declare(strict_types=1);

use CB\Core\Admin\PageRegistry;
use CB\Core\Design\Editor\Assets as DesignEditorAssets;

final class CB_Design_Foundation_Editor_Public_Boundary_Test extends WP_UnitTestCase {

	public function test_design_editor_is_a_public_semantic_foundation_requirement(): void {
		$normalized = PageRegistry::normalize_semantic_requirements(
			[ 'foundations' => [ 'design-editor' ] ],
			'fixture:design-editor'
		);

		self::assertSame(
			[ 'foundations' => [ 'design-editor' ], 'components' => [] ],
			$normalized
		);
	}

	public function test_public_module_identifier_and_asset_path_are_base_owned(): void {
		self::assertSame( '@cb-core/design-editor', DesignEditorAssets::MODULE_ID );

		$path = dirname( __DIR__, 2 ) . '/src/Design/Editor/Assets.php';
		$source = (string) file_get_contents( $path );
		self::assertStringContainsString( "CB_CORE_URL . 'assets/js/design/editor.js'", $source );
		self::assertStringContainsString( 'wp_enqueue_script_module(', $source );
	}

	public function test_public_facade_hides_private_editor_file_layout_from_semantic_consumers(): void {
		$page_registry = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/PageRegistry.php' );
		self::assertStringContainsString( "'design-editor'", $page_registry );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue();', $page_registry );
		self::assertStringNotContainsString( 'assets/js/design/core/', $page_registry );
		self::assertStringNotContainsString( 'assets/js/design/document/', $page_registry );

		$facade = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/design/editor.js' );
		self::assertStringContainsString( 'window.cbCore.designEditor = publicApi;', $facade );
		self::assertStringContainsString( "'document-flow'", $facade );
		self::assertStringContainsString( "'document-fixed'", $facade );
		self::assertStringContainsString( 'allowCommand', $facade );
		self::assertStringContainsString( 'onChange', $facade );
	}
	public function test_public_browser_api_is_frozen_and_first_party_consumers_do_not_bypass_it(): void {
		$root = dirname( __DIR__, 2 );
		$docs = (string) file_get_contents( $root . '/docs/DESIGNER-FOUNDATION-API.md' );

		self::assertStringContainsString( 'Status: **public v1 frozen contract**.', $docs );
		self::assertStringContainsString( "@cb-core/design-editor", $docs );
		self::assertStringContainsString( 'createSession(options)', $docs );
		self::assertStringContainsString( 'createDesignerShell(root, options)', $docs );
		self::assertStringContainsString( 'createDesignerSelectionController(options)', $docs );
		self::assertStringContainsString( 'commands.insertNode', $docs );
		self::assertStringContainsString( 'document-flow', $docs );
		self::assertStringContainsString( 'createFlowPreviewHost', $docs );
		self::assertStringContainsString( 'ProjectState', $docs );
		self::assertStringContainsString( 'does **not** make them a supported extension contract', $docs );

		foreach ( glob( $root . '/assets/js/features/*.js' ) ?: [] as $path ) {
			$source = (string) file_get_contents( $path );
			if ( ! str_contains( $source, '@cb-core/design-editor' ) ) {
				continue;
			}
			self::assertSame(
				0,
				preg_match( '/from\\s+[\'"]\.\.\/design\//', $source ),
				basename( $path ) . ' must not mix the public Designer module with private Design source imports.'
			);
		}
	}
}
