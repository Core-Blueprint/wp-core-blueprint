<?php
declare(strict_types=1);

use CB\Core\DataExchange\CsvEntityInterface;
use CB\Core\DataExchange\Engine;
use CB\Core\DataExchange\EntityInterface;
use CB\Core\DataExchange\Foundation;
use CB\Core\ExtensionRegistry;
use CB\Core\Interoperability\Registry;

final class CB_Data_Exchange_Fixture_Entity implements CsvEntityInterface {
	/** @var list<array<string,mixed>> */
	public static array $export_records = [];
	/** @var array<string,array<string,mixed>> */
	public static array $store = [];
	public static bool $available = true;
	public static bool $authorized = true;
	public static bool $invalid_plan = false;
	public static bool $invalid_apply_reference = false;
	public static bool $oversized_plan = false;
	public static bool $oversized_csv_record = false;
	public static bool $provider_error = false;

	public function is_available(): bool {
		return self::$available;
	}

	public function schema_version(): int {
		return 1;
	}

	public function supports_schema_version( int $schema_version ): bool {
		return 1 === $schema_version;
	}

	public function can_export( array $context = [] ): bool {
		unset( $context );
		return self::$authorized;
	}

	public function can_import( array $context = [] ): bool {
		unset( $context );
		return self::$authorized;
	}

	public function export_records( array $context = [] ): iterable|WP_Error {
		unset( $context );
		return self::$export_records;
	}

	public function plan_import( array $record, string $mode, int $source_schema_version, array $context = [] ): array|WP_Error {
		unset( $context );
		if ( self::$provider_error ) {
			return new WP_Error( 'fixture_provider_error', str_repeat( 'x', 1500 ) );
		}
		if ( self::$oversized_plan ) {
			return [
				'operation' => Foundation::OP_CREATE,
				'reference' => 'fixture:oversized',
				'payload'   => [ 'blob' => str_repeat( 'x', Foundation::MAX_INPUT_BYTES + 1 ) ],
				'warnings'  => [],
			];
		}
		if ( self::$invalid_plan ) {
			return [ 'operation' => Foundation::OP_UPDATE, 'reference' => 'fixture:invalid', 'payload' => $record ];
		}
		if ( 1 !== $source_schema_version || array_keys( $record ) !== [ 'key', 'title', 'kind' ] ) {
			return new WP_Error( 'fixture_invalid_record', 'Fixture record is invalid.' );
		}
		$key = is_string( $record['key'] ) ? trim( $record['key'] ) : '';
		if ( '' === $key || ! is_string( $record['title'] ) || ! is_string( $record['kind'] ) ) {
			return new WP_Error( 'fixture_invalid_record', 'Fixture record is invalid.' );
		}
		$exists = isset( self::$store[ $key ] );
		$operation = match ( $mode ) {
			Foundation::MODE_CREATE_ONLY     => $exists ? Foundation::OP_SKIP : Foundation::OP_CREATE,
			Foundation::MODE_UPDATE_EXISTING => $exists ? Foundation::OP_UPDATE : Foundation::OP_SKIP,
			Foundation::MODE_CREATE_UPDATE   => $exists ? Foundation::OP_UPDATE : Foundation::OP_CREATE,
			default                          => Foundation::OP_SKIP,
		};
		return [
			'operation' => $operation,
			'reference' => 'fixture:' . $key,
			'payload'   => $record,
			'warnings'  => [],
		];
	}

	public function apply_import( array $plan, array $context = [] ): array|WP_Error {
		unset( $context );
		$payload = $plan['payload'] ?? null;
		if ( ! is_array( $payload ) || ! isset( $payload['key'] ) || ! is_string( $payload['key'] ) ) {
			return new WP_Error( 'fixture_apply_invalid', 'Fixture apply payload is invalid.' );
		}
		if ( self::$invalid_apply_reference ) {
			return [ 'reference' => '   ' ];
		}
		self::$store[ $payload['key'] ] = $payload;
		return [ 'reference' => 'fixture:' . $payload['key'] ];
	}

	public function csv_columns( int $schema_version ): array {
		return 1 === $schema_version ? [ 'key', 'title', 'kind' ] : [];
	}

	public function to_csv_row( array $record ): array|WP_Error {
		return [
			'key'   => $record['key'] ?? '',
			'title' => $record['title'] ?? '',
			'kind'  => $record['kind'] ?? '',
		];
	}

	public function from_csv_row( array $row, int $schema_version ): array|WP_Error {
		if ( 1 !== $schema_version ) {
			return new WP_Error( 'fixture_schema', 'Unsupported fixture schema.' );
		}
		if ( self::$oversized_csv_record ) {
			return [
				'key'   => (string) ( $row['key'] ?? '' ),
				'title' => (string) ( $row['title'] ?? '' ),
				'kind'  => (string) ( $row['kind'] ?? '' ),
				'blob'  => str_repeat( 'x', Foundation::MAX_INPUT_BYTES + 1 ),
			];
		}
		return [
			'key'   => (string) ( $row['key'] ?? '' ),
			'title' => (string) ( $row['title'] ?? '' ),
			'kind'  => (string) ( $row['kind'] ?? '' ),
		];
	}
}

final class CB_Base_Data_Exchange_Foundation_Contract_Test extends WP_UnitTestCase {

	private const PROVIDER = 'acme-data-exchange';
	private const ENTITY   = 'fixture';
	private const PLUGIN_FILE = self::PROVIDER . '/' . self::PROVIDER . '.php';

	public function set_up(): void {
		parent::set_up();
		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );
		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		CB_Data_Exchange_Fixture_Entity::$export_records = [
			[ 'key' => 'one', 'title' => 'First', 'kind' => 'demo' ],
			[ 'key' => 'formula', 'title' => '=1+1', 'kind' => 'demo' ],
		];
		CB_Data_Exchange_Fixture_Entity::$store                   = [];
		CB_Data_Exchange_Fixture_Entity::$available               = true;
		CB_Data_Exchange_Fixture_Entity::$authorized              = true;
		CB_Data_Exchange_Fixture_Entity::$invalid_plan            = false;
		CB_Data_Exchange_Fixture_Entity::$invalid_apply_reference = false;
		CB_Data_Exchange_Fixture_Entity::$oversized_plan          = false;
		CB_Data_Exchange_Fixture_Entity::$oversized_csv_record     = false;
		CB_Data_Exchange_Fixture_Entity::$provider_error          = false;

		add_action( 'cb_core_register_extensions', [ $this, 'register_extension' ] );
		add_action( 'cb_core_register_interoperability_implementations', [ $this, 'register_entity' ] );
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_extension' ] );
		remove_action( 'cb_core_register_interoperability_implementations', [ $this, 'register_entity' ] );
		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		$this->remove_fixture();
		wp_clean_plugins_cache( true );
		parent::tear_down();
	}

	public function test_dx1_base_owns_contract_and_reuses_canonical_extension_identity(): void {
		$contract = Registry::contract( Foundation::CONTRACT_OWNER, Foundation::CONTRACT_ID, Foundation::CONTRACT_VERSION );
		self::assertIsArray( $contract );
		self::assertSame( EntityInterface::class, $contract['interface'] ?? null );

		$entities = Engine::entities( [ Foundation::SUPPORT_IMPORT, Foundation::SUPPORT_JSON ] );
		self::assertCount( 1, $entities );
		$entity = array_values( $entities )[0];
		self::assertSame( self::PROVIDER, $entity['provider'] ?? null );
		self::assertSame( self::ENTITY, $entity['id'] ?? null );
	}

	public function test_dx1_json_export_preview_apply_and_stale_plan_protection(): void {
		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );
		$decoded = json_decode( $json, true );
		self::assertSame( Foundation::FORMAT_ID, $decoded['format'] ?? null );
		self::assertSame( Foundation::FORMAT_VERSION, $decoded['format_version'] ?? null );
		self::assertSame( self::PROVIDER, $decoded['extension_id'] ?? null );
		self::assertSame( self::ENTITY, $decoded['entity'] ?? null );
		self::assertSame( 1, $decoded['schema_version'] ?? null );
		self::assertCount( 2, $decoded['records'] ?? [] );

		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertIsArray( $preview );
		self::assertTrue( $preview['valid'] );
		self::assertSame( 2, $preview['counts'][ Foundation::OP_CREATE ] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $preview['fingerprint'] );
		self::assertArrayNotHasKey( 'payload', $preview['items'][0] );

		$applied = Engine::apply_json( $json, Foundation::MODE_CREATE_UPDATE, $preview['fingerprint'] );
		self::assertIsArray( $applied );
		self::assertSame( 'complete', $applied['status'] );
		self::assertSame( 2, $applied['applied_count'] );
		self::assertCount( 2, CB_Data_Exchange_Fixture_Entity::$store );

		$stale = Engine::apply_json( $json, Foundation::MODE_CREATE_UPDATE, $preview['fingerprint'] );
		self::assertWPError( $stale );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $stale->get_error_code() );
	}

	public function test_dx1_csv_is_self_describing_and_formula_safe_without_changing_roundtrip_data(): void {
		$csv = Engine::export_csv( self::PROVIDER, self::ENTITY );
		self::assertIsString( $csv );
		self::assertStringContainsString( 'cb_extension_id', $csv );
		self::assertStringContainsString( self::PROVIDER, $csv );
		self::assertStringContainsString( "'=1+1", $csv );

		$preview = Engine::preview_csv( $csv, Foundation::MODE_CREATE_UPDATE );
		self::assertIsArray( $preview );
		self::assertTrue( $preview['valid'] );
		$applied = Engine::apply_csv( $csv, Foundation::MODE_CREATE_UPDATE, $preview['fingerprint'] );
		self::assertIsArray( $applied );
		self::assertSame( 'complete', $applied['status'] );
		self::assertSame( '=1+1', CB_Data_Exchange_Fixture_Entity::$store['formula']['title'] ?? null );
	}

	public function test_dx1_authorization_availability_and_unknown_schema_fail_closed(): void {
		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );

		CB_Data_Exchange_Fixture_Entity::$authorized = false;
		$forbidden = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $forbidden );
		self::assertSame( 'cb_core_data_exchange_forbidden', $forbidden->get_error_code() );

		CB_Data_Exchange_Fixture_Entity::$authorized = true;
		CB_Data_Exchange_Fixture_Entity::$available  = false;
		$unavailable = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $unavailable );
		self::assertSame( 'cb_core_data_exchange_unavailable', $unavailable->get_error_code() );

		CB_Data_Exchange_Fixture_Entity::$available = true;
		$decoded = json_decode( $json, true );
		$decoded['schema_version'] = 2;
		$unsupported = Engine::preview_json( (string) wp_json_encode( $decoded ), Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $unsupported );
		self::assertSame( 'cb_core_data_exchange_unsupported_schema', $unsupported->get_error_code() );
	}

	public function test_dx1_identity_is_exact_and_never_silently_trimmed_into_canonical_metadata(): void {
		$bad_export = Engine::export_json( ' ' . self::PROVIDER, self::ENTITY );
		self::assertWPError( $bad_export );
		self::assertSame( 'cb_core_data_exchange_invalid_identity', $bad_export->get_error_code() );

		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );
		$decoded = json_decode( $json, true );
		$decoded['extension_id'] = self::PROVIDER . ' ';
		$bad_import = Engine::preview_json( (string) wp_json_encode( $decoded ), Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $bad_import );
		self::assertSame( 'cb_core_data_exchange_invalid_identity', $bad_import->get_error_code() );
		self::assertSame( [], CB_Data_Exchange_Fixture_Entity::$store );
	}

	public function test_dx1_explicit_invalid_apply_reference_fails_instead_of_falling_back(): void {
		CB_Data_Exchange_Fixture_Entity::$export_records = [
			[ 'key' => 'one', 'title' => 'First', 'kind' => 'demo' ],
		];
		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertIsArray( $preview );
		self::assertTrue( $preview['valid'] );

		CB_Data_Exchange_Fixture_Entity::$invalid_apply_reference = true;
		$result = Engine::apply_json( $json, Foundation::MODE_CREATE_UPDATE, $preview['fingerprint'] );
		self::assertIsArray( $result );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 0, $result['applied_count'] );
		self::assertSame( 'cb_core_data_exchange_apply_contract', $result['error']['code'] ?? null );
		self::assertSame( [], CB_Data_Exchange_Fixture_Entity::$store );
	}

	public function test_dx1_duplicate_portable_references_fail_preflight_before_mutation(): void {
		CB_Data_Exchange_Fixture_Entity::$export_records = [
			[ 'key' => 'same', 'title' => 'One', 'kind' => 'demo' ],
			[ 'key' => 'same', 'title' => 'Two', 'kind' => 'demo' ],
		];
		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertIsArray( $preview );
		self::assertFalse( $preview['valid'] );
		self::assertSame( 'cb_core_data_exchange_duplicate_reference', $preview['errors'][0]['code'] ?? null );
		self::assertSame( [], CB_Data_Exchange_Fixture_Entity::$store );
	}

	public function test_dx1_malformed_transport_and_provider_plan_contracts_fail_closed(): void {
		$bad_json = Engine::preview_json( '{', Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $bad_json );
		self::assertSame( 'cb_core_data_exchange_invalid_json', $bad_json->get_error_code() );

		$too_large = Engine::preview_json( str_repeat( 'x', Foundation::MAX_INPUT_BYTES + 1 ), Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $too_large );
		self::assertSame( 'cb_core_data_exchange_input_too_large', $too_large->get_error_code() );

		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );
		CB_Data_Exchange_Fixture_Entity::$invalid_plan = true;
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY );
		self::assertIsArray( $preview );
		self::assertFalse( $preview['valid'] );
		self::assertSame( '', $preview['fingerprint'] );
		self::assertSame( 'cb_core_data_exchange_plan_contract', $preview['errors'][0]['code'] ?? null );
	}

	public function test_dx1_provider_plan_and_error_amplification_are_bounded(): void {
		$json = Engine::export_json( self::PROVIDER, self::ENTITY );
		self::assertIsString( $json );

		CB_Data_Exchange_Fixture_Entity::$provider_error = true;
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertIsArray( $preview );
		self::assertFalse( $preview['valid'] );
		self::assertSame( 'fixture_provider_error', $preview['errors'][0]['code'] ?? null );
		self::assertLessThanOrEqual( 1000, strlen( (string) ( $preview['errors'][0]['message'] ?? '' ) ) );

		CB_Data_Exchange_Fixture_Entity::$provider_error = false;
		CB_Data_Exchange_Fixture_Entity::$oversized_plan = true;
		$oversized = Engine::preview_json( $json, Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $oversized );
		self::assertSame( 'cb_core_data_exchange_plan_too_large', $oversized->get_error_code() );
	}

	public function test_dx1_csv_provider_output_cannot_amplify_beyond_transport_limit(): void {
		$csv = Engine::export_csv( self::PROVIDER, self::ENTITY );
		self::assertIsString( $csv );
		CB_Data_Exchange_Fixture_Entity::$oversized_csv_record = true;
		$preview = Engine::preview_csv( $csv, Foundation::MODE_CREATE_UPDATE );
		self::assertWPError( $preview );
		self::assertSame( 'cb_core_data_exchange_output_too_large', $preview->get_error_code() );
	}

	public function register_extension(): void {
		ExtensionRegistry::register( [
			'id'            => self::PROVIDER,
			'plugin_file'   => self::PLUGIN_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '1.0.0-rc1',
			'menu_url'      => '',
			'status_id'     => '',
		] );
	}

	public function register_entity(): void {
		Registry::register_implementation( [
			'provider'         => self::PROVIDER,
			'id'               => self::ENTITY,
			'label'            => 'Fixture records',
			'description'      => 'Data Exchange Foundation fixture entity.',
			'contract_owner'   => Foundation::CONTRACT_OWNER,
			'contract'         => Foundation::CONTRACT_ID,
			'contract_version' => Foundation::CONTRACT_VERSION,
			'supports'         => [ Foundation::SUPPORT_EXPORT, Foundation::SUPPORT_IMPORT, Foundation::SUPPORT_JSON, Foundation::SUPPORT_CSV ],
			'factory'          => static fn(): EntityInterface => new CB_Data_Exchange_Fixture_Entity(),
		] );
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::PROVIDER;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create Data Exchange fixture directory.' );
		$plugin = "<?php\n/**\n * Plugin Name: Acme Data Exchange\n * Author: Acme Labs\n * Version: 1.0.0\n */\ndefined( 'ABSPATH' ) || exit;\n";
		self::assertNotFalse( file_put_contents( $directory . '/' . self::PROVIDER . '.php', $plugin ) );
	}

	private function remove_fixture(): void {
		$file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		$directory = dirname( $file );
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}
}
