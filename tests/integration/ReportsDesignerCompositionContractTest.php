<?php
declare(strict_types=1);

use CoreBlueprint\Core\Reports\DesignerPreview;
use CoreBlueprint\Core\Reports\MaintenanceFlowBranding;
use CoreBlueprint\Core\Reports\ReportBranding;
use CoreBlueprint\Core\Reports\ReportBrandingInput;
use CoreBlueprint\Core\Settings;

final class CB_Reports_Designer_Composition_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_report_branding_defaults_match_the_base_settings_schema(): void {
		self::assertSame(
			ReportBranding::settings_defaults(),
			Settings::defaults()['reports']['branding']
		);
	}

	public function test_branding_save_and_preview_share_one_domain_normalizer(): void {
		self::assertSame(
			[
				'logo_attachment_id' => 0,
				'show_logo'          => true,
				'provider_name'      => '',
				'provider_contact'   => '',
				'accent_color'       => ReportBranding::DEFAULT_ACCENT,
				'surface_style'      => 'cards',
				'density'            => 'comfortable',
				'corner_style'       => 'soft',
				'text_scale'         => 'standard',
			],
			ReportBrandingInput::normalize( [] )
		);

		$unicode = ReportBrandingInput::normalize( [
			'provider_name'    => str_repeat( 'é', 121 ),
			'provider_contact' => str_repeat( 'ø', 201 ),
		] );
		self::assertSame( str_repeat( 'é', 120 ), $unicode['provider_name'] );
		self::assertSame( str_repeat( 'ø', 200 ), $unicode['provider_contact'] );

		$bounded = ReportBrandingInput::normalize( [
			'surface_style' => 'custom',
			'density'       => 'arbitrary',
			'corner_style'  => 'extreme',
			'text_scale'    => 'huge',
		] );
		self::assertSame( 'cards', $bounded['surface_style'] );
		self::assertSame( 'comfortable', $bounded['density'] );
		self::assertSame( 'soft', $bounded['corner_style'] );
		self::assertSame( 'standard', $bounded['text_scale'] );

		$handler    = $this->source( 'src/Ajax/Handlers/Branding.php' );
		$normalizer = $this->source( 'src/Reports/ReportBrandingInput.php' );
		self::assertStringContainsString( 'ReportBrandingInput::normalize', $handler );
		self::assertStringContainsString( 'cb_core_save_report_branding', $handler );
		self::assertStringContainsString( 'cb_core_preview_report_branding', $handler );
		self::assertStringNotContainsString( 'mb_strlen(', $normalizer );
		self::assertStringNotContainsString( 'mb_substr(', $normalizer );
	}

	public function test_designer_preview_uses_the_typed_flow_pipeline_instead_of_a_parallel_html_template(): void {
		$preview = $this->source( 'src/Reports/DesignerPreview.php' );

		self::assertStringContainsString( 'MaintenanceFlowCompiler', $preview );
		self::assertStringContainsString( 'MaintenanceFlowBranding::resolve_values', $preview );
		self::assertStringContainsString( 'FlowRenderApi::preview_html(', $preview );
		self::assertStringContainsString( "\$document['preview_regions']", $preview );
		self::assertStringNotContainsString( 'new HtmlRenderer', $preview );
		self::assertStringContainsString( 'Storage::find_recent( 1 )', $preview );
		self::assertStringContainsString( 'MaintenanceAggregator::SNAPSHOT_VERSION', $preview );
		self::assertStringNotContainsString( 'ReportBranding::attachment_url', $preview );
		self::assertStringNotContainsString( 'ReportBranding::fallback', $preview );
		self::assertStringNotContainsString( '<html', $preview );
		self::assertStringNotContainsString( '<table', $preview );
	}

	public function test_designer_preview_renders_typed_flow_end_to_end(): void {
		$branding = ReportBrandingInput::normalize( [] );
		$resolved = MaintenanceFlowBranding::resolve_values( $branding );

		self::assertFalse( str_starts_with( $resolved['logo_url'], 'http://' ) );
		self::assertFalse( str_starts_with( $resolved['logo_url'], 'https://' ) );
		if ( '' !== $resolved['logo_url'] ) {
			self::assertStringStartsWith( 'data:image/', $resolved['logo_url'] );
		} else {
			self::assertSame( 'Core Blueprint', $resolved['fallback_text'] );
		}

		$html = ( new DesignerPreview() )->render( $branding );
		self::assertStringStartsWith( '<!doctype html>', $html );
		self::assertStringContainsString( 'Maintenance Report', $html );
		self::assertStringContainsString( 'cb-flow-block', $html );
		self::assertStringContainsString( 'data-cb-flow-preview-region="header"', $html );
		self::assertStringContainsString( 'data-cb-flow-preview-region="footer"', $html );
	}

	public function test_reports_template_is_a_thin_golden_designer_consumer(): void {
		$template = $this->source( 'templates/preferences-reports.php' );

		foreach ( [
			'data-cb-design-launch-root',
			'data-cb-design-launch-context',
			'data-cb-design-shell',
			'data-cb-design-shell-context',
			'data-cb-design-shell-undo',
			'data-cb-design-shell-redo',
			'data-cb-design-shell-primary-action',
			'data-cb-design-shell-toolbar-extension="actions"',
			'cb-core-design-shell__palette--tabbed',
			'cb-core-design-shell__palette--composed',
			'cb-core-design-shell__canvas--composed',
			'cb-core-design-shell__sidebar--composed',
			'cb-core-design-shell__panel-body',
			'cb-core-design-shell__panel-section',
			'cb-core-design-shell__field',
			'cb-core-reports__designer-preview-surface',
			'data-cb-design-shell-group="palette"',
			'data-cb-design-shell-tab="elements"',
			'data-cb-design-shell-panel="elements"',
			'data-cb-design-shell-sidebar-role="inspector"',
			'data-cb-design-shell-sidebar-role="layers"',
			'data-cb-design-shell-sidebar-role="settings"',
			'data-cb-report-elements',
			'data-cb-report-inspector',
			'data-cb-report-layers',
			'Maintenance Report',
		] as $contract ) {
			self::assertStringContainsString( $contract, $template );
		}

		foreach ( [
			'cb-core-show-logo',
			'cb-core-report-surface-style',
			'cb-core-report-density',
			'cb-core-report-corner-style',
			'cb-core-report-text-scale',
		] as $appearance_control ) {
			self::assertStringContainsString( $appearance_control, $template );
		}

		self::assertStringNotContainsString( '@media', $template );
		self::assertStringNotContainsString( 'cb-core-form-scope', $template );
	}

	public function test_reports_static_preview_presentation_is_css_owned_while_paper_medium_stays_explicit(): void {
		$template = $this->source( 'templates/preferences-reports.php' );
		$runtime  = $this->source( 'assets/js/features/reports-preferences.js' );
		$style    = $this->source( 'assets/css/pages/reports-designer.css' );

		self::assertStringContainsString( '[data-cb-report-preview]', $style );
		self::assertStringContainsString( '#cb-core-logo-preview img', $style );
		self::assertStringNotContainsString( 'style="background:#fff;"', $template );
		self::assertStringContainsString( 'background: transparent;', $style );
		self::assertStringContainsString( 'background:#fff;', $this->source( 'src/Design/Profile/Document/Flow/HtmlRenderer.php' ) );
		self::assertStringNotContainsString( 'style="display:block;border:0;background:#fff;"', $template );
		self::assertStringNotContainsString( 'max-height:96px', $template );
		self::assertStringNotContainsString( 'image.style.', $runtime );
	}

	public function test_reports_bootstrap_scopes_designer_mode_to_the_reports_preferences_route(): void {
		$bootstrap = $this->source( 'src/Reports/Bootstrap.php' );

		self::assertStringContainsString( "'admin_enqueue_scripts'", $bootstrap );
		self::assertStringContainsString( "'core-blueprint-preferences' !== \$page", $bootstrap );
		self::assertStringContainsString( "'reports' !== \$tab", $bootstrap );
		self::assertStringContainsString( "current_user_can( 'cb_manage_branding' )", $bootstrap );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_designer_mode', $bootstrap );
		self::assertStringContainsString( "'cb-core-reports-designer'", $bootstrap );
		self::assertStringContainsString( "'assets/css/pages/reports-designer.css'", $bootstrap );
	}

	public function test_mail_and_reports_share_one_layer_primitive_and_context_chrome(): void {
		$reports_runtime = $this->source( 'assets/js/features/reports-preferences.js' );
		$mail_runtime    = $this->source( 'assets/js/features/mail-designer.js' );
		$reports_style   = $this->source( 'assets/css/pages/reports-designer.css' );
		$mail_style      = $this->source( 'assets/css/pages/mail-designer.css' );

		foreach ( [ $reports_runtime, $mail_runtime ] as $runtime ) {
			self::assertStringContainsString( 'createDesignerLayerTree', $runtime );
			self::assertStringContainsString( 'onSelectItem:', $runtime );
			self::assertStringNotContainsString( 'cb-core-design-shell__layer-list', $runtime );
			self::assertStringContainsString( 'createDesignerSelectionController', $runtime );
			self::assertStringNotContainsString( "row.className = 'cb-core-design-shell__layer-row';", $runtime );
			self::assertStringNotContainsString( 'decorateDesignerControl', $runtime );
			self::assertStringNotContainsString( 'cb-core-reports-structure__row', $runtime );
			self::assertStringNotContainsString( 'cb-core-mail-structure__row', $runtime );
		}

		self::assertStringContainsString( 'data-cb-design-shell-context', $this->source( 'templates/mail-designer.php' ) );
		self::assertStringNotContainsString( '.cb-core-reports-structure__row', $reports_style );
		self::assertStringNotContainsString( '.cb-core-mail-structure__row', $mail_style );
	}

	public function test_reports_designer_uses_the_canonical_design_session_history(): void {
		$runtime = $this->source( 'assets/js/features/reports-preferences.js' );

		self::assertStringContainsString( 'createSession', $runtime );
		self::assertStringContainsString( "profile: 'document-flow'", $runtime );
		self::assertStringContainsString( 'createDesignerShell( shell, { session } )', $runtime );
		self::assertStringContainsString( 'createDesignerSelectionController', $runtime );
		self::assertStringContainsString( 'createDesignerLayerTree', $runtime );
		self::assertStringContainsString( 'commands.reorderNode', $runtime );
		self::assertStringContainsString( 'commands.setProperty', $runtime );
		self::assertStringContainsString( "command?.label === 'remove-node'", $runtime );
		self::assertStringContainsString( "command?.label === 'insert-node'", $runtime );
		self::assertStringNotContainsString( 'createSnapshotHistory', $runtime );
		self::assertStringNotContainsString( 'checkpoint()', $runtime );
	}

	public function test_reports_designer_separates_elements_layers_inspector_and_settings(): void {
		$runtime = $this->source( 'assets/js/features/reports-preferences.js' );
		$style   = $this->source( 'assets/css/pages/reports-designer.css' );

		self::assertStringContainsString( "qs( '[data-cb-report-elements]'", $runtime );
		self::assertStringContainsString( "qs( '[data-cb-report-layers]'", $runtime );
		self::assertStringContainsString( "qs( '[data-cb-report-inspector]'", $runtime );
		self::assertStringContainsString( 'renderElements', $runtime );
		self::assertStringContainsString( 'renderLayers', $runtime );
		self::assertStringContainsString( 'renderInspector', $runtime );
		self::assertStringContainsString( 'normalizeBlockSettings', $runtime );
		self::assertStringContainsString( "commands.setProperty( [ index ], [ 'settings', key ]", $runtime );
		self::assertStringContainsString( 'cbReportBlockSetting', $runtime );


		self::assertStringContainsString( "layerTree = createDesignerLayerTree", $runtime );
		self::assertStringContainsString( "dataset: { cbReportLayer: block.type }", $runtime );
		self::assertStringContainsString( 'createDesignerLayerTree', $runtime );
		self::assertStringContainsString( 'createDesignerSelectionController', $runtime );
		self::assertStringContainsString( 'previewHost?.setSelection', $runtime );
		self::assertStringNotContainsString( 'createLayerMoveButton', $runtime );
		self::assertStringNotContainsString( 'session.editorState.selection', $runtime );
		self::assertStringNotContainsString( "layerList.className = 'cb-core-design-shell__palette-grid'", $runtime );
		self::assertStringNotContainsString( '.cb-core-design-shell__workspace', $style );
		self::assertStringNotContainsString( '.cb-core-design-shell__layer-row', $style );
	}

	public function test_reports_designer_initializes_the_shared_shell_and_saves_in_place(): void {
		$runtime = $this->source( 'assets/js/features/reports-preferences.js' );
		$i18n    = $this->source( 'src/Admin/AdminModuleDefinitionsPreferences.php' );

		self::assertStringContainsString( "from '@cb-core/design-editor'", $runtime );
		self::assertStringContainsString( 'createDesignerShell( shell, { session } )', $runtime );
		self::assertStringContainsString( "'@cb-core/design-editor'", $i18n );
		self::assertStringContainsString( "apiPost( 'cb_core_preview_report_branding'", $runtime );
		self::assertStringContainsString( 'previewSequence', $runtime );
		self::assertStringContainsString( 'createFlowPreviewHost', $runtime );
		self::assertStringContainsString( 'host.render( response.data.html )', $runtime );
		self::assertStringNotContainsString( 'previewFrame.srcdoc', $runtime );
		self::assertStringNotContainsString( 'allow-same-origin', $runtime );
		self::assertStringContainsString( 'requestErrorMessage', $runtime );
		self::assertStringContainsString( 'catch ( error )', $runtime );
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

	public function test_reports_reset_fails_closed_when_modal_contract_is_unavailable(): void {
		$runtime = $this->source( 'assets/js/features/reports-preferences.js' );

		$guard = "if ( typeof modal?.show !== 'function' ) return;";
		$reset = "apiPost( 'cb_core_reset_report_branding'";
		$guard_position = strpos( $runtime, $guard );
		$reset_position = strpos( $runtime, $reset );

		self::assertNotFalse( $guard_position );
		self::assertNotFalse( $reset_position );
		self::assertLessThan( $reset_position, $guard_position );
		self::assertStringNotContainsString( "\t\t\t: true;", $runtime );
		self::assertStringNotContainsString( 'window.confirm', $runtime );
	}
}
