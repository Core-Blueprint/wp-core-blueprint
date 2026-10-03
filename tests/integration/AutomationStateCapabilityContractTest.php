<?php
declare(strict_types=1);

use CoreBlueprint\Core\Automation\StateRegistry;
use CoreBlueprint\Core\Automation\TriggerRegistry;
use CoreBlueprint\Core\ExtensionRegistry;

final class CB_Base_Automation_State_Capability_Contract_Test extends WP_UnitTestCase {

	private const PROVIDER = 'acme-state-fixture';
	private const PLUGIN_FILE = self::PROVIDER . '/' . self::PROVIDER . '.php';

	/** @var array<string,bool> */
	private array $registration_results = [];
	private int $collection_count = 0;

	public function set_up(): void {
		parent::set_up();

		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		StateRegistry::_reset_for_testing();
		$this->registration_results = [];
		$this->collection_count = 0;

		add_action( 'cb_core_register_extensions', [ $this, 'register_fixture_extension' ] );
		add_action( 'cb_core_register_automation_capabilities', [ $this, 'register_fixture_capabilities' ] );
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_fixture_extension' ] );
		remove_action( 'cb_core_register_automation_capabilities', [ $this, 'register_fixture_capabilities' ] );
		ExtensionRegistry::reset();
		StateRegistry::_reset_for_testing();
		$this->remove_fixture();
		wp_clean_plugins_cache( true );

		parent::tear_down();
	}

	public function test_af2_public_state_facade_is_discovery_only(): void {
		self::assertTrue( class_exists( StateRegistry::class ) );
		self::assertTrue( method_exists( StateRegistry::class, 'register' ) );
		self::assertTrue( method_exists( StateRegistry::class, 'all' ) );
		self::assertTrue( method_exists( StateRegistry::class, 'get' ) );
		self::assertFalse( method_exists( StateRegistry::class, 'resolve' ) );
		self::assertFalse( method_exists( StateRegistry::class, 'resolver' ) );
	}

	public function test_af2_state_discovery_uses_shared_single_collection_lifecycle(): void {
		$states = StateRegistry::all();
		TriggerRegistry::all();

		self::assertSame( 1, $this->collection_count, 'Automation capability lifecycle fired more than once.' );
		self::assertTrue( $this->registration_results['state'] ?? false );
		self::assertFalse( $this->registration_results['duplicate_state'] ?? true );
		self::assertFalse( $this->registration_results['empty_output'] ?? true );
		self::assertFalse( $this->registration_results['object_output'] ?? true );
		self::assertFalse( $this->registration_results['invalid_resolver'] ?? true );
		self::assertFalse( $this->registration_results['reserved_provider'] ?? true );
		self::assertArrayHasKey( self::PROVIDER . '::invoice.current', $states );
	}

	public function test_af2_state_discovery_keeps_resolver_private_and_schema_visible(): void {
		$state = StateRegistry::get( self::PROVIDER, 'invoice.current' );

		self::assertIsArray( $state );
		self::assertArrayNotHasKey( 'resolver', $state );
		self::assertSame( 'read', $state['required_capability'] ?? null );
		self::assertSame( 'integer', $state['input_schema']['invoice_id']['type'] ?? null );
		self::assertSame( 'string', $state['output_schema']['status']['type'] ?? null );
		self::assertSame( 'number', $state['output_schema']['balance']['type'] ?? null );
		self::assertTrue( $state['output_schema']['customer_email']['sensitive'] ?? false );
	}

	public function test_af2_public_state_registration_is_refused_outside_controlled_lifecycle(): void {
		self::assertFalse( StateRegistry::register( $this->state_definition() ) );
	}

	public function test_af2_base_owned_state_uses_reserved_provider_path(): void {
		$result = StateRegistry::register_base( [
			'provider'            => 'must-be-ignored',
			'id'                  => 'site.current',
			'label'               => 'Current site state',
			'description'         => 'Base-owned state fixture.',
			'schema_version'      => '1',
			'input_schema'        => [],
			'output_schema'       => [
				'site_id' => [ 'type' => 'integer', 'required' => true ],
			],
			'required_capability' => 'read',
			'resolver'            => static fn (): array => [ 'site_id' => 1 ],
		] );

		self::assertTrue( $result );
		$definition = StateRegistry::get( 'core-blueprint', 'site.current' );
		self::assertIsArray( $definition );
		self::assertSame( 'core-blueprint', $definition['provider'] ?? null );
		self::assertArrayNotHasKey( 'resolver', $definition );
	}

	public function register_fixture_extension(): void {
		ExtensionRegistry::register( [
			'id'            => self::PROVIDER,
			'plugin_file'   => self::PLUGIN_FILE,
			'requires_api'  => '1.0',
			'requires_base' => '1.0.0-rc1',
			'menu_url'      => '',
			'status_id'     => '',
		] );
	}

	public function register_fixture_capabilities(): void {
		++$this->collection_count;
		$this->registration_results['state'] = StateRegistry::register( $this->state_definition() );
		$this->registration_results['duplicate_state'] = StateRegistry::register( $this->state_definition() );
		$this->registration_results['empty_output'] = StateRegistry::register( [
			'provider'            => self::PROVIDER,
			'id'                  => 'invoice.empty',
			'label'               => 'Empty state',
			'description'         => '',
			'schema_version'      => '1',
			'input_schema'        => [],
			'output_schema'       => [],
			'required_capability' => 'read',
			'resolver'            => static fn (): array => [],
		] );
		$this->registration_results['object_output'] = StateRegistry::register( [
			'provider'            => self::PROVIDER,
			'id'                  => 'invoice.object',
			'label'               => 'Object state',
			'description'         => '',
			'schema_version'      => '1',
			'input_schema'        => [],
			'output_schema'       => [ 'invoice' => [ 'type' => 'object' ] ],
			'required_capability' => 'read',
			'resolver'            => static fn (): array => [],
		] );
		$this->registration_results['invalid_resolver'] = StateRegistry::register( [
			'provider'            => self::PROVIDER,
			'id'                  => 'invoice.noresolver',
			'label'               => 'Missing resolver',
			'description'         => '',
			'schema_version'      => '1',
			'input_schema'        => [],
			'output_schema'       => [ 'status' => [ 'type' => 'string' ] ],
			'required_capability' => 'read',
			'resolver'            => 'definitely_not_a_callable',
		] );
		$this->registration_results['reserved_provider'] = StateRegistry::register( [
			'provider'            => 'core-blueprint',
			'id'                  => 'site.spoofed',
			'label'               => 'Spoofed Base state',
			'description'         => '',
			'schema_version'      => '1',
			'input_schema'        => [],
			'output_schema'       => [ 'value' => [ 'type' => 'string' ] ],
			'required_capability' => 'read',
			'resolver'            => static fn (): array => [ 'value' => 'spoofed' ],
		] );
	}

	/** @return array<string,mixed> */
	private function state_definition(): array {
		return [
			'provider'            => self::PROVIDER,
			'id'                  => 'invoice.current',
			'label'               => 'Current invoice state',
			'description'         => 'Read current invoice facts without copying domain ownership.',
			'schema_version'      => '1',
			'input_schema'        => [
				'invoice_id' => [ 'type' => 'integer', 'required' => true ],
			],
			'output_schema'       => [
				'status'         => [ 'type' => 'string', 'required' => true ],
				'balance'        => [ 'type' => 'number', 'required' => true ],
				'customer_email' => [ 'type' => 'string', 'sensitive' => true ],
			],
			'required_capability' => 'read',
			'resolver'            => static fn ( array $input ): array => [
				'status'         => 'overdue',
				'balance'        => 125.50,
				'customer_email' => 'customer@example.test',
			],
		];
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::PROVIDER;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create Automation State fixture directory.' );

		$plugin = <<<'PHP'
<?php
/**
 * Plugin Name: Acme State Fixture
 * Author: Acme Labs
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
PHP;

		self::assertNotFalse(
			file_put_contents( $directory . '/' . self::PROVIDER . '.php', $plugin ),
			'Could not write Automation State fixture plugin.'
		);
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
