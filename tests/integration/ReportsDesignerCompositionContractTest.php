<?php
declare(strict_types=1);

use CB\Core\Reports\ReportBranding;
use CB\Core\Reports\ReportBrandingInput;

final class CB_Reports_Designer_Composition_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_branding_save_and_preview_share_one_domain_normalizer(): void {
		self::assertSame(
			[
				'logo_attachment_id' => 0,
				'provider_name'       => '',
				'provider_contact'    => '',
				'accent_color'        => ReportBranding::DEFAULT_ACCENT,
			],
			ReportBrandingInput::normalize( [] )
		);

		$handler = $this->source( 'src/Ajax/Handlers/Branding.php' );
		self::assertStringContainsString( 'ReportBrandingInput::normalize', $handler );
		self::assertStringContainsString( 'cb_core_save_report_branding', $handler );
		self::assertStringContainsString( 'cb_core_preview_report_branding', $handler );
	}

	public function test_designer_preview_uses_the_typed_flow_pipeline_instead_of_a_parallel_html_template(): void {
		$preview = $this->source( 'src/Reports/DesignerPreview.php' );

		self::assertStringContainsString( 'MaintenanceFlowCompiler', $preview );
		self::assertStringContainsString( 'HtmlRenderer', $preview );
		self::assertStringContainsString( 'Storage::find_recent( 1 )', $preview );
		self::assertStringContainsString( 'MaintenanceAggregator::SNAPSHOT_VERSION', $preview );
		self::assertStringNotContainsString( '<html', $preview );
		self::assertStringNotContainsString( '<table', $preview );
	}

	public function test_reports_template_is_a_thin_golden_designer_consumer(): void {
		$template = $this->source( 'templates/preferences-reports.php' );

		foreach ( [
			'data-cb-design-launch-root',
			'data-cb-design-launch-context',
			'data-cb-design-shell',
			'data-cb-design-shell-primary-action',
			'data-cb-design-shell-toolbar-extension="actions"',
			'cb-core-design-shell__palette--composed',
			'cb-core-design-shell__canvas--composed',
			'cb-core-design-shell__sidebar--composed',
			'cb-core-design-shell__panel-body',
			'cb-core-design-shell__panel-section',
			'cb-core-design-shell__field',
			'cb-core-design-shell__surface--document',
		] as $contract ) {
			self::assertStringContainsString( $contract, $template );
		}

		self::assertStringNotContainsString( '@media', $template );
		self::assertStringNotContainsString( 'cb-core-form-scope', $template );
	}

	public function test_reports_bootstrap_scopes_designer_mode_to_the_reports_preferences_route(): void {
		$bootstrap = $this->source( 'src/Reports/Bootstrap.php' );

		self::assertStringContainsString( "'admin_enqueue_scripts'", $bootstrap );
		self::assertStringContainsString( "'core-blueprint-preferences' !== \$page", $bootstrap );
		self::assertStringContainsString( "'reports' !== \$tab", $bootstrap );
		self::assertStringContainsString( "current_user_can( 'cb_manage_branding' )", $bootstrap );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_designer_mode', $bootstrap );
	}

	public function test_reports_designer_previews_and_saves_in_place(): void {
		$runtime = $this->source( 'assets/js/features/reports-preferences.js' );
		$i18n    = $this->source( 'src/Admin/AdminModuleDefinitionsPreferences.php' );

		self::assertStringContainsString( "apiPost( 'cb_core_preview_report_branding'", $runtime );
		self::assertStringContainsString( 'previewSequence', $runtime );
		self::assertStringContainsString( 'previewFrame.srcdoc = response.data.html', $runtime );
		self::assertStringContainsString( "apiPost( 'cb_core_save_report_branding'", $runtime );
		self::assertStringContainsString( "cb:design-shell:savechange", $runtime );
		self::assertStringContainsString( "setSaveState( 'saving' )", $runtime );
		self::assertStringContainsString( "setSaveState( 'saved' )", $runtime );
		self::assertStringContainsString( "setSaveState( 'error' )", $runtime );
		self::assertStringContainsString( "'previewLoading'", $i18n );
		self::assertStringContainsString( "'previewFailed'", $i18n );
		self::assertStringNotContainsString( 'window.location', $runtime );
		self::assertStringNotContainsString( 'location.reload', $runtime );
	}
}
