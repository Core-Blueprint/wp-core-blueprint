<?php
declare(strict_types=1);

use CB\Core\Design\Editor\Assets as DesignEditorAssets;

final class CB_Designer_Toolbar_Composition_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_designer_mode_enqueues_the_base_owned_adaptive_toolbar_runtime_and_presentation(): void {
		DesignEditorAssets::enqueue_designer_mode( 'Toolbar proof' );

		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_MODE_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_style_is( DesignEditorAssets::DESIGNER_TOOLBAR_STYLE, 'enqueued' ) );

		$scripts = wp_scripts();
		$toolbar = $scripts->registered[ DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT ] ?? null;
		self::assertInstanceOf( _WP_Dependency::class, $toolbar );
		self::assertContains( DesignEditorAssets::DESIGNER_MODE_SCRIPT, $toolbar->deps );

		$styles        = wp_styles();
		$toolbar_style = $styles->registered[ DesignEditorAssets::DESIGNER_TOOLBAR_STYLE ] ?? null;
		self::assertInstanceOf( _WP_Dependency::class, $toolbar_style );
		self::assertContains( DesignEditorAssets::DESIGNER_COMPOSITION_STYLE, $toolbar_style->deps );
	}

	public function test_compact_toolbar_is_capability_driven_and_keeps_close_and_primary_save_pinned(): void {
		$runtime = $this->source( 'assets/js/features/designer-toolbar.js' );

		self::assertStringContainsString( 'const COMPACT_WIDTH = 800;', $runtime );
		self::assertStringContainsString( 'new ResizeObserver(applyCompactState).observe(toolbar)', $runtime );
		self::assertStringContainsString( '[data-cb-design-shell-viewport]', $runtime );
		self::assertStringContainsString( 'controlsInExtension(shell, \'view\')', $runtime );
		self::assertStringContainsString( 'controlsInExtension(shell, \'actions\')', $runtime );
		self::assertStringContainsString( '[data-cb-design-shell-primary-action]', $runtime );
		self::assertStringContainsString( "const closeControl = toolbar.querySelector('[data-cb-design-shell-close], [data-cb-design-shell-fullscreen]');", $runtime );
		self::assertStringContainsString( 'const actionAnchor = closeControl || save;', $runtime );
		self::assertStringContainsString( 'before: actionAnchor', $runtime );
		self::assertStringNotContainsString( 'before: save', $runtime );
		self::assertStringContainsString( 'source.click();', $runtime );
		self::assertStringContainsString( 'new MutationObserver(() => syncProxy(record))', $runtime );
	}

	public function test_base_chrome_owns_context_close_and_layer_presentation(): void {
		$launch      = $this->source( 'assets/js/features/designer-launch.js' );
		$toolbar_css = $this->source( 'assets/css/design/designer-toolbar.css' );
		$layers_css  = $this->source( 'assets/css/design/designer-composition.css' );
		$icons       = $this->source( 'assets/js/design/shell/icons.js' );

		self::assertStringContainsString( "const contextSwitcher = toolbar.querySelector('[data-cb-design-shell-context]');", $launch );
		self::assertStringContainsString( 'if (contextSwitcher) start.append(contextSwitcher);', $launch );
		self::assertStringContainsString( "shellApi.icons.decorate(fullscreen, active ? 'x' : 'maximize-2'", $launch );
		self::assertStringNotContainsString( "active ? 'minimize-2'", $launch );
		self::assertStringContainsString( "close.dataset.cbDesignShellClose = '';", $launch );
		self::assertStringContainsString( 'if (save) end.append(save);', $launch );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar-context', $toolbar_css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-row.is-selected', $layers_css );
		self::assertStringContainsString( '.cb-core-design-shell__layer-action.button', $layers_css );
		self::assertStringContainsString( "'arrow-up': Object.freeze([", $icons );
		self::assertStringContainsString( "'arrow-down': Object.freeze([", $icons );
	}

	public function test_designer_history_keeps_one_canonical_command_history_authority(): void {
		$editor  = $this->source( 'assets/js/design/editor.js' );
		$core    = $this->source( 'assets/js/design/core/index.js' );
		$history = $this->source( 'assets/js/design/core/history.js' );
		$root    = dirname( __DIR__, 2 );

		self::assertStringContainsString( 'CommandHistory', $editor );
		self::assertStringContainsString( "export { CommandHistory } from './history.js';", $core );
		self::assertStringContainsString( 'export class CommandHistory', $history );
		self::assertStringNotContainsString( 'createSnapshotHistory', $editor );
		self::assertStringNotContainsString( 'createSnapshotHistory', $core );
		self::assertFileDoesNotExist( $root . '/assets/js/design/core/snapshot-history.js' );
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

	public function test_toolbar_controls_share_one_canonical_geometry_and_compact_save_does_not_look_larger(): void {
		$toolbar_css = $this->source( 'assets/css/design/designer-toolbar.css' );

		self::assertStringContainsString( '--cb-design-toolbar-control-size: calc(var(--cb-control-height) + var(--cb-space-1));', $toolbar_css );
		self::assertStringContainsString( 'inline-size: var(--cb-design-toolbar-control-size) !important;', $toolbar_css );
		self::assertStringContainsString( 'block-size: var(--cb-design-toolbar-control-size) !important;', $toolbar_css );
		self::assertStringContainsString( 'align-self: center;', $toolbar_css );
		self::assertStringContainsString( '.cb-core-design-shell__compact-menu', $toolbar_css );
		self::assertStringContainsString( '[data-cb-design-shell-primary-action].cb-core-button--primary', $toolbar_css );
		self::assertStringContainsString( 'box-shadow: inset 0 0 0 1px currentColor !important;', $toolbar_css );
	}

	public function test_drawer_launcher_visibility_remains_owned_by_responsive_designer_mode_after_icon_decoration(): void {
		$designer_css = $this->source( 'assets/css/design/designer-mode.css' );
		$toolbar_css  = $this->source( 'assets/css/design/designer-toolbar.css' );
		$icons        = $this->source( 'assets/js/design/shell/icons.js' );

		self::assertStringContainsString(
			".cb-core-design-shell__drawer-launcher {\n\tdisplay: none !important;\n}",
			$designer_css
		);
		self::assertMatchesRegularExpression(
			'/@media \(max-width: 1280px\).*?\.cb-core-design-shell__drawer-launcher\s*\{\s*display:\s*inline-flex !important;/s',
			$designer_css
		);
		self::assertStringContainsString(
			"control.classList?.add('cb-core-design-shell__icon-button');",
			$icons
		);
		self::assertStringContainsString(
			'.cb-core-design-shell__icon-button:not(.cb-core-design-shell__drawer-launcher),',
			$toolbar_css
		);
		self::assertStringContainsString(
			".cb-core-design-shell__icon-button,\n\t.cb-core-design-shell__drawer-launcher,\n\t.cb-core-design-shell__compact-menu-trigger,\n\t[data-cb-design-shell-primary-action]\n) {\n\talign-items: center !important;",
			$toolbar_css
		);
	}

	public function test_compact_toolbar_presentation_is_base_owned_and_not_mail_owned(): void {
		$css         = $this->source( 'assets/css/design/designer-mode.css' );
		$toolbar_css = $this->source( 'assets/css/design/designer-toolbar.css' );
		$mail_css    = $this->source( 'assets/css/pages/mail-designer.css' );
		$icons       = $this->source( 'assets/js/design/shell/icons.js' );

		self::assertStringContainsString( '.cb-core-design-shell__toolbar--designer.is-compact', $css );
		self::assertStringContainsString( '.cb-core-design-shell__compact-menu-popover', $css );
		self::assertStringContainsString( '.cb-core-design-shell__compact-menu-item', $css );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar--designer', $toolbar_css );
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
