<?php
declare(strict_types=1);

use CB\Core\Reports\Composer\BlockCatalog;
use CB\Core\Reports\Composer\MaintenanceTemplate;

final class CB_Reports_Composer_Interaction_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	}

	public function test_transport_json_is_bounded_and_server_normalized(): void {
		$input = wp_json_encode( [
			'schema_version' => 999,
			'blocks' => [
				[ 'type' => BlockCatalog::HEADER, 'enabled' => false ],
				[ 'type' => BlockCatalog::NOTES, 'enabled' => false ],
				[ 'type' => 'arbitrary_html', 'enabled' => true ],
				[ 'type' => BlockCatalog::FOOTER, 'enabled' => false ],
			],
		] );
		self::assertIsString( $input );

		$template = MaintenanceTemplate::from_json( $input );
		self::assertSame( MaintenanceTemplate::SCHEMA_VERSION, $template['schema_version'] );
		self::assertSame( BlockCatalog::HEADER, $template['blocks'][0]['type'] );
		self::assertTrue( $template['blocks'][0]['enabled'] );
		self::assertFalse( $template['blocks'][1]['enabled'] );
		self::assertNotContains( 'arbitrary_html', array_column( $template['blocks'], 'type' ) );
		self::assertSame( BlockCatalog::FOOTER, $template['blocks'][ count( $template['blocks'] ) - 1 ]['type'] );
	}

	public function test_reports_module_data_exposes_only_bounded_composer_state_and_labels(): void {
		$definitions = $this->source( 'src/Admin/AdminModuleDefinitionsPreferences.php' );

		self::assertStringContainsString( 'MaintenanceTemplate::current()', $definitions );
		self::assertStringContainsString( "'blockLabels'", $definitions );
		self::assertStringContainsString( "'composerUi'", $definitions );
		self::assertStringContainsString( "'@cb-core/design-editor'", $definitions );
	}

	public function test_reports_runtime_mutates_composer_state_inside_public_designer_primitives(): void {
		$runtime = $this->source( 'assets/js/features/reports-preferences.js' );

		self::assertStringContainsString( 'normalizeClientTemplate', $runtime );
		self::assertStringContainsString( 'buildComposerControls', $runtime );
		self::assertStringContainsString( 'renderComposerControls', $runtime );
		self::assertStringContainsString( 'moveSelectedBlock', $runtime );
		self::assertStringContainsString( "template: JSON.stringify( composer )", $runtime );
		self::assertStringContainsString( "apiPost( 'cb_core_preview_report_branding', nonce, reportsPayload() )", $runtime );
		self::assertStringContainsString( "apiPost( 'cb_core_save_report_branding', nonce, reportsPayload() )", $runtime );
		self::assertStringContainsString( 'cb-core-design-shell__palette-grid', $runtime );
		self::assertStringContainsString( 'cb-core-design-shell__panel-section', $runtime );
		self::assertStringContainsString( 'cb-core-design-shell__field', $runtime );
		self::assertStringNotContainsString( 'window.location', $runtime );
		self::assertStringNotContainsString( 'location.reload', $runtime );
	}

	public function test_ajax_authority_commits_branding_and_template_as_one_reports_document(): void {
		$handler = $this->source( 'src/Ajax/Handlers/Branding.php' );
		$preview = $this->source( 'src/Reports/DesignerPreview.php' );

		self::assertStringContainsString( 'normalized_template_request()', $handler );
		self::assertStringContainsString( "\$composer['maintenance']", $handler );
		self::assertStringContainsString( "Settings::set_key( 'reports', \$reports", $handler );
		self::assertStringContainsString( "'template' => \$template", $handler );
		self::assertStringContainsString( 'MaintenanceTemplate::defaults()', $handler );
		self::assertStringContainsString( 'MaintenanceTemplate::current()', $handler );
		self::assertStringContainsString( 'MaintenanceTemplate::from_json', $handler );
		self::assertStringContainsString( 'render( $branding, $template )', $handler );
		self::assertStringContainsString( '?array $template = null', $preview );
	}
}
