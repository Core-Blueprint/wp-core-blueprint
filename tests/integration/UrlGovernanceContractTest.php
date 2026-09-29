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
	private array $saved_extra_rules_top = [];

	public function set_up(): void {
		parent::set_up();

		global $wp_rewrite;

		$this->saved_settings            = get_option( CB_CORE_SETTINGS, '__cb_missing__' );
		$this->saved_permalink_structure = get_option( 'permalink_structure', '__cb_missing__' );
		$this->saved_category_base       = get_option( 'category_base', '__cb_missing__' );
		$this->saved_rewrite_dirty       = get_option( 'cb_core_routing_rewrite_dirty', '__cb_missing__' );
		$this->saved_extra_rules_top     = is_array( $wp_rewrite->extra_rules_top ) ? $wp_rewrite->extra_rules_top : [];

		$this->reset_settings_cache();
		update_option( 'permalink_structure', '/%category%/%postname%/', false );
		update_option( 'category_base', '', false );
		Settings::set_key( Policy::SETTINGS_KEY, Policy::defaults(), 'test:routing' );
		$this->reset_settings_cache();
	}

	public function tear_down(): void {
		global $wp_rewrite;

		$this->restore_option( CB_CORE_SETTINGS, $this->saved_settings );
		$this->restore_option( 'permalink_structure', $this->saved_permalink_structure );
		$this->restore_option( 'category_base', $this->saved_category_base );
		$this->restore_option( 'cb_core_routing_rewrite_dirty', $this->saved_rewrite_dirty );
		$wp_rewrite->extra_rules_top = $this->saved_extra_rules_top;
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
		self::assertArrayNotHasKey( '^(.+?)/p([0-9]+)/?	}

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
, $wp_rewrite->extra_rules_top );
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
