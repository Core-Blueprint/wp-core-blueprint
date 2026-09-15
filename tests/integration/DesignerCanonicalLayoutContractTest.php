<?php
declare(strict_types=1);

use CB\Core\Design\Editor\Assets as DesignEditorAssets;

final class CB_Designer_Canonical_Layout_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_designer_mode_enqueues_canonical_layout_between_launch_and_toolbar(): void {
		DesignEditorAssets::enqueue_designer_mode( 'Canonical layout proof' );

		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_MODE_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_LAYOUT_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT, 'enqueued' ) );

		$scripts = wp_scripts();
		$layout  = $scripts->registered[ DesignEditorAssets::DESIGNER_LAYOUT_SCRIPT ] ?? null;
		$toolbar = $scripts->registered[ DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT ] ?? null;

		self::assertInstanceOf( _WP_Dependency::class, $layout );
		self::assertInstanceOf( _WP_Dependency::class, $toolbar );
		self::assertContains( DesignEditorAssets::DESIGNER_MODE_SCRIPT, $layout->deps );
		self::assertContains( DesignEditorAssets::DESIGNER_LAYOUT_SCRIPT, $toolbar->deps );
	}

	public function test_base_owns_workspace_order_palette_roles_and_context_placement(): void {
		$layout = $this->source( 'assets/js/features/designer-layout.js' );

		self::assertStringContainsString( "const PALETTE_ROLE_ORDER = Object.freeze(['elements', 'dynamic-data']);", $layout );
		self::assertStringContainsString( 'normalizeWorkspace', $layout );
		self::assertStringContainsString( 'normalizePalette', $layout );
		self::assertStringContainsString( "workspace.dataset.cbDesignShellLayout = 'canonical';", $layout );
		self::assertStringContainsString( "palette.dataset.cbDesignShellRail = 'left';", $layout );
		self::assertStringContainsString( "root.querySelector('[data-cb-design-shell-context]')", $layout );
		self::assertStringContainsString( "start.insertBefore(context, brand.nextSibling);", $layout );
		self::assertStringContainsString( "context.dataset.cbDesignShellContextPosition = 'canonical';", $layout );
		self::assertStringContainsString( 'new MutationObserver', $layout );

		self::assertStringNotContainsString( 'cb-core-mail-', $layout );
		self::assertStringNotContainsString( 'cb-core-reports-', $layout );
		self::assertStringNotContainsString( 'cb-ce-', $layout );
	}

	public function test_base_localizes_palette_vocabulary_and_centers_context_selector(): void {
		$assets   = $this->source( 'src/Design/Editor/Assets.php' );
		$toolbar  = $this->source( 'assets/css/design/designer-toolbar.css' );
		$mail_css = $this->source( 'assets/css/pages/mail-designer.css' );

		self::assertStringContainsString( "'paletteLabels' => [", $assets );
		self::assertStringContainsString( "'elements'     => __( 'Elements', 'core-blueprint' )", $assets );
		self::assertStringContainsString( "'dynamic-data' => __( 'Dynamic data', 'core-blueprint' )", $assets );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar-context {', $toolbar );
		self::assertStringContainsString( 'align-self: center;', $toolbar );
		self::assertStringContainsString( 'block-size: var(--cb-design-toolbar-control-size);', $toolbar );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar-zone--start > .cb-core-design-shell__toolbar-context', $toolbar );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar-context > .cb-core-field__label', $toolbar );
		self::assertStringNotContainsString( '.cb-core-design-shell__toolbar-context.cb-core-mail-designer__template-control', $mail_css );
	}
}
