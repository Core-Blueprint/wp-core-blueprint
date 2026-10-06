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
}
