<?php
declare(strict_types=1);

use CoreBlueprint\Core\DataExchange\Engine;
use CoreBlueprint\Core\DataExchange\EntityInterface;
use CoreBlueprint\Core\DataExchange\Foundation;
use CoreBlueprint\Core\ExtensionRegistry;
use CoreBlueprint\Core\Interoperability\Registry;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;

/**
 * Test-only consumer adapter for FRG-T4-01A. Never registered in production.
 * The in-memory store represents consumer-owned canonical persistence.
 */
final class CB_T4_Portable_Template_Fixture implements EntityInterface {
	public static array $record = [];
	public static array $store = [];
	public static bool $authorized = true;

	public function is_available(): bool {
		return true;
	}

	public function schema_version(): int {
		return 1;
	}

	public function supports_schema_version( int $schema_version ): bool {
		return 1 === $schema_version;
	}

	public function can_export( array $context = [] ): bool {
		return self::$authorized && current_user_can( 'manage_options' )
			&& ( $context['portable_id'] ?? null ) === ( self::$record['portable_id'] ?? null );
	}

	public function can_import( array $context = [] ): bool {
		return self::$authorized && current_user_can( 'manage_options' )
			&& ( $context['intent'] ?? null ) === 'create_personal_copy'
			&& is_string( $context['destination_id'] ?? null )
			&& TemplateQuery::valid_id( $context['destination_id'] );
	}

	public function export_records( array $context = [] ): iterable|WP_Error {
		if ( ! $this->can_export( $context ) ) {
			return new WP_Error( 'fixture_export_forbidden', 'Cannot export this template.' );
		}
		return [ self::$record ];
	}

	public function plan_import( array $record, string $mode, int $source_schema_version, array $context = [] ): array|WP_Error {
		if ( Foundation::MODE_CREATE_ONLY !== $mode || 1 !== $source_schema_version ) {
			return new WP_Error( 'fixture_unsupported_mode', 'Only current-schema copy creation is supported.' );
		}
		$required = [
			'portable_id', 'designer_type', 'document_schema_version', 'template_version',
			'title', 'description', 'locale', 'category', 'resources', 'document',
		];
		if ( [] !== array_diff( $required, array_keys( $record ) )
			|| [] !== array_diff( array_keys( $record ), $required )
			|| ! is_string( $record['portable_id'] )
			|| ! TemplateQuery::valid_id( $record['portable_id'] )
			|| 'workflow' !== $record['designer_type']
			|| 1 !== $record['document_schema_version']
			|| '1' !== $record['template_version']
			|| ! is_string( $record['title'] )
			|| '' === trim( $record['title'] )
			|| strlen( $record['title'] ) > 120
			|| ! is_string( $record['description'] )
			|| strlen( $record['description'] ) > 500
			|| 'en' !== $record['locale']
			|| 'operations' !== $record['category']
			|| [] !== $record['resources']
			|| ! is_array( $record['document'] )
			|| array_keys( $record['document'] ) !== [ 'steps', 'layout' ]
			|| ! is_array( $record['document']['steps'] )
			|| ! array_is_list( $record['document']['steps'] )
			|| ! is_array( $record['document']['layout'] )
			|| [ 'columns' => 1 ] !== $record['document']['layout'] ) {
			return new WP_Error( 'fixture_invalid_portable_template', 'Template does not match the current portable document contract.' );
		}
		if ( ! $this->can_import( $context ) ) {
			return new WP_Error( 'fixture_forbidden', 'Cannot create a template copy.' );
		}
		$destination = $context['destination_id'];
		if ( isset( self::$store[ $destination ] ) ) {
			return new WP_Error( 'fixture_destination_collision', 'Local template identity already exists.' );
		}
		return [
			'operation' => Foundation::OP_CREATE,
			'reference' => 'template:' . $destination,
			'payload'   => [
				'destination_id' => $destination,
				'actor'          => get_current_user_id(),
				'source_identity'=> $record['portable_id'],
				'copy'           => $record,
			],
			'warnings'  => [],
		];
	}

	public function apply_import( array $plan, array $context = [] ): array|WP_Error {
		$payload = $plan['payload'] ?? [];
		$target = $payload['destination_id'] ?? null;
		if ( Foundation::OP_CREATE !== ( $plan['operation'] ?? null )
			|| ! $this->can_import( $context )
			|| ! is_string( $target )
			|| $target !== $context['destination_id']
			|| ( $payload['actor'] ?? null ) !== get_current_user_id()
			|| ! is_array( $payload['copy'] ?? null )
			|| ( $payload['source_identity'] ?? null ) !== ( $payload['copy']['portable_id'] ?? null ) ) {
			return new WP_Error( 'fixture_apply_forbidden', 'Import authority or plan changed before persistence.' );
		}
		if ( isset( self::$store[ $target ] ) ) {
			return new WP_Error( 'fixture_destination_collision', 'Local identity became occupied before apply.' );
		}
		self::$store[ $target ] = [
			'local_id'        => $target,
			'owner'           => get_current_user_id(),
			'source_portable' => $payload['source_identity'],
			'state'           => 'draft',
			'document'        => $payload['copy']['document'],
		];
		return [ 'reference' => 'template:' . $target ];
	}
}

final class CB_T4_Template_Portability_Contract_Test extends WP_UnitTestCase {
	private const PROVIDER = 'acme-t4-portability';
	private const ENTITY = 'template.workflow';
	private const PLUGIN_FILE = self::PROVIDER . '/' . self::PROVIDER . '.php';
	private int $operator;

	public function set_up(): void {
		parent::set_up();
		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );
		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		CB_T4_Portable_Template_Fixture::$record = [
			'portable_id'             => 'portable-workflow',
			'designer_type'           => 'workflow',
			'document_schema_version' => 1,
			'template_version'        => '1',
			'title'                   => 'Workflow',
			'description'             => 'Asset-free workflow template',
			'locale'                  => 'en',
			'category'                => 'operations',
			'resources'               => [],
			'document'                => [ 'steps' => [], 'layout' => [ 'columns' => 1 ] ],
		];
		CB_T4_Portable_Template_Fixture::$store = [];
		CB_T4_Portable_Template_Fixture::$authorized = true;
		$this->operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->operator );
		add_action( 'core_blueprint_register_extensions', [ $this, 'register_extension' ] );
		add_action( 'core_blueprint_register_interoperability_implementations', [ $this, 'register_entity' ] );
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_extensions', [ $this, 'register_extension' ] );
		remove_action( 'core_blueprint_register_interoperability_implementations', [ $this, 'register_entity' ] );
		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		$this->remove_fixture();
		wp_clean_plugins_cache( true );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function export_file(): string {
		$json = Engine::export_json( self::PROVIDER, self::ENTITY, [ 'portable_id' => 'portable-workflow' ] );
		self::assertIsString( $json );
		return $json;
	}

	private function copy_context( string $destination ): array {
		return [ 'intent' => 'create_personal_copy', 'destination_id' => $destination ];
	}

	public function test_t4_json_uses_existing_exact_envelope_with_one_asset_free_template(): void {
		$decoded = json_decode( $this->export_file(), true, 32, JSON_THROW_ON_ERROR );
		self::assertSame(
			[ 'format', 'format_version', 'extension_id', 'entity', 'schema_version', 'exported_at', 'records' ],
			array_keys( $decoded )
		);
		self::assertSame( Foundation::FORMAT_ID, $decoded['format'] );
		self::assertSame( Foundation::FORMAT_VERSION, $decoded['format_version'] );
		self::assertSame( self::PROVIDER, $decoded['extension_id'] );
		self::assertSame( self::ENTITY, $decoded['entity'] );
		self::assertSame( 1, $decoded['schema_version'] );
		self::assertSame( [ CB_T4_Portable_Template_Fixture::$record ], $decoded['records'] );
		self::assertSame( [], CB_T4_Portable_Template_Fixture::$store );
	}

	public function test_t4_same_portable_identity_imports_as_two_distinct_local_draft_copies(): void {
		$json = $this->export_file();
		foreach ( [ 'local-copy-a', 'local-copy-b' ] as $index => $local ) {
			$context = $this->copy_context( $local );
			$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $context );
			self::assertIsArray( $preview );
			self::assertTrue( $preview['valid'] );
			self::assertSame( 1, $preview['counts'][ Foundation::OP_CREATE ] );
			self::assertSame( 'template:' . $local, $preview['items'][0]['reference'] );
			self::assertArrayNotHasKey( 'payload', $preview['items'][0] );
			self::assertCount( $index, CB_T4_Portable_Template_Fixture::$store, 'Preview must not mutate consumer storage.' );
			$applied = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $context );
			self::assertIsArray( $applied );
			self::assertSame( 'complete', $applied['status'] );
			self::assertSame( 1, $applied['applied_count'] );
		}
		self::assertCount( 2, CB_T4_Portable_Template_Fixture::$store );
		self::assertSame( [ 'local-copy-a', 'local-copy-b' ], array_keys( CB_T4_Portable_Template_Fixture::$store ) );
		foreach ( CB_T4_Portable_Template_Fixture::$store as $copy ) {
			self::assertSame( $this->operator, $copy['owner'] );
			self::assertSame( 'portable-workflow', $copy['source_portable'] );
			self::assertSame( 'draft', $copy['state'] );
			self::assertSame( CB_T4_Portable_Template_Fixture::$record['document'], $copy['document'] );
		}
	}

	public function test_t4_destination_collision_fails_preview_without_overwrite(): void {
		$json = $this->export_file();
		CB_T4_Portable_Template_Fixture::$store['local-copy-a'] = [ 'sentinel' => 'untouched' ];
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->copy_context( 'local-copy-a' ) );
		self::assertIsArray( $preview );
		self::assertFalse( $preview['valid'] );
		self::assertSame( '', $preview['fingerprint'] );
		self::assertSame( 'fixture_destination_collision', $preview['errors'][0]['code'] ?? null );
		self::assertSame( [ 'sentinel' => 'untouched' ], CB_T4_Portable_Template_Fixture::$store['local-copy-a'] );
	}

	public function test_t4_destination_actor_and_document_are_bound_to_preview(): void {
		$json = $this->export_file();
		$context = $this->copy_context( 'local-copy-a' );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $context );
		self::assertTrue( $preview['valid'] );
		$changed_destination = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->copy_context( 'local-copy-b' ) );
		self::assertWPError( $changed_destination );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $changed_destination->get_error_code() );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $other );
		$other_actor = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $context );
		self::assertWPError( $other_actor );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $other_actor->get_error_code() );
		wp_set_current_user( $this->operator );
		$changed = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		$changed['records'][0]['title'] = 'Unapproved modification';
		$tampered = Engine::apply_json( (string) wp_json_encode( $changed ), Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $context );
		self::assertWPError( $tampered );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $tampered->get_error_code() );
		self::assertSame( [], CB_T4_Portable_Template_Fixture::$store );
	}

	public function test_t4_revoked_permission_and_concurrent_destination_fail_closed(): void {
		$json = $this->export_file();
		$context = $this->copy_context( 'local-copy-a' );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $context );
		self::assertTrue( $preview['valid'] );
		CB_T4_Portable_Template_Fixture::$authorized = false;
		$denied = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $context );
		self::assertWPError( $denied );
		self::assertSame( 'cb_core_data_exchange_forbidden', $denied->get_error_code() );
		CB_T4_Portable_Template_Fixture::$authorized = true;
		CB_T4_Portable_Template_Fixture::$store['local-copy-a'] = [ 'sentinel' => true ];
		$collision = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $context );
		self::assertWPError( $collision );
		self::assertSame( 'cb_core_data_exchange_invalid_records', $collision->get_error_code() );
		self::assertSame( [ 'sentinel' => true ], CB_T4_Portable_Template_Fixture::$store['local-copy-a'] );
	}

	public function test_t4_unsupported_schema_media_and_local_identifiers_are_rejected(): void {
		$json = $this->export_file();
		$context = $this->copy_context( 'local-copy-a' );
		$base = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		$variants = [];
		$variant = $base;
		$variant['records'][0]['resources'] = [ [ 'attachment_id' => 42 ] ];
		$variants[] = $variant;
		$variant = $base;
		$variant['records'][0]['wp_post_id'] = 42;
		$variants[] = $variant;
		$variant = $base;
		$variant['records'][0]['document']['attachment_id'] = 42;
		$variants[] = $variant;
		$variant = $base;
		$variant['records'][0]['designer_type'] = 'mail';
		$variants[] = $variant;
		$variant = $base;
		unset( $variant['records'][0]['document_schema_version'] );
		$variants[] = $variant;
		$variant = $base;
		$variant['records'][0]['portable_id'] = '12345';
		$variants[] = $variant;
		foreach ( $variants as $variant ) {
			$preview = Engine::preview_json( (string) wp_json_encode( $variant ), Foundation::MODE_CREATE_ONLY, $context );
			self::assertIsArray( $preview );
			self::assertFalse( $preview['valid'] );
			self::assertSame( 'fixture_invalid_portable_template', $preview['errors'][0]['code'] ?? null );
		}
		$wrong_schema = $base;
		$wrong_schema['schema_version'] = 2;
		$unsupported = Engine::preview_json( (string) wp_json_encode( $wrong_schema ), Foundation::MODE_CREATE_ONLY, $context );
		self::assertWPError( $unsupported );
		self::assertSame( 'cb_core_data_exchange_unsupported_schema', $unsupported->get_error_code() );
		$wrong_owner = $base;
		$wrong_owner['extension_id'] = 'unknown-template-owner';
		$owner_denied = Engine::preview_json( (string) wp_json_encode( $wrong_owner ), Foundation::MODE_CREATE_ONLY, $context );
		self::assertWPError( $owner_denied );
		self::assertSame( 'cb_core_data_exchange_unknown_entity', $owner_denied->get_error_code() );
		self::assertSame( [], CB_T4_Portable_Template_Fixture::$store );
	}

	public function test_t4_duplicate_destination_plan_references_are_rejected(): void {
		$envelope = json_decode( $this->export_file(), true, 32, JSON_THROW_ON_ERROR );
		$envelope['records'][] = $envelope['records'][0];
		$preview = Engine::preview_json( (string) wp_json_encode( $envelope ), Foundation::MODE_CREATE_ONLY, $this->copy_context( 'local-copy-a' ) );
		self::assertIsArray( $preview );
		self::assertFalse( $preview['valid'] );
		self::assertSame( 'cb_core_data_exchange_duplicate_reference', $preview['errors'][0]['code'] ?? null );
		self::assertSame( [], CB_T4_Portable_Template_Fixture::$store );
	}

	public function test_t4_import_is_not_a_generic_upsert_and_export_checks_selection(): void {
		$export_denied = Engine::export_json( self::PROVIDER, self::ENTITY, [ 'portable_id' => 'hidden-workflow' ] );
		self::assertWPError( $export_denied );
		self::assertSame( 'cb_core_data_exchange_forbidden', $export_denied->get_error_code() );
		$json = $this->export_file();
		$context = $this->copy_context( 'local-copy-a' );
		$unauthorized_mode = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE, $context );
		self::assertIsArray( $unauthorized_mode );
		self::assertFalse( $unauthorized_mode['valid'] );
		self::assertSame( 'fixture_unsupported_mode', $unauthorized_mode['errors'][0]['code'] ?? null );
		$no_context = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY );
		self::assertWPError( $no_context );
		self::assertSame( 'cb_core_data_exchange_forbidden', $no_context->get_error_code() );
		self::assertSame( [], CB_T4_Portable_Template_Fixture::$store );
	}

	public function register_extension(): void {
		ExtensionRegistry::register( [
			'id' => self::PROVIDER,
			'plugin_file' => self::PLUGIN_FILE,
			'requires_api' => '1.0',
			'requires_base' => '1.0.0-rc1',
			'menu_url' => '',
			'status_id' => '',
		] );
	}

	public function register_entity(): void {
		Registry::register_implementation( [
			'provider' => self::PROVIDER,
			'id' => self::ENTITY,
			'label' => 'Portable template fixture',
			'description' => 'Asset-free copy-only template import/export contract.',
			'contract_owner' => Foundation::CONTRACT_OWNER,
			'contract' => Foundation::CONTRACT_ID,
			'contract_version' => Foundation::CONTRACT_VERSION,
			'supports' => [ Foundation::SUPPORT_EXPORT, Foundation::SUPPORT_IMPORT, Foundation::SUPPORT_JSON ],
			'factory' => static fn(): EntityInterface => new CB_T4_Portable_Template_Fixture(),
		] );
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::PROVIDER;
		self::assertTrue( wp_mkdir_p( $directory ) );
		$php = "<?php\n/**\n * Plugin Name: Acme T4 Portability\n * Version: 1.0.0\n */\ndefined( 'ABSPATH' ) || exit;\n";
		self::assertNotFalse( file_put_contents( $directory . '/' . self::PROVIDER . '.php', $php ) );
	}

	private function remove_fixture(): void {
		$file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( dirname( $file ) ) ) {
			rmdir( dirname( $file ) );
		}
	}
}
