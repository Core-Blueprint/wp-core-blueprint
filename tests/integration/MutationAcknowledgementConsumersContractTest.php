<?php
declare(strict_types=1);

final class CB_Base_Mutation_Acknowledgement_Consumers_Contract_Test extends WP_UnitTestCase {

	private function source( string $path ): string {
		$source = file_get_contents( CB_CORE_DIR . $path );
		self::assertIsString( $source, $path );
		return $source;
	}

	private function section( string $source, string $start, string $end ): string {
		$start_pos = strpos( $source, $start );
		$end_pos   = strpos( $source, $end, false === $start_pos ? 0 : $start_pos + strlen( $start ) );
		self::assertNotFalse( $start_pos, $start );
		self::assertNotFalse( $end_pos, $end );
		return substr( $source, (int) $start_pos, (int) $end_pos - (int) $start_pos );
	}

	public function test_direct_form_consumers_render_and_enforce_the_shared_boundary(): void {
		$media = $this->source( 'src/MediaReplace/AdminIntegration.php' );
		self::assertStringContainsString( "use CB\\Core\\Admin\\MutationAcknowledgement;", $media );
		self::assertStringContainsString( "self::ACKNOWLEDGEMENT_FIELD", $media );
		self::assertStringContainsString( "MutationAcknowledgement::require_confirmed(", $media );
		self::assertStringContainsString( "media.replace_acknowledged", $media );
		self::assertLessThan(
			strpos( $media, "->replace( \$attachment_id, \$upload )" ),
			strpos( $media, "media.replace_acknowledged" )
		);

		$tools = $this->source( 'src/ContentModels/Admin/ToolsView.php' );
		self::assertStringContainsString( "name=\"content_models_import_acknowledgement\"", $this->rendered_acknowledgement_fixture( $tools, 'content_models_import_acknowledgement' ) );

		$transfer = $this->source( 'src/ContentModels/Admin/Transfer.php' );
		self::assertStringContainsString( "MutationAcknowledgement::require_confirmed(", $transfer );
		self::assertStringContainsString( "content_models.schema_import_acknowledged", $transfer );
		self::assertLessThan(
			strpos( $transfer, "SchemaTransfer::import( \$preview['document'], \$overwrite )" ),
			strpos( $transfer, "content_models.schema_import_acknowledged" )
		);

		$native = $this->source( 'src/ContentModels/Importers/NativeWordPress/Bootstrap.php' );
		self::assertStringContainsString( "content_models_native_import_acknowledgement", $native );
		self::assertStringContainsString( "MutationAcknowledgement::require_confirmed(", $native );
		self::assertStringContainsString( "content_models.native_import_acknowledged", $native );
		self::assertLessThan(
			strpos( $native, "Importer::apply_plan();" ),
			strpos( $native, "content_models.native_import_acknowledged" )
		);
	}

	public function test_snippets_acknowledgement_is_required_only_for_preserved_id_restore(): void {
		$page = $this->source( 'src/Snippets/Admin/Page.php' );
		$actions = $this->source( 'src/Snippets/Admin/Actions.php' );
		$script = $this->source( 'assets/js/features/snippets.js' );

		self::assertStringContainsString( 'cb-snippets-restore-acknowledgement-template', $page );
		self::assertStringContainsString( 'data-cb-snippets-preserve-ids', $page );
		self::assertStringContainsString( "if ( \$overwrite ) {", $actions );
		self::assertStringContainsString( "MutationAcknowledgement::require_confirmed(", $actions );
		self::assertStringContainsString( "Importer::import_json( \$json, \$overwrite )", $actions );
		self::assertStringContainsString( "snippets.restore_acknowledged", $actions );
		self::assertStringContainsString( "if ( preserveIds.checked )", $script );
		self::assertStringContainsString( "restoreAcknowledgementTemplate.content.cloneNode( true )", $script );
	}

	public function test_notes_acknowledgement_is_required_only_when_an_import_overwrites(): void {
		$page = $this->source( 'src/Notes/Admin/Page.php' );
		$controller = $this->source( 'src/Notes/Rest/NotesController.php' );
		$script = $this->source( 'assets/js/features/notes.js' );

		self::assertStringContainsString( 'cb-notes-import-overwrite-acknowledgement-template', $page );
		self::assertStringContainsString( "if ( \$overwrite_count > 0 ) {", $controller );
		self::assertStringContainsString( "MutationAcknowledgement::require_confirmed(", $controller );
		self::assertStringContainsString( "notes_import_overwrite_acknowledged", $controller );
		self::assertStringContainsString( "Object.values(decisions).includes('overwrite')", $script );
		self::assertStringContainsString( "notes_import_overwrite_acknowledgement: hasOverwrite ? '1' : ''", $script );
		self::assertLessThan(
			strpos( $controller, "Repository::import_commit( \$notes, \$decisions )" ),
			strpos( $controller, "notes_import_overwrite_acknowledged" )
		);
	}

	public function test_scanner_restore_requires_acknowledgement_without_expanding_to_quarantine_or_delete(): void {
		$view = $this->source( 'src/Integrity/Admin/ScannerQuarantineView.php' );
		$controller = $this->source( 'src/Integrity/Rest/ScanController.php' );
		$script = $this->source( 'assets/js/features/core-scanner.js' );

		self::assertStringContainsString( 'cb-integrity-quarantine-restore-acknowledgement-template', $view );
		self::assertStringContainsString( "MutationAcknowledgement::require_confirmed(", $controller );
		self::assertStringContainsString( "integrity_quarantine_restore_acknowledged", $controller );
		self::assertStringContainsString( "quarantine_restore_acknowledgement: '1'", $script );

		$quarantine = $this->section( $controller, 'public function quarantine_finding', 'public function inspect_quarantine' );
		$delete = $this->section( $controller, 'public function delete_quarantine', 'public function add_quarantine_note' );
		self::assertStringNotContainsString( 'MutationAcknowledgement', $quarantine );
		self::assertStringNotContainsString( 'MutationAcknowledgement', $delete );
	}

	public function test_acknowledgement_audits_precede_their_mutations(): void {
		$snippets = $this->source( 'src/Snippets/Admin/Actions.php' );
		self::assertLessThan(
			strpos( $snippets, "Importer::import_json( \$json, \$overwrite )" ),
			strpos( $snippets, "snippets.restore_acknowledged" )
		);

		$scanner = $this->source( 'src/Integrity/Rest/ScanController.php' );
		self::assertLessThan(
			strpos( $scanner, "QuarantineService::restore( \$id )" ),
			strpos( $scanner, "integrity_quarantine_restore_acknowledged" )
		);
	}

	/**
	 * Source-level helper to keep the UI contract assertion independent from
	 * WordPress admin routing. The shared component is already behavior-tested
	 * by ProfilesFoundationContractTest; consumers prove they invoke it with the
	 * expected field.
	 */
	private function rendered_acknowledgement_fixture( string $source, string $field ): string {
		self::assertStringContainsString( "MutationAcknowledgement::render(", $source );
		self::assertStringContainsString( "'{$field}'", $source );
		return '<input name="' . $field . '">';
	}
}
