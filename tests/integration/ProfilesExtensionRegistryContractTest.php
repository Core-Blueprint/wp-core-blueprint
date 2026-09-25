<?php
declare(strict_types=1);

use CB\Core\ExtensionRegistry;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\ExactSection;
use CB\Core\Profiles\SchemaGuard;
use CB\Core\Profiles\SectionRegistry;

final class CB_Profiles_Test_Extension_Section extends ExactSection {
	public function __construct(
		private readonly string $section_id,
		private readonly string $option_name,
		private readonly int $current_schema_version = 1
	) {}

	public function id(): string { return $this->section_id; }
	public function label(): string { return 'Extension fixture'; }
	public function description(): string { return 'First-party Profile extension fixture.'; }
	public function schema_version(): int { return $this->current_schema_version; }

	public function export(): array {
		return [ 'enabled' => (bool) get_option( $this->option_name, false ) ];
	}

	public function normalize( array $incoming ): array {
		SchemaGuard::exact_keys( $incoming, [ 'enabled' ], 'extension fixture' );
		return [ 'enabled' => SchemaGuard::bool( $incoming['enabled'] ?? null, 'extension fixture state' ) ];
	}

	public function apply( array $incoming, string $actor ): void {
		unset( $actor );
		$incoming = $this->normalize( $incoming );
		update_option( $this->option_name, $incoming['enabled'], false );
	}
}

final class CB_Base_Profiles_Extension_Registry_Contract_Test extends WP_UnitTestCase {
	private const FIRST_PARTY = 'core-blueprint-profile-fixture';
	private const FIRST_PARTY_FILE = self::FIRST_PARTY . '/' . self::FIRST_PARTY . '.php';
	private const THIRD_PARTY = 'acme-profile-fixture';
	private const THIRD_PARTY_FILE = self::THIRD_PARTY . '/' . self::THIRD_PARTY . '.php';

	/** @var array<string,bool> */
	private array $extension_results = [];

	/** @var array<string,bool> */
	private array $profile_results = [];

	public function set_up(): void {
		parent::set_up();

		$this->remove_fixtures();
		$this->create_fixture( self::FIRST_PARTY, 'Core Blueprint Profile Fixture', 'Core Blueprint' );
		$this->create_fixture( self::THIRD_PARTY, 'Acme Profile Fixture', 'Acme Labs' );
		wp_clean_plugins_cache( true );

		ExtensionRegistry::reset();
		SectionRegistry::_reset_for_testing();
		$this->extension_results = [];
		$this->profile_results = [];

		add_action( 'cb_core_register_extensions', [ $this, 'register_extensions' ] );
		add_action( 'cb_core_register_profile_sections', [ $this, 'register_profile_sections' ] );

		delete_option( 'cb_profiles_extension_alpha' );
		delete_option( 'cb_profiles_extension_zeta' );
		delete_option( 'cb_profiles_extension_third' );
		delete_option( 'cb_profiles_extension_unowned' );
		delete_option( 'cb_profiles_extension_invalid_schema' );
		delete_option( 'cb_profiles_extension_late' );
	}

	public function tear_down(): void {
		remove_action( 'cb_core_register_extensions', [ $this, 'register_extensions' ] );
		remove_action( 'cb_core_register_profile_sections', [ $this, 'register_profile_sections' ] );
		remove_action( 'cb_core_register_profile_sections', [ $this, 'register_late_profile_section' ] );

		ExtensionRegistry::reset();
		SectionRegistry::_reset_for_testing();

		foreach ( [
			'cb_profiles_extension_alpha',
			'cb_profiles_extension_zeta',
			'cb_profiles_extension_third',
			'cb_profiles_extension_unowned',
			'cb_profiles_extension_invalid_schema',
			'cb_profiles_extension_late',
		] as $option ) {
			delete_option( $option );
		}

		$this->remove_fixtures();
		wp_clean_plugins_cache( true );

		parent::tear_down();
	}

	public function test_per1_registration_is_controlled_first_party_and_namespaced(): void {
		$outside = new CB_Profiles_Test_Extension_Section(
			self::FIRST_PARTY . '-outside',
			'cb_profiles_extension_alpha'
		);
		self::assertFalse( SectionRegistry::register( self::FIRST_PARTY, $outside ) );

		SectionRegistry::collect();

		self::assertTrue( $this->extension_results['first_party'] ?? false );
		self::assertTrue( $this->extension_results['third_party'] ?? false );
		self::assertTrue( $this->profile_results['alpha'] ?? false );
		self::assertTrue( $this->profile_results['zeta'] ?? false );
		self::assertFalse( $this->profile_results['third_party'] ?? true );
		self::assertFalse( $this->profile_results['unowned'] ?? true );
		self::assertFalse( $this->profile_results['invalid_schema'] ?? true );

		$ids = array_keys( SectionRegistry::all() );
		self::assertContains( self::FIRST_PARTY . '-alpha', $ids );
		self::assertContains( self::FIRST_PARTY . '-zeta', $ids );
		self::assertNotContains( self::THIRD_PARTY . '-policy', $ids );
		self::assertNotContains( 'unowned-policy', $ids );
		self::assertLessThan(
			array_search( self::FIRST_PARTY . '-zeta', $ids, true ),
			array_search( self::FIRST_PARTY . '-alpha', $ids, true )
		);
		self::assertSame( 'module-states', end( $ids ) );
	}

	public function test_per2_extension_section_uses_the_normal_profile_transaction_contract(): void {
		SectionRegistry::collect();
		update_option( 'cb_profiles_extension_alpha', false, false );

		$id = self::FIRST_PARTY . '-alpha';
		$document = Engine::export_document( 'Extension policy', '', [ $id ] );
		$document['sections'][ $id ]['data']['enabled'] = true;
		$preview = Engine::preview( $document );

		self::assertSame( 1, $preview['total_changes'] );
		$result = Engine::apply( $document, $preview['fingerprint'], 'test-extension' );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( [ $id ], $result['sections'] );
		self::assertTrue( (bool) get_option( 'cb_profiles_extension_alpha', false ) );
	}

	public function test_per3_missing_extension_section_fails_closed_before_mutation(): void {
		SectionRegistry::collect();
		$id = self::FIRST_PARTY . '-alpha';
		$document = Engine::export_document( 'Missing extension', '', [ $id ] );

		remove_action( 'cb_core_register_profile_sections', [ $this, 'register_profile_sections' ] );
		SectionRegistry::_reset_for_testing();

		try {
			Engine::preview( $document );
			self::fail( 'A Profile section from an unavailable extension was accepted.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertStringContainsString( 'not available', $error->getMessage() );
		}

		self::assertFalse( (bool) get_option( 'cb_profiles_extension_alpha', false ) );
	}

	public function test_per4_registry_freezes_after_canonical_collection(): void {
		SectionRegistry::collect();

		add_action( 'cb_core_register_profile_sections', [ $this, 'register_late_profile_section' ] );
		do_action( 'cb_core_register_profile_sections' );

		self::assertFalse( $this->profile_results['late'] ?? true );
		self::assertNull( SectionRegistry::get( self::FIRST_PARTY . '-late' ) );
	}

	public function register_extensions(): void {
		$this->extension_results['first_party'] = ExtensionRegistry::register( [
			'id'            => self::FIRST_PARTY,
			'plugin_file'   => self::FIRST_PARTY_FILE,
			'requires_api'  => '1.1',
			'requires_base' => '1.0.0-rc1',
			'menu_url'      => '',
			'status_id'     => '',
		] );

		$this->extension_results['third_party'] = ExtensionRegistry::register( [
			'id'            => self::THIRD_PARTY,
			'plugin_file'   => self::THIRD_PARTY_FILE,
			'requires_api'  => '1.1',
			'requires_base' => '1.0.0-rc1',
			'menu_url'      => '',
			'status_id'     => '',
		] );
	}

	public function register_profile_sections(): void {
		// Register in reverse lexical order to prove deterministic sorting.
		$this->profile_results['zeta'] = SectionRegistry::register(
			self::FIRST_PARTY,
			new CB_Profiles_Test_Extension_Section(
				self::FIRST_PARTY . '-zeta',
				'cb_profiles_extension_zeta'
			)
		);
		$this->profile_results['alpha'] = SectionRegistry::register(
			self::FIRST_PARTY,
			new CB_Profiles_Test_Extension_Section(
				self::FIRST_PARTY . '-alpha',
				'cb_profiles_extension_alpha'
			)
		);
		$this->profile_results['third_party'] = SectionRegistry::register(
			self::THIRD_PARTY,
			new CB_Profiles_Test_Extension_Section(
				self::THIRD_PARTY . '-policy',
				'cb_profiles_extension_third'
			)
		);
		$this->profile_results['unowned'] = SectionRegistry::register(
			self::FIRST_PARTY,
			new CB_Profiles_Test_Extension_Section(
				'unowned-policy',
				'cb_profiles_extension_unowned'
			)
		);
		$this->profile_results['invalid_schema'] = SectionRegistry::register(
			self::FIRST_PARTY,
			new CB_Profiles_Test_Extension_Section(
				self::FIRST_PARTY . '-invalid-schema',
				'cb_profiles_extension_invalid_schema',
				0
			)
		);
	}

	public function register_late_profile_section(): void {
		$this->profile_results['late'] = SectionRegistry::register(
			self::FIRST_PARTY,
			new CB_Profiles_Test_Extension_Section(
				self::FIRST_PARTY . '-late',
				'cb_profiles_extension_late'
			)
		);
	}

	private function create_fixture( string $id, string $name, string $author ): void {
		$directory = WP_PLUGIN_DIR . '/' . $id;
		self::assertTrue( wp_mkdir_p( $directory ), 'Could not create Profile extension fixture directory.' );
		$plugin = "<?php\n/**\n * Plugin Name: {$name}\n * Author: {$author}\n * Version: 1.0.0\n */\ndefined( 'ABSPATH' ) || exit;\n";
		self::assertNotFalse(
			file_put_contents( $directory . '/' . $id . '.php', $plugin ),
			'Could not write Profile extension fixture plugin.'
		);
	}

	private function remove_fixtures(): void {
		foreach ( [ self::FIRST_PARTY_FILE, self::THIRD_PARTY_FILE ] as $plugin_file ) {
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
