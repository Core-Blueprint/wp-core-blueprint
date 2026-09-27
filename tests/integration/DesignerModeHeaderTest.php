<?php
declare(strict_types=1);

final class CB_Designer_Mode_Header_Test extends WP_UnitTestCase {

	public function test_designer_mode_chrome_reuses_shared_fullscreen_runtime(): void {
		$root = dirname( __DIR__, 2 );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );

		self::assertStringContainsString( "fullscreen.click()", $launch );
		self::assertStringContainsString( "shell.addEventListener('cb:design-shell:fullscreenchange'", $launch );
		self::assertStringNotContainsString( 'createDesignerShell', $launch );
		self::assertStringNotContainsString( 'requestFullscreen', $launch );
	}

	public function test_designer_launch_retries_until_shared_editor_module_is_available(): void {
		$root = dirname( __DIR__, 2 );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );

		self::assertStringContainsString( 'const BOOT_RETRY_DELAY_MS = 50;', $launch );
		self::assertStringContainsString( 'const BOOT_RETRY_LIMIT = 200;', $launch );
		self::assertStringContainsString( "window.addEventListener('cb:design-editor:ready', attemptBoot)", $launch );
		self::assertStringContainsString( 'retryTimer = window.setTimeout(() => {', $launch );
		self::assertStringContainsString( 'if (boot()) {', $launch );
		self::assertStringContainsString( "window.removeEventListener('cb:design-editor:ready', attemptBoot)", $launch );
	}

	public function test_designer_header_uses_shared_toolbar_and_viewport_contracts(): void {
		$root = dirname( __DIR__, 2 );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );
		$viewports = (string) file_get_contents( $root . '/assets/js/design/shell/viewports.js' );
		$template = (string) file_get_contents( $root . '/templates/mail-designer.php' );
		$shell_css = (string) file_get_contents( $root . '/assets/css/design/editor-shell.css' );
		$mail_css = (string) file_get_contents( $root . '/assets/css/pages/mail-designer.css' );

		self::assertStringContainsString( "[data-cb-design-shell-undo]", $launch );
		self::assertStringContainsString( "[data-cb-design-shell-redo]", $launch );
		self::assertStringContainsString( "[data-cb-design-shell-fullscreen]", $launch );
		self::assertStringContainsString( "[data-cb-design-shell-primary-action]", $launch );
		self::assertStringContainsString( "[data-cb-design-shell-viewport]", $launch );
		self::assertStringContainsString( 'shellApi.configureViewports(shell)', $launch );
		self::assertStringContainsString( "DESIGNER_VIEWPORT_ORDER = Object.freeze(['mobile', 'tablet', 'desktop'])", $viewports );
		self::assertStringContainsString( "cb:design-shell:viewportchange", $viewports );
		self::assertStringContainsString( 'data-cb-design-shell-viewport="tablet"', $template );
		self::assertStringContainsString( 'cb-core-design-shell__toolbar--designer', $launch );
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);', $shell_css );
		self::assertStringContainsString( '.cb-core-mail-designer__preview-frame.is-tablet iframe', $mail_css );
		self::assertStringContainsString( 'max-width: 782px;', $mail_css );
	}

	public function test_designer_mode_is_launch_only_before_fullscreen_and_toolbar_actions_do_not_shrink(): void {
		$root = dirname( __DIR__, 2 );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );
		$shell_css = (string) file_get_contents( $root . '/assets/css/design/editor-shell.css' );
		$mail_css = (string) file_get_contents( $root . '/assets/css/pages/mail-designer.css' );

		self::assertStringContainsString( "root.classList.toggle('is-designer-mode-active', active)", $launch );
		self::assertStringContainsString( ".cb-core-mail-designer__workspace {\n\tdisplay: none;", $mail_css );
		self::assertStringContainsString( '.cb-core-mail-designer.is-designer-mode-active .cb-core-mail-designer__workspace', $mail_css );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar--designer .cb-core-design-shell__toolbar-group', $shell_css );
		self::assertStringContainsString( "\tflex: 0 0 auto;", $shell_css );
		self::assertStringContainsString( '.cb-core-design-shell__toolbar--designer .cb-core-design-shell__toolbar-status:empty', $shell_css );
	}

	public function test_foundation_owns_lucide_designer_icons_sidebar_roles_motion_and_viewport_state(): void {
		$root = dirname( __DIR__, 2 );
		$icons = (string) file_get_contents( $root . '/assets/js/design/shell/icons.js' );
		$shell = (string) file_get_contents( $root . '/assets/js/design/shell/index.js' );
		$viewports = (string) file_get_contents( $root . '/assets/js/design/shell/viewports.js' );
		$editor = (string) file_get_contents( $root . '/assets/js/design/editor.js' );
		$shell_css = (string) file_get_contents( $root . '/assets/css/design/editor-shell.css' );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );

		self::assertStringContainsString( 'Lucide Icons and Contributors', $icons );
		self::assertStringContainsString( "'undo-2'", $icons );
		self::assertStringContainsString( "'redo-2'", $icons );
		self::assertStringContainsString( "'sliders-horizontal'", $icons );
		self::assertStringContainsString( "'settings-2'", $icons );
		self::assertStringContainsString( "['inspector', 'layers', 'settings']", $shell );
		self::assertStringContainsString( 'configureDesignerSidebar', $shell );
		self::assertStringContainsString( "mobile: 'smartphone'", $viewports );
		self::assertStringContainsString( "tablet: 'tablet'", $viewports );
		self::assertStringContainsString( "desktop: 'monitor'", $viewports );
		self::assertStringContainsString( 'root.dataset.cbDesignShellViewport = value', $viewports );
		self::assertStringContainsString( 'configureViewports: configureDesignerViewports', $editor );
		self::assertStringContainsString( 'viewportOrder: DESIGNER_VIEWPORT_ORDER', $editor );
		self::assertStringNotContainsString( 'data-cb-mail-', $launch );
		self::assertStringNotContainsString( 'const ICONS', $launch );
		self::assertStringContainsString( '.cb-core-design-shell.is-fullscreen.is-entering', $shell_css );
		self::assertStringContainsString( '.cb-core-design-shell.is-fullscreen.is-exiting', $shell_css );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $shell_css );
	}

	public function test_designer_mode_is_exposed_through_the_public_design_foundation_asset_boundary(): void {
		$root = dirname( __DIR__, 2 );
		$assets = (string) file_get_contents( $root . '/src/Design/Editor/Assets.php' );
		$page = (string) file_get_contents( $root . '/src/Mail/Admin/Page.php' );

		self::assertStringContainsString( "public const DESIGNER_MODE_SCRIPT = 'cb-core-designer-mode';", $assets );
		self::assertStringContainsString( "public static function enqueue_designer_mode( string \$title = '' ): void", $assets );
		self::assertStringContainsString( "CB_CORE_URL . 'assets/js/features/designer-launch.js'", $assets );
		self::assertStringContainsString( "'cbCoreDesignerLaunch'", $assets );
		self::assertStringContainsString( 'CoreBlueprintLockup::enqueue_assets()', $assets );
		self::assertStringContainsString( 'CoreBlueprintMark::data_uri()', $assets );
		self::assertStringContainsString( "DesignEditorAssets::enqueue_designer_mode( __( 'Mail Designer', 'core-blueprint' ) );", $page );
		self::assertStringNotContainsString( "assets/js/features/designer-launch.js", $page );
		self::assertStringNotContainsString( 'wp_localize_script(', $page );
	}

	public function test_designer_mode_branding_uses_the_canonical_lockup_and_branded_mode_title(): void {
		$root      = dirname( __DIR__, 2 );
		$launch    = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );
		$shell_css = (string) file_get_contents( $root . '/assets/css/design/editor-shell.css' );
		$brand_css = (string) file_get_contents( $root . '/assets/css/components/brand-lockup.css' );

		self::assertStringContainsString( "brand.className = 'cb-core-design-shell__brand cb-core-brand-lockup'", $launch );
		self::assertStringContainsString( "mark.className = 'cb-core-brand-lockup__mark'", $launch );
		self::assertStringContainsString( "wordmark.className = 'cb-core-brand-lockup__wordmark cb-core-design-shell__brand-wordmark'", $launch );
		self::assertStringContainsString( 'appendBrandedModeTitle(wordmark, title)', $launch );
		self::assertStringContainsString( "dot.className = 'cb-core-brand-lockup__dot'", $launch );
		self::assertStringContainsString( 'word.toLocaleLowerCase()', $launch );
		self::assertStringNotContainsString( '.cb-core-design-shell__brand-mark {', $shell_css );
		self::assertStringContainsString( 'font-family: "Miriam Libre", sans-serif;', $brand_css );
		self::assertStringContainsString( 'font-weight: 400;', $brand_css );
		self::assertStringContainsString( 'color: #00b3ea;', $brand_css );
	}

	public function test_mail_declares_its_panels_against_canonical_inspector_layers_settings_roles(): void {
		$root = dirname( __DIR__, 2 );
		$template = (string) file_get_contents( $root . '/templates/mail-designer.php' );
		$launch = (string) file_get_contents( $root . '/assets/js/features/designer-launch.js' );
		$assets = (string) file_get_contents( $root . '/src/Design/Editor/Assets.php' );

		self::assertStringContainsString( 'data-cb-design-shell-tab="inspector" data-cb-design-shell-sidebar-role="inspector"', $template );
		self::assertStringContainsString( 'data-cb-design-shell-tab="structure" data-cb-design-shell-sidebar-role="layers"', $template );
		self::assertStringContainsString( 'data-cb-design-shell-tab="email" data-cb-design-shell-sidebar-role="settings"', $template );
		self::assertStringContainsString( 'discoverSidebarRoles', $launch );
		self::assertStringNotContainsString( "layers: 'structure'", $launch );
		self::assertStringNotContainsString( "settings: 'email'", $launch );
		self::assertStringContainsString( "'sidebarLabels' => [", $assets );
		self::assertStringContainsString( "'inspector' => __( 'Inspector', 'core-blueprint' )", $assets );
		self::assertStringContainsString( "'layers'    => __( 'Layers', 'default' )", $assets );
		self::assertStringContainsString( "'settings'  => __( 'Settings', 'core-blueprint' )", $assets );
	}

	public function test_tablet_viewport_is_a_shared_designer_capability_while_mail_owns_preview_response(): void {
		$root = dirname( __DIR__, 2 );
		$template = (string) file_get_contents( $root . '/templates/mail-designer.php' );
		$viewports = (string) file_get_contents( $root . '/assets/js/design/shell/viewports.js' );
		$feature = (string) file_get_contents( $root . '/assets/js/features/mail-designer.js' );

		self::assertStringContainsString( "\$tablet_label = __( 'Tablet', 'default' )", $template );
		self::assertStringContainsString( 'data-cb-design-shell-viewport="tablet"', $template );
		self::assertStringContainsString( 'data-cb-mail-viewport="tablet"', $template );
		self::assertStringNotContainsString( "'Tablet', 'core-blueprint'", $template );
		self::assertStringContainsString( "cb:design-shell:viewportchange", $viewports );
		self::assertStringContainsString( "value === 'tablet'", $feature );
		self::assertStringContainsString( "classList.toggle('is-tablet'", $feature );
	}

	public function test_designer_mode_and_hud_share_the_canonical_core_blueprint_mark(): void {
		$root = dirname( __DIR__, 2 );
		$assets = (string) file_get_contents( $root . '/src/Design/Editor/Assets.php' );
		$hud = (string) file_get_contents( $root . '/src/HUD/Brand/CoreBlueprint.php' );
		$mark = (string) file_get_contents( $root . '/src/Brand/CoreBlueprintMark.php' );

		self::assertStringContainsString( 'CoreBlueprintMark::data_uri()', $assets );
		self::assertStringContainsString( 'CoreBlueprintMark::svg()', $hud );
		self::assertStringContainsString( '#00FFDD', $mark );
		self::assertStringContainsString( '#0037FF', $mark );
		self::assertStringContainsString( '#131648', $mark );
		self::assertStringNotContainsString( 'assets/core-blueprint-icon.svg', $assets );
	}
}
