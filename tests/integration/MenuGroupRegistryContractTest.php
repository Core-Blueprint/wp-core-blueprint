<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\MenuGroup;
use CoreBlueprint\Core\Admin\MenuGroupRegistry;
use CoreBlueprint\Core\Admin\Page;

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
