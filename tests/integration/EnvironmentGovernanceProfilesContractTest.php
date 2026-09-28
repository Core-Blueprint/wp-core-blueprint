<?php
declare(strict_types=1);

use CB\Core\Environment\EnvironmentTypeTestShim;
use CB\Core\Environment\Governance;
use CB\Core\Profiles\ApplyLock;
use CB\Core\Profiles\Diff;
use CB\Core\Profiles\Engine;
use CB\Core\Profiles\SectionInterface;
use CB\Core\Profiles\SectionRegistry;
use CB\Core\Profiles\Sections\EnvironmentGovernanceSection;
use CB\Core\Security\AccessMode;
use CB\Core\Security\AccessModeState;
use CB\Core\Settings;

final class CB_Profiles_Environment_Governance_Failing_Section implements SectionInterface {
	public function id(): string { return 'zz-environment-failing'; }
	public function label(): string { return 'Environment rollback fixture'; }
	public function description(): string { return 'Injected failure after Environment Governance.'; }
	public function schema_version(): int { return 1; }
	public function supports_schema_version( int $schema_version ): bool { return 1 === $schema_version; }
	public function migrate( array $incoming, int $source_schema_version ): array {
		if ( 1 !== $source_schema_version ) {
			throw new InvalidArgumentException();
		}
		return $this->normalize( $incoming );
	}
	public function export(): array { return [ 'value' => (string) get_option( 'cb_profiles_environment_failure', 'stable' ) ]; }
	public function normalize( array $incoming ): array {
		if ( [ 'value' ] !== array_keys( $incoming ) || ! is_string( $incoming['value'] ?? null ) ) {
			throw new InvalidArgumentException();
		}
		return [ 'value' => $incoming['value'] ];
	}
	public function preflight( array $incoming, array $current ): void { unset( $incoming, $current ); }
	public function snapshot(): array { return $this->export(); }
	public function warnings( array $incoming ): array { unset( $incoming ); return []; }
	public function preview( array $current, array $incoming ): array { return Diff::between( $current, $incoming ); }
	public function apply( array $incoming, string $actor ): void {
		unset( $incoming, $actor );
		update_option( 'cb_profiles_environment_failure', 'partial', false );
		throw new RuntimeException( 'Injected Environment Profile failure.' );
	}
	public function restore( array $snapshot, array $incoming, string $actor ): void {
		unset( $incoming, $actor );
		update_option( 'cb_profiles_environment_failure', (string) $snapshot['value'], false );
	}
	public function verify( array $incoming ): bool { unset( $incoming ); return false; }
}

final class CB_Base_Environment_Governance_Profiles_Contract_Test extends WP_UnitTestCase {


	/** @var array{protect_search_indexing:bool} */
	private array $previous_policy = [];

	private string $previous_access_mode = AccessMode::MODE_PUBLIC;

	public function set_up(): void {
		parent::set_up();

		EnvironmentTypeTestShim::reset();
		$this->previous_policy = Governance::policy();
		$this->previous_access_mode = AccessMode::current();

		ApplyLock::clear();
		SectionRegistry::_set_for_testing( null );
		delete_option( 'cb_profiles_environment_failure' );
		remove_filter( 'wp_robots', [ Governance::class, 'filter_robots' ], 100 );
		remove_filter( 'wp_headers', [ Governance::class, 'filter_headers' ], 100 );
	}

	public function tear_down(): void {
		SectionRegistry::_set_for_testing( null );
		ApplyLock::clear();
		delete_option( 'cb_profiles_environment_failure' );
		Settings::set_key( Governance::POLICY_KEY, $this->previous_policy, 'test:environment-profile-restore' );
		AccessModeState::persist_mode( $this->previous_access_mode );
		remove_filter( 'wp_robots', [ Governance::class, 'filter_robots' ], 100 );
		remove_filter( 'wp_headers', [ Governance::class, 'filter_headers' ], 100 );
		EnvironmentTypeTestShim::reset();

		parent::tear_down();
	}

	public function test_ep1_registry_metadata_and_export_are_strictly_portable(): void {
		$section = SectionRegistry::get( 'environment-governance' );

		self::assertInstanceOf( EnvironmentGovernanceSection::class, $section );
		self::assertSame( 'environment-governance', $section->id() );
		self::assertSame( 1, $section->schema_version() );
		self::assertSame(
			[ Governance::PROTECT_SEARCH_INDEXING ],
			array_keys( $section->export() )
		);
		self::assertIsBool( $section->export()[ Governance::PROTECT_SEARCH_INDEXING ] );

		$document = Engine::export_document( 'Environment portability', '', [ 'environment-governance' ] );
		$json = wp_json_encode( $document );

		self::assertIsString( $json );
		foreach ( [
			'WP_ENVIRONMENT_TYPE',
			'environment_type',
			'hostname',
			'domain',
			'site_url',
			'access-mode',
			'blog_public',
			'X-Robots-Tag',
		] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $json );
		}
	}

	public function test_ep2_normalization_accepts_only_the_exact_boolean_payload(): void {
		$section = new EnvironmentGovernanceSection();

		self::assertSame(
			[ Governance::PROTECT_SEARCH_INDEXING => true ],
			$section->normalize( [ Governance::PROTECT_SEARCH_INDEXING => true ] )
		);
		self::assertSame(
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			$section->normalize( [ Governance::PROTECT_SEARCH_INDEXING => false ] )
		);

		foreach ( [
			[],
			[ Governance::PROTECT_SEARCH_INDEXING => 'true' ],
			[ Governance::PROTECT_SEARCH_INDEXING => true, 'environment_type' => 'staging' ],
		] as $invalid ) {
			try {
				$section->normalize( $invalid );
				self::fail( 'Malformed Environment Governance Profile payload was accepted.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_ep3_snapshot_preview_apply_verify_and_guarded_restore_use_canonical_policy(): void {
		$section = new EnvironmentGovernanceSection();
		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			'test:environment-profile'
		);

		$snapshot = $section->snapshot();
		$incoming = [ Governance::PROTECT_SEARCH_INDEXING => true ];
		$changes = $section->preview( $snapshot, $incoming );

		self::assertSame( [ Governance::PROTECT_SEARCH_INDEXING => false ], $snapshot );
		self::assertCount( 1, $changes );

		$section->apply( $incoming, 'test:environment-profile' );
		self::assertTrue( $section->verify( $incoming ) );
		self::assertSame( $incoming, Governance::policy() );

		$section->restore( $snapshot, $incoming, 'test:environment-profile-rollback' );
		self::assertSame( $snapshot, Governance::policy() );
		self::assertTrue( $section->verify( $snapshot ) );

		$file = ( new ReflectionClass( EnvironmentGovernanceSection::class ) )->getFileName();
		self::assertIsString( $file );
		$source = (string) file_get_contents( $file );
		self::assertStringContainsString( 'Settings::set_key(', $source );
		self::assertStringNotContainsString( 'update_option(', $source );
	}

	public function test_ep4_later_profile_section_failure_rolls_environment_policy_back(): void {
		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			'test:environment-profile'
		);
		update_option( 'cb_profiles_environment_failure', 'stable', false );
		SectionRegistry::_set_for_testing( [ new CB_Profiles_Environment_Governance_Failing_Section() ] );

		$document = [
			'format'         => 'core-blueprint-profile',
			'format_version' => 1,
			'profile'        => [ 'name' => 'Environment rollback', 'description' => '' ],
			'source'         => [ 'base_version' => CB_CORE_VERSION ],
			'exported_at'    => gmdate( 'c' ),
			'sections'       => [
				'environment-governance' => [
					'schema_version' => 1,
					'data'           => [ Governance::PROTECT_SEARCH_INDEXING => true ],
				],
				'zz-environment-failing' => [
					'schema_version' => 1,
					'data'           => [ 'value' => 'explode' ],
				],
			],
		];

		$preview = Engine::preview( $document );
		try {
			Engine::apply( $document, $preview['fingerprint'], 'test:environment-profile' );
			self::fail( 'Injected later Profile failure did not abort the transaction.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Injected Environment Profile failure', $error->getMessage() );
		}

		self::assertSame(
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			Governance::policy()
		);
		self::assertSame( 'stable', get_option( 'cb_profiles_environment_failure' ) );
	}

	public function test_ep5_policy_is_portable_across_production_and_non_production_without_access_mode_mutation(): void {
		AccessModeState::persist_mode( AccessMode::MODE_ADMIN_ONLY );
		$this->set_environment( 'production' );
		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			'test:environment-profile'
		);

		$document = [
			'format'         => 'core-blueprint-profile',
			'format_version' => 1,
			'profile'        => [ 'name' => 'Portable environment policy', 'description' => '' ],
			'source'         => [ 'base_version' => CB_CORE_VERSION ],
			'exported_at'    => gmdate( 'c' ),
			'sections'       => [
				'environment-governance' => [
					'schema_version' => 1,
					'data'           => [ Governance::PROTECT_SEARCH_INDEXING => true ],
				],
			],
		];

		$preview = Engine::preview( $document );
		$result = Engine::apply( $document, $preview['fingerprint'], 'test:environment-profile' );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( [ Governance::PROTECT_SEARCH_INDEXING => true ], Governance::policy() );
		self::assertSame( AccessMode::MODE_ADMIN_ONLY, AccessMode::current() );
		self::assertFalse( Governance::should_protect_search_indexing() );
		self::assertSame( [ 'nofollow' => true ], Governance::filter_robots( [ 'nofollow' => true ] ) );

		Governance::boot();
		self::assertFalse( has_filter( 'wp_robots', [ Governance::class, 'filter_robots' ] ) );
		self::assertFalse( has_filter( 'wp_headers', [ Governance::class, 'filter_headers' ] ) );

		$this->set_environment( 'staging' );
		self::assertTrue( Governance::should_protect_search_indexing() );
		self::assertSame(
			[ 'nofollow' => true, 'noindex' => true ],
			Governance::filter_robots( [ 'nofollow' => true ] )
		);
		self::assertSame( AccessMode::MODE_ADMIN_ONLY, AccessMode::current() );
	}

	public function test_ep6_false_policy_uses_existing_profiles_warning_boundary_only(): void {
		$section = new EnvironmentGovernanceSection();

		self::assertSame( [], $section->warnings( [ Governance::PROTECT_SEARCH_INDEXING => true ] ) );

		$warnings = $section->warnings( [ Governance::PROTECT_SEARCH_INDEXING => false ] );
		self::assertCount( 1, $warnings );
		self::assertStringContainsString( 'disables the non-production noindex safeguard', $warnings[0] );

		$document = [
			'format'         => 'core-blueprint-profile',
			'format_version' => 1,
			'profile'        => [ 'name' => 'Warning fixture', 'description' => '' ],
			'source'         => [ 'base_version' => CB_CORE_VERSION ],
			'exported_at'    => gmdate( 'c' ),
			'sections'       => [
				'environment-governance' => [
					'schema_version' => 1,
					'data'           => [ Governance::PROTECT_SEARCH_INDEXING => false ],
				],
			],
		];

		$preview = Engine::preview( $document );
		self::assertSame( $warnings, $preview['sections']['environment-governance']['warnings'] );
	}

	private function set_environment( string $type ): void {
		EnvironmentTypeTestShim::set( $type );
	}
}
