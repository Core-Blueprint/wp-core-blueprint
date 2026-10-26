<?php
declare(strict_types=1);

use CoreBlueprint\Core\Reports\Composer\BlockCatalog;
use CoreBlueprint\Core\Reports\Composer\MaintenanceTemplate;

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

	public function test_native_reports_platform_validates_complete_document_and_denies_composer_envelopes(): void {
		$document = [
			'project' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_project(),
			'fields' => \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::default_fields(),
		];
		self::assertTrue( \CoreBlueprint\Core\Reports\Composer\NativeComposition::valid_document( $document ) );
		self::assertTrue( \CoreBlueprint\Core\Design\Editor\PlatformTransport::valid_native_document(
			\CoreBlueprint\Core\Design\Document\Repository::REPORTS, $document
		) );
		$invalid = $document;
		$invalid['composer'] = MaintenanceTemplate::defaults();
		self::assertFalse( \CoreBlueprint\Core\Reports\Composer\NativeComposition::valid_document( $invalid ) );
		$invalid = $document;
		$invalid['project']['root']['children'][0]['properties']['enabled'] = 'on';
		self::assertFalse( \CoreBlueprint\Core\Reports\Composer\NativeComposition::valid_document( $invalid ) );
	}

	public function test_active_reports_editor_uses_native_platform_not_legacy_composer_transport(): void {
		$bootstrap = $this->source( 'src/Reports/Bootstrap.php' );
		self::assertStringContainsString( 'DesignEditorAssets::platform_fullscreen_requested()', $bootstrap );
		self::assertStringContainsString( 'DesignEditorAssets::enqueue_platform()', $bootstrap );
		self::assertStringContainsString( "wp_dequeue_script_module( '@cb-core/reports-preferences' )", $bootstrap );
		self::assertStringContainsString( "current_user_can( 'cb_manage_branding' )", $bootstrap );
		$adapter = \CoreBlueprint\Core\Design\Profile\Reports\PlatformAdapter::class;
		self::assertSame( 'base/reports', $adapter::PROFILE );
		self::assertSame( 'core.blueprint.reports', $adapter::TYPE );
	}

	public function test_legacy_composer_write_cannot_mutate_native_design_authority(): void {
		$before = \CoreBlueprint\Core\Design\Document\Repository::get(
			\CoreBlueprint\Core\Design\Document\Repository::REPORTS, 'maintenance'
		);
		$branding = \CoreBlueprint\Core\Reports\ReportBrandingInput::normalize( [] );
		$template = MaintenanceTemplate::defaults();
		self::assertSame( 'blocked', \CoreBlueprint\Core\Reports\DesignDocumentAdapter::save(
			'0', $branding, $template
		)['status'] );
		self::assertSame( 'blocked', \CoreBlueprint\Core\Reports\DesignDocumentAdapter::reset( '0' )['status'] );
		self::assertSame( $before, \CoreBlueprint\Core\Design\Document\Repository::get(
			\CoreBlueprint\Core\Design\Document\Repository::REPORTS, 'maintenance'
		) );
	}

	public function test_legacy_ajax_branch_is_explicit_and_cannot_gain_native_authority(): void {
		$handler = $this->source( 'src/Ajax/Handlers/Branding.php' );
		$preview = $this->source( 'src/Reports/DesignerPreview.php' );
		$adapter = $this->source( 'src/Reports/DesignDocumentAdapter.php' );
		self::assertStringContainsString( '\CoreBlueprint\Core\Design\Document\Authority::inspect()', $handler );
		self::assertStringContainsString( "'active' === \$authority['status']", $handler );
		self::assertStringContainsString( "DesignDocumentAdapter::save( \$expected, \$branding, \$template )", $handler );
		self::assertStringContainsString( "if ( ! in_array( \$authority['status'], [ 'legacy', 'prepared' ], true ) )", $handler );
		self::assertStringContainsString( 'Settings::set_paths( [', $handler );
		self::assertStringContainsString( "return [ 'status' => 'blocked' ];", $adapter );
		self::assertStringContainsString( 'render( ?array $native = null )', $preview );
		self::assertStringContainsString( 'FlowRenderApi::preview_html(', $preview );
	}
}
