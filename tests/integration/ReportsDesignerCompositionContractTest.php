<?php
declare(strict_types=1);

use CoreBlueprint\Core\Reports\DesignerPreview;
use CoreBlueprint\Core\Reports\MaintenanceFlowBranding;
use CoreBlueprint\Core\Reports\ReportBranding;
use CoreBlueprint\Core\Reports\ReportBrandingInput;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter;
use CoreBlueprint\Core\Design\Editor\PlatformTransport;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Reports\Composer\NativeComposition;
use CoreBlueprint\Core\Reports\DesignDocumentAdapter;

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
		$compiler = $this->source( 'src/Reports/MaintenanceFlowCompiler.php' );
		self::assertStringContainsString( 'MaintenanceFlowCompiler', $preview );
		self::assertStringContainsString( 'render( ?array $native = null )', $preview );
		self::assertStringContainsString( 'MaintenanceFlowBranding::resolve_values', $compiler );
		self::assertStringContainsString( 'FlowRenderApi::preview_html(', $preview );
		self::assertStringContainsString( "\$document['preview_regions']", $preview );
		self::assertStringContainsString( 'Storage::find_recent( 1 )', $preview );
		self::assertStringContainsString( 'MaintenanceAggregator::SNAPSHOT_VERSION', $preview );
		self::assertStringNotContainsString( 'new HtmlRenderer', $preview );
		self::assertStringNotContainsString( '<table', $preview );
	}

	public function test_designer_preview_renders_typed_flow_end_to_end(): void {
		$native = [ 'project' => PlatformAdapter::default_project(), 'fields' => PlatformAdapter::default_fields() ];
		$native['fields']['provider_name'] = 'Core <Blueprint>';
		self::assertTrue( NativeComposition::valid_document( $native ) );
		$resolved = MaintenanceFlowBranding::resolve_values( NativeComposition::flow_branding( $native['fields'] ) );
		self::assertFalse( str_starts_with( $resolved['logo_url'], 'http://' ) );
		self::assertFalse( str_starts_with( $resolved['logo_url'], 'https://' ) );
		$html = ( new DesignerPreview() )->render( $native );
		self::assertStringStartsWith( '<!doctype html>', $html );
		self::assertStringContainsString( 'Maintenance Report', $html );
		self::assertStringContainsString( 'cb-flow-block', $html );
		self::assertStringContainsString( 'data-cb-flow-preview-region="r0"', $html );
		self::assertStringContainsString( 'Core &lt;Blueprint&gt;', $html );
		self::assertStringNotContainsString( 'Core <Blueprint>', $html );
	}

	public function test_reports_template_is_a_thin_golden_designer_consumer(): void {
		$bootstrap = $this->source( 'src/Reports/Bootstrap.php' );
		$module = $this->source( 'assets/js/design/reports/platform-adapter.js' );
		self::assertStringContainsString( "wp_dequeue_script_module( '@cb-core/reports-preferences' )", $bootstrap );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_platform()', $bootstrap );
		foreach ( [ 'validateProject', 'authorizeCommand', 'layerPolicyFor', 'resolveCommand',
			'createRenderAdapter', 'preview.setSelection', 'syncSelection' ] as $contract ) {
			self::assertStringContainsString( $contract, $module );
		}
		$native = [ 'project' => PlatformAdapter::default_project(), 'fields' => PlatformAdapter::default_fields() ];
		self::assertTrue( PlatformTransport::valid_native_document( Repository::REPORTS, $native ) );
		$invalid = $native;
		$invalid['project']['root']['children'][0]['type'] = 'reports.paragraph';
		self::assertFalse( PlatformTransport::valid_native_document( Repository::REPORTS, $invalid ) );
	}

	public function test_reports_static_preview_presentation_is_css_owned_while_paper_medium_stays_explicit(): void {
		$template = $this->source( 'templates/preferences-reports.php' );
		$runtime  = $this->source( 'assets/js/features/reports-preferences.js' );
		$style    = $this->source( 'assets/css/pages/reports-designer.css' );

		self::assertStringContainsString( '[data-cb-report-preview]', $style );
		self::assertStringContainsString( '.cb-reports-platform__branding', $style );
		self::assertStringContainsString( '--cb-report-paper: #fff;', $style );
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
		self::assertStringContainsString( 'DesignEditorAssets::platform_fullscreen_requested()', $bootstrap );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_platform()', $bootstrap );
		self::assertStringContainsString( "'cb-core-reports-designer'", $bootstrap );
		self::assertStringContainsString( "'assets/css/pages/reports-designer.css'", $bootstrap );
	}

	public function test_mail_and_reports_share_one_layer_primitive_and_context_chrome(): void {
		$reports = $this->source( 'assets/js/design/reports/platform-adapter.js' );
		$mail = $this->source( 'assets/js/design/mail/platform-adapter.js' );
		foreach ( [ $reports, $mail ] as $module ) {
			self::assertStringContainsString( 'layerPolicyFor', $module );
			self::assertStringContainsString( 'createRenderAdapter', $module );
			self::assertStringContainsString( 'syncSelection', $module );
			self::assertStringContainsString( 'validateProject', $module );
		}
		$native = [ 'project' => PlatformAdapter::default_project(), 'fields' => PlatformAdapter::default_fields() ];
		self::assertTrue( NativeComposition::valid_document( $native ) );
		$mail_native = \CoreBlueprint\Core\Mail\Designer\WordPressTemplates::default_document( 'wordpress.password-reset' );
		self::assertTrue( PlatformTransport::valid_native_document( Repository::MAIL, $mail_native ) );
	}

	public function test_reports_designer_uses_the_canonical_design_session_history(): void {
		$module = $this->source( 'assets/js/design/reports/platform-adapter.js' );
		self::assertStringContainsString( 'resolveCommand', $module );
		self::assertStringContainsString( 'authorizeCommand', $module );
		self::assertStringContainsString( 'validateProject', $module );
		$project = PlatformAdapter::default_project();
		$original = array_column( $project['root']['children'], 'type' );
		self::assertSame( 'reports.header', $original[0] );
		self::assertSame( 'reports.footer', $original[ count( $original ) - 1 ] );
		$reordered = $project;
		[ $reordered['root']['children'][1], $reordered['root']['children'][2] ] =
			[ $project['root']['children'][2], $project['root']['children'][1] ];
		self::assertTrue( NativeComposition::valid_document( [
			'project' => $reordered, 'fields' => PlatformAdapter::default_fields(),
		] ) );
		self::assertNotSame( $project, $reordered );
	}

	public function test_reports_designer_separates_elements_layers_inspector_and_settings(): void {
		$definitions = PlatformAdapter::node_definitions();
		foreach ( [ 'reports.heading', 'reports.metric', 'reports.paragraph',
			'reports.section', 'reports.status', 'reports.kpis' ] as $type ) {
			self::assertArrayHasKey( $type, $definitions );
		}
		self::assertSame( [ PlatformAdapter::TYPE ], $definitions['reports.section']['parents'] );
		self::assertSame( [ PlatformAdapter::TYPE ], $definitions['reports.header']['parents'] );
		self::assertArrayHasKey( 'enabled', $definitions['reports.section']['properties'] );
		self::assertArrayHasKey( 'accent_color', PlatformAdapter::branding_definitions() );
		$module = $this->source( 'assets/js/design/reports/platform-adapter.js' );
		self::assertStringContainsString( 'layerPolicyFor', $module );
		self::assertStringContainsString( 'propertiesFor', $module );
		self::assertStringContainsString( 'componentsFor', $module );
	}

	public function test_reports_designer_initializes_the_shared_shell_and_saves_in_place(): void {
		$bootstrap = $this->source( 'src/Reports/Bootstrap.php' );
		$transport = $this->source( 'src/Design/Editor/PlatformTransport.php' );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_platform()', $bootstrap );
		self::assertStringContainsString( 'new TransactionalSave(', $transport );
		self::assertStringContainsString( 'expectedStoreRevision', $transport );
		self::assertStringContainsString( 'registryFingerprint', $transport );
		$before = Repository::get( Repository::REPORTS, 'maintenance' );
		self::assertSame( 'blocked', DesignDocumentAdapter::save( '0', [], [] )['status'] );
		self::assertSame( $before, Repository::get( Repository::REPORTS, 'maintenance' ) );
	}

	public function test_reports_reset_fails_closed_when_modal_contract_is_unavailable(): void {
		$before = Repository::get( Repository::REPORTS, 'maintenance' );
		self::assertSame( 'blocked', DesignDocumentAdapter::reset( '0' )['status'] );
		self::assertSame( $before, Repository::get( Repository::REPORTS, 'maintenance' ) );
		$invalid = [ 'project' => PlatformAdapter::default_project(), 'fields' => PlatformAdapter::default_fields() ];
		$invalid['project']['root']['children'][0]['properties']['unknown'] = 'on';
		self::assertFalse( NativeComposition::valid_document( $invalid ) );
	}

}
