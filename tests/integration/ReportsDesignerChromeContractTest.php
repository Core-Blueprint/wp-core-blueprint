<?php
declare(strict_types=1);

final class CB_Reports_Designer_Chrome_Contract_Test extends WP_UnitTestCase {

	public function test_reports_designer_uses_top_context_without_redundant_canvas_title_chrome(): void {
		$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/preferences-reports.php' );

		self::assertStringContainsString( 'data-cb-design-shell-context', $template );
		self::assertStringContainsString( '<option value="maintenance" selected><?php esc_html_e( \'Maintenance Report\', \'core-blueprint\' ); ?></option>', $template );
		self::assertStringContainsString( 'cb-core-design-shell__canvas cb-core-design-shell__canvas--composed', $template );
		self::assertStringContainsString( 'cb-core-design-shell__canvas-workarea', $template );
		self::assertStringContainsString( 'cb-core-reports__designer-preview-surface', $template );
		self::assertStringContainsString( 'data-cb-report-preview', $template );
		self::assertStringNotContainsString( 'cb-core-design-shell__surface--document', $template );
		self::assertStringContainsString( 'cb-core-design-shell__palette--composed', $template );
		self::assertStringContainsString( 'cb-core-design-shell__sidebar--composed', $template );
		self::assertStringContainsString( 'data-cb-design-shell-primary-action', $template );
		self::assertStringNotContainsString( 'cb-core-design-shell__canvas-header', $template );
		self::assertStringNotContainsString( 'cb-core-design-shell__canvas-title', $template );
	}
}
