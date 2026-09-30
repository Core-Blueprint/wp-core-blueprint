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
	private mixed $saved_posts_per_page;
	private string $saved_wp_permalink_structure = '';
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
		$this->saved_posts_per_page      = get_option( 'posts_per_page', '__cb_missing__' );
		$this->saved_wp_permalink_structure = (string) $wp_rewrite->permalink_structure;
		$this->saved_extra_rules_top     = is_array( $wp_rewrite->extra_rules_top ) ? $wp_rewrite->extra_rules_top : [];
		$this->saved_extra_rules         = is_array( $wp_rewrite->extra_rules ) ? $wp_rewrite->extra_rules : [];

		$this->reset_settings_cache();
		update_option( 'permalink_structure', '/%category%/%postname%/', false );
		update_option( 'category_base', '', false );
		update_option( 'posts_per_page', 10, false );
		$wp_rewrite->set_permalink_structure( '/%category%/%postname%/' );
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
		$this->restore_option( 'posts_per_page', $this->saved_posts_per_page );
		$wp_rewrite->set_permalink_structure( $this->saved_wp_permalink_structure );
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

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

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

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

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
		$wordpress = [
			'^category/(.+?)/?$' => 'index.php?category_name=$matches[1]',
		];

		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );

		self::assertSame(
			$wordpress,
			Runtime::filter_category_rewrite_rules( $wordpress )
		);
	}
	public function test_preflight_requires_standard_pretty_permalinks_without_index_php(): void {
		update_option( 'permalink_structure', '', false );
		$plain = Preflight::run();

		self::assertFalse( $plain['ready'] );
		self::assertStringContainsString(
			'pretty permalink structure without index.php',
			implode( ' ', $plain['blockers'] )
		);

		update_option( 'permalink_structure', '/index.php/%postname%/', false );
		$index = Preflight::run();

		self::assertFalse( $index['ready'] );
		self::assertStringContainsString(
			'pretty permalink structure without index.php',
			implode( ' ', $index['blockers'] )
		);
	}

	public function test_preflight_reserves_the_current_wordpress_author_base(): void {
		global $wp_rewrite;

		$saved_author_base = $wp_rewrite->author_base;
		$wp_rewrite->author_base = 'people';

		try {
			self::factory()->category->create( [ 'name' => 'People', 'slug' => 'people' ] );

			$result = Preflight::run();

			self::assertFalse( $result['ready'] );
			self::assertStringContainsString( '/people/', implode( ' ', $result['blockers'] ) );
		} finally {
			$wp_rewrite->author_base = $saved_author_base;
		}
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

	public function test_preflight_blocks_posts_that_really_publish_on_compact_pagination_routes(): void {
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

	public function test_preflight_does_not_block_draft_p_number_posts(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_title'    => 'Draft pagination slug',
				'post_name'     => 'p2',
				'post_category' => [ $term_id ],
			]
		);

		$result = Preflight::run();

		self::assertTrue( $result['ready'] );
		self::assertStringNotContainsString( '/blog/p2/', implode( ' ', $result['blockers'] ) );
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

	public function test_preflight_blocks_queryable_nonpublic_cpt_single_route_collisions(): void {
		register_post_type(
			'cb_route_queryable',
			[
				'public'             => false,
				'publicly_queryable' => true,
				'has_archive'        => false,
				'rewrite'            => [ 'slug' => 'blog' ],
			]
		);

		try {
			self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
			self::factory()->post->create(
				[
					'post_type'   => 'cb_route_queryable',
					'post_status' => 'publish',
					'post_title'  => 'Queryable CPT collision',
					'post_name'   => 'p2',
				]
			);

			$result = Preflight::run();

			self::assertFalse( $result['ready'] );
			self::assertStringContainsString( '/blog/p2/', implode( ' ', $result['blockers'] ) );
		} finally {
			unregister_post_type( 'cb_route_queryable' );
		}
	}

	public function test_preflight_does_not_treat_a_taxonomy_rewrite_base_as_a_term_route_collision(): void {
		register_taxonomy(
			'cb_route_topic',
			'post',
			[
				'public'             => false,
				'publicly_queryable' => true,
				'rewrite'            => [ 'slug' => 'topics' ],
			]
		);

		try {
			self::factory()->category->create( [ 'name' => 'Topics', 'slug' => 'topics' ] );

			$result = Preflight::run();

			self::assertTrue( $result['ready'] );
			self::assertStringNotContainsString( 'taxonomy route at "/topics/"', implode( ' ', $result['blockers'] ) );
		} finally {
			unregister_taxonomy( 'cb_route_topic' );
		}
	}

	public function test_preflight_blocks_a_concrete_nested_taxonomy_term_route_collision(): void {
		register_taxonomy(
			'cb_route_topic',
			'post',
			[
				'public'             => false,
				'publicly_queryable' => true,
				'hierarchical'       => true,
				'rewrite'            => [
					'slug'         => 'news',
					'hierarchical' => true,
				],
			]
		);

		try {
			$parent_id = self::factory()->category->create( [ 'name' => 'News', 'slug' => 'news' ] );
			self::factory()->category->create(
				[
					'name'   => 'Company',
					'slug'   => 'company',
					'parent' => $parent_id,
				]
			);
			wp_insert_term( 'Company', 'cb_route_topic', [ 'slug' => 'company' ] );

			$result = Preflight::run();

			self::assertFalse( $result['ready'] );
			self::assertStringContainsString( '/news/company/', implode( ' ', $result['blockers'] ) );
		} finally {
			unregister_taxonomy( 'cb_route_topic' );
		}
	}

	public function test_enabled_runtime_registers_specific_category_rules_without_a_root_catch_all(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$rules = Runtime::filter_category_rewrite_rules(
			[
				'^category/(.+?)/?$' => 'index.php?category_name=$matches[1]',
			]
		);

		$pagination_rules = array_filter(
			$rules,
			static fn( string $query, string $regex ): bool =>
				str_contains( $regex, '/p([0-9]+)' )
				&& str_contains( $regex, 'blog' )
				&& 'index.php?category_name=$matches[1]&paged=$matches[2]' === $query,
			ARRAY_FILTER_USE_BOTH
		);

		self::assertCount( 1, $pagination_rules );
		self::assertFalse( array_key_exists( '^(.+?)/p([0-9]+)/?$', $rules ) );
		self::assertArrayHasKey( '^category/(.+?)/?$', $rules );
	}
	public function test_compact_blog_page_two_parses_as_category_pagination_not_post_page(): void {
		global $wp, $wp_rewrite;

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
		flush_rewrite_rules( false );

		self::go_to( home_url( '/blog/p2/' ) );

		self::assertTrue( is_category( 'blog' ) );
		self::assertTrue( is_paged() );
		self::assertFalse( is_404() );
		self::assertSame( 2, (int) get_query_var( 'paged' ) );
		self::assertSame( '', (string) get_query_var( 'name' ) );
		self::assertIsString( $wp->matched_rule );
		self::assertStringContainsString( '/p([0-9]+)', $wp->matched_rule );
		self::assertStringContainsString( 'blog', $wp->matched_rule );
		self::assertSame( 'category_name=blog&paged=2', $wp->matched_query );
	}
	public function test_term_link_changes_only_after_explicit_enable(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );
		self::assertInstanceOf( WP_Term::class, $term );

		$wordpress = '/category/%category%/';
		self::assertSame( $wordpress, Runtime::filter_pre_term_link( $wordpress, $term ) );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		self::assertSame(
			'/%category%/',
			Runtime::filter_pre_term_link( $wordpress, $term )
		);
		self::assertSame(
			home_url( '/blog/' ),
			get_term_link( $term, 'category' )
		);
	}
	public function test_category_permastruct_preserves_unknown_context_before_the_category_base(): void {
		update_option( 'category_base', 'topics/category', false );

		self::assertSame(
			'/site/nl/%category%/',
			CategoryRoutes::clean_permastruct( '/site/nl/topics/category/%category%/' )
		);
		self::assertSame(
			'/site/nl/other/%category%/',
			CategoryRoutes::clean_permastruct( '/site/nl/other/%category%/' )
		);
	}

	public function test_resolved_term_link_keeps_downstream_language_domain_and_directory_context(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );
		self::assertInstanceOf( WP_Term::class, $term );

		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$language_context = static function ( string $url ): string {
			$home = home_url( '/' );
			return str_replace( $home, 'https://nl.example.test/nl/', $url );
		};

		add_filter( 'term_link', $language_context, 50, 1 );
		try {
			self::assertSame(
				'https://nl.example.test/nl/blog/',
				get_term_link( $term, 'category' )
			);
			self::assertSame(
				'https://nl.example.test/nl/blog/p2/',
				CategoryRoutes::canonical_url( $term, 2 )
			);
		} finally {
			remove_filter( 'term_link', $language_context, 50 );
		}
	}

	public function test_compact_pagination_preserves_directory_domain_and_query_context(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );
		self::assertInstanceOf( WP_Term::class, $term );

		self::assertSame(
			'https://nl.example.test/site/nl/blog/p3/?lang=nl&utm_source=test',
			CategoryRoutes::pagination_url(
				'https://nl.example.test/site/nl/category/blog/page/2/?lang=nl&utm_source=test',
				$term,
				3
			)
		);
		self::assertSame(
			'https://nl.example.test/site/nl/blog/p3/?lang=nl&utm_source=test',
			CategoryRoutes::pagination_url(
				'https://nl.example.test/site/nl/blog/p2/page/3/?lang=nl&utm_source=test',
				$term,
				3
			)
		);
	}

	public function test_context_transform_fails_open_for_unknown_route_shapes(): void {
		$term_id = self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		$term    = get_term( $term_id, 'category' );
		self::assertInstanceOf( WP_Term::class, $term );

		$url = 'https://nl.example.test/nl/blog/custom/2/?lang=nl';
		self::assertSame( $url, CategoryRoutes::pagination_url( $url, $term, 3 ) );
	}

	public function test_category_rewrite_lifecycle_is_composable_with_later_language_context_filters(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$saw_clean_rule = false;
		$language_filter = static function ( array $rules ) use ( &$saw_clean_rule ): array {
			foreach ( array_keys( $rules ) as $regex ) {
				if ( str_contains( $regex, 'blog' ) && str_contains( $regex, '/p([0-9]+)' ) ) {
					$saw_clean_rule = true;
					break;
				}
			}

			return [ '^nl/context-check/?$' => 'index.php?lang=nl' ] + $rules;
		};

		add_filter( 'category_rewrite_rules', $language_filter, 10 );
		try {
			$rules = apply_filters( 'category_rewrite_rules', [] );
		} finally {
			remove_filter( 'category_rewrite_rules', $language_filter, 10 );
		}

		self::assertTrue( $saw_clean_rule );
		self::assertArrayHasKey( '^nl/context-check/?$', $rules );
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

	public function test_plugin_routing_invalidation_ignores_base_self_lifecycle(): void {
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();
		delete_option( 'cb_core_routing_rewrite_dirty' );

		Runtime::plugin_routing_structure_changed( CB_CORE_BASENAME );
		self::assertFalse( get_option( 'cb_core_routing_rewrite_dirty', false ) );

		Runtime::plugin_routing_structure_changed( 'example-plugin/example-plugin.php' );
		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
	}

	public function test_deactivation_cleanup_preserves_policy_and_removes_registered_rules(): void {
		self::factory()->category->create( [ 'name' => 'Blog', 'slug' => 'blog' ] );
		Settings::set_key(
			Policy::SETTINGS_KEY,
			[ Policy::CLEAN_ARCHIVE_URLS => true ],
			'test:routing'
		);
		$this->reset_settings_cache();

		$owned = Runtime::filter_category_rewrite_rules( [] );
		self::assertNotEmpty( $owned );

		Runtime::cleanup_deactivation();

		self::assertTrue( Policy::enabled() );
		self::assertFalse( get_option( 'cb_core_routing_rewrite_dirty', false ) );

		$stored = get_option( 'rewrite_rules', [] );
		self::assertIsArray( $stored );
		foreach ( array_keys( $owned ) as $owned_regex ) {
			self::assertArrayNotHasKey( $owned_regex, $stored );
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

		$owned_rules = Runtime::filter_category_rewrite_rules( [] );
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
		self::assertFalse( Runtime::is_active() );

		Runtime::prepare_runtime();

		self::assertFalse( Runtime::is_active() );
		self::assertSame( '1', get_option( 'cb_core_routing_runtime_suspended' ) );
		self::assertSame( [], Runtime::filter_category_rewrite_rules( [] ) );

		$filtered = Runtime::filter_generated_rules(
			$owned_rules + [ '^keep-me$' => 'index.php?keep=1' ]
		);
		self::assertArrayHasKey( '^keep-me$', $filtered );
		foreach ( array_keys( $owned_rules ) as $owned_regex ) {
			self::assertArrayNotHasKey( $owned_regex, $filtered );
		}

		$wordpress = '/category/%category%/';
		self::assertSame(
			$wordpress,
			Runtime::filter_pre_term_link( $wordpress, $term )
		);
		$candidate = home_url( '/blog/page/2/' );
		self::assertSame(
			$candidate,
			Runtime::filter_redirect_canonical( $candidate, home_url( '/blog/p2/' ) )
		);

		wp_delete_post( $page_id, true );
		self::assertSame( '1', get_option( 'cb_core_routing_rewrite_dirty' ) );
		Runtime::prepare_runtime();

		self::assertTrue( Runtime::is_active() );
		self::assertFalse( get_option( 'cb_core_routing_runtime_suspended', false ) );

		$restored = Runtime::filter_category_rewrite_rules( [] );
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

	public function test_routing_preferences_use_shared_base_composition_foundation(): void {
		$template = file_get_contents( CB_CORE_DIR . 'templates/preferences-routing.php' );
		self::assertIsString( $template );

		self::assertStringContainsString( 'cb-core-preferences-section', $template );
		self::assertStringContainsString( 'cb-core-stack', $template );
		self::assertStringContainsString( 'widefat cb-core-kv', $template );
		self::assertStringNotContainsString( 'cb-core-panel', $template );
		self::assertStringNotContainsString( 'cb-core-kv-table', $template );
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
