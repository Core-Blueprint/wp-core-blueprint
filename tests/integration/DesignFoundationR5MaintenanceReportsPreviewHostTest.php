<?php
declare(strict_types=1);

final class CB_Design_Foundation_R5_Maintenance_Reports_Preview_Host_Test extends WP_UnitTestCase {

	public function test_reports_consumes_canonical_flow_preview_host_without_iframe_ownership(): void {
		$source = file_get_contents( CB_CORE_DIR . 'assets/js/features/reports-preferences.js' );
		self::assertIsString( $source );

		self::assertStringContainsString( "import { createFlowPreviewHost } from '../design/document/flow/index.js';", $source );
		self::assertStringContainsString( 'createFlowPreviewHost( previewFrame )', $source );
		self::assertStringContainsString( 'host.render( response.data.html )', $source );
		self::assertStringContainsString( 'previewHost?.destroy()', $source );

		self::assertStringNotContainsString( 'window.CBBase?.Document?.FlowPreview', $source );
		self::assertStringNotContainsString( 'previewHostApi.createHost', $source );
		self::assertStringNotContainsString( 'previewHost.clear()', $source );
		self::assertStringNotContainsString( 'previewFrame.contentDocument', $source );
		self::assertStringNotContainsString( 'syncPreviewFrameHeight', $source );
		self::assertStringNotContainsString( 'previewFrame.srcdoc =', $source );
		self::assertStringNotContainsString( 'scrollHeight', $source );
	}

	public function test_reports_template_and_css_do_not_own_iframe_isolation_or_height(): void {
		$template = file_get_contents( CB_CORE_DIR . 'templates/preferences-reports.php' );
		$css = file_get_contents( CB_CORE_DIR . 'assets/css/pages/reports-designer.css' );
		self::assertIsString( $template );
		self::assertIsString( $css );

		self::assertStringNotContainsString( 'allow-same-origin', $template );
		self::assertStringNotContainsString( 'scrolling="no"', $template );
		self::assertStringNotContainsString( 'min-height: 297mm', $css );
		self::assertStringNotContainsString( 'overflow: hidden', $css );
	}

	public function test_reports_preview_uses_public_continuous_flow_render_target(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Reports/DesignerPreview.php' );
		self::assertIsString( $source );

		self::assertStringContainsString( 'FlowRenderApi::preview_html(', $source );
		self::assertStringNotContainsString( 'new HtmlRenderer', $source );
		self::assertStringNotContainsString( '->render(', $source );
	}
}
