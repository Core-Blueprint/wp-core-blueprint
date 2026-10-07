<?php
declare(strict_types=1);

final class CB_Base_Modal_Presentation_Contract_Test extends WP_UnitTestCase {

	private function css( string $file ): string {
		$source = file_get_contents( CB_CORE_DIR . 'assets/css/components/' . $file );
		self::assertIsString( $source );
		return $source;
	}

	public function test_core_presentation_scrolls_only_the_modal_body(): void {
		$css = $this->css( 'modals.css' );

		self::assertStringContainsString( 'overflow: hidden;', $css );
		self::assertStringContainsString( 'flex-direction: column;', $css );
		self::assertStringContainsString( 'max-height: min(85vh, 720px);', $css );
		self::assertStringContainsString( '.cb-core-modal__body {', $css );
		self::assertStringContainsString( 'min-height: 0;', $css );
		self::assertStringContainsString( 'overflow-y: auto;', $css );
		self::assertStringContainsString( 'overscroll-behavior: contain;', $css );
		self::assertStringContainsString( '.cb-core-modal__actions {', $css );
		self::assertStringContainsString( 'flex: 0 0 auto;', $css );
		self::assertStringContainsString( 'border-top: 1px solid var(--cb-surface-3);', $css );
	}

	public function test_wordpress_native_presentation_matches_scroll_ownership(): void {
		$css = $this->css( 'modals-native.css' );

		self::assertStringContainsString( 'overflow: hidden;', $css );
		self::assertStringContainsString( 'flex-direction: column;', $css );
		self::assertStringContainsString( 'max-height: min(85vh, 720px);', $css );
		self::assertStringContainsString( '.cb-core-modal__body {', $css );
		self::assertStringContainsString( 'min-height: 0;', $css );
		self::assertStringContainsString( 'overflow-y: auto;', $css );
		self::assertStringContainsString( 'overscroll-behavior: contain;', $css );
		self::assertStringContainsString( '.cb-core-modal__actions {', $css );
		self::assertStringContainsString( 'flex: 0 0 auto;', $css );
		self::assertStringContainsString( 'border-top: 1px solid var(--cb-modal-native-border);', $css );
	}

	public function test_public_modal_runtime_api_does_not_change(): void {
		$source = file_get_contents( CB_CORE_DIR . 'assets/js/core/modal.js' );
		self::assertIsString( $source );

		self::assertStringContainsString( 'window.cbCore.modal.show', $source );
		self::assertStringContainsString( "actions.className = 'cb-core-modal__actions';", $source );
		self::assertStringContainsString( "body.className = 'cb-core-modal__body cb-scrollbar';", $source );
	}

	public function test_workspace_modal_is_bounded_expandable_and_opt_in(): void {
		$source = file_get_contents( CB_CORE_DIR . 'assets/js/core/modal.js' );
		$assets = file_get_contents( CB_CORE_DIR . 'src/UI/Assets.php' );
		$core_css = $this->css( 'modals.css' );
		$native_css = $this->css( 'modals-native.css' );

		self::assertIsString( $source );
		self::assertIsString( $assets );
		self::assertStringContainsString( "[ 'wide', 'workspace' ]", $source );
		self::assertStringContainsString( "opts.expandable === true && size === 'workspace'", $source );
		self::assertStringContainsString( "expandToggle.setAttribute( 'aria-pressed'", $source );
		self::assertStringContainsString( "'workspace-expand'", $source );
		self::assertStringContainsString( "'workspace-restore'", $source );
		self::assertStringContainsString( "'expand'           => __( 'Expand', 'core-blueprint' )", $assets );
		self::assertStringContainsString( "'restoreSize'      => __( 'Restore size', 'core-blueprint' )", $assets );

		foreach ( [ $core_css, $native_css ] as $css ) {
			self::assertStringContainsString( '.cb-core-modal--workspace', $css );
			self::assertStringContainsString( '.cb-core-modal--workspace.is-expanded', $css );
			self::assertStringContainsString( 'box-sizing: border-box;', $css );
			self::assertStringContainsString( '.cb-core-modal__expand-toggle', $css );
		}

		self::assertStringContainsString( 'width: min(1480px, calc(100vw - var(--cb-space-4) - var(--cb-space-4)));', $core_css );
		self::assertStringContainsString( 'width: calc(100vw - var(--cb-space-4) - var(--cb-space-4));', $core_css );
		self::assertStringContainsString( 'height: calc(100vh - var(--cb-space-4) - var(--cb-space-4));', $core_css );
		self::assertStringContainsString( 'inset-block-start: var(--cb-space-4);', $core_css );
		self::assertStringContainsString( 'inset-inline-end: var(--cb-space-4);', $core_css );

		self::assertStringContainsString( 'var(--cb-space-4, 16px)', $native_css );
	}
	public function test_modal_assets_are_content_versioned_within_a_stable_plugin_release(): void {
		$assets = file_get_contents( CB_CORE_DIR . 'src/UI/Assets.php' );
		self::assertIsString( $assets );

		self::assertStringContainsString( 'private static function asset_revision( string $relative ): string', $assets );
		self::assertStringContainsString( "hash_file( 'sha256', \$path )", $assets );
		self::assertStringContainsString( "self::asset_revision( 'assets/css/components/modals.css' )", $assets );
		self::assertStringContainsString( "self::asset_revision( 'assets/css/components/modals-native.css' )", $assets );
		self::assertStringContainsString( "self::asset_revision( 'assets/js/core/modal.js' )", $assets );
	}

}
