<?php
declare(strict_types=1);

use CB\Core\Admin\Pages\Preferences;
use CB\Core\Routing\CategoryRoutes;
use CB\Core\Routing\Policy;
use CB\Core\Routing\Preflight;
use CB\Core\Routing\Runtime;
use CB\Core\Settings;
use CB\Core\Setup\Registry;

final class CB_Base_URL_Governance_Contract_Test extends WP_UnitTestCase {

	private mixed $saved_settings;
	private mixed $saved_permalink_structure;
	private mixed $saved_category_base;
	private mixed $saved_rewrite_dirty;
	private mixed $saved_runtime_suspended;
	private mixed $saved_rewrite_rules_option;
	private array $saved_extra_rules_top = [];
	private array $saved_extra_rules = [];

	public function set_up(): void {
		parent::set_up();

		global $wp_rewrite;

		$this->saved_settings            = get_option( CB_CORE_SETTINGS, '__cb_missing__' );
		$this->saved_permalink_structure = get_option( 'permalink_structure', '__cb_missing__' );
		$this->saved_category_base       = get_option( 'category_base', '__cb_missing__' );
		$this->saved_rewrite_dirty       = get_option( 'cb_core_routing_rewrite_dirty', '__cb_missing__' );
		$this->saved_runtime_suspended   = get_option( 'cb_core_routing_runtime_suspended', '__cb_missing__' );
		$this->saved_rewrite_rules_option = get_option( 'rewrite_rules', '__cb_missing__' );
		$this->saved_extra_rules_top     = is_array( $wp_rewrite->extra_rules_top ) ? $wp_rewrite->extra_rules_top : [];
		$this->saved_extra_rules         = is_array( $wp_rewrite->extra_rules ) ? $wp_rewrite->extra_rules : [];

		$this->reset_settings_cache();
		update_option( 'permalink_structure', '/%category%/%postname%/', false );
		update_option( 'category_base', '', false );
		Settings::set_key( Policy::SETTINGS_KEY, Policy::defaults(), 'test:routing' );
		delete_option( 'cb_core_routing_runtime_suspended' );
		$this->reset_settings_cache();
	}

	public function tear_down(): void {
		global $wp_rewrite;

		$this->restore_option( CB_CORE_SETTINGS, $this->saved_settings );
		$this->restore_option( 'permalink_structure', $this->saved_permalink_structure );
		$this->restore_option( 'category_base', $this->saved_category_base );
		$this->restore_option( 'cb_core_routing_rewrite_dirty', $this->saved_rewrite_dirty );
		$this->restore_option( 'cb_core_routing_runtime_suspended', $this->saved_runtime_suspended );
		$this->restore_option( 'rewrite_rules', $this->saved_rewrite_rules_option );
		$wp_rewrite->extra_rules_top = $this->saved_extra_rules_top;
		$wp_rewrite->extra_rules     = $this->saved_extra_rules;
		$this->reset_settings_cache();

		parent::tear_down();
	}

	public function test_routing_policy_is_opt_in_by_default(): void {
		self::assertFalse( Policy::defaults()[ Policy::CLEAN_ARCHIVE_URLS ] );
		self::assertFalse( Policy::enabled() );
	}

	public function test_category_archive_contract_uses_root_path_and_compact_pagination(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );

		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame( 'blog', CategoryRoutes::path( $term ) );
		self::assertSame(
			home_url( user_trailingslashit( 'blog', 'category' ) ),
			CategoryRoutes::canonical_url( $term )
		);
		self::assertSame(
			home_url( user_trailingslashit( 'blog/p2', 'paged' ) ),
			CategoryRoutes::canonical_url( $term, 2 )
		);
	}

	public function test_nested_categories_keep_hierarchy_in_clean_route(): void {
		$parent_id = self::factory()->category->create( [ 'name' => 'News', 'slug' => 'news' ] );
		$child_id  = self::factory()->category->create(
			[
				'name'   => 'Company',
				'slug'   => 'company',
				'parent' => $parent_id,
			]
		);
		$term = get_term( $child_id, 'category' );

		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame( 'news/company', CategoryRoutes::path( $term ) );
		self::assertSame(
			home_url( user_trailingslashit( 'news/company/p2', 'paged' ) ),
			CategoryRoutes::canonical_url( $term, 2 )
		);
	}

	public function test_default_feed_format_resolves_to_one_canonical_feed_url(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );

		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame(
			CategoryRoutes::feed_url( $term ),
			CategoryRoutes::feed_url( $term, get_default_feed() )
		);
	}

	public function test_category_base_dot_is_treated_as_no_legacy_base(): void {
		update_option( 'category_base', '.', false );

		self::assertSame( '', CategoryRoutes::category_base_path() );
	}

	public function test_disabled_runtime_does_not_register_clean_category_rules(): void {
		global $wp_rewrite;

		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$wp_rewrite->extra_rules_top = [];

		Runtime::register_rewrite_rules();

		self::assertSame( [], $wp_rewrite->extra_rules_top );
	}

	public function test_preflight_blocks_an_existing_public_content_route(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Blog landing',
				'post_name'   => 'blog',
			]
		);

		$result = Preflight::run();

		self::assertFalse( $result['ready'] );
		self::assertNotEmpty( $result['blockers'] );
		self::assertStringContainsString( '/blog/', implode( ' ', $result['blockers'] ) );
	}

	public function test_preflight_reserves_p_number_post_slugs_inside_categories(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Conflicting post',
				'post_name'     => 'p2',
				'post_category' => [ $term_id ],
			]
		);

		$result = Preflight::run();

		self::assertFalse( $result['ready'] );
		self::assertStringContainsString( '/blog/p2/', implode( ' ', $result['blockers'] ) );
	}

	public function test_preflight_blocks_public_cpt_single_on_reserved_compact_pagination_route(): void {
		register_post_type(
			'cb_route_item',
			[
				'public'      => true,
				'has_archive' => false,
				'rewrite'     => [ 'slug' => 'blog' ],
			]
		);

		try {
			self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
			self::factory()->post->create(
				[
					'post_type'   => 'cb_route_item',
					'post_status' => 'publish',
					'post_title'  => 'CPT pagination collision',
					'post_name'   => 'p2',
				]
			);

			$result = Preflight::run();

			self::assertFalse( $result['ready'] );
			self::assertStringContainsString( '/blog/p2/', implode( ' ', $result['blockers'] ) );
		} finally {
			unregister_post_type( 'cb_route_item' );
		}
	}

	public function test_enabled_runtime_registers_specific_category_rules_without_a_root_catch_all(): void {
		global $wp_rewrite;

		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$wp_rewrite->extra_rules_top = [];
		Runtime::register_rewrite_rules();

		$pagination_rules = array_filter(
			$wp_rewrite->extra_rules_top,
			static fn( string $query, string $regex ): bool =>
				str_contains( $regex, '/p([0-9]+)' )
				&& str_contains( $regex, 'blog' )
				&& 'index.php?category_name=$matches[1]&paged=$matches[2]' === $query,
			ARRAY_FILTER_USE_BOTH
		);

		self::assertCount( 1, $pagination_rules );
		self::assertFalse(
			array_key_exists(
				'^(.+?)/p([0-9]+)/?$',
				$wp_rewrite->extra_rules_top
			)
		);
	}

	public function test_compact_blog_page_two_parses_as_category_pagination_not_post_page(): void {
		global $wp_rewrite, $wp_query;

		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		for ( $i = 1; $i <= 11; $i++ ) {
			self::factory()->post->create(
				[
					'post_status'   => 'publish',
					'post_title'    => 'Routing post ' . $i,
					'post_name'     => 'routing-post-' . $i,
					'post_category' => [ $term_id ],
				]
			);
		}

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$wp_rewrite->set_permalink_structure( '/%category%/%postname%/' );
		Runtime::register_rewrite_rules();
		flush_rewrite_rules( false );

		self::go_to( home_url( '/blog/p2/' ) );

		self::assertTrue( is_category( 'blog' ) );
		self::assertTrue( is_paged() );
		self::assertFalse( is_404() );
		self::assertSame( 2, (int) get_query_var( 'paged' ) );
		self::assertSame( '', (string) get_query_var( 'name' ) );
		self::assertSame( 1, (int) $wp_query->post_count );
	}

	public function test_term_link_changes_only_after_explicit_enable(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );
		self::assertInstanceOf( WP_Term::class, $term );

		$wordpress = home_url( '/category/blog/' );
		self::assertSame( $wordpress, Runtime::filter_term_link( $wordpress, $term, 'category' ) );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		self::assertSame(
			CategoryRoutes::canonical_url( $term ),
			Runtime::filter_term_link( $wordpress, $term, 'category' )
		);
	}

	public function test_redirect_canonical_suppresses_only_clean_canonical_shapes(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$candidate = home_url( '/blog/page/2/' );

		self::assertFalse(
			Runtime::filter_redirect_canonical( $candidate, home_url( '/blog/p2/' ) )
		);
		self::assertSame(
			$candidate,
			Runtime::filter_redirect_canonical( $candidate, home_url( '/blog/rss2/' ) )
		);
	}

	public function test_category_changes_mark_rewrite_rules_dirty_only_when_policy_is_enabled(): void {
		delete_option( 'cb_core_routing_rewrite_dirty' );
		Runtime::category_changed();
		self::assertFalse( get_option( 'cb_core_routing_rewrite_dirty', false ) );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		Runtime::category_changed();
		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
	}

	public function test_reactivation_marks_rewrite_reconciliation_dirty_when_policy_is_enabled(): void {
		delete_option( 'cb_core_routing_rewrite_dirty' );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		Runtime::reconcile_activation();

		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
	}

	public function test_reactivation_is_noop_when_policy_is_disabled(): void {
		delete_option( 'cb_core_routing_rewrite_dirty' );

		Runtime::reconcile_activation();

		self::assertFalse( get_option( 'cb_core_routing_rewrite_dirty', false ) );
	}

	public function test_deactivation_cleanup_preserves_policy_and_removes_registered_rules(): void {
		global $wp_rewrite;

		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$wp_rewrite->extra_rules_top = [];
		Runtime::register_rewrite_rules();
		self::assertNotEmpty( $wp_rewrite->extra_rules_top );

		Runtime::cleanup_deactivation();

		self::assertTrue( Policy::enabled() );
		self::assertFalse( get_option( 'cb_core_routing_rewrite_dirty', false ) );

		foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $regex ) {
			self::assertStringNotContainsString( '/p([0-9]+)', $regex );
			self::assertStringNotContainsString( 'blog', $regex );
		}
	}

	public function test_content_only_edits_do_not_dirty_routing_but_slug_changes_do(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Routing page',
				'post_name'   => 'routing-page',
			]
		);

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();
		delete_option( 'cb_core_routing_rewrite_dirty' );

		wp_update_post(
			[
				'ID'         => $page_id,
				'post_title' => 'Routing page updated',
			]
		);
		self::assertFalse( get_option( 'cb_core_routing_rewrite_dirty', false ) );

		wp_update_post(
			[
				'ID'        => $page_id,
				'post_name' => 'routing-page-renamed',
			]
		);
		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
	}

	public function test_category_relationship_changes_dirty_routing_when_policy_is_enabled(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Relationship routing post',
				'post_name'   => 'relationship-routing-post',
			]
		);

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();
		delete_option( 'cb_core_routing_rewrite_dirty' );

		wp_set_post_categories( $post_id, [ $term_id ], false );

		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
	}

	public function test_runtime_fails_open_after_a_post_enable_route_collision_and_recovers_after_resolution(): void {
		global $wp_rewrite;

		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );
		self::assertInstanceOf( WP_Term::class, $term );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();
		Runtime::mark_rewrite_dirty();
		Runtime::prepare_runtime();

		self::assertTrue( Runtime::is_active() );

		$wp_rewrite->extra_rules_top = [];
		Runtime::register_rewrite_rules();
		$owned_rules = $wp_rewrite->extra_rules_top;
		self::assertNotEmpty( $owned_rules );

		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Blog landing',
				'post_name'   => 'blog',
			]
		);

		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
		Runtime::prepare_runtime();

		self::assertFalse( Runtime::is_active() );
		self::assertSame( '1', get_option( 'cb_core_routing_runtime_suspended' ) );

		$filtered = Runtime::filter_generated_rules(
			$owned_rules + [ '^keep-me$' => 'index.php?keep=1' ]
		);
		self::assertArrayHasKey( '^keep-me$', $filtered );
		foreach ( array_keys( $owned_rules ) as $owned_regex ) {
			self::assertArrayNotHasKey( $owned_regex, $filtered );
		}

		$wp_rewrite->extra_rules_top = [];
		Runtime::register_rewrite_rules();
		self::assertSame( [], $wp_rewrite->extra_rules_top );

		$wordpress = home_url( '/category/blog/' );
		self::assertSame(
			$wordpress,
			Runtime::filter_term_link( $wordpress, $term, 'category' )
		);

		wp_delete_post( $page_id, true );
		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
		Runtime::prepare_runtime();

		self::assertTrue( Runtime::is_active() );
		self::assertFalse( get_option( 'cb_core_routing_runtime_suspended', false ) );

		$restored = Runtime::filter_generated_rules( [ '^keep-me$' => 'index.php?keep=1' ] );
		self::assertArrayHasKey( '^keep-me$', $restored );
		self::assertTrue(
			(bool) array_filter(
				array_keys( $restored ),
				static fn( string $regex ): bool => str_contains( $regex, 'blog' ) && str_contains( $regex, '/p([0-9]+)' )
			)
		);
	}

	public function test_disabled_core_setup_evidence_reports_wordpress_default_without_collision_attention(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Blog landing',
				'post_name'   => 'blog',
			]
		);

		$check = Registry::get( 'routing-urls' );
		self::assertNotNull( $check );

		$evidence = $check->evidence();

		self::assertSame( 'ok', $evidence->health() );
		self::assertSame( 'routing.wordpress-default', $evidence->code() );
		self::assertFalse( $evidence->context()['enabled'] );
		self::assertSame( 0, $evidence->context()['blocker_count'] );
	}

	public function test_enabled_core_setup_evidence_surfaces_route_collisions_as_attention(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Blog landing',
				'post_name'   => 'blog',
			]
		);
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$check = Registry::get( 'routing-urls' );
		self::assertNotNull( $check );

		$evidence = $check->evidence();

		self::assertSame( 'attention', $evidence->health() );
		self::assertSame( 'routing.collision-detected', $evidence->code() );
		self::assertTrue( $evidence->context()['enabled'] );
		self::assertGreaterThan( 0, $evidence->context()['blocker_count'] );
	}

	public function test_core_setup_registers_routing_as_optional_cms_tool(): void {
		$check = Registry::get( 'routing-urls' );

		self::assertNotNull( $check );
		self::assertSame( 'cms-tools', $check->section() );
		self::assertSame( 'Routing & URLs', $check->label() );
		self::assertStringContainsString(
			'page=' . Preferences::SLUG . '&tab=routing',
			html_entity_decode( $check->configuration_url() )
		);
	}

	public function test_preferences_owns_routing_instead_of_extensions_settings_hub(): void {
		$preferences_source = file_get_contents( CB_CORE_DIR . 'src/Admin/Pages/Preferences.php' );
		self::assertIsString( $preferences_source );
		self::assertStringContainsString( "'routing'", $preferences_source );
		self::assertStringContainsString( 'Routing & URLs', $preferences_source );

		$settings_source = file_get_contents( CB_CORE_DIR . 'src/Admin/Pages/Settings.php' );
		self::assertIsString( $settings_source );
		self::assertStringContainsString(
			'canonical configuration directory for Core Blueprint extensions',
			$settings_source
		);
	}

	private function reset_settings_cache(): void {
		$property = new ReflectionProperty( Settings::class, 'cached' );
		$property->setValue( null, null );
	}

	private function restore_option( string $name, mixed $value ): void {
		if ( '__cb_missing__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
