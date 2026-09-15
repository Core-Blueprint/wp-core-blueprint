<?php
declare(strict_types=1);

final class CB_Design_Foundation_R5_Maintenance_Reports_Preview_Host_Test extends WP_UnitTestCase {

	public function test_reports_consumes_foundation_preview_host_without_child_dom_access(): void {
		$source = file_get_contents( CB_CORE_DIR . 'assets/js/features/reports-preferences.js' );
		self::assertIsString( $source );

		self::assertStringContainsString( 'window.CBBase?.Document?.FlowPreview', $source );
		self::assertStringContainsString( 'previewHostApi.createHost( previewFrame )', $source );
		self::assertStringContainsString( 'previewHost.render( response.data.html )', $source );
		self::assertStringContainsString( 'previewHost.clear()', $source );
		self::assertStringContainsString( 'previewHost?.destroy()', $source );
		self::assertStringContainsString( 'The report preview host is unavailable. Reload the page.', $source );

		self::assertStringNotContainsString( 'previewFrame.contentDocument', $source );
		self::assertStringNotContainsString( 'syncPreviewFrameHeight', $source );
		self::assertStringNotContainsString( 'previewFrame.srcdoc =', $source );
	}

	public function test_reports_preview_uses_public_continuous_flow_render_target(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Reports/DesignerPreview.php' );
		self::assertIsString( $source );

		self::assertStringContainsString( 'FlowRenderApi::preview_html(', $source );
		self::assertStringNotContainsString( 'new HtmlRenderer', $source );
		self::assertStringNotContainsString( '->render(', $source );
	}
}
