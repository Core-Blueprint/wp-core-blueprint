<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\MutationAcknowledgement;
use CoreBlueprint\Core\Governance\EventRegistry;

final class MutationAcknowledgementDirectConsumersContractTest extends WP_UnitTestCase {

	private function source( string $path ): string {
		$source = file_get_contents( CB_CORE_DIR . $path );
		self::assertIsString( $source, $path );
		return $source;
	}

	public function test_shared_acknowledgement_contract_is_strict_and_required(): void {
		self::assertTrue( MutationAcknowledgement::confirmed( '1' ) );
		self::assertFalse( MutationAcknowledgement::confirmed( 1 ) );
		self::assertFalse( MutationAcknowledgement::confirmed( true ) );
		self::assertFalse( MutationAcknowledgement::confirmed( '01' ) );

		ob_start();
		MutationAcknowledgement::render(
			'direct_consumer_acknowledgement',
			'cb-direct-consumer-acknowledgement',
			'Confirm consequential mutation.',
			'Backup or recovery responsibility remains with the operator.'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="direct_consumer_acknowledgement"', $html );
		self::assertStringContainsString( 'value="1"', $html );
		self::assertStringContainsString( 'required', $html );
	}

	public function test_media_replace_requires_acknowledgement_and_audits_before_replace(): void {
		$source = $this->source( 'src/MediaReplace/AdminIntegration.php' );

		self::assertStringContainsString( 'MutationAcknowledgement::render(', $source );
		self::assertStringContainsString( "self::ACKNOWLEDGEMENT_FIELD", $source );
		self::assertStringContainsString( 'MutationAcknowledgement::require_confirmed(', $source );
		self::assertStringContainsString( "AuditLog::log( 'media.replace.acknowledged'", $source );

		$acknowledgement = strpos( $source, "AuditLog::log( 'media.replace.acknowledged'" );
		$mutation = strpos( $source, '->replace( $attachment_id, $upload )' );
		self::assertNotFalse( $acknowledgement );
		self::assertNotFalse( $mutation );
		self::assertLessThan( $mutation, $acknowledgement );
	}

	public function test_schema_import_requires_acknowledgement_and_audits_before_import(): void {
		$view = $this->source( 'src/ContentModels/Admin/ToolsView.php' );
		self::assertStringContainsString( "'content_models_import_acknowledgement'", $view );
		self::assertStringContainsString( 'MutationAcknowledgement::render(', $view );

		$transfer = $this->source( 'src/ContentModels/Admin/Transfer.php' );
		self::assertStringContainsString( 'MutationAcknowledgement::require_confirmed(', $transfer );
		self::assertStringContainsString( "'1' === sanitize_text_field( wp_unslash( (string) \$_POST['overwrite'] ) )", $transfer );
		self::assertStringContainsString( "AuditLog::log( 'content.models.schema.import.acknowledged'", $transfer );

		$acknowledgement = strpos( $transfer, "AuditLog::log( 'content.models.schema.import.acknowledged'" );
		$mutation = strpos( $transfer, "SchemaTransfer::import( \$preview['document'], \$overwrite )" );
		self::assertNotFalse( $acknowledgement );
		self::assertNotFalse( $mutation );
		self::assertLessThan( $mutation, $acknowledgement );
	}

	public function test_native_adoption_requires_acknowledgement_and_audits_before_apply(): void {
		$source = $this->source( 'src/ContentModels/Importers/NativeWordPress/Bootstrap.php' );

		self::assertStringContainsString( "'content_models_native_import_acknowledgement'", $source );
		self::assertStringContainsString( 'MutationAcknowledgement::render(', $source );
		self::assertStringContainsString( 'MutationAcknowledgement::require_confirmed(', $source );
		self::assertStringContainsString( "AuditLog::log( 'content.models.native.import.acknowledged'", $source );

		$acknowledgement = strpos( $source, "AuditLog::log( 'content.models.native.import.acknowledged'" );
		$mutation = strpos( $source, 'Importer::apply_plan();' );
		self::assertNotFalse( $acknowledgement );
		self::assertNotFalse( $mutation );
		self::assertLessThan( $mutation, $acknowledgement );
	}

	public function test_direct_consumer_acknowledgement_events_are_registered_and_plain_described(): void {
		$events = [
			'media.replace.acknowledged',
			'content.models.schema.import.acknowledged',
			'content.models.native.import.acknowledged',
		];

		foreach ( $events as $event ) {
			self::assertTrue( EventRegistry::is_valid_id( $event ), $event );
		}

		$media = $this->source( 'src/MediaReplace/Bootstrap.php' );
		self::assertStringContainsString( "'media.replace.acknowledged'", $media );

		$content_models = $this->source( 'src/ContentModels/Bootstrap.php' );
		self::assertStringContainsString( "'content.models.schema.import.acknowledged'", $content_models );
		self::assertStringContainsString( "'content.models.native.import.acknowledged'", $content_models );

		$language = $this->source( 'src/Log/Language.php' );
		foreach ( $events as $event ) {
			self::assertStringContainsString( "'{$event}'", $language );
		}
	}
}
