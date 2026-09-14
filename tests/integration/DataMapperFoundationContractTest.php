<?php
declare(strict_types=1);

use CB\Core\DataExchange\Foundation;
use CB\Core\DataExchange\Mapper;
use CB\Core\DataExchange\Mapper\Renderer;

final class CB_Base_Data_Mapper_Foundation_Contract_Test extends WP_UnitTestCase {

	/** @return list<array<string,mixed>> */
	private function target_schema(): array {
		return [
			[
				'id'       => 'email',
				'label'    => 'Email',
				'type'     => 'string',
				'required' => true,
				'readable' => true,
				'writable' => true,
				'aliases'  => [ 'EMAIL', 'email address', 'e-mail' ],
			],
			[
				'id'       => 'first_name',
				'label'    => 'First name',
				'type'     => 'string',
				'required' => false,
				'readable' => true,
				'writable' => true,
				'aliases'  => [ 'FNAME', 'firstname', 'voornaam' ],
			],
			[
				'id'       => 'company',
				'label'    => 'Company',
				'type'     => 'string',
				'required' => false,
				'readable' => true,
				'writable' => true,
				'aliases'  => [ 'COMPANY' ],
			],
		];
	}

	public function test_dm1_csv_inspection_and_alias_matching_are_deterministic(): void {
		$csv = "EMAIL;FNAME;COMPANY;LEGACY_NOTE\nada@example.test;Ada;Analytical Engines;old\n";
		$inspection = Mapper::inspect_csv( $csv );
		self::assertIsArray( $inspection );
		self::assertSame( ';', $inspection['delimiter'] );
		self::assertSame( 1, $inspection['record_count'] );
		self::assertSame( [ 'EMAIL', 'FNAME', 'COMPANY', 'LEGACY_NOTE' ], array_column( $inspection['fields'], 'id' ) );

		$mapping = Mapper::suggest( $inspection['fields'], $this->target_schema() );
		self::assertIsArray( $mapping );
		self::assertSame( 'email', $mapping[0]['target'] );
		self::assertSame( 'first_name', $mapping[1]['target'] );
		self::assertSame( 'company', $mapping[2]['target'] );
		self::assertSame( Foundation::MAP_IGNORE, $mapping[3]['transform'] );
	}

	public function test_dm1_ambiguous_aliases_are_not_guessed(): void {
		$source = [ [ 'id' => 'NAME', 'label' => 'Name', 'type' => 'string', 'readable' => true, 'writable' => false ] ];
		$target = [
			[ 'id' => 'first_name', 'label' => 'First name', 'type' => 'string', 'aliases' => [ 'NAME' ] ],
			[ 'id' => 'display_name', 'label' => 'Display name', 'type' => 'string', 'aliases' => [ 'NAME' ] ],
		];
		$mapping = Mapper::suggest( $source, $target );
		self::assertIsArray( $mapping );
		self::assertSame( Foundation::MAP_IGNORE, $mapping[0]['transform'] );
		self::assertNull( $mapping[0]['target'] );
	}

	public function test_dm1_mapping_plan_fails_closed_for_duplicate_targets_and_missing_required_fields(): void {
		$source = [
			[ 'id' => 'EMAIL', 'label' => 'Email', 'type' => 'string', 'readable' => true, 'writable' => false ],
			[ 'id' => 'ALT_EMAIL', 'label' => 'Alt email', 'type' => 'string', 'readable' => true, 'writable' => false ],
		];
		$duplicate = Mapper::plan(
			$source,
			$this->target_schema(),
			[
				[ 'source' => 'EMAIL', 'target' => 'email', 'transform' => Foundation::MAP_DIRECT, 'value' => null ],
				[ 'source' => 'ALT_EMAIL', 'target' => 'email', 'transform' => Foundation::MAP_DIRECT, 'value' => null ],
			]
		);
		self::assertIsArray( $duplicate );
		self::assertFalse( $duplicate['valid'] );
		self::assertContains( 'cb_core_data_mapper_invalid_direct_mapping', array_column( $duplicate['errors'], 'code' ) );

		$missing = Mapper::plan(
			$source,
			$this->target_schema(),
			[
				[ 'source' => 'EMAIL', 'target' => null, 'transform' => Foundation::MAP_IGNORE, 'value' => null ],
				[ 'source' => 'ALT_EMAIL', 'target' => null, 'transform' => Foundation::MAP_IGNORE, 'value' => null ],
			]
		);
		self::assertIsArray( $missing );
		self::assertFalse( $missing['valid'] );
		self::assertContains( 'cb_core_data_mapper_required_unmapped', array_column( $missing['errors'], 'code' ) );
	}

	public function test_dm1_schema_booleans_and_mapping_tokens_are_exact(): void {
		$bad_schema = Mapper::normalize_schema( [
			[
				'id'       => 'email',
				'label'    => 'Email',
				'type'     => 'string',
				'required' => 'yes',
			],
		] );
		self::assertWPError( $bad_schema );
		self::assertSame( 'cb_core_data_mapper_invalid_schema', $bad_schema->get_error_code() );

		$source = [ [ 'id' => 'EMAIL', 'label' => 'Email', 'type' => 'string', 'readable' => true, 'writable' => false ] ];
		$plan = Mapper::plan(
			$source,
			$this->target_schema(),
			[ [ 'source' => 'EMAIL', 'target' => 'email', 'transform' => ' direct ', 'value' => null ] ]
		);
		self::assertIsArray( $plan );
		self::assertFalse( $plan['valid'] );
		self::assertSame( 'cb_core_data_mapper_invalid_transform', $plan['errors'][0]['code'] ?? null );
	}

	public function test_dm1_mapped_records_feed_the_canonical_data_exchange_envelope(): void {
		$inspection = Mapper::inspect_csv( "EMAIL,FNAME\nada@example.test,Ada\n" );
		self::assertIsArray( $inspection );
		$mapping = Mapper::suggest( $inspection['fields'], $this->target_schema() );
		self::assertIsArray( $mapping );
		$mapped = Mapper::map_records( $inspection['records'], $inspection['fields'], $this->target_schema(), $mapping );
		self::assertSame(
			[ [ 'email' => 'ada@example.test', 'first_name' => 'Ada' ] ],
			$mapped
		);

		$json = Mapper::exchange_json( 'vendor-crm', 'contacts', 1, $mapped );
		self::assertIsString( $json );
		$decoded = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		self::assertSame( Foundation::FORMAT_ID, $decoded['format'] );
		self::assertSame( 'vendor-crm', $decoded['extension_id'] );
		self::assertSame( 'contacts', $decoded['entity'] );
		self::assertSame( $mapped, $decoded['records'] );

		$invalid = Mapper::exchange_json( ' vendor-crm ', 'contacts', 1, $mapped );
		self::assertWPError( $invalid );
		self::assertSame( 'cb_core_data_mapper_invalid_extension', $invalid->get_error_code() );
	}

	public function test_dm1_constant_mapping_cannot_amplify_output_beyond_transport_limit(): void {
		$source = [ [ 'id' => 'KEY', 'label' => 'Key', 'type' => 'string', 'readable' => true, 'writable' => false ] ];
		$target = [ [ 'id' => 'blob', 'label' => 'Blob', 'type' => 'string', 'required' => true, 'readable' => false, 'writable' => true ] ];
		$constant = str_repeat( 'x', intdiv( Foundation::MAX_INPUT_BYTES, 2 ) + 1 );
		$result = Mapper::map_records(
			[ [ 'KEY' => 'one' ], [ 'KEY' => 'two' ] ],
			$source,
			$target,
			[ [ 'source' => null, 'target' => 'blob', 'transform' => Foundation::MAP_CONSTANT, 'value' => $constant ] ]
		);
		self::assertWPError( $result );
		self::assertSame( 'cb_core_data_mapper_output_too_large', $result->get_error_code() );
	}

	public function test_dm1_renderer_consumes_the_shared_designer_shell_without_document_profile_semantics(): void {
		$html = Renderer::render( [
			'direction'     => Foundation::DIRECTION_IMPORT,
			'title'         => 'Contact Mapper',
			'source_label'  => 'CSV',
			'target_label'  => 'CRM',
			'source_fields' => [ [ 'id' => 'EMAIL', 'label' => 'Email', 'type' => 'string', 'readable' => true, 'writable' => false ] ],
			'target_fields' => $this->target_schema(),
			'launch_mode'   => 'manual',
		] );
		self::assertIsString( $html );
		self::assertStringContainsString( 'data-cb-design-launch-root', $html );
		self::assertStringContainsString( 'data-cb-design-shell', $html );
		self::assertStringContainsString( 'cb-core-design-shell__workspace', $html );
		self::assertStringContainsString( 'data-cb-data-mapper-root', $html );
		self::assertStringContainsString( 'data-cb-design-shell-group="mapper-details"', $html );
		self::assertStringNotContainsString( 'data-cb-design-shell-sidebar-role="layers"', $html );
		self::assertStringNotContainsString( 'document-fixed', $html );
		self::assertStringNotContainsString( 'document-flow', $html );
	}

	public function test_dm1_import_intake_can_start_without_a_source_schema_and_stays_in_the_shared_shell(): void {
		$html = Renderer::render( [
			'direction'     => Foundation::DIRECTION_IMPORT,
			'intake'        => true,
			'target_fields' => $this->target_schema(),
			'launch_mode'   => 'manual',
		] );
		self::assertIsString( $html );
		self::assertStringContainsString( 'data-cb-data-mapper-file', $html );
		self::assertStringContainsString( 'Choose a source file to begin mapping.', $html );
		self::assertStringContainsString( 'data-cb-design-shell', $html );

		$export_intake = Renderer::render( [
			'direction'     => Foundation::DIRECTION_EXPORT,
			'intake'        => true,
			'target_fields' => $this->target_schema(),
		] );
		self::assertWPError( $export_intake );
		self::assertSame( 'cb_core_data_mapper_missing_source_schema', $export_intake->get_error_code() );
	}

	public function test_dm1_renderer_refuses_a_structurally_invalid_initial_mapping(): void {
		$result = Renderer::render( [
			'direction'     => Foundation::DIRECTION_IMPORT,
			'source_fields' => [
				[ 'id' => 'EMAIL', 'label' => 'Email', 'type' => 'string', 'readable' => true, 'writable' => false ],
				[ 'id' => 'ALT_EMAIL', 'label' => 'Alt email', 'type' => 'string', 'readable' => true, 'writable' => false ],
			],
			'target_fields' => $this->target_schema(),
			'mapping'       => [
				[ 'source' => 'EMAIL', 'target' => 'email', 'transform' => Foundation::MAP_DIRECT, 'value' => null ],
				[ 'source' => 'ALT_EMAIL', 'target' => 'email', 'transform' => Foundation::MAP_DIRECT, 'value' => null ],
			],
		] );
		self::assertWPError( $result );
		self::assertSame( 'cb_core_data_mapper_invalid_mapping', $result->get_error_code() );
	}

	public function test_dm1_mapper_source_remains_provider_neutral_and_uses_public_designer_assets(): void {
		$root = dirname( __DIR__, 2 );
		$assets = file_get_contents( $root . '/src/DataExchange/Mapper/Assets.php' );
		$renderer = file_get_contents( $root . '/src/DataExchange/Mapper/Renderer.php' );
		$runtime = file_get_contents( $root . '/assets/js/data-exchange/data-mapper.js' );
		$styles = file_get_contents( $root . '/assets/css/data-exchange/data-mapper.css' );
		self::assertIsString( $assets );
		self::assertIsString( $renderer );
		self::assertIsString( $runtime );
		self::assertIsString( $styles );

		self::assertStringContainsString( 'DesignerAssets::enqueue_designer_mode', $assets );
		self::assertStringContainsString( "from '@cb-core/design-editor'", $runtime );
		self::assertStringContainsString( 'createDesignerShell', $runtime );
		self::assertStringNotContainsString( 'jQuery', $runtime );
		self::assertStringNotContainsString( 'Bricks', $assets . $renderer . $runtime . $styles );
		self::assertStringNotContainsString( 'Mailchimp', $assets . $renderer . $runtime . $styles );
		self::assertStringNotContainsString( 'Brevo', $assets . $renderer . $runtime . $styles );
		self::assertStringNotContainsString( 'position: fixed', $styles );
	}
}
