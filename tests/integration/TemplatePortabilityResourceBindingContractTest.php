<?php
declare(strict_types=1);

use CoreBlueprint\Core\DataExchange\Engine;
use CoreBlueprint\Core\DataExchange\EntityInterface;
use CoreBlueprint\Core\DataExchange\Foundation;
use CoreBlueprint\Core\ExtensionRegistry;
use CoreBlueprint\Core\Interoperability\Registry;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;

/**
 * FRG-T4-01B contract-only adapter. The trusted projection is test-controlled:
 * this fixture DOES NOT decode archives, hash real media or persist assets.
 */
final class CB_T4_Resource_Binding_Fixture implements EntityInterface {
	public static array $verified = [];
	public static array $store = [];
	public static bool $allowed = true;

	public function is_available(): bool {
		return true;
	}

	public function schema_version(): int {
		return 1;
	}

	public function supports_schema_version( int $version ): bool {
		return 1 === $version;
	}

	public function can_export( array $context = [] ): bool {
		return false; // No package export implementation in this fixture.
	}

	public function export_records( array $context = [] ): iterable|WP_Error {
		return new WP_Error( 'fixture_export_not_supported', 'This is import-binding evidence only.' );
	}

	public function can_import( array $context = [] ): bool {
		return self::$allowed
			&& current_user_can( 'manage_options' )
			&& ( $context['intent'] ?? null ) === 'create_personal_copy'
			&& is_string( $context['destination_id'] ?? null )
			&& TemplateQuery::valid_id( $context['destination_id'] )
			&& is_string( $context['bundle_id'] ?? null )
			&& isset( self::$verified[ $context['bundle_id'] ] );
	}

	public function plan_import( array $record, string $mode, int $source_schema_version, array $context = [] ): array|WP_Error {
		if ( Foundation::MODE_CREATE_ONLY !== $mode || 1 !== $source_schema_version || ! $this->can_import( $context ) ) {
			return new WP_Error( 'fixture_unavailable', 'Package copy is not authorized or supported.' );
		}
		$required = [
			'portable_id', 'designer_type', 'document_schema_version', 'template_version',
			'title', 'description', 'locale', 'category', 'resources', 'document',
		];
		$keys = array_keys( $record );
		$resources = $record['resources'] ?? null;
		$document = $record['document'] ?? null;
		if ( [] !== array_diff( $required, $keys ) || [] !== array_diff( $keys, $required )
			|| ! is_string( $record['portable_id'] ) || ! TemplateQuery::valid_id( $record['portable_id'] )
			|| ! in_array( $record['designer_type'], [ 'report', 'certificate' ], true )
			|| 1 !== $record['document_schema_version']
			|| '1' !== $record['template_version']
			|| ! is_string( $record['title'] ) || '' === trim( $record['title'] )
			|| ! is_string( $record['description'] )
			|| 'en' !== $record['locale'] || 'operations' !== $record['category']
			|| ! is_array( $resources ) || ! array_is_list( $resources ) || 1 !== count( $resources )
			|| ! is_array( $document ) || array_is_list( $document )
			|| [] !== array_diff( [ 'type', 'resource_key' ], array_keys( $document ) )
			|| [] !== array_diff( array_keys( $document ), [ 'type', 'resource_key' ] )
			|| $record['designer_type'] !== $document['type']
		) {
			return new WP_Error( 'fixture_invalid_document', 'The resource-bound template is invalid.' );
		}
		$resource = $resources[0];
		$resource_keys = [ 'key', 'sha256', 'media_type', 'size' ];
		if ( ! is_array( $resource ) || [] !== array_diff( $resource_keys, array_keys( $resource ) )
			|| [] !== array_diff( array_keys( $resource ), $resource_keys )
			|| ! is_string( $resource['key'] ) || ! is_string( $document['resource_key'] )
			|| $resource['key'] !== $document['resource_key']
			|| ! is_string( $resource['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $resource['sha256'] )
			|| 'image/png' !== $resource['media_type']
			|| ! is_int( $resource['size'] ) || $resource['size'] < 1 || $resource['size'] > 5 * 1024 * 1024
			|| ( 'report' === $document['type'] && 'brand-logo' !== $resource['key'] )
			|| ( 'certificate' === $document['type'] && 'background-image' !== $resource['key'] )
		) {
			return new WP_Error( 'fixture_invalid_resource', 'A template resource is invalid.' );
		}
		$verified = self::$verified[ $context['bundle_id'] ];
		if ( ! is_array( $verified )
			|| ! is_string( $verified['package_sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $verified['package_sha256'] )
			|| ( $verified['resource'] ?? null ) !== $resource
			|| ( $verified['designer_type'] ?? null ) !== $document['type'] ) {
			return new WP_Error( 'fixture_unverified_resource', 'Template resource is not in the trusted package projection.' );
		}
		$destination = $context['destination_id'];
		if ( isset( self::$store[ $destination ] ) ) {
			return new WP_Error( 'fixture_destination_collision', 'The selected destination already exists.' );
		}
		return [
			'operation' => Foundation::OP_CREATE,
			'reference' => 'resource-template:' . $destination,
			'payload' => [
				'actor'           => get_current_user_id(),
				'destination_id'  => $destination,
				'package_sha256' => $verified['package_sha256'],
				'resource'        => $resource,
				'document'        => $document,
				'portable_id'     => $record['portable_id'],
			],
			'warnings' => [],
		];
	}

	public function apply_import( array $plan, array $context = [] ): array|WP_Error {
		if ( ! $this->can_import( $context ) || ( $plan['operation'] ?? null ) !== Foundation::OP_CREATE ) {
			return new WP_Error( 'fixture_apply_forbidden', 'Cannot apply resource-bound template copy.' );
		}
		$payload = $plan['payload'] ?? [];
		$verified = self::$verified[ $context['bundle_id'] ];
		$destination = $context['destination_id'];
		if ( ! is_array( $payload )
			|| ( $payload['actor'] ?? null ) !== get_current_user_id()
			|| ( $payload['destination_id'] ?? null ) !== $destination
			|| ( $payload['package_sha256'] ?? null ) !== ( $verified['package_sha256'] ?? null )
			|| ( $payload['resource'] ?? null ) !== ( $verified['resource'] ?? null )
			|| isset( self::$store[ $destination ] ) ) {
			return new WP_Error( 'fixture_apply_stale', 'Verified package, identity or destination changed.' );
		}
		// No binary write: the consumer adapter's staging/persistence is NOT implemented.
		self::$store[ $destination ] = [
			'owner'      => get_current_user_id(),
			'state'      => 'draft',
			'document'   => $payload['document'],
			'resource'   => $payload['resource'],
			'portable_id'=> $payload['portable_id'],
		];
		return [ 'reference' => 'resource-template:' . $destination ];
	}
}

final class CB_T4_Template_Resource_Binding_Contract_Test extends WP_UnitTestCase {
	private const PROVIDER = 'acme-t4-resource';
	private const ENTITY = 'template.resource';
	private const PLUGIN_FILE = self::PROVIDER . '/' . self::PROVIDER . '.php';
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );
		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		CB_T4_Resource_Binding_Fixture::$verified = [];
		CB_T4_Resource_Binding_Fixture::$store = [];
		CB_T4_Resource_Binding_Fixture::$allowed = true;
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $this->admin_id );
		self::assertInstanceOf( WP_User::class, $admin );
		self::assertTrue( PrivilegedAccessRegistry::approve( $admin, 0, 't4_resource_test_fixture' ) );
		wp_set_current_user( $this->admin_id );
		self::assertTrue( current_user_can( 'manage_options' ) );
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

	private function fixture( string $type ): array {
		self::assertContains( $type, [ 'report', 'certificate' ] );
		$key = 'report' === $type ? 'brand-logo' : 'background-image';
		$resource = [
			'key'        => $key,
			'sha256'     => str_repeat( 'a', 64 ),
			'media_type' => 'image/png',
			'size'       => 4096,
		];
		$record = [
			'portable_id'             => 'source-' . $type,
			'designer_type'           => $type,
			'document_schema_version' => 1,
			'template_version'        => '1',
			'title'                   => ucfirst( $type ) . ' sample',
			'description'             => 'Test-only verified package projection.',
			'locale'                  => 'en',
			'category'                => 'operations',
			'resources'               => [ $resource ],
			'document'                => [ 'type' => $type, 'resource_key' => $key ],
		];
		CB_T4_Resource_Binding_Fixture::$verified['bundle-a'] = [
			'package_sha256' => str_repeat( 'b', 64 ),
			'designer_type' => $type,
			'resource' => $resource,
		];
		$json = wp_json_encode( [
			'format'         => Foundation::FORMAT_ID,
			'format_version' => Foundation::FORMAT_VERSION,
			'extension_id'   => self::PROVIDER,
			'entity'         => self::ENTITY,
			'schema_version' => 1,
			'exported_at'    => '2026-11-05T00:00:00Z',
			'records'        => [ $record ],
		] );
		self::assertIsString( $json );
		return [ $json, $record ];
	}

	private function context( string $destination = 'personal-copy-a' ): array {
		return [
			'intent' => 'create_personal_copy',
			'destination_id' => $destination,
			'bundle_id' => 'bundle-a',
		];
	}

	public function test_t4b_reports_and_certificates_verified_projection_creates_draft_only(): void {
		foreach ( [ 'report', 'certificate' ] as $type ) {
			CB_T4_Resource_Binding_Fixture::$store = [];
			[ $json, $record ] = $this->fixture( $type );
			$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context() );
			self::assertIsArray( $preview );
			self::assertTrue( $preview['valid'] );
			self::assertSame( 1, $preview['counts'][ Foundation::OP_CREATE ] );
			self::assertArrayNotHasKey( 'payload', $preview['items'][0] );
			self::assertSame( [], CB_T4_Resource_Binding_Fixture::$store, 'Preview must remain read-only.' );
			$applied = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->context() );
			self::assertIsArray( $applied );
			self::assertSame( 'complete', $applied['status'] );
			$copy = CB_T4_Resource_Binding_Fixture::$store['personal-copy-a'];
			self::assertSame( $this->admin_id, $copy['owner'] );
			self::assertSame( 'draft', $copy['state'] );
			self::assertSame( $record['document'], $copy['document'] );
			self::assertSame( $record['resources'][0], $copy['resource'] );
			self::assertArrayNotHasKey( 'asset_id', $copy['document'] );
			self::assertArrayNotHasKey( 'logo_attachment_id', $copy['document'] );
			self::assertArrayNotHasKey( 'preview_url', $copy['document'] );
		}
	}

	public function test_t4b_changed_trusted_package_digest_invalidates_preview_fingerprint(): void {
		[ $json ] = $this->fixture( 'report' );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context() );
		self::assertTrue( $preview['valid'] );
		CB_T4_Resource_Binding_Fixture::$verified['bundle-a']['package_sha256'] = str_repeat( 'c', 64 );
		$result = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->context() );
		self::assertWPError( $result );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $result->get_error_code() );
		self::assertSame( [], CB_T4_Resource_Binding_Fixture::$store );
	}

	public function test_t4b_tampered_resource_or_local_id_never_reaches_apply(): void {
		[ $json ] = $this->fixture( 'certificate' );
		$decoded = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		foreach ( [ 'tampered_digest', 'source_local_id' ] as $case ) {
			$input = $decoded;
			if ( 'tampered_digest' === $case ) {
				$input['records'][0]['resources'][0]['sha256'] = str_repeat( 'd', 64 );
			} else {
				$input['records'][0]['document']['asset_id'] = 123;
			}
			$preview = Engine::preview_json( (string) wp_json_encode( $input ), Foundation::MODE_CREATE_ONLY, $this->context() );
			self::assertIsArray( $preview );
			self::assertFalse( $preview['valid'] );
			self::assertNotEmpty( $preview['errors'] );
		}
		self::assertSame( [], CB_T4_Resource_Binding_Fixture::$store );
	}

	public function test_t4b_destination_and_actor_are_bound_and_collisions_fail_closed(): void {
		[ $json ] = $this->fixture( 'report' );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context() );
		self::assertTrue( $preview['valid'] );
		$changed_destination = Engine::apply_json(
			$json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->context( 'personal-copy-b' )
		);
		self::assertWPError( $changed_destination );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $changed_destination->get_error_code() );
		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );
		self::assertTrue( PrivilegedAccessRegistry::approve( $other, 0, 't4_resource_test_fixture' ) );
		wp_set_current_user( $other_id );
		self::assertTrue( current_user_can( 'manage_options' ) );
		$changed_actor = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->context() );
		self::assertWPError( $changed_actor );
		self::assertSame( 'cb_core_data_exchange_stale_plan', $changed_actor->get_error_code() );
		wp_set_current_user( $this->admin_id );
		CB_T4_Resource_Binding_Fixture::$store['personal-copy-a'] = [ 'untouched' => true ];
		$collision = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context() );
		self::assertIsArray( $collision );
		self::assertFalse( $collision['valid'] );
		self::assertSame( 'fixture_destination_collision', $collision['errors'][0]['code'] ?? null );
		self::assertSame( [ 'untouched' => true ], CB_T4_Resource_Binding_Fixture::$store['personal-copy-a'] );
	}

	public function test_t4b_revocation_or_missing_trusted_bundle_fails_closed(): void {
		[ $json ] = $this->fixture( 'certificate' );
		$preview = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context() );
		self::assertTrue( $preview['valid'] );
		CB_T4_Resource_Binding_Fixture::$allowed = false;
		$revoked = Engine::apply_json( $json, Foundation::MODE_CREATE_ONLY, $preview['fingerprint'], $this->context() );
		self::assertWPError( $revoked );
		self::assertSame( 'cb_core_data_exchange_forbidden', $revoked->get_error_code() );
		CB_T4_Resource_Binding_Fixture::$allowed = true;
		unset( CB_T4_Resource_Binding_Fixture::$verified['bundle-a'] );
		$missing = Engine::preview_json( $json, Foundation::MODE_CREATE_ONLY, $this->context() );
		self::assertWPError( $missing );
		self::assertSame( 'cb_core_data_exchange_forbidden', $missing->get_error_code() );
		self::assertSame( [], CB_T4_Resource_Binding_Fixture::$store );
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
			'label' => 'Resource package binding fixture',
			'description' => 'Test-only verified-package projection for data exchange.',
			'contract_owner' => Foundation::CONTRACT_OWNER,
			'contract' => Foundation::CONTRACT_ID,
			'contract_version' => Foundation::CONTRACT_VERSION,
			'supports' => [ Foundation::SUPPORT_IMPORT, Foundation::SUPPORT_JSON ],
			'factory' => static fn(): EntityInterface => new CB_T4_Resource_Binding_Fixture(),
		] );
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::PROVIDER;
		self::assertTrue( wp_mkdir_p( $directory ) );
		$source = "<?php\n/**\n * Plugin Name: Acme T4 Resource\n * Author: Acme Labs\n * Version: 1.0.0\n */\ndefined( 'ABSPATH' ) || exit;\n";
		self::assertNotFalse( file_put_contents( $directory . '/' . self::PROVIDER . '.php', $source ) );
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
