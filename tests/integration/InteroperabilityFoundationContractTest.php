<?php
declare(strict_types=1);

use CoreBlueprint\Core\ExtensionRegistry;
use CoreBlueprint\Core\Interoperability\Registry;

interface CB_Interop_Fixture_Contract {
	public function name(): string;
}

final class CB_Interop_Fixture_Implementation implements CB_Interop_Fixture_Contract {
	public function __construct( private readonly string $name ) {}

	public function name(): string {
		return $this->name;
	}
}

final class CB_Base_Interoperability_Foundation_Contract_Test extends WP_UnitTestCase {

	private const OWNER                = 'acme-contract-owner';
	private const OWNER_PLUGIN_FILE    = self::OWNER . '/' . self::OWNER . '.php';
	private const PROVIDER             = 'acme-contract-provider';
	private const PROVIDER_PLUGIN_FILE = self::PROVIDER . '/' . self::PROVIDER . '.php';
	private const CONTRACT             = 'resource.provider';
	private const VERSION              = '1';

	/** @var array<string,bool> */
	private array $results = [];
	private int $contract_collection_count = 0;
	private int $implementation_collection_count = 0;

	public function set_up(): void {
		parent::set_up();

		$this->remove_fixtures();
		$this->create_fixture( self::OWNER, 'Acme Contract Owner' );
		$this->create_fixture( self::PROVIDER, 'Acme Contract Provider' );
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		$this->results = [];
		$this->contract_collection_count = 0;
		$this->implementation_collection_count = 0;

		add_action( 'cb_core_register_extensions', [ $this, 'register_fixture_extensions' ] );
		add_action( 'cb_core_register_interoperability_contracts', [ $this, 'register_fixture_contracts' ] );
		add_action( 'cb_core_register_interoperability_implementations', [ $this, 'register_fixture_implementations' ] );
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_fixture_extensions' ] );
		remove_action( 'cb_core_register_interoperability_contracts', [ $this, 'register_fixture_contracts' ] );
		remove_action( 'cb_core_register_interoperability_implementations', [ $this, 'register_fixture_implementations' ] );
		remove_action( 'cb_core_register_interoperability_implementations', [ $this, 'register_late_implementation' ] );

		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		$this->remove_fixtures();
		wp_clean_plugins_cache( true );

		parent::tear_down();
	}

	public function test_if1_public_registry_contract_exists(): void {
		self::assertTrue( class_exists( Registry::class ) );
		foreach ( [ 'register_contract', 'register_implementation', 'contracts', 'implementations', 'contract', 'discover', 'implementation', 'resolve' ] as $method ) {
			self::assertTrue( method_exists( Registry::class, $method ), Registry::class . '::' . $method );
		}
		self::assertFalse( method_exists( Registry::class, 'register_base_contract' ) );
	}

	public function test_if1_collects_contracts_before_multiple_implementations_once(): void {
		$contracts = Registry::contracts();
		$implementations = Registry::implementations();

		self::assertSame( 1, $this->contract_collection_count, 'Contract lifecycle fired more than once.' );
		self::assertSame( 1, $this->implementation_collection_count, 'Implementation lifecycle fired more than once.' );
		self::assertTrue( $this->results['contract'] ?? false );
		self::assertTrue( $this->results['primary'] ?? false );
		self::assertTrue( $this->results['secondary'] ?? false );
		self::assertTrue( $this->results['invalid_runtime'] ?? false );
		self::assertFalse( $this->results['duplicate_contract'] ?? true );
		self::assertFalse( $this->results['unknown_contract'] ?? true );
		self::assertFalse( $this->results['unknown_provider'] ?? true );

		self::assertArrayHasKey( self::OWNER . '::' . self::CONTRACT . '@' . self::VERSION, $contracts );
		self::assertCount( 3, $implementations, 'Multiple implementations for one contract were not retained.' );
	}

	public function test_if1_registration_is_refused_outside_controlled_lifecycle(): void {
		self::assertFalse( Registry::register_contract( $this->contract_definition() ) );
		self::assertFalse( Registry::register_implementation( $this->implementation_definition( 'outside', [] ) ) );
	}

	public function test_if1_discovery_is_exact_versioned_and_support_aware(): void {
		$all = Registry::discover( self::OWNER, self::CONTRACT, self::VERSION );
		self::assertCount( 3, $all );

		$schema = Registry::discover( self::OWNER, self::CONTRACT, self::VERSION, [ 'schema.read' ] );
		self::assertCount( 2, $schema );
		foreach ( $schema as $descriptor ) {
			self::assertContains( 'schema.read', $descriptor['supports'] );
		}

		$writes = Registry::discover( self::OWNER, self::CONTRACT, self::VERSION, [ 'resource.write' ] );
		self::assertCount( 1, $writes );
		self::assertSame( 'primary', reset( $writes )['id'] ?? null );

		self::assertSame( [], Registry::discover( self::OWNER, self::CONTRACT, '2' ) );
		self::assertSame( [], Registry::discover( self::OWNER, self::CONTRACT, self::VERSION, [ 'missing.capability' ] ) );
	}

	public function test_if1_public_descriptors_never_leak_factories_or_runtime_objects(): void {
		$implementations = Registry::implementations();
		self::assertNotEmpty( $implementations );

		foreach ( $implementations as $descriptor ) {
			self::assertArrayNotHasKey( 'factory', $descriptor );
			$this->assert_transport_safe( $descriptor );
		}
	}

	public function test_if1_resolution_enforces_the_domain_owned_interface(): void {
		$resolved = Registry::resolve( self::OWNER, self::CONTRACT, self::VERSION, self::PROVIDER, 'primary' );
		self::assertInstanceOf( CB_Interop_Fixture_Contract::class, $resolved );
		self::assertSame( 'primary', $resolved->name() );

		$invalid = Registry::resolve( self::OWNER, self::CONTRACT, self::VERSION, self::PROVIDER, 'invalid-runtime' );
		self::assertWPError( $invalid );
		self::assertSame( 'cb_core_interop_contract_violation', $invalid->get_error_code() );

		$unknown = Registry::resolve( self::OWNER, self::CONTRACT, self::VERSION, self::PROVIDER, 'missing' );
		self::assertWPError( $unknown );
		self::assertSame( 'cb_core_interop_unknown_implementation', $unknown->get_error_code() );
	}

	public function test_if1_registry_freezes_after_canonical_collection(): void {
		self::assertCount( 3, Registry::implementations() );

		add_action( 'cb_core_register_interoperability_implementations', [ $this, 'register_late_implementation' ] );
		do_action( 'cb_core_register_interoperability_implementations' );

		self::assertFalse( $this->results['late'] ?? true, 'A late implementation bypassed the frozen registry.' );
		self::assertCount( 3, Registry::implementations(), 'Frozen registry mutated after canonical collection.' );
	}

	public function test_if1_foundation_source_remains_domain_and_builder_neutral(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Interoperability/Registry.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString( 'Bricks', $source );
		self::assertStringNotContainsString( 'Bricksforge', $source );
		self::assertStringNotContainsString( 'Forms', $source );
	}

	public function register_fixture_extensions(): void {
		ExtensionRegistry::register( [
			'id'            => self::OWNER,
			'plugin_file'   => self::OWNER_PLUGIN_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '1.0.0-rc1',
			'menu_url'      => '',
			'status_id'     => '',
		] );
		ExtensionRegistry::register( [
			'id'            => self::PROVIDER,
			'plugin_file'   => self::PROVIDER_PLUGIN_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '1.0.0-rc1',
			'menu_url'      => '',
			'status_id'     => '',
		] );
	}

	public function register_fixture_contracts(): void {
		++$this->contract_collection_count;
		$this->results['contract'] = Registry::register_contract( $this->contract_definition() );
		$this->results['duplicate_contract'] = Registry::register_contract( $this->contract_definition() );
	}

	public function register_fixture_implementations(): void {
		++$this->implementation_collection_count;
		$this->results['primary'] = Registry::register_implementation(
			$this->implementation_definition( 'primary', [ 'resource.write', 'schema.read' ] )
		);
		$this->results['secondary'] = Registry::register_implementation(
			$this->implementation_definition( 'secondary', [ 'schema.read' ] )
		);
		$this->results['invalid_runtime'] = Registry::register_implementation(
			$this->implementation_definition( 'invalid-runtime', [], static fn(): object => new stdClass() )
		);

		$unknown_contract = $this->implementation_definition( 'unknown-contract', [] );
		$unknown_contract['contract'] = 'missing.contract';
		$this->results['unknown_contract'] = Registry::register_implementation( $unknown_contract );

		$unknown_provider = $this->implementation_definition( 'unknown-provider', [] );
		$unknown_provider['provider'] = 'unknown-extension-provider';
		$this->results['unknown_provider'] = Registry::register_implementation( $unknown_provider );
	}

	public function register_late_implementation(): void {
		$this->results['late'] = Registry::register_implementation( $this->implementation_definition( 'late', [] ) );
	}

	/** @return array<string,mixed> */
	private function contract_definition(): array {
		return [
			'owner'       => self::OWNER,
			'id'          => self::CONTRACT,
			'version'     => self::VERSION,
			'label'       => 'Resource provider',
			'description' => 'Neutral fixture contract owned by a domain extension.',
			'interface'   => CB_Interop_Fixture_Contract::class,
		];
	}

	/** @param list<string> $supports @return array<string,mixed> */
	private function implementation_definition( string $id, array $supports, ?callable $factory = null ): array {
		return [
			'provider'         => self::PROVIDER,
			'id'               => $id,
			'label'            => ucfirst( str_replace( '-', ' ', $id ) ),
			'description'      => 'Neutral fixture implementation.',
			'contract_owner'   => self::OWNER,
			'contract'         => self::CONTRACT,
			'contract_version' => self::VERSION,
			'supports'         => $supports,
			'factory'          => $factory ?? static fn(): CB_Interop_Fixture_Contract => new CB_Interop_Fixture_Implementation( $id ),
		];
	}

	/** @param array<mixed> $value */
	private function assert_transport_safe( array $value ): void {
		array_walk_recursive(
			$value,
			static function ( mixed $item ): void {
				self::assertFalse( is_object( $item ), 'Public interoperability descriptor leaked an object.' );
				self::assertFalse( is_resource( $item ), 'Public interoperability descriptor leaked a resource.' );
			}
		);
	}

	private function create_fixture( string $id, string $name ): void {
		$directory = WP_PLUGIN_DIR . '/' . $id;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create interoperability fixture directory.' );
		$plugin = "<?php\n/**\n * Plugin Name: {$name}\n * Author: Acme Labs\n * Version: 1.0.0\n */\ndefined( 'ABSPATH' ) || exit;\n";
		self::assertNotFalse(
			file_put_contents( $directory . '/' . $id . '.php', $plugin ),
			'Could not write interoperability fixture plugin.'
		);
	}

	private function remove_fixtures(): void {
		foreach ( [ self::OWNER_PLUGIN_FILE, self::PROVIDER_PLUGIN_FILE ] as $plugin_file ) {
			$file = WP_PLUGIN_DIR . '/' . $plugin_file;
			$directory = dirname( $file );
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
	}
}
