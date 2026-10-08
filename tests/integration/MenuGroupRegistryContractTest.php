<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\MenuGroup;
use CoreBlueprint\Core\Admin\MenuGroupRegistry;
use CoreBlueprint\Core\Admin\Page;
use CoreBlueprint\Core\Admin\ScreenContext;
use CoreBlueprint\Core\Admin\ScreenAssetRegistry;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;

final class CB_Base_Menu_Group_Registry_Contract_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		MenuGroupRegistry::_reset_for_testing();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		MenuGroupRegistry::_reset_for_testing();
		parent::tearDown();
	}

	public function test_registers_typed_product_group_with_distinct_page_identities(): void {
		$workflows = $this->page( 'cb-test-product-workflows', 'Workflows', 'manage_options', 10 );
		$runs = $this->page( 'cb-test-product-runs', 'Runs', 'read', 20 );
		$group = new MenuGroup(
			'cb-test-product',
			'Test Product',
			'Test Product',
			'read',
			'dashicons-admin-generic',
			58
		);

		self::assertTrue(
			MenuGroupRegistry::register(
				$group,
				[ $workflows, $runs ],
				[
					'cb-test-product-workflows' => [ 'components' => [ 'cards' ] ],
					'cb-test-product-runs' => [ 'components' => [ 'status' ] ],
				]
			)
		);
		self::assertSame( $group, MenuGroupRegistry::group( 'cb-test-product' ) );
		self::assertSame( $workflows, MenuGroupRegistry::get( 'cb-test-product-workflows' ) );
		self::assertSame( $runs, MenuGroupRegistry::get( 'cb-test-product-runs' ) );
	}

	public function test_rejects_page_slug_that_collides_with_group_slug(): void {
		$group = new MenuGroup(
			'cb-test-product',
			'Test Product',
			'Test Product',
			'manage_options'
		);

		$this->setExpectedIncorrectUsage( MenuGroupRegistry::class );
		self::assertFalse(
			MenuGroupRegistry::register(
				$group,
				[ $this->page( 'cb-test-product', 'Workflows', 'manage_options', 10 ) ]
			)
		);
	}

	public function test_top_level_hook_renders_accessible_landing_once_and_matches_page_hook(): void {
		$workflows = $this->page( 'cb-test-render-workflows', 'Workflows', 'read', 10 );
		$runs = $this->page( 'cb-test-render-runs', 'Runs', 'read', 20 );
		$group = new MenuGroup(
			'cb-test-render',
			'Test Render',
			'Test Render',
			'read',
			'dashicons-admin-generic',
			58
		);

		self::assertTrue( MenuGroupRegistry::register( $group, [ $workflows, $runs ] ) );
		MenuGroupRegistry::finalize();

		$root_hook = get_plugin_page_hookname( 'cb-test-render', '' );
		self::assertTrue( MenuGroupRegistry::is_page_hook( 'cb-test-render-workflows', $root_hook ) );
		self::assertFalse( MenuGroupRegistry::is_page_hook( 'cb-test-render-runs', $root_hook ) );
		self::assertNotSame( '', MenuGroupRegistry::hook_suffix( 'cb-test-render-workflows' ) );
		self::assertNotSame( $root_hook, MenuGroupRegistry::hook_suffix( 'cb-test-render-workflows' ) );

		ob_start();
		do_action( $root_hook );
		$output = (string) ob_get_clean();
		self::assertSame( 'cb-test-render-workflows', $output );
	}

	public function test_product_screen_context_resolves_landing_and_children_as_core_admin_screens(): void {
		$overview = $this->page( 'cb-test-hub-overview', 'Overview', 'manage_options', 10 );
		$operations = $this->page( 'cb-test-hub-operations', 'Operations', 'manage_options', 30 );
		$group = new MenuGroup(
			'cb-test-hub-menu',
			'Test Hub',
			'Hub',
			'manage_options',
			'dashicons-networking',
			82
		);

		// Product-menu screen resolution needs an approved privileged identity.
		// Base's Privileged Access Guard intentionally restricts new administrators.
		$user = wp_get_current_user();
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'menu_group_screen_context_fixture' ) );
		self::assertTrue( current_user_can( 'manage_options' ) );

		self::assertTrue( MenuGroupRegistry::register( $group, [ $overview, $operations ] ) );
		MenuGroupRegistry::finalize();

		$landing_hook = get_plugin_page_hookname( $group->slug(), '' );
		$overview_hook = MenuGroupRegistry::hook_suffix( $overview->slug() );
		$operations_hook = MenuGroupRegistry::hook_suffix( $operations->slug() );

		self::assertSame( $overview->slug(), MenuGroupRegistry::registered_page_slug_for_hook( $landing_hook ) );
		self::assertSame( $overview->slug(), MenuGroupRegistry::registered_page_slug_for_hook( $overview_hook ) );
		self::assertSame( $operations->slug(), MenuGroupRegistry::registered_page_slug_for_hook( $operations_hook ) );
		self::assertSame( '', MenuGroupRegistry::registered_page_slug_for_hook( 'unregistered-admin-hook' ) );

		$landing = ScreenContext::from_request( $landing_hook );
		$child = ScreenContext::from_request( $operations_hook );
		self::assertSame( $overview->slug(), $landing->registered_slug() );
		self::assertSame( $operations->slug(), $child->registered_slug() );
		self::assertTrue( ScreenAssetRegistry::owns( $landing ) );
		self::assertTrue( ScreenAssetRegistry::owns( $child ) );
		self::assertFalse( ScreenAssetRegistry::requires_full_set( $child ) );
	}

	/** WordPress must receive product menus through the actual admin_menu hook. */
	public function test_product_menu_bootstrap_registers_wordpress_hooks_and_wires_pages(): void {
		$core_source = file_get_contents( CB_CORE_DIR . 'src/Core.php' );
		self::assertIsString( $core_source );
		self::assertStringContainsString( 'MenuGroupRegistry::init();', $core_source, 'Base must initialize the product-menu registry from its admin-screen bootstrap.' );

		MenuGroupRegistry::init();
		self::assertSame( 21, has_action( 'admin_menu', [ MenuGroupRegistry::class, 'finalize' ] ) );
		self::assertSame( 20, has_action( 'admin_enqueue_scripts', [ MenuGroupRegistry::class, 'enqueue_requirements_for_hook' ] ) );

		$overview = $this->page( 'cb-test-menu-lifecycle-overview', 'Overview', 'read', 10 );
		$group = new MenuGroup( 'cb-test-menu-lifecycle', 'Lifecycle', 'Lifecycle', 'read', 'dashicons-admin-generic', 82 );
		self::assertTrue( MenuGroupRegistry::register( $group, [ $overview ] ) );

		// Do not call finalize() directly: exercise the same WordPress hook
		// dispatch used by real wp-admin requests.
		do_action( 'admin_menu' );
		$landing_hook = get_plugin_page_hookname( $group->slug(), '' );
		$overview_hook = MenuGroupRegistry::hook_suffix( $overview->slug() );
		self::assertNotSame( '', $overview_hook );
		self::assertSame( $overview->slug(), MenuGroupRegistry::registered_page_slug_for_hook( $landing_hook ) );
		self::assertSame( $overview->slug(), MenuGroupRegistry::registered_page_slug_for_hook( $overview_hook ) );
	}
	private function page( string $slug, string $menu_title, string $capability, ?int $position ): Page {
		return new class( $slug, $menu_title, $capability, $position ) implements Page {
			public function __construct(
				private string $slug_value,
				private string $menu_title_value,
				private string $capability_value,
				private ?int $position_value
			) {}

			public function slug(): string {
				return $this->slug_value;
			}

			public function title(): string {
				return $this->menu_title_value;
			}

			public function menu_title(): string {
				return $this->menu_title_value;
			}

			public function capability(): string {
				return $this->capability_value;
			}

			public function position(): ?int {
				return $this->position_value;
			}

			public function render(): void {
				echo esc_html( $this->slug_value );
			}
		};
	}
}
