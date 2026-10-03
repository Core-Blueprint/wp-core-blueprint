<?php
declare(strict_types=1);

use CoreBlueprint\Core\Automation\ActionRegistry;
use CoreBlueprint\Core\Automation\Emitter;
use CoreBlueprint\Core\Automation\Schema;
use CoreBlueprint\Core\Automation\TriggerEvent;
use CoreBlueprint\Core\Automation\TriggerRegistry;
use CoreBlueprint\Core\ExtensionRegistry;

final class CB_Base_Automation_Foundation_Contract_Test extends WP_UnitTestCase {

	private const PROVIDER = 'acme-automation-fixture';
	private const PLUGIN_FILE = self::PROVIDER . '/' . self::PROVIDER . '.php';

	/** @var array<string,bool> */
	private array $registration_results = [];
	private int $capability_collection_count = 0;

	public function set_up(): void {
		parent::set_up();

		$this->remove_fixture();
		$this->create_fixture();
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		TriggerRegistry::_reset_for_testing();
		$this->registration_results = [];
		$this->capability_collection_count = 0;

		add_action( 'cb_core_register_extensions', [ $this, 'register_fixture_extension' ] );
		add_action( 'cb_core_register_automation_capabilities', [ $this, 'register_fixture_capabilities' ] );
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_fixture_extension' ] );
		remove_action( 'cb_core_register_automation_capabilities', [ $this, 'register_fixture_capabilities' ] );
		ExtensionRegistry::reset();
		TriggerRegistry::_reset_for_testing();
		$this->remove_fixture();
		wp_clean_plugins_cache( true );

		parent::tear_down();
	}

	public function test_af1_public_classes_and_facades_exist(): void {
		$contracts = [
			TriggerRegistry::class => [ 'register', 'all', 'get' ],
			ActionRegistry::class  => [ 'register', 'all', 'get' ],
			Emitter::class         => [ 'emit' ],
			Schema::class          => [ 'normalize', 'validate' ],
			TriggerEvent::class    => [ 'event_id', 'provider', 'trigger_id', 'schema_version', 'payload', 'occurred_at' ],
		];

		foreach ( $contracts as $class => $methods ) {
			self::assertTrue( class_exists( $class ), $class );
			foreach ( $methods as $method ) {
				self::assertTrue( method_exists( $class, $method ), $class . '::' . $method );
			}
		}
	}

	public function test_af1_collects_third_party_triggers_and_actions_once(): void {
		$triggers = TriggerRegistry::all();
		$actions = ActionRegistry::all();

		self::assertSame( 1, $this->capability_collection_count, 'Capability lifecycle fired more than once.' );
		self::assertTrue( $this->registration_results['trigger'] ?? false );
		self::assertTrue( $this->registration_results['action'] ?? false );
		self::assertFalse( $this->registration_results['duplicate_trigger'] ?? true );
		self::assertFalse( $this->registration_results['invalid_schema'] ?? true );
		self::assertFalse( $this->registration_results['reserved_provider'] ?? true );
		self::assertArrayHasKey( self::PROVIDER . '::contract.signed', $triggers );
		self::assertArrayHasKey( self::PROVIDER . '::work.project.create', $actions );
	}

	public function test_af1_public_registration_is_refused_outside_controlled_lifecycle(): void {
		self::assertFalse( TriggerRegistry::register( $this->trigger_definition() ) );
		self::assertFalse( ActionRegistry::register( $this->action_definition() ) );
	}

	public function test_af1_action_discovery_keeps_executor_private(): void {
		$action = ActionRegistry::get( self::PROVIDER, 'work.project.create' );

		self::assertIsArray( $action );
		self::assertSame( 'manage_options', $action['required_capability'] ?? null );
		self::assertArrayNotHasKey( 'executor', $action );
		self::assertSame( 'integer', $action['output_schema']['project_id']['type'] ?? null );
	}

	public function test_af1_base_owned_registration_has_separate_reserved_provider_path(): void {
		$result = TriggerRegistry::register_base( [
			'provider'       => 'must-be-ignored',
			'id'             => 'mail.delivered',
			'label'          => 'Mail delivered',
			'description'    => 'Base-owned fixture trigger.',
			'schema_version' => '1',
			'payload_schema' => [
				'message_id' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		self::assertTrue( $result );
		$definition = TriggerRegistry::get( 'core-blueprint', 'mail.delivered' );
		self::assertIsArray( $definition );
		self::assertSame( 'core-blueprint', $definition['provider'] ?? null );
	}

	public function test_af1_emits_valid_versioned_event_without_mutating_payload(): void {
		$received = null;
		$listener = static function ( TriggerEvent $event ) use ( &$received ): void {
			$received = $event;
		};
		add_action( 'cb_core_automation_trigger_emitted', $listener );

		$payload = [
			'contract_id'   => 42,
			'signed_at'     => '2026-09-11T00:00:00+00:00',
			'customer_email'=> 'customer@example.test',
			'tags'          => [ 'signed', 'service' ],
		];
		$result = Emitter::emit( self::PROVIDER, 'contract.signed', $payload, 'contract:42:signed:1' );

		remove_action( 'cb_core_automation_trigger_emitted', $listener );

		self::assertInstanceOf( TriggerEvent::class, $result );
		self::assertSame( $result, $received );
		self::assertSame( 'contract:42:signed:1', $result->event_id() );
		self::assertSame( self::PROVIDER, $result->provider() );
		self::assertSame( 'contract.signed', $result->trigger_id() );
		self::assertSame( '1', $result->schema_version() );
		self::assertSame( $payload, $result->payload() );
	}

	public function test_af1_emission_fails_closed_for_unknown_extra_or_object_payload_data(): void {
		$valid = [
			'contract_id' => 42,
			'signed_at'   => '2026-09-11T00:00:00+00:00',
			'tags'        => [],
		];

		$unknown = Emitter::emit( self::PROVIDER, 'contract.unknown', $valid );
		self::assertWPError( $unknown );
		self::assertSame( 'cb_core_automation_unknown_trigger', $unknown->get_error_code() );

		$extra = Emitter::emit( self::PROVIDER, 'contract.signed', $valid + [ 'undocumented' => 'value' ] );
		self::assertWPError( $extra );
		self::assertSame( 'cb_core_automation_invalid_payload', $extra->get_error_code() );

		$object = $valid;
		$object['tags'] = [ new stdClass() ];
		$object_result = Emitter::emit( self::PROVIDER, 'contract.signed', $object );
		self::assertWPError( $object_result );
		self::assertSame( 'cb_core_automation_invalid_payload', $object_result->get_error_code() );
	}

	public function test_af1_schema_rejects_nested_or_undeclared_transport_shapes(): void {
		self::assertNull( Schema::normalize( [
			'payload' => [ 'type' => 'object' ],
		] ) );
		self::assertNull( Schema::normalize( [
			'items' => [ 'type' => 'array', 'items' => 'array' ],
		] ) );
		self::assertNull( Schema::normalize( [
			'value' => [ 'type' => 'string', 'unknown' => true ],
		] ) );
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
		++$this->capability_collection_count;

		$this->registration_results['trigger'] = TriggerRegistry::register( $this->trigger_definition() );
		$this->registration_results['action'] = ActionRegistry::register( $this->action_definition() );
		$this->registration_results['duplicate_trigger'] = TriggerRegistry::register( $this->trigger_definition() );
		$this->registration_results['invalid_schema'] = TriggerRegistry::register( [
			'provider'       => self::PROVIDER,
			'id'             => 'contract.objectleak',
			'label'          => 'Object leak',
			'description'    => '',
			'schema_version' => '1',
			'payload_schema' => [
				'payload' => [ 'type' => 'object' ],
			],
		] );
		$this->registration_results['reserved_provider'] = TriggerRegistry::register( [
			'provider'       => 'core-blueprint',
			'id'             => 'mail.spoofed',
			'label'          => 'Spoofed Base trigger',
			'description'    => '',
			'schema_version' => '1',
			'payload_schema' => [],
		] );
	}

	/** @return array<string,mixed> */
	private function trigger_definition(): array {
		return [
			'provider'       => self::PROVIDER,
			'id'             => 'contract.signed',
			'label'          => 'Contract signed',
			'description'    => 'A contract reached its canonical signed state.',
			'schema_version' => '1',
			'payload_schema' => [
				'contract_id' => [ 'type' => 'integer', 'required' => true ],
				'signed_at' => [ 'type' => 'string', 'required' => true ],
				'customer_email' => [ 'type' => 'string', 'sensitive' => true ],
				'tags' => [ 'type' => 'array', 'items' => 'string' ],
			],
		];
	}

	/** @return array<string,mixed> */
	private function action_definition(): array {
		return [
			'provider'            => self::PROVIDER,
			'id'                  => 'work.project.create',
			'label'               => 'Create project',
			'description'         => 'Create a domain-owned project.',
			'schema_version'      => '1',
			'input_schema'        => [
				'project_name' => [ 'type' => 'string', 'required' => true ],
				'source_id'    => [ 'type' => 'integer' ],
			],
			'output_schema'       => [
				'project_id' => [ 'type' => 'integer', 'required' => true ],
			],
			'required_capability' => 'manage_options',
			'executor'            => static fn( array $input ): array => [ 'project_id' => (int) ( $input['source_id'] ?? 1 ) ],
		];
	}

	private function create_fixture(): void {
		$directory = WP_PLUGIN_DIR . '/' . self::PROVIDER;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create Automation Foundation fixture directory.' );

		$plugin = <<<'PHP'
<?php
/**
 * Plugin Name: Acme Automation Fixture
 * Author: Acme Labs
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
PHP;

		self::assertNotFalse(
			file_put_contents( $directory . '/' . self::PROVIDER . '.php', $plugin ),
			'Could not write Automation Foundation fixture plugin.'
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
