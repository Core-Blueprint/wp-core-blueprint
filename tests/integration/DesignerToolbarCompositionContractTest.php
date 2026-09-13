<?php
declare(strict_types=1);

use CB\Core\Design\Editor\Assets as DesignEditorAssets;

final class CB_Designer_Toolbar_Composition_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_designer_mode_enqueues_the_base_owned_adaptive_toolbar_runtime(): void {
		DesignEditorAssets::enqueue_designer_mode( 'Toolbar proof' );

		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_MODE_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT, 'enqueued' ) );

		$scripts = wp_scripts();
		$toolbar = $scripts->registered[ DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT ] ?? null;
		self::assertInstanceOf( _WP_Dependency::class, $toolbar );
		self::assertContains( DesignEditorAssets::DESIGNER_MODE_SCRIPT, $toolbar->deps );
	}

	public function test_compact_toolbar_is_capability_driven_and_keeps_primary_save_pinned(): void {
		$runtime = $this->source( 'assets/js/features/designer-toolbar.js' );

		self::assertStringContainsString( 'const COMPACT_WIDTH = 800;', $runtime );
		self::assertStringContainsString( 'new ResizeObserver(applyCompactState).observe(toolbar)', $runtime );
		self::assertStringContainsString( '[data-cb-design-shell-viewport]', $runtime );
		self::assertStringContainsString( 'controlsInExtension(shell, \'view\')', $runtime );
		self::assertStringContainsString( 'controlsInExtension(shell, \'actions\')', $runtime );
		self::assertStringContainsString( '[data-cb-design-shell-primary-action]', $runtime );
		self::assertStringContainsString( 'before: save', $runtime );
		self::assertStringContainsString( 'source.click();', $runtime );
		self::assertStringContainsString( 'new MutationObserver(() => syncProxy(record))', $runtime );
	}

	public function test_toolbar_extensions_are_declared_outside_internal_toolbar_structure(): void {
		$runtime = $this->source( 'assets/js/features/designer-toolbar.js' );
		$css     = $this->source( 'assets/css/design/designer-mode.css' );

		self::assertStringContainsString( 'data-cb-design-shell-toolbar-extension', $runtime );
		self::assertStringContainsString( 'createExtensionGroup', $runtime );
		self::assertStringContainsString( 'cb-core-design-shell__toolbar-group--extension', $runtime );
		self::assertStringContainsString( '[data-cb-design-shell-toolbar-extension]', $css );
		self::assertStringContainsString( 'display: none !important;', $css );
	}

	public function test_compact_toolbar_owns_disclosures_escape_and_outside_click_without_aria_menu_mismatch(): void {
		$runtime = $this->source( 'assets/js/features/designer-toolbar.js' );

		self::assertStringContainsString( "trigger.setAttribute('aria-expanded', 'false')", $runtime );
		self::assertStringContainsString( "trigger.setAttribute('aria-controls', panel.id)", $runtime );
		self::assertStringContainsString( "window.addEventListener('keydown'", $runtime );
		self::assertStringContainsString( "event.key !== 'Escape'", $runtime );
		self::assertStringContainsString( "document.addEventListener('pointerdown'", $runtime );
		self::assertStringNotContainsString( 'aria-haspopup', $runtime );
	}

	public function test_compact_toolbar_presentation_is_base_owned_and_not_mail_owned(): void {
		$css      = $this->source( 'assets/css/design/designer-mode.css' );
		$mail_css = $this->source( 'assets/css/pages/mail-designer.css' );
		$icons    = $this->source( 'assets/js/design/shell/icons.js' );

		self::assertStringContainsString( '.cb-core-design-shell__toolbar--designer.is-compact', $css );
		self::assertStringContainsString( '.cb-core-design-shell__compact-menu-popover', $css );
		self::assertStringContainsString( '.cb-core-design-shell__compact-menu-item', $css );
		self::assertStringContainsString( '[data-cb-design-shell-compact-group]', $css );
		self::assertStringContainsString( 'ellipsis: Object.freeze([', $icons );
		self::assertStringNotContainsString( 'cb-core-design-shell__compact-menu', $mail_css );
	}

	public function test_public_docs_define_the_extendable_toolbar_ownership_boundary(): void {
		$docs = $this->source( 'docs/DESIGNER-MODE.md' );

		self::assertStringContainsString( '## Adaptive toolbar composition', $docs );
		self::assertStringContainsString( 'data-cb-design-shell-toolbar-extension="view"', $docs );
		self::assertStringContainsString( 'data-cb-design-shell-toolbar-extension="actions"', $docs );
		self::assertStringContainsString( 'data-cb-design-shell-primary-action', $docs );
		self::assertStringContainsString( 'actual toolbar width', $docs );
		self::assertStringContainsString( 'Consumers must not implement their own mobile toolbar', $docs );
	}
}
