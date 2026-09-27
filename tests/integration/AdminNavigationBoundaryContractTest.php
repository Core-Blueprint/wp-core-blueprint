<?php
declare(strict_types=1);

final class CB_Base_Admin_Navigation_Boundary_Contract_Test extends WP_UnitTestCase {

	public function test_admin_navigation_assets_are_scoped_to_the_single_preferences_tab(): void {
		$screen_context = file_get_contents( CB_CORE_DIR . 'src/Admin/ScreenContext.php' );
		$asset_registry = file_get_contents( CB_CORE_DIR . 'src/Admin/ScreenAssetRegistry.php' );
		$modules = file_get_contents( CB_CORE_DIR . 'src/Admin/AdminModuleDefinitionsPreferences.php' );
		self::assertIsString( $screen_context );
		self::assertIsString( $asset_registry );
		self::assertIsString( $modules );

		self::assertStringContainsString( "'floating-menu', 'admin-navigation', 'reports'", $screen_context );
		self::assertSame( 1, substr_count( $asset_registry, "case 'admin-navigation':" ) );
		self::assertStringContainsString( "'foundation.reorder', 'module.admin-navigation'", $asset_registry );
		self::assertStringContainsString( "'@cb-core/admin-navigation' => static function", $modules );
		self::assertStringContainsString( "'src'  => 'features/admin-navigation.js'", $modules );
		self::assertStringContainsString( "'deps' => [ '@cb-core/reorder' ]", $modules );
	}

	public function test_admin_navigation_uninstall_owns_only_its_canonical_option(): void {
		$uninstall = file_get_contents( CB_CORE_DIR . 'uninstall.php' );
		self::assertIsString( $uninstall );
		self::assertSame( 1, substr_count( $uninstall, "'cb_core_admin_navigation_policy'" ) );
	}

	public function test_v1_boundary_contains_no_menu_internals_aliases_dom_menu_mutation_or_plugin_adapters(): void {
		$root = CB_CORE_DIR . 'src/AdminNavigation/';
		$source = '';
		foreach ( [ 'Policy.php', 'Audience.php', 'Discovery.php', 'AdminMenuRuntime.php', 'ToolbarRuntime.php', 'Bootstrap.php', 'Admin.php' ] as $file ) {
			$contents = file_get_contents( $root . $file );
			self::assertIsString( $contents );
			$source .= "\n" . $contents;
		}
		$source .= "\n" . file_get_contents( CB_CORE_DIR . 'assets/js/features/admin-navigation.js' );

		foreach ( [
			'global $menu',
			'$submenu',
			'menu_page_url(',
			'add_menu_page(',
			'add_submenu_page(',
			'remove_submenu_page(',
			'#adminmenu',
			'querySelector("#adminmenu")',
			"querySelector('#adminmenu')",
			'jQuery',
			'menu.custom',
			'toolbar.custom',
		] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}

	public function test_profile_section_uses_policy_mutation_boundary_without_direct_option_writes(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Profiles/Sections/AdminNavigationSection.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'Policy::replace(', $source );
		self::assertStringNotContainsString( 'update_option(', $source );
		self::assertStringNotContainsString( 'delete_option(', $source );
	}
}
