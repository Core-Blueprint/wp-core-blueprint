<?php
declare(strict_types=1);

use CB\Core\Design\Editor\Assets as DesignEditorAssets;

final class CB_Designer_Canonical_Layout_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_designer_mode_enqueues_canonical_layout_context_and_toolbar_chain(): void {
		DesignEditorAssets::enqueue_designer_mode( 'Canonical layout proof' );

		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_MODE_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_LAYOUT_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_CONTEXT_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_script_is( DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT, 'enqueued' ) );
		self::assertTrue( wp_style_is( DesignEditorAssets::DESIGNER_CONTEXT_STYLE, 'enqueued' ) );

		$scripts = wp_scripts();
		$layout  = $scripts->registered[ DesignEditorAssets::DESIGNER_LAYOUT_SCRIPT ] ?? null;
		$context = $scripts->registered[ DesignEditorAssets::DESIGNER_CONTEXT_SCRIPT ] ?? null;
		$toolbar = $scripts->registered[ DesignEditorAssets::DESIGNER_TOOLBAR_SCRIPT ] ?? null;

		self::assertInstanceOf( _WP_Dependency::class, $layout );
		self::assertInstanceOf( _WP_Dependency::class, $context );
		self::assertInstanceOf( _WP_Dependency::class, $toolbar );
		self::assertContains( DesignEditorAssets::DESIGNER_MODE_SCRIPT, $layout->deps );
		self::assertContains( DesignEditorAssets::DESIGNER_MODE_SCRIPT, $context->deps );
		self::assertContains( DesignEditorAssets::DESIGNER_LAYOUT_SCRIPT, $context->deps );
		self::assertContains( DesignEditorAssets::DESIGNER_CONTEXT_SCRIPT, $toolbar->deps );
	}

	public function test_base_owns_workspace_order_palette_roles_and_context_placement(): void {
		$layout = $this->source( 'assets/js/features/designer-layout.js' );

		self::assertStringContainsString( "const PALETTE_ROLE_ORDER = Object.freeze(['elements', 'dynamic-data']);", $layout );
		self::assertStringContainsString( 'normalizeWorkspace', $layout );
		self::assertStringContainsString( 'normalizePalette', $layout );
		self::assertStringContainsString( "setData(workspace, 'cbDesignShellLayout', 'canonical');", $layout );
		self::assertStringContainsString( "setData(palette, 'cbDesignShellRail', 'left');", $layout );
		self::assertStringContainsString( "root.querySelector('[data-cb-design-shell-context]')", $layout );
		self::assertStringContainsString( "start.insertBefore(context, brand.nextSibling);", $layout );
		self::assertStringContainsString( "setData(context, 'cbDesignShellContextPosition', 'canonical');", $layout );
		self::assertStringContainsString( "if (!node || node.dataset?.[key] === value) return;", $layout );
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

	public function test_base_owns_async_context_switch_lifecycle_and_canvas_transition(): void {
		$runtime = $this->source( 'assets/js/features/designer-context.js' );
		$css     = $this->source( 'assets/css/design/designer-context.css' );

		self::assertStringContainsString( "const REQUEST_EVENT = 'cb:design-shell:contextrequest';", $runtime );
		self::assertStringContainsString( "const CHANGED_EVENT = 'cb:design-shell:contextchanged';", $runtime );
		self::assertStringContainsString( 'respondWith', $runtime );
		self::assertStringContainsString( "document.addEventListener('change'", $runtime );
		self::assertStringContainsString( 'event.stopImmediatePropagation();', $runtime );
		self::assertStringContainsString( "setTransition(shell, 'loading'", $runtime );
		self::assertStringContainsString( 'control.value = previousValue;', $runtime );
		self::assertStringContainsString( '.cb-core-design-shell__context-transition', $css );
		self::assertStringContainsString( '.cb-core-design-shell.is-context-switching', $css );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );

		self::assertStringNotContainsString( 'cb-core-mail-', $runtime );
		self::assertStringNotContainsString( 'cb-core-reports-', $runtime );
		self::assertStringNotContainsString( 'cb-ce-', $runtime );
	}

	public function test_mail_consumes_context_contract_without_page_navigation_or_context_placement(): void {
		$mail = $this->source( 'assets/js/features/mail-designer.js' );
		$ajax = $this->source( 'src/Mail/Admin/DesignerAjax.php' );

		self::assertStringContainsString( "root.addEventListener('cb:design-shell:contextrequest'", $mail );
		self::assertStringContainsString( 'event.detail.respondWith(loadTemplateContext', $mail );
		self::assertStringContainsString( "action: 'cb_core_mail_designer_context'", $mail );
		self::assertStringContainsString( "session.replace(data.project, { source: 'context-switch' });", $mail );
		self::assertStringContainsString( 'window.history.replaceState', $mail );
		self::assertStringNotContainsString( 'window.location.assign(url)', $mail );
		self::assertStringNotContainsString( 'shellToolbar.prepend', $mail );
		self::assertStringNotContainsString( "templateControl.dataset.cbDesignShellContext", $mail );

		self::assertStringContainsString( "add_action( 'wp_ajax_cb_core_mail_designer_context'", $ajax );
		self::assertStringContainsString( 'current_user_can( \'manage_options\' )', $ajax );
		self::assertStringContainsString( "check_ajax_referer( 'cb_core_mail_designer_preview', 'nonce' )", $ajax );
		self::assertStringContainsString( "'template_id' => \$template_id", $ajax );
		self::assertStringContainsString( "'project'     => \$project", $ajax );
		self::assertStringContainsString( "'html'        => \$preview['html']", $ajax );
		self::assertStringContainsString( "trim( (string) wp_unslash( \$_POST['template_id'] ) )", $ajax );
		self::assertStringNotContainsString( 'sanitize_key( wp_unslash( $_POST[\'template_id\'] ) )', $ajax );
	}
}
