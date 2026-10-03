<?php
declare(strict_types=1);

use CoreBlueprint\Core\Environment\EnvironmentTypeTestShim;
use CoreBlueprint\Core\Environment\Governance;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\SettingsDefaults;

final class CB_Base_Environment_Governance_Contract_Test extends WP_UnitTestCase {


	/** @var array<string,mixed> */
	private array $previous_policy = [];

	public function set_up(): void {
		parent::set_up();

		EnvironmentTypeTestShim::reset();
		$this->previous_policy = is_array( Settings::get()[ Governance::POLICY_KEY ] ?? null )
			? Settings::get()[ Governance::POLICY_KEY ]
			: Governance::default_policy();

		Settings::set_key( Governance::POLICY_KEY, Governance::default_policy(), 'test:environment-governance' );
		remove_filter( 'wp_robots', [ Governance::class, 'filter_robots' ], 100 );
		remove_filter( 'wp_headers', [ Governance::class, 'filter_headers' ], 100 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_robots', [ Governance::class, 'filter_robots' ], 100 );
		remove_filter( 'wp_headers', [ Governance::class, 'filter_headers' ], 100 );
		Settings::set_key( Governance::POLICY_KEY, $this->previous_policy, 'test:environment-governance-restore' );
		EnvironmentTypeTestShim::reset();

		parent::tear_down();
	}

	public function test_eg1_default_policy_is_portable_and_enabled(): void {
		$defaults = SettingsDefaults::all();

		self::assertSame(
			[ Governance::PROTECT_SEARCH_INDEXING => true ],
			$defaults[ Governance::POLICY_KEY ]
		);
		self::assertSame( Governance::default_policy(), Governance::policy() );
	}

	public function test_eg2_wordpress_environment_identity_is_the_only_identity_source(): void {
		foreach ( [ 'local', 'development', 'staging', 'production' ] as $type ) {
			$this->set_environment( $type );
			self::assertSame( $type, Governance::current_type() );
		}
	}

	public function test_eg3_production_is_a_complete_runtime_noop(): void {
		$this->set_environment( 'production' );

		$robots = [ 'max-image-preview' => 'large', 'nofollow' => true ];
		$headers = [ 'X-Robots-Tag' => 'noarchive, nosnippet', 'Cache-Control' => 'public' ];

		self::assertFalse( Governance::should_protect_search_indexing() );
		self::assertSame( $robots, Governance::filter_robots( $robots ) );
		self::assertSame( $headers, Governance::filter_headers( $headers ) );

		Governance::boot();
		self::assertFalse( has_filter( 'wp_robots', [ Governance::class, 'filter_robots' ] ) );
		self::assertFalse( has_filter( 'wp_headers', [ Governance::class, 'filter_headers' ] ) );
	}

	public function test_eg4_all_non_production_types_add_only_noindex_to_robots(): void {
		foreach ( [ 'local', 'development', 'staging' ] as $type ) {
			$this->set_environment( $type );
			$robots = [
				'max-image-preview' => 'large',
				'nofollow'          => true,
			];

			$filtered = Governance::filter_robots( $robots );

			self::assertTrue( $filtered['noindex'] );
			self::assertSame( 'large', $filtered['max-image-preview'] );
			self::assertTrue( $filtered['nofollow'] );
			self::assertArrayNotHasKey( 'follow', $filtered );
		}
	}

	public function test_eg5_x_robots_tag_is_additive_case_insensitive_and_idempotent(): void {
		$this->set_environment( 'staging' );

		$headers = [
			'X-Robots-Tag' => 'noarchive, nosnippet',
			'Cache-Control' => 'private',
		];
		$once = Governance::filter_headers( $headers );
		self::assertSame( 'noarchive, nosnippet, noindex', $once['X-Robots-Tag'] );
		self::assertSame( 'private', $once['Cache-Control'] );

		$twice = Governance::filter_headers( $once );
		self::assertSame( $once, $twice );

		$existing = [
			'x-robots-tag' => 'NOINDEX, nofollow',
			'Vary'         => 'Accept-Encoding',
		];
		self::assertSame( $existing, Governance::filter_headers( $existing ) );
	}

	public function test_eg6_disabled_policy_leaves_non_production_responses_untouched(): void {
		$this->set_environment( 'development' );
		Settings::set_key(
			Governance::POLICY_KEY,
			[ Governance::PROTECT_SEARCH_INDEXING => false ],
			'test:environment-governance'
		);

		$robots = [ 'nofollow' => true ];
		$headers = [ 'X-Robots-Tag' => 'noarchive' ];

		self::assertFalse( Governance::should_protect_search_indexing() );
		self::assertSame( $robots, Governance::filter_robots( $robots ) );
		self::assertSame( $headers, Governance::filter_headers( $headers ) );
	}

	public function test_eg7_non_production_boot_registers_only_the_native_search_filters(): void {
		$this->set_environment( 'local' );

		Governance::boot();

		self::assertSame( 100, has_filter( 'wp_robots', [ Governance::class, 'filter_robots' ] ) );
		self::assertSame( 100, has_filter( 'wp_headers', [ Governance::class, 'filter_headers' ] ) );
	}

	public function test_eg8_search_safeguard_never_mutates_wordpress_blog_public_state(): void {
		$this->set_environment( 'staging' );
		$before = get_option( 'blog_public' );

		Governance::filter_robots( [] );
		Governance::filter_headers( [] );

		self::assertSame( $before, get_option( 'blog_public' ) );
	}

	public function test_eg9_malformed_stored_policy_falls_back_to_safe_default(): void {
		Settings::set_key( Governance::POLICY_KEY, 'malformed', 'test:environment-governance' );

		self::assertSame( Governance::default_policy(), Governance::policy() );
	}

	public function test_eg10_missing_x_robots_tag_gets_exact_noindex_header(): void {
		$this->set_environment( 'staging' );

		self::assertSame(
			[ 'X-Robots-Tag' => 'noindex' ],
			Governance::filter_headers( [] )
		);
	}

	private function set_environment( string $type ): void {
		EnvironmentTypeTestShim::set( $type );
	}
}
