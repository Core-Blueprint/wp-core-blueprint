<?php
declare(strict_types=1);

use CoreBlueprint\Core\ExtensionRegistry;
use CoreBlueprint\Core\Forms\Foundation;
use CoreBlueprint\Core\Forms\ProviderInterface;
use CoreBlueprint\Core\Forms\SubmissionEmitter;
use CoreBlueprint\Core\Forms\SubmissionEvent;
use CoreBlueprint\Core\Interoperability\Registry;

final class CB_Forms_Fixture_Provider implements ProviderInterface {
	public function __construct( private readonly bool $available = true ) {}

	public function is_available(): bool {
		return $this->available;
	}
}

final class CB_Base_Forms_Foundation_Contract_Test extends WP_UnitTestCase {

	private const PROVIDER_A = 'acme-forms-provider';
	private const PROVIDER_B = 'beta-forms-provider';
	private const PROVIDER_A_FILE = self::PROVIDER_A . '/' . self::PROVIDER_A . '.php';
	private const PROVIDER_B_FILE = self::PROVIDER_B . '/' . self::PROVIDER_B . '.php';

	/** @var array<string,bool> */
	private array $results = [];

	/** @var list<SubmissionEvent> */
	private array $events = [];

	public function set_up(): void {
		parent::set_up();

		$this->remove_fixtures();
		$this->create_fixture( self::PROVIDER_A, 'Acme Forms Provider' );
		$this->create_fixture( self::PROVIDER_B, 'Beta Forms Provider' );
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		$this->results = [];
		$this->events  = [];

		add_action( 'core_blueprint_register_extensions', [ $this, 'register_fixture_extensions' ] );
		add_action( 'core_blueprint_register_interoperability_contracts', [ $this, 'attempt_base_owner_spoof' ], 20 );
		add_action( 'core_blueprint_register_interoperability_implementations', [ $this, 'register_fixture_implementations' ] );
		add_action( 'core_blueprint_forms_submission_emitted', [ $this, 'capture_event' ] );
	}

	public function tear_down(): void {
		remove_action( 'core_blueprint_register_extensions', [ $this, 'register_fixture_extensions' ] );
		remove_action( 'core_blueprint_register_interoperability_contracts', [ $this, 'attempt_base_owner_spoof' ], 20 );
		remove_action( 'core_blueprint_register_interoperability_implementations', [ $this, 'register_fixture_implementations' ] );
		remove_action( 'core_blueprint_forms_submission_emitted', [ $this, 'capture_event' ] );

		ExtensionRegistry::reset();
		Registry::_reset_for_testing();
		$this->remove_fixtures();
		wp_clean_plugins_cache( true );

		parent::tear_down();
	}

	public function test_ff1_base_owns_the_forms_contract_without_exposing_owner_spoofing(): void {
		$contract = Registry::contract(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION
		);

		self::assertIsArray( $contract );
		self::assertSame( 'core-blueprint', $contract['owner'] ?? null );
		self::assertSame( ProviderInterface::class, $contract['interface'] ?? null );
		self::assertFalse( $this->results['base_owner_spoof'] ?? true );
		self::assertFalse( method_exists( Registry::class, 'register_base_contract' ) );
	}

	public function test_ff1_multiple_extensions_can_implement_the_same_forms_contract(): void {
		$matches = Registry::discover(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			[ Foundation::SUPPORT_SUBMISSION_EMIT ]
		);

		self::assertCount( 3, $matches );
		$providers = array_values( array_unique( array_column( $matches, 'provider' ) ) );
		sort( $providers );
		self::assertSame( [ self::PROVIDER_A, self::PROVIDER_B ], $providers );

		$unsupported = Registry::implementation(
			Foundation::CONTRACT_OWNER,
			Foundation::CONTRACT_ID,
			Foundation::CONTRACT_VERSION,
			self::PROVIDER_A,
			'no-submit'
		);
		self::assertIsArray( $unsupported );
		self::assertSame( [], $unsupported['supports'] ?? null );
	}

	public function test_ff1_submission_ingress_preserves_valid_values_and_emits_once(): void {
		$fields = [
			[ 'id' => 'name', 'value' => 'Ada Lovelace' ],
			[ 'id' => 'message', 'value' => '<strong>Keep exact submitted text.</strong>' ],
			[ 'id' => 'consent', 'value' => true ],
			[ 'id' => 'score', 'value' => 4.5 ],
			[ 'id' => 'topics', 'value' => [ 'support', 'billing' ] ],
			[ 'id' => 'optional', 'value' => null ],
		];

		$result = SubmissionEmitter::emit(
			self::PROVIDER_A,
			'default',
			'contact-main',
			$fields,
			'submission-42',
			'forms:test:42'
		);

		self::assertInstanceOf( SubmissionEvent::class, $result );
		self::assertSame( 'forms:test:42', $result->event_id() );
		self::assertSame( self::PROVIDER_A, $result->provider() );
		self::assertSame( 'default', $result->implementation() );
		self::assertSame( 'contact-main', $result->form_id() );
		self::assertSame( 'submission-42', $result->submission_id() );
		self::assertSame( $fields, $result->fields() );
		self::assertNotSame( '', $result->occurred_at() );

		self::assertCount( 1, $this->events );
		self::assertSame( $result, $this->events[0] );
	}

	public function test_ff1_submission_ingress_fails_closed_for_provider_contract_failures(): void {
		$fields = [ [ 'id' => 'message', 'value' => 'Hello' ] ];

		$unknown = SubmissionEmitter::emit( 'missing-provider', 'default', 'contact', $fields );
		self::assertWPError( $unknown );
		self::assertSame( 'cb_core_forms_unknown_provider', $unknown->get_error_code() );

		$unsupported = SubmissionEmitter::emit( self::PROVIDER_A, 'no-submit', 'contact', $fields );
		self::assertWPError( $unsupported );
		self::assertSame( 'cb_core_forms_unsupported', $unsupported->get_error_code() );

		$unavailable = SubmissionEmitter::emit( self::PROVIDER_A, 'unavailable', 'contact', $fields );
		self::assertWPError( $unavailable );
		self::assertSame( 'cb_core_forms_provider_unavailable', $unavailable->get_error_code() );

		self::assertSame( [], $this->events );
	}

	public function test_ff1_submission_transport_is_bounded_and_rejects_nested_or_ambiguous_values(): void {
		$duplicate = SubmissionEmitter::emit(
			self::PROVIDER_A,
			'default',
			'contact',
			[
				[ 'id' => 'message', 'value' => 'One' ],
				[ 'id' => 'message', 'value' => 'Two' ],
			]
		);
		self::assertWPError( $duplicate );
		self::assertSame( 'cb_core_forms_invalid_fields', $duplicate->get_error_code() );

		$nested = SubmissionEmitter::emit(
			self::PROVIDER_A,
			'default',
			'contact',
			[ [ 'id' => 'nested', 'value' => [ [ 'secret' => 'value' ] ] ] ]
		);
		self::assertWPError( $nested );
		self::assertSame( 'cb_core_forms_invalid_fields', $nested->get_error_code() );

		$object = SubmissionEmitter::emit(
			self::PROVIDER_A,
			'default',
			'contact',
			[ [ 'id' => 'object', 'value' => new stdClass() ] ]
		);
		self::assertWPError( $object );
		self::assertSame( 'cb_core_forms_invalid_fields', $object->get_error_code() );

		$oversized = SubmissionEmitter::emit(
			self::PROVIDER_A,
			'default',
			'contact',
			[ [ 'id' => 'message', 'value' => str_repeat( 'x', 65536 ) ] ]
		);
		self::assertWPError( $oversized );
		self::assertSame( 'cb_core_forms_invalid_fields', $oversized->get_error_code() );

		$unknown_shape = SubmissionEmitter::emit(
			self::PROVIDER_A,
			'default',
			'contact',
			[ [ 'id' => 'message', 'value' => 'Hello', 'label' => 'Message' ] ]
		);
		self::assertWPError( $unknown_shape );
		self::assertSame( 'cb_core_forms_invalid_fields', $unknown_shape->get_error_code() );

		self::assertSame( [], $this->events );
	}

	public function test_ff1_explicit_empty_optional_ids_and_documented_transport_limits_fail_closed(): void {
		$fields = [ [ 'id' => 'message', 'value' => 'Hello' ] ];

		$empty_submission_id = SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', $fields, '' );
		self::assertWPError( $empty_submission_id );
		self::assertSame( 'cb_core_forms_invalid_submission_id', $empty_submission_id->get_error_code() );

		$empty_event_id = SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', $fields, null, '' );
		self::assertWPError( $empty_event_id );
		self::assertSame( 'cb_core_forms_invalid_event_id', $empty_event_id->get_error_code() );

		$max_fields = [];
		for ( $i = 0; $i < 256; ++$i ) {
			$max_fields[] = [ 'id' => 'field-' . $i, 'value' => '' ];
		}
		self::assertInstanceOf( SubmissionEvent::class, SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', $max_fields ) );
		$too_many_fields = $max_fields;
		$too_many_fields[] = [ 'id' => 'field-256', 'value' => '' ];
		self::assertWPError( SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', $too_many_fields ) );

		self::assertInstanceOf(
			SubmissionEvent::class,
			SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', [ [ 'id' => 'list', 'value' => array_fill( 0, 100, 'x' ) ] ] )
		);
		self::assertWPError(
			SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', [ [ 'id' => 'list', 'value' => array_fill( 0, 101, 'x' ) ] ] )
		);

		self::assertInstanceOf(
			SubmissionEvent::class,
			SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', [ [ 'id' => 'message', 'value' => str_repeat( 'x', 65535 ) ] ] )
		);
		self::assertWPError(
			SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', [ [ 'id' => 'message', 'value' => str_repeat( 'x', 65536 ) ] ] )
		);

		$exact_budget = [];
		for ( $i = 0; $i < 16; ++$i ) {
			$exact_budget[] = [ 'id' => 'chunk-' . $i, 'value' => str_repeat( 'x', 65535 ) ];
		}
		$exact_budget[] = [ 'id' => 'tail', 'value' => str_repeat( 'x', 16 ) ];
		self::assertInstanceOf( SubmissionEvent::class, SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', $exact_budget ) );
		$over_budget = $exact_budget;
		$over_budget[16]['value'] = str_repeat( 'x', 17 );
		self::assertWPError( SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', $over_budget ) );

		foreach ( [ INF, NAN ] as $non_finite ) {
			self::assertWPError(
				SubmissionEmitter::emit( self::PROVIDER_A, 'default', 'contact', [ [ 'id' => 'score', 'value' => $non_finite ] ] )
			);
		}
	}

	public function test_ff1_foundation_is_builder_neutral_and_does_not_persist_or_copy_raw_submission_data(): void {
		$source = '';
		foreach ( [
			'/src/Forms/Foundation.php',
			'/src/Forms/ProviderInterface.php',
			'/src/Forms/SubmissionEvent.php',
			'/src/Forms/SubmissionEmitter.php',
		] as $path ) {
			$content = file_get_contents( dirname( __DIR__, 2 ) . $path );
			self::assertIsString( $content );
			$source .= $content;
		}

		foreach ( [ 'Bricks', 'Bricksforge', 'Gravity Forms', 'Fluent Forms' ] as $builder_name ) {
			self::assertStringNotContainsString( $builder_name, $source );
		}

		foreach ( [ '$wpdb', 'update_option(', 'add_option(', 'wp_insert_post(', 'Audit::', 'Automation\\Emitter' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}

	public function register_fixture_extensions(): void {
		foreach ( [
			[ self::PROVIDER_A, self::PROVIDER_A_FILE ],
			[ self::PROVIDER_B, self::PROVIDER_B_FILE ],
		] as [ $id, $plugin_file ] ) {
			ExtensionRegistry::register( [
				'id'            => $id,
				'plugin_file'   => $plugin_file,
				'requires_api'  => '1.0',
				'requires_base' => '1.0.0-rc1',
				'menu_url'      => '',
				'status_id'     => '',
			] );
		}
	}

	public function attempt_base_owner_spoof(): void {
		$this->results['base_owner_spoof'] = Registry::register_contract( [
			'owner'       => Foundation::CONTRACT_OWNER,
			'id'          => 'forms.hijack',
			'version'     => '1',
			'label'       => 'Hijacked Forms contract',
			'description' => 'A public extension path must never claim Base ownership.',
			'interface'   => ProviderInterface::class,
		] );
	}

	public function register_fixture_implementations(): void {
		$this->results['provider_a_default'] = Registry::register_implementation(
			$this->implementation_definition( self::PROVIDER_A, 'default', true, [ Foundation::SUPPORT_SUBMISSION_EMIT ] )
		);
		$this->results['provider_a_no_submit'] = Registry::register_implementation(
			$this->implementation_definition( self::PROVIDER_A, 'no-submit', true, [] )
		);
		$this->results['provider_a_unavailable'] = Registry::register_implementation(
			$this->implementation_definition( self::PROVIDER_A, 'unavailable', false, [ Foundation::SUPPORT_SUBMISSION_EMIT ] )
		);
		$this->results['provider_b_default'] = Registry::register_implementation(
			$this->implementation_definition( self::PROVIDER_B, 'default', true, [ Foundation::SUPPORT_SUBMISSION_EMIT ] )
		);
	}

	public function capture_event( SubmissionEvent $event ): void {
		$this->events[] = $event;
	}

	/** @param list<string> $supports @return array<string,mixed> */
	private function implementation_definition( string $provider, string $id, bool $available, array $supports ): array {
		return [
			'provider'         => $provider,
			'id'               => $id,
			'label'            => ucfirst( str_replace( '-', ' ', $id ) ),
			'description'      => 'Forms Foundation fixture provider.',
			'contract_owner'   => Foundation::CONTRACT_OWNER,
			'contract'         => Foundation::CONTRACT_ID,
			'contract_version' => Foundation::CONTRACT_VERSION,
			'supports'         => $supports,
			'factory'          => static fn(): ProviderInterface => new CB_Forms_Fixture_Provider( $available ),
		];
	}

	private function create_fixture( string $id, string $name ): void {
		$directory = WP_PLUGIN_DIR . '/' . $id;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create Forms Foundation fixture directory.' );
		$plugin = "<?php\n/**\n * Plugin Name: {$name}\n * Author: Acme Labs\n * Version: 1.0.0\n */\ndefined( 'ABSPATH' ) || exit;\n";
		self::assertNotFalse(
			file_put_contents( $directory . '/' . $id . '.php', $plugin ),
			'Could not write Forms Foundation fixture plugin.'
		);
	}

	private function remove_fixtures(): void {
		foreach ( [ self::PROVIDER_A_FILE, self::PROVIDER_B_FILE ] as $plugin_file ) {
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
